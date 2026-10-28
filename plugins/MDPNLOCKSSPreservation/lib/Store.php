<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace MDPNPreservation;
require_once __DIR__.'/Config.php';

/** The private SQLite ledger is deliberately separate from Providence's schema. */
final class Store {
	private $db;
	private $root;
	private $organization;
	private $readOnly;
	public function __construct(Config $config, bool $initialize=false, bool $readOnly=false) {
		if ($initialize && $readOnly) { throw new Failure('Read-only preservation storage cannot be initialized.'); }
		$this->readOnly = $readOnly;
		$this->root = $config->stateDirectory(!$readOnly);
		$this->organization = (string)$config->get('organization_code');
		$path = $this->root.'/ledger.sqlite';
		if (is_link($path) || (!$initialize && !is_file($path))) { throw new Failure('Preservation storage is not initialized. Run mdpn-preservation-init --apply.'); }
		$old = umask(0077);
		$dsn = $readOnly ? 'sqlite:file:'.str_replace('%2F','/',rawurlencode($path)).'?mode=ro' : 'sqlite:'.$path;
		try { $this->db = new \PDO($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]); }
		finally { umask($old); }
		if ($readOnly) { $this->db->exec('PRAGMA query_only=ON'); }
		$this->db->exec('PRAGMA busy_timeout=10000');
		if ($initialize) { $this->initialize(); }
		if ($this->db->query('PRAGMA user_version')->fetchColumn() != 1) { throw new Failure('Unsupported preservation ledger version.'); }
	}
	private function initialize() : void {
		$version = (int)$this->db->query('PRAGMA user_version')->fetchColumn();
		if ($version === 1) { return; }
		if ($version !== 0) { throw new Failure('Unsupported preservation ledger version.'); }
		$this->db->beginTransaction();
		try {
			$this->db->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
			$this->db->exec('CREATE TABLE collections (collection_id INTEGER PRIMARY KEY, group_identifier TEXT UNIQUE NOT NULL)');
			$this->db->exec("CREATE TABLE jobs (id TEXT PRIMARY KEY, collection_id INTEGER NOT NULL CHECK(collection_id > 0), revision INTEGER NOT NULL,
				mode TEXT NOT NULL CHECK(mode IN ('scan','full','supplemental')), target TEXT NOT NULL, user_id INTEGER NOT NULL,
				status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, metadata TEXT NOT NULL,
				parent_id TEXT, task_id INTEGER, result TEXT, error TEXT, confirmation TEXT)");
			$this->db->exec('CREATE UNIQUE INDEX deposit_revision ON jobs(collection_id, revision) WHERE revision > 0');
			$this->db->exec("CREATE UNIQUE INDEX active_collection ON jobs(collection_id) WHERE status IN ('queued','running')");
			$this->db->exec('CREATE INDEX collection_history ON jobs(collection_id, created_at)');
			$this->db->prepare('INSERT INTO settings VALUES (?,?)')->execute(['repository_id', bin2hex(random_bytes(16))]);
			$this->db->exec('PRAGMA user_version=1');
			$this->db->commit();
		} catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
	}
	public function repositoryId() : string { return (string)$this->db->query("SELECT value FROM settings WHERE key='repository_id'")->fetchColumn(); }
	private function requireWritable() : void { if ($this->readOnly) { throw new Failure('Preservation storage is read-only.'); } }
	public function groupIdentifier(int $collection) : string {
		$this->requireWritable();
		if ($collection < 1 || !preg_match('/^[A-Z0-9]{2,12}$/D',$this->organization)) { throw new Failure('Configure organization_code before preserving collections.'); }
		$this->db->prepare('INSERT OR IGNORE INTO collections VALUES (?,?)')->execute([$collection,$this->organization.'_'.$this->repositoryId().'_C'.$collection]);
		$q = $this->db->prepare('SELECT group_identifier FROM collections WHERE collection_id=?'); $q->execute([$collection]);
		return $q->fetchColumn();
	}
	public function create(int $collection, string $mode, string $target, int $user, array $metadata) : array {
		$this->requireWritable();
		if ($collection < 1 || $user < 1 || !in_array($mode, ['scan', 'full', 'supplemental'], true)) { throw new Failure('Invalid preservation request.'); }
		$this->db->exec('BEGIN IMMEDIATE');
		try {
			$parent = $this->baseline($collection, $target);
			if ($mode === 'supplemental' && !$parent) { throw new Failure('Create a full deposit for this destination before a supplement.'); }
			$q = $this->db->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM jobs WHERE collection_id=?');
			$q->execute([$collection]);
			$revision = $mode === 'scan' ? 0 : (int)$q->fetchColumn();
			$id = bin2hex(random_bytes(16));
			$now = gmdate('c');
			$q = $this->db->prepare('INSERT INTO jobs(id,collection_id,revision,mode,target,user_id,status,created_at,updated_at,metadata,parent_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
			$q->execute([$id,$collection,$revision,$mode,$target,$user,'queued',$now,$now,self::json($metadata),$mode === 'supplemental' ? $parent['id'] : null]);
			$this->db->exec('COMMIT');
			return $this->get($id);
		} catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
	}
	public function get(string $id) : array {
		if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new Failure('Invalid preservation job identifier.'); }
		$q = $this->db->prepare('SELECT * FROM jobs WHERE id=?'); $q->execute([$id]);
		if (!($row = $q->fetch(\PDO::FETCH_ASSOC))) { throw new Failure('Preservation job does not exist.'); }
		foreach (['metadata','result','confirmation'] as $field) { $row[$field] = $row[$field] === null ? null : json_decode($row[$field], true, 512, JSON_THROW_ON_ERROR); }
		return $row;
	}
	public function history(int $collection) : array {
		$q = $this->db->prepare('SELECT id FROM jobs WHERE collection_id=? ORDER BY created_at DESC, rowid DESC LIMIT 100'); $q->execute([$collection]);
		return array_map(function($id) { return $this->get($id); }, $q->fetchAll(\PDO::FETCH_COLUMN));
	}
	public function baseline(int $collection, string $target) : ?array {
		$q = $this->db->prepare("SELECT id FROM jobs WHERE collection_id=? AND target=? AND mode!='scan' AND status IN ('built','uploaded','confirmed') ORDER BY revision DESC LIMIT 1");
		$q->execute([$collection,$target]); $id = $q->fetchColumn(); return $id ? $this->get($id) : null;
	}
	/** Uncapped history for maintenance: old confirmations must not disappear. */
	public function reportJobs(string $target) : \Generator {
		$q = $this->db->prepare("SELECT id FROM jobs WHERE target=? AND mode!='scan' ORDER BY collection_id, revision DESC");
		$q->execute([$target]);
		while ($id = $q->fetchColumn()) { yield $this->get($id); }
	}
	public function update(string $id, array $fields) : void {
		$this->requireWritable();
		$allowed = ['status','task_id','result','error','confirmation']; $set = []; $values = [];
		foreach ($fields as $key => $value) {
			if (!in_array($key,$allowed,true)) { throw new \LogicException('Invalid ledger field.'); }
			$set[] = $key.'=?'; $values[] = in_array($key,['result','confirmation'],true) && $value !== null ? self::json($value) : $value;
		}
		$set[] = 'updated_at=?'; $values[] = gmdate('c'); $values[] = $id;
		$this->db->prepare('UPDATE jobs SET '.implode(',',$set).' WHERE id=?')->execute($values);
	}
	public function directory(string $id, bool $create=false) : string {
		if ($create) { $this->requireWritable(); }
		$this->get($id); $path = $this->root.'/'.$id;
		if (is_link($path)) { throw new Failure('Unsafe preservation job directory.'); }
		if ($create && !is_dir($path) && !mkdir($path,0700)) { throw new Failure('Cannot create preservation job directory.'); }
		return $path;
	}
	public function lock(int $collection) {
		$this->requireWritable();
		$path = $this->root.'/collection_'.$collection.'.lock';
		if (is_link($path)) { throw new Failure('Unsafe preservation lock.'); }
		$handle = @fopen($path,'c');
		if (!$handle) { throw new Failure('The preservation collection lock could not be opened.'); }
		chmod($path,0600);
		if (!flock($handle,LOCK_EX|LOCK_NB)) { fclose($handle); throw new Failure('This collection has an active preservation worker.'); }
		return $handle;
	}
	public static function json($data) : string { return json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
	public static function write(string $path, $data) : void {
		$temp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
		if (file_put_contents($temp,self::json($data),LOCK_EX) === false) { throw new Failure('Cannot write preservation state.'); }
		chmod($temp,0600);
		if (!rename($temp,$path)) { throw new Failure('Cannot publish preservation state.'); }
	}
}
