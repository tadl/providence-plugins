<?php
/** Synthetic persisted scan, cache invalidation and no-original-read integration. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/ChangeScan.php';
require_once __DIR__.'/../lib/Report.php';
require_once __DIR__.'/SyntheticCatalogue.php';
define('__CA_MODELS_DIR__',__DIR__.'/stubs'); define('__CA_LIB_DIR__',__DIR__.'/stubs/lib'); define('__CA_ACL_READONLY_ACCESS__',1);
require_once __CA_MODELS_DIR__.'/ca_users.php';
use MDPNPreservation\Config;
use MDPNPreservation\Store;
use MDPNPreservation\Service;
use MDPNPreservation\Report;
use MDPNPreservation\ChangeScan;
function check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
class ScanCatalogue extends SyntheticCatalogue {
	public $source;
	public $events = [];
	public $resolveCalls = 0;
	public $forbidResolve = false;
	public $failHistory = false;
	public function files(string $table,array $row,array $attributes) : array {
		$files = parent::files($table,$row,$attributes);
		foreach ($files as &$file) { $file['source_path'] = $this->source; $file['source_size'] = filesize($this->source); } unset($file); return $files;
	}
	public function scanFileSpecs(array $inventory) : array {
		if ($this->forbidResolve) { throw new RuntimeException('Warm scans must reuse the small file inventory.'); }
		$this->resolveCalls++; $files = [];
		foreach ($inventory as $file) { $files[$file['key']] = $file + ['source_path' => $this->source]; } return $files;
	}
	public function changeCursor() : int { return count($this->events); }
	public function scanEvents(array $scope,int $since,?int $cursor=null) : array {
		if ($this->failHistory) { throw new \MDPNPreservation\Failure('Synthetic unavailable change history'); }
		return array_values(array_filter($this->events,static function($e) use ($scope,$cursor) {
			if ($cursor !== null && $e['id'] <= $cursor) { return false; }
			// Native queries filter unrelated authorities even when they have collection subjects.
			if ($e['table'] === 'ca_entities') { return false; }
			return in_array($e['row_id'],$scope[$e['table']] ?? [],false)
				|| in_array($e['subject_id'] ?? 0,$scope[$e['subject_table'] ?? ''] ?? [],false);
		}));
	}
}
$root = realpath(sys_get_temp_dir()).'/mdpn-change-scan-'.bin2hex(random_bytes(8)); mkdir($root,0700);
try {
	$values = ['enable' => 1,'state_directory' => $root,'python_binary' => getenv('TEST_PYTHON_BINARY') ?: '/usr/bin/python3','organization_code' => 'EXAMPLE',
		'source_organization' => 'Example Archive','contact_name' => 'Example Staff','contact_email' => 'preservation@example.org',
		'transport' => 'local','destination_id' => 'test','part_size_bytes' => 5000000,'bagit_profile' => 'generic'];
	$config = new Config($values); $store = new Store($config,true); $user = new ca_users(1);
	$c = new ScanCatalogue(); $c->source = $root.'/original'; file_put_contents($c->source,'original A'); touch($c->source,1600000000);
	$factory = static function($u) use ($c) { return $c; };
	$service = new Service($config,$store,dirname(__DIR__),$factory);
	$job = $service->enqueue(1,'full',$user,[]); check($service->run($job['id'])['status'] === 'built','Synthetic deposit');
	$store->update($job['id'],['status' => 'uploaded']); $service->confirm($job['id'],$user);
	$scanner = new ChangeScan($config,$store,dirname(__DIR__),$factory);
	$reporter = new Report($config,new Store($config,false,true),dirname(__DIR__),$factory);
	$scan = $scanner->run($user); check($scan['summary']['errors'] === 0 && $scan['summary']['checked'] === 2,'All eligible roots scanned, including never-deposited collections');
	$saved = $store->changeScan(1,$config->target()); $check = $saved['checks'][$job['id']];
	check($check['freshness'] === 'current' && $check['method'] === ChangeScan::METHOD && $check['checked_at'],'Change scan saves method and checked time without claiming fixity');
	$rows = array_column($reporter->run($user)['collections'],null,'collection_id');
	check($rows[1]['status'] === 'current' && $rows[1]['preservation']['check_method'] === ChangeScan::METHOD
		&& $rows[1]['last_checked_at'] === $saved['checked_at'] && $rows[99]['status'] === 'never_deposited' && $rows[99]['last_checked_at'],
		'Recorded reports read saved flags and times while retaining new collections');
	$path = $store->directory($job['id']).'/result.json'; rename($path,$path.'.saved'); $c->forbidResolve = true;
	check($scanner->run($user)['summary']['errors'] === 0 && $c->resolveCalls === 1,'Warm scans do not open full snapshots or resolve original media again');
	rename($path.'.saved',$path);
	$event = static function($id,$table,$row,$fields,$subjectTable=null,$subjectId=null) { return ['id' => $id,'table' => $table,'row_id' => $row,'operation' => 'U','fields' => $fields,'subject_table' => $subjectTable,'subject_id' => $subjectId]; };
	$c->events[] = $event(1,'ca_collections',1,['rank','hier_left']);
	$c->events[] = $event(2,'ca_entities',30,['idno'],'ca_objects',10);
	$c->data['ca_collections'][1]['rank'] = 25; $c->data['ca_collections'][1]['hier_left'] = 10;
	$c->events[] = $event(3,'ca_collections',1,array_keys($c->data['ca_collections'][1]));
	$scanner->run($user); check($store->changeScan(1,$config->target())['checks'][$job['id']]['freshness'] === 'current','Root sibling ordering and related-authority edits do not flag preservation');
	$c->data['ca_object_labels'][1]['name'] = 'Changed object label';
	$c->events[] = $event(4,'ca_object_labels',1,['name'],'ca_objects',10);
	$scanner->run($user); check($store->changeScan(1,$config->target())['checks'][$job['id']]['changes']['catalogue_changes'] === 1,'Object metadata updates are saved as out-of-date flags');
	$c->data['ca_objects'][13] = ['object_id' => 13,'parent_id' => null];
	$c->data['ca_objects_x_collections'][4] = ['relation_id' => 4,'collection_id' => 2,'object_id' => 13,'type_id' => 5];
	$c->events[] = $event(5,'ca_objects_x_collections',4,['object_id','collection_id']);
	$scanner->run($user); check($store->changeScan(1,$config->target())['checks'][$job['id']]['changes']['catalogue_changes'] === 2,'New collection membership is detected without reading original bytes');
	file_put_contents($c->source,'original B'); touch($c->source,1600000100); clearstatcache();
	$scanner->run($user); $saved = $store->changeScan(1,$config->target());
	check($saved['checks'][$job['id']]['changes']['file_metadata_changes'] === 3,'Same-sized file replacements are flagged from file metadata');
	$scanner->run($user); check($store->changeScan(1,$config->target())['checks'][$job['id']]['freshness'] === 'out_of_date','Repeated scans do not accept a detected changed file as a new baseline');
	$c->forbidResolve = false;
	$new = $service->enqueue(1,'full',$user,[]); check($service->run($new['id'])['status'] === 'built','New deposit after changes'); $store->update($new['id'],['status' => 'uploaded']);
	$rows = array_column($reporter->run($user)['collections'],null,'collection_id');
	check($rows[1]['status'] === 'awaiting_confirmation' && $rows[1]['latest_deposit']['freshness'] === 'not_checked','A newer deposit invalidates saved comparison results');
	$scanner->run($user); $saved = $store->changeScan(1,$config->target());
	check($saved['checks'][$new['id']]['freshness'] === 'current' && $saved['checks'][$job['id']]['freshness'] === 'out_of_date','Latest and confirmed baselines are checked separately');
	$c->failHistory = true; $scanner->run($user);
	$rows = array_column($reporter->run($user)['collections'],null,'collection_id');
	check($rows[1]['status'] === 'error' && strpos($rows[1]['preservation']['error'],'unavailable change history') !== false,'Scan failure replaces a misleading old clean flag with a dated error');
	$c->failHistory = false; $scanner->run($user);
	$before = $store->changeScan(1,$config->target()); $lock = $store->lock(1);
	check($scanner->run($user)['summary']['skipped_active'] === 1 && $store->changeScan(1,$config->target()) === $before,'An active collection lock preserves previous check results'); fclose($lock);
	$nonadmin = new class(1) extends ca_users { public function canDoAction($action) { return $action !== 'is_administrator'; } };
	try { $scanner->run($nonadmin); throw new RuntimeException('Expected permission refusal'); } catch (\MDPNPreservation\Failure $e) { check(strpos($e->getMessage(),'administrator') !== false,'Administrator-only scheduled scanner'); }
	try { (new Store($config,false,true))->saveChangeScan(1,$config->target(),[]); throw new RuntimeException('Expected read-only refusal'); } catch (\MDPNPreservation\Failure $e) { check(strpos($e->getMessage(),'read-only') !== false,'Reports cannot write scan state'); }
	check(!ChangeScan::significant($event(5,'ca_metadata_elements',4,['description']),1)
		&& ChangeScan::significant($event(6,'ca_metadata_elements',4,['datatype']),1),'Only interpretation changes to used definitions require attention');
	print "Saved preservation change scans, cache invalidation, permissions and original-file metadata checks passed.\n";
} finally {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } } rmdir($root);
}
