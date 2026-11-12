<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace FileHealth;
require_once __DIR__.'/Config.php';

/** Private, independent history. Reads never create storage or update a check. */
final class Store {
	public const STATUSES = ['verified','mismatch','missing','unreadable','unverified','changed','error'];
	public const SCOPE = 'Representation media only; metadata file/media attributes and uploaded multifiles are excluded.';
	private $db;
	private $root;
	private $config;
	private $readOnly;
	public function __construct(Config $config, bool $initialize=false, bool $readOnly=false) {
		$this->config = $config; $this->readOnly = $readOnly;
		if ($initialize && $readOnly) { throw new Failure('Read-only storage cannot be initialized.'); }
		$this->root = $config->stateDirectory(!$readOnly);
		$path = $this->root.'/health.sqlite';
		if (is_link($path) || (!$initialize && !is_file($path))) { throw new Failure('File Health storage is not initialized. Run file-health-init --apply.'); }
		if (is_file($path) && (fileperms($path) & 0077)) { throw new Failure('File Health storage must be private (0600).'); }
		$old = umask(0077);
		try {
			$dsn = $readOnly ? 'sqlite:file:'.str_replace('%2F','/',rawurlencode($path)).'?mode=ro' : 'sqlite:'.$path;
			$this->db = new \PDO($dsn,null,null,[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
		} finally { umask($old); }
		$this->db->exec('PRAGMA busy_timeout=10000');
		$this->db->exec('PRAGMA foreign_keys=ON');
		if ($readOnly) { $this->db->exec('PRAGMA query_only=ON'); }
		if ($initialize) { $lock = $this->lock(); try { $this->initialize(); } finally { self::unlock($lock); } }
		if ((int)$this->db->query('PRAGMA user_version')->fetchColumn() !== 1) { throw new Failure('Unsupported File Health storage version.'); }
	}
	private function initialize() : void {
		$version = (int)$this->db->query('PRAGMA user_version')->fetchColumn();
		if ($version === 1) { return; }
		if ($version !== 0) { throw new Failure('Unsupported File Health storage version.'); }
		$this->transaction(function() {
			$this->db->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
			$this->db->exec("CREATE TABLE runs (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, status TEXT NOT NULL CHECK(status IN ('running','completed','failed','superseded')),
				versions TEXT NOT NULL, max_id INTEGER NOT NULL, cursor INTEGER NOT NULL DEFAULT 0, started_at TEXT NOT NULL, heartbeat_at TEXT NOT NULL,
				finished_at TEXT, checked INTEGER NOT NULL DEFAULT 0, problems INTEGER NOT NULL DEFAULT 0)");
			$this->db->exec("CREATE UNIQUE INDEX active_run ON runs(status) WHERE status='running'");
			$this->db->exec('CREATE TABLE checks (id INTEGER PRIMARY KEY, run_id TEXT NOT NULL REFERENCES runs(id), representation_id INTEGER NOT NULL CHECK(representation_id>0),
				version TEXT NOT NULL, fingerprint TEXT NOT NULL, status TEXT NOT NULL, reason TEXT NOT NULL, label TEXT NOT NULL, idno TEXT NOT NULL,
				expected_md5 TEXT, checked_at TEXT NOT NULL, bytes INTEGER NOT NULL DEFAULT 0)');
			$this->db->exec('CREATE INDEX check_history ON checks(representation_id,id DESC)');
			$this->db->exec('CREATE TABLE targets (representation_id INTEGER NOT NULL, version TEXT NOT NULL, check_id INTEGER NOT NULL REFERENCES checks(id), PRIMARY KEY(representation_id,version))');
			$this->db->exec('CREATE TABLE issues (id INTEGER PRIMARY KEY, representation_id INTEGER NOT NULL, version TEXT NOT NULL, fingerprint TEXT NOT NULL,
				first_check_id INTEGER NOT NULL REFERENCES checks(id), last_check_id INTEGER NOT NULL REFERENCES checks(id), resolved_at TEXT, resolution TEXT,
				UNIQUE(representation_id,version,fingerprint))');
			$this->db->exec('CREATE TABLE issue_reviews (id INTEGER PRIMARY KEY, issue_id INTEGER NOT NULL REFERENCES issues(id), check_id INTEGER NOT NULL REFERENCES checks(id), user_id INTEGER NOT NULL, reviewed_at TEXT NOT NULL, note TEXT NOT NULL)');
			$this->db->exec('PRAGMA user_version=1');
		});
	}
	private function writable() : void { if ($this->readOnly) { throw new Failure('File Health storage is read-only.'); } }
	private function transaction(callable $operation) {
		$this->writable(); $this->db->exec('BEGIN IMMEDIATE');
		try { $value = $operation(); $this->db->exec('COMMIT'); return $value; }
		catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
	}
	private function query(string $sql, array $params=[]) : \PDOStatement {
		$q = $this->db->prepare($sql); $q->execute($params); return $q;
	}
	private function setting(string $key) : ?string {
		$value = $this->query('SELECT value FROM settings WHERE key=?',[$key])->fetchColumn(); return $value === false ? null : (string)$value;
	}
	public function heartbeat() : void {
		$this->writable(); $this->query('INSERT INTO settings VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',['last_worker_at',gmdate('c')]);
	}
	public function lock() {
		$this->writable(); $path = $this->root.'/scan.lock';
		if (is_link($path) || (file_exists($path) && (!is_file($path) || (fileperms($path) & 0077)))) { throw new Failure('Invalid File Health lock.'); }
		$old = umask(0077);
		try { $handle = fopen($path,'c'); } finally { umask($old); }
		if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if ($handle) { fclose($handle); } throw new Failure('A File Health check is already running.'); }
		return $handle;
	}
	public static function unlock($lock) : void { flock($lock,LOCK_UN); fclose($lock); }
	private static function runRow($row) : ?array {
		if (!$row) { return null; }
		$row['versions'] = json_decode($row['versions'],true,512,JSON_THROW_ON_ERROR);
		foreach (['user_id','cursor','max_id','checked','problems'] as $field) { $row[$field] = (int)$row[$field]; }
		return $row;
	}
	public function activeRun() : ?array { return self::runRow($this->query("SELECT * FROM runs WHERE status='running'")->fetch(\PDO::FETCH_ASSOC)); }
	public function startRun(int $maxId, array $versions, int $userId) : array {
		$this->writable(); if ($maxId < 0 || $userId < 1 || !$versions) { throw new Failure('Invalid scan request.'); }
		$id = bin2hex(random_bytes(16)); $now = gmdate('c');
		$this->query("INSERT INTO runs(id,user_id,status,versions,max_id,started_at,heartbeat_at) VALUES (?,?,'running',?,?,?,?)",[$id,$userId,json_encode($versions,JSON_THROW_ON_ERROR),$maxId,$now,$now]);
		return $this->activeRun();
	}
	public function endRun(string $id, string $status) : void {
		$this->writable(); if (!in_array($status,['completed','failed','superseded'],true)) { throw new Failure('Invalid scan state.'); }
		$this->query("UPDATE runs SET status=?,finished_at=?,heartbeat_at=? WHERE id=? AND status='running'",[$status,gmdate('c'),gmdate('c'),$id]);
	}
	/** All versions of a representation and its cursor are committed together. */
	public function record(string $runId, int $representationId, array $results) : void {
		$this->transaction(function() use ($runId,$representationId,$results) {
			$run = $this->activeRun();
			if (!$run || $run['id'] !== $runId || $representationId <= $run['cursor'] || $representationId > $run['max_id'] || count($results) !== count($run['versions'])) { throw new Failure('Invalid scan checkpoint.'); }
			$seen = []; $problems = 0;
			foreach ($results as $result) {
				$version = $result['version'] ?? ''; $status = $result['status'] ?? '';
				if ((int)($result['representation_id'] ?? 0) !== $representationId || !in_array($version,$run['versions'],true) || isset($seen[$version]) || !in_array($status,self::STATUSES,true)) { throw new Failure('Invalid file check result.'); }
				$seen[$version] = true;
				$fingerprint = $result['fingerprint'] ?? hash('sha256',json_encode([$representationId,$version,$result['expected_md5'] ?? null],JSON_THROW_ON_ERROR));
				if (!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)) { throw new Failure('Invalid file identity.'); }
				$md5 = $result['expected_md5'] ?? null;
				if ($md5 !== null && !preg_match('/^[a-f0-9]{32}$/Di',$md5)) { $md5 = null; }
				if ($status === 'verified' && $md5 === null) { throw new Failure('A verified file must have a recorded upload checksum.'); }
				$this->query('INSERT INTO checks(run_id,representation_id,version,fingerprint,status,reason,label,idno,expected_md5,checked_at,bytes) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
					[$runId,$representationId,$version,$fingerprint,$status,(string)($result['reason'] ?? ''),(string)($result['label'] ?? ''),(string)($result['idno'] ?? ''),$md5,gmdate('c'),max(0,(int)($result['bytes'] ?? 0))]);
				$checkId = (int)$this->db->lastInsertId();
				$this->query('INSERT INTO targets VALUES (?,?,?) ON CONFLICT(representation_id,version) DO UPDATE SET check_id=excluded.check_id',[$representationId,$version,$checkId]);
				if ($status === 'verified') {
					$this->query('UPDATE issues SET resolved_at=?,resolution=? WHERE representation_id=? AND version=? AND fingerprint=? AND resolved_at IS NULL',[gmdate('c'),'Reverified against the same recorded file identity.',$representationId,$version,$fingerprint]);
				} else {
					$problems++;
					$this->query('INSERT INTO issues(representation_id,version,fingerprint,first_check_id,last_check_id) VALUES (?,?,?,?,?) ON CONFLICT(representation_id,version,fingerprint) DO UPDATE SET last_check_id=excluded.last_check_id,resolved_at=NULL,resolution=NULL',[$representationId,$version,$fingerprint,$checkId,$checkId]);
				}
			}
			$this->query('UPDATE runs SET cursor=?,heartbeat_at=?,checked=checked+?,problems=problems+? WHERE id=?',[$representationId,gmdate('c'),count($results),$problems,$runId]);
			$this->heartbeat();
		});
	}
	public function recentRuns(int $limit=10) : array {
		$limit = max(1,min(100,$limit));
		return array_map([self::class,'runRow'],$this->query('SELECT * FROM runs ORDER BY rowid DESC LIMIT '.$limit)->fetchAll(\PDO::FETCH_ASSOC));
	}
	public function problems(int $limit=25, int $offset=0) : array {
		return $this->query('SELECT c.*,i.id AS issue_id,i.first_check_id,CASE WHEN c.fingerprint=latest.fingerprint THEN 1 ELSE 0 END AS is_current FROM issues i JOIN checks c ON c.id=i.last_check_id JOIN targets t ON t.representation_id=i.representation_id AND t.version=i.version JOIN checks latest ON latest.id=t.check_id WHERE i.resolved_at IS NULL ORDER BY c.id DESC LIMIT '.max(1,min(100,$limit)).' OFFSET '.max(0,$offset))->fetchAll(\PDO::FETCH_ASSOC);
	}
	public function history(int $representationId, int $limit=50) : array {
		return $this->query('SELECT * FROM checks WHERE representation_id=? ORDER BY id DESC LIMIT '.max(1,min(200,$limit)),[$representationId])->fetchAll(\PDO::FETCH_ASSOC);
	}
	public function issue(int $id) : array {
		$row = $this->query('SELECT c.*,i.id AS issue_id,i.resolved_at,i.resolution FROM issues i JOIN checks c ON c.id=i.last_check_id WHERE i.id=?',[$id])->fetch(\PDO::FETCH_ASSOC);
		if (!$row) { throw new Failure('The File Health issue does not exist.'); }
		$row['reviews'] = $this->query('SELECT user_id,reviewed_at,note,check_id FROM issue_reviews WHERE issue_id=? ORDER BY id DESC',[$id])->fetchAll(\PDO::FETCH_ASSOC);
		return $row;
	}
	public function issueHistory(int $representationId, int $limit=50) : array {
		$ids = $this->query('SELECT id FROM issues WHERE representation_id=? ORDER BY id DESC LIMIT '.max(1,min(100,$limit)),[$representationId])->fetchAll(\PDO::FETCH_COLUMN);
		return array_map(function($id) { return $this->issue((int)$id); },$ids);
	}
	/** A replacement cannot erase an old failure without an explicit administrator review. */
	public function reviewIssue(int $id, int $userId, string $note) : void {
		$note = trim($note);
		if ($userId < 1 || $note === '' || strlen($note) > 2000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$note)) { throw new Failure('An administrator and a review note of up to 2000 bytes are required.'); }
		$this->transaction(function() use ($id,$userId,$note) {
			$issue = $this->issue($id);
			$current = $this->query('SELECT c.* FROM targets t JOIN checks c ON c.id=t.check_id WHERE t.representation_id=? AND t.version=?',[$issue['representation_id'],$issue['version']])->fetch(\PDO::FETCH_ASSOC);
			if ($issue['resolved_at'] || !$current || $current['status'] !== 'verified' || strtotime($current['checked_at']) < time()-$this->config->intervalSeconds()) { throw new Failure('Review requires an open issue and a recent successful check of the current file.'); }
			$this->query('INSERT INTO issue_reviews(issue_id,check_id,user_id,reviewed_at,note) VALUES (?,?,?,?,?)',[$id,$current['id'],$userId,gmdate('c'),$note]);
			$this->query('UPDATE issues SET resolved_at=?,resolution=? WHERE id=?',[gmdate('c'),'Reviewed by backend user '.$userId.': '.$note,$id]);
		});
	}
	public function summary(?int $now=null) : array {
		$now = $now ?? time(); $counts = array_fill_keys(self::STATUSES,0);
		foreach ($this->query('SELECT c.status,COUNT(*) AS n FROM targets t JOIN checks c ON c.id=t.check_id GROUP BY c.status')->fetchAll(\PDO::FETCH_ASSOC) as $row) { $counts[$row['status']] = (int)$row['n']; }
		$last = self::runRow($this->query("SELECT * FROM runs WHERE status='completed' ORDER BY rowid DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC));
		$worker = $this->setting('last_worker_at'); $active = $this->activeRun();
		$versions = $this->config->versions();
		$scopeMatches = $last && $last['versions'] === $versions;
		$overdue = (int)$this->query('SELECT COUNT(*) FROM targets t JOIN checks c ON c.id=t.check_id WHERE c.checked_at < ?',[gmdate('c',$now-$this->config->intervalSeconds())])->fetchColumn();
		return ['counts' => $counts,'total_targets' => array_sum($counts),'unresolved_issues' => (int)$this->query('SELECT COUNT(*) FROM issues WHERE resolved_at IS NULL')->fetchColumn(),
			'overdue' => $overdue,'last_completed_at' => $last['finished_at'] ?? null,'last_completed_run' => $last,'active_run' => $active,
			'last_worker_at' => $worker,'worker_stale' => !$worker || strtotime($worker) < $now-$this->config->staleSeconds(),
			'sweep_overdue' => !$scopeMatches || strtotime($last['started_at']) < $now-$this->config->intervalSeconds(),
			'versions' => $versions,'scope' => self::SCOPE];
	}
}
