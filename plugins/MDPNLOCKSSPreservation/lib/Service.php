<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace MDPNPreservation;
require_once __DIR__.'/Store.php';
require_once __DIR__.'/Catalogue.php';

final class Service {
	private $config;
	private $store;
	private $root;
	private $catalogueFactory;
	public function __construct(Config $config, Store $store, string $root, ?callable $catalogueFactory=null) {
		$this->config = $config; $this->store = $store; $this->root = $root;
		$this->catalogueFactory = $catalogueFactory ?: static function($user) { return new ProvidenceCatalogue($user); };
	}
	public static function authorize($user, int $collection, string $action='can_preserve_collections') : void {
		if (defined('__CA_DISABLE_ACL__') && __CA_DISABLE_ACL__) { throw new Failure('Preservation requires native item-level permission checks. Use the supplied isolated queue worker or run without disabling ACLs.'); }
		if (!$user || !$user->getPrimaryKey() || !$user->get('active') || !$user->canDoAction($action)) { throw new Failure('Preservation permission is required.'); }
		require_once __CA_MODELS_DIR__.'/ca_collections.php';
		$c = new \ca_collections($collection);
		if (!$c->getPrimaryKey() || $c->get('deleted') || $c->checkACLAccessForUser($user) < __CA_ACL_READONLY_ACCESS__) { throw new Failure('Collection access is required.'); }
	}
	public function enqueue(int $collection, string $mode, $user, array $input) : array {
		self::authorize($user,$collection);
		$metadata = $mode === 'scan' ? [] : $this->config->bagMetadata($input);
		$job = $this->store->create($collection,$mode,$this->config->target(),(int)$user->getPrimaryKey(),$metadata);
		$this->queue($job); return $this->store->get($job['id']);
	}
	private function queue(array $job) : void {
		try {
			require_once __CA_LIB_DIR__.'/TaskQueue.php';
			$queue = new \TaskQueue();
			$task = $queue->addTask('MDPNLOCKSSPreservation',['job_id' => $job['id']],['user_id' => (int)$job['user_id'],'priority' => 20,
				'entity_key' => 'mdpn:'.$job['collection_id'],'row_key' => 'mdpn:'.$job['id'],'notes' => 'Collection preservation job']);
			if (!$task) { throw new Failure('The preservation task could not be queued.'); }
			$this->store->update($job['id'],['task_id' => $task]);
		} catch (\Throwable $e) { $this->store->update($job['id'],['status' => 'failed','error' => 'The preservation task could not be queued.']); throw $e; }
	}
	public function retry(string $id, $user) : void {
		$job = $this->store->get($id); self::authorize($user,(int)$job['collection_id']);
		$lock = $this->store->lock((int)$job['collection_id']);
		try {
			$job = $this->store->get($id);
			if ($job['status'] !== 'failed' || !is_file($this->store->directory($id).'/result.json') || $job['target'] !== $this->config->target()) {
				throw new Failure('Only a completed package with a failed transfer can be retried. Otherwise create a new deposit.');
			}
			$this->store->update($id,['status' => 'queued','error' => null]); $this->queue($job);
		}
		finally { flock($lock,LOCK_UN); fclose($lock); }
	}
	public function confirm(string $id, $user, string $reference) : void {
		$job = $this->store->get($id); self::authorize($user,(int)$job['collection_id'],'can_confirm_preservation');
		$lock = $this->store->lock((int)$job['collection_id']);
		try {
			$job = $this->store->get($id);
			if ($job['status'] !== 'uploaded' || trim($reference) === '' || strlen($reference) > 2000) { throw new Failure('An uploaded deposit and a preservation confirmation reference are required.'); }
			if ($job['parent_id'] && $this->store->get($job['parent_id'])['status'] !== 'confirmed') { throw new Failure('Confirm the preceding deposit before its supplement.'); }
			$this->store->update($id,['status' => 'confirmed','confirmation' => ['reference' => trim($reference),'user_id' => (int)$user->getPrimaryKey(),'recorded_at' => gmdate('c')]]);
		} finally { flock($lock,LOCK_UN); fclose($lock); }
	}
	public function run(string $id) : array {
		$job = $this->store->get($id);
		$lock = $this->store->lock((int)$job['collection_id']);
		try {
			$job = $this->store->get($id);
			if ($job['status'] !== 'queued') { return ['status' => $job['status']]; }
			$this->store->update($id,['status' => 'running','error' => null]);
			if (!$this->config->enabled() || $job['target'] !== $this->config->target()) { throw new Failure('The preservation configuration changed. Create a new job.'); }
			$user = $this->freshUser((int)$job['user_id']); self::authorize($user,(int)$job['collection_id']);
			$directory = $this->store->directory($id,true);
			if (is_file($directory.'/result.json')) {
				$result = json_decode(file_get_contents($directory.'/result.json'),true,512,JSON_THROW_ON_ERROR);
				foreach ($result['parts'] as $part) {
					if (basename($part['filename']) !== $part['filename'] || !hash_equals($part['sha256'],hash_file('sha256',$directory.'/'.$part['filename']))) { throw new Failure('A retained archive has changed. Create a new full deposit.'); }
				}
			} else {
				$collector = new Collector(($this->catalogueFactory)($user));
				$snapshot = $collector->capture((int)$job['collection_id'],$this->store->repositoryId());
				$snapshot['schema']['bagit_profile'] = $this->config->profile();
				$baseline = $job['parent_id'] ? $this->store->get($job['parent_id']) : $this->store->baseline((int)$job['collection_id'],$job['target']);
				$parent = $baseline ? json_decode(file_get_contents($this->store->directory($baseline['id']).'/result.json'),true,512,JSON_THROW_ON_ERROR) : null;
				$code = (string)$this->config->get('organization_code');
				$request = ['snapshot' => $snapshot,'job' => $job,'parent' => $parent,'output_directory' => $directory,
					'organization_code' => $code,'bag_metadata' => $job['metadata'],'part_size_bytes' => (int)$this->config->get('part_size_bytes'),
					'bag_group_identifier' => $this->store->groupIdentifier((int)$job['collection_id'])];
				Store::write($directory.'/request.json',$request);
				$result = $this->worker($directory);
				$user = $this->freshUser((int)$job['user_id']); self::authorize($user,(int)$job['collection_id']);
				$after = (new Collector(($this->catalogueFactory)($user)))->capture((int)$job['collection_id'],$this->store->repositoryId());
				$after['schema']['bagit_profile'] = $this->config->profile();
				unset($snapshot['captured_at'],$after['captured_at']);
				if (Store::json($snapshot) !== Store::json($after)) { throw new Failure('Catalogue metadata or original files changed during capture. Create a new job.'); }
				Store::write($directory.'/result.json',$result);
			}
			$summary = ['fingerprint' => $result['snapshot']['fingerprint'],'parts' => $result['parts'],
				'changes' => array_slice($result['snapshot']['changes'],0,200),'change_count' => count($result['snapshot']['changes']),'captured_at' => $result['snapshot']['captured_at'],
				'files' => count($result['snapshot']['files']),'records' => array_map('count',$result['snapshot']['records']),
				'changed' => $result['changed']];
			$summary['compared_to_deposit_id'] = $result['snapshot']['compared_to_deposit_id'] ?? null;
			$summary['recovery_chain'] = $result['snapshot']['chain'] ?? [];
			if ($summary['recovery_chain']) { $summary['recovery_chain'][count($summary['recovery_chain'])-1]['parts'] = $result['parts']; }
			$this->store->update($id,['result' => $summary]);
			if ($job['mode'] === 'scan') { $status = 'scanned'; }
			elseif ($this->config->get('transport','local') === 'local') { $status = 'built'; }
			else {
				require_once __DIR__.'/SftpTransport.php';
				$transport = new SftpTransport($this->config);
				foreach ($result['parts'] as $part) { $transport->send($directory.'/'.$part['filename'],$part); }
				$status = 'uploaded';
			}
			$this->store->update($id,['status' => $status]);
			return ['status' => $status,'parts' => count($result['parts'])];
		} catch (\Throwable $e) {
			// Native exceptions may include SQL, credentials or catalogue values.
			// Only our controlled Failure messages may reach the UI.
			$message = $e instanceof Failure ? $e->getMessage() : 'Preservation failed ('.get_class($e).'). Check configuration and the private worker diagnostics.';
			$this->store->update($id,['status' => 'failed','error' => $message]);
			return ['status' => 'failed'];
		} finally { flock($lock,LOCK_UN); fclose($lock); }
	}
	private function freshUser(int $id) {
		require_once __CA_MODELS_DIR__.'/ca_users.php';
		if (method_exists('ca_users','clearCaches')) { \ca_users::clearCaches(); }
		if (class_exists('ca_acl') && method_exists('ca_acl','clearAccessValueCache')) { \ca_acl::clearAccessValueCache(); }
		return new \ca_users($id);
	}
	private function worker(string $directory) : array {
		$binary = (string)$this->config->get('python_binary','/usr/bin/python3');
		if ($binary[0] !== '/' || !is_executable($binary)) { throw new Failure('Configure an absolute executable python_binary (Python 3.9 or newer).'); }
		$stdout = $directory.'/worker-result.json'; $stderr = $directory.'/worker-error.txt';
		$old = umask(0077);
		try { $process = proc_open([$binary,'-B',$this->root.'/worker/preservation.py','build',$directory.'/request.json'],[0 => ['file','/dev/null','r'],1 => ['file',$stdout,'w'],2 => ['file',$stderr,'w']],$pipes,null,['PATH' => '/usr/bin:/bin','PYTHONNOUSERSITE' => '1']); }
		finally { umask($old); }
		if (!is_resource($process)) { throw new Failure('The preservation worker could not start.'); }
		if (proc_close($process) !== 0) {
			$detail = trim((string)file_get_contents($stderr,false,null,0,1000));
			throw new Failure($detail ?: 'The preservation worker failed.');
		}
		return json_decode(file_get_contents($stdout),true,512,JSON_THROW_ON_ERROR);
	}
}
