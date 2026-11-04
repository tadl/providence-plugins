<?php
/** Real read-only ledger and Python comparison, entirely synthetic catalogue data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Report.php';
require_once __DIR__.'/SyntheticCatalogue.php';
define('__CA_MODELS_DIR__',__DIR__.'/stubs'); define('__CA_LIB_DIR__',__DIR__.'/stubs/lib'); define('__CA_ACL_READONLY_ACCESS__',1);
require_once __CA_MODELS_DIR__.'/ca_users.php';
use MDPNPreservation\Config;
use MDPNPreservation\Store;
use MDPNPreservation\Service;
use MDPNPreservation\Report;

function check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
function fails($call,$expected) {
	try { $call(); } catch (Throwable $e) { check(strpos($e->getMessage(),$expected) !== false,'Unexpected error: '.$e->getMessage()); return; }
	throw new RuntimeException('Expected failure: '.$expected);
}
class ReportCatalogue extends SyntheticCatalogue {
	public $source;
	public $forbidFiles = false;
	public function __construct() {
		parent::__construct();
		$this->data['ca_collection_labels'] = [1 => ['label_id' => 1,'collection_id' => 1,'name' => 'Synthetic archive','is_preferred' => 1],
			2 => ['label_id' => 2,'collection_id' => 2,'name' => 'Synthetic child','is_preferred' => 1]];
	}
	public function info(string $table) : array {
		if ($table === 'ca_collection_labels') { return ['primary_key' => 'label_id','table_num' => 14,'label_table' => null,'relationship' => null,'fields' => ['label_id','collection_id','name','is_preferred'],'foreign_keys' => []]; }
		$info = parent::info($table); if ($table === 'ca_collections') { $info['label_table'] = 'ca_collection_labels'; } return $info;
	}
	public function files(string $table,array $row,array $attributes) : array {
		if ($this->forbidFiles) { throw new RuntimeException('History-only report must not read originals.'); }
		$files = parent::files($table,$row,$attributes);
		foreach ($files as &$file) { $file['source_path'] = $this->source; $file['source_size'] = filesize($this->source); } unset($file);
		return $files;
	}
}
function index_rows(array $report) : array { return array_column($report['collections'],null,'collection_id'); }
function private_tree(string $root) : array {
	$files = [];
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file) {
		if ($file->isFile()) { $files[substr($file->getPathname(),strlen($root)+1)] = hash_file('sha256',$file->getPathname()); }
	}
	ksort($files); return $files;
}

$root = realpath(sys_get_temp_dir()).'/mdpn-report space-'.bin2hex(random_bytes(8)); mkdir($root,0700);
try {
	$values = ['enable' => 1,'state_directory' => $root,'python_binary' => getenv('TEST_PYTHON_BINARY') ?: '/usr/bin/python3',
		'organization_code' => 'EXAMPLE','source_organization' => 'Example Archive','contact_name' => 'Example Staff','contact_email' => 'preservation@example.org',
		'transport' => 'local','destination_id' => 'test','part_size_bytes' => 5000000,'bagit_profile' => 'generic','organizational_unit_element' => 'internal_notes'];
	$config = new Config($values); $store = new Store($config,true); $user = new ca_users(1);
	$catalogue = new ReportCatalogue(); $catalogue->source = $root.'/synthetic-original'; file_put_contents($catalogue->source,'original A');
	$catalogue->data['ca_collections'][100] = ['collection_id' => 100,'parent_id' => null];
	$catalogue->data['ca_collections'][101] = ['collection_id' => 101,'parent_id' => 100];
	$catalogue->data['ca_collections'][1]['parent_id'] = 101;
	$catalogue->data['ca_collections'][99]['parent_id'] = 100;
	$catalogue->organizationalNotes = [100 => '[[organizational_unit]]',101 => '<p>[[organizational_unit]]</p>'];
	$factory = function($user) use ($catalogue) { return $catalogue; };
	$service = new Service($config,$store,dirname(__DIR__),$factory);
	$readOnly = new Store($config,false,true); $reporter = new Report($config,$readOnly,dirname(__DIR__),$factory);
	$initial = $reporter->run($user); $rows = index_rows($initial);
	check($initial['summary']['collections'] === 2 && $rows[1]['status'] === 'never_deposited' && !isset($rows[2]) && $initial['summary']['included_descendants'] === 1,'Only eligible roots appear; subordinate records are counted with parents');
	check($initial['summary']['organizational_units'] === 2 && !isset($rows[100],$rows[101]),'Nested organizational units are excluded without a fixed hierarchy depth');
	$first = $service->enqueue(1,'full',$user,[]);
	check($service->run($first['id'])['status'] === 'built','Synthetic local deposit must succeed');
	$before = private_tree($root); $queued = count(TaskQueue::$jobs);
	$local = $reporter->run($user); $rows = index_rows($local);
	check($rows[1]['status'] === 'local_only' && !isset($rows[2]),'Local packages do not claim remote preservation or list child rows');
	$firstSnapshot = json_decode(file_get_contents($store->directory($first['id']).'/result.json'),true)['snapshot'];
	check(isset($firstSnapshot['records']['ca_collections'][2]),'Suppressing subordinate report rows never removes them from the preserved snapshot');
	check($rows[99]['status'] === 'never_deposited','A boundary reference is not preservation coverage');
	check(private_tree($root) === $before && count(TaskQueue::$jobs) === $queued,'A fresh report changes no ledger, files, jobs or archives');
	$failedFirst = $store->create(99,'full',$config->target(),1,[]); $store->update($failedFirst['id'],['status' => 'failed']);
	$failureReport = $reporter->run($user); $failureRow = index_rows($failureReport)[99];
	check($failureRow['status'] === 'deposit_failed' && $failureRow['preservation'] === null && $failureRow['latest_deposit'] === null
		&& $failureRow['attention_required'],'A failed first attempt reports deposit_failed without claiming a completed or confirmed deposit');
	$text = Report::render($failureReport,'text');
	check(preg_match('/^99\s+deposit_failed\s+never_confirmed\s+none\s/m',$text)
		&& strpos($text,'Latest deposit attempt failed.') === false,'Failure appears in the main status column instead of a separate line');
	$jsonFailure = json_decode(Report::render($failureReport,'json'),true,512,JSON_THROW_ON_ERROR);
	check(index_rows($jsonFailure)[99]['status'] === 'deposit_failed' && $jsonFailure['summary']['states']['deposit_failed'] === 1,'JSON and summary counts use the same failure status');
	fails(function() use ($readOnly,$config) { $readOnly->create(1,'full',$config->target(),1,[]); },'read-only');
	fails(function() use ($readOnly,$first) { $readOnly->update($first['id'],['status' => 'confirmed']); },'read-only');
	fails(function() use ($readOnly) { $readOnly->groupIdentifier(1); },'read-only');
	fails(function() use ($readOnly) { $readOnly->lock(1); },'read-only');
	fails(function() use ($readOnly,$first) { $readOnly->directory($first['id'],true); },'read-only');
	$store->update($first['id'],['status' => 'uploaded']); $service->confirm($first['id'],$user,'synthetic node receipt');
	$rows = index_rows($reporter->run($user));
	check($rows[1]['status'] === 'current' && !isset($rows[2]),'Confirmed current preservation is reported once for the complete hierarchy');
	$catalogue->data['ca_object_labels'][1]['name'] = 'Changed object metadata';
	$changed = $reporter->run($user); $rows = index_rows($changed);
	check($rows[1]['status'] === 'out_of_date' && $rows[1]['preservation']['changes_in_deposit_root']['records_changed'] === 1,'Metadata changes make confirmed preservation out of date');
	check(!isset($rows[2]),'Metadata changes do not introduce duplicate subordinate report rows');
	$catalogue->data['ca_object_labels'][1]['name'] = 'Synthetic object';
	file_put_contents($catalogue->source,'original B');
	$rows = index_rows($reporter->run($user));
	check($rows[1]['status'] === 'out_of_date' && $rows[1]['preservation']['changes_in_deposit_root']['files_changed'] === 3,'Same-sized file edits are detected by SHA-256 without a catalogue timestamp change');
	file_put_contents($catalogue->source,'original A');
	$catalogue->data['ca_collections'][3] = ['collection_id' => 3,'parent_id' => 1,'access' => 0];
	$catalogue->data['ca_collections'][4] = ['collection_id' => 4,'parent_id' => 1,'deleted' => 1];
	$rows = index_rows($reporter->run($user));
	check($rows[1]['status'] === 'out_of_date' && !isset($rows[3],$rows[4]),'New children make the parent stale without creating separate report rows; deleted records stay excluded');
	for ($i=0; $i<102; $i++) { $failed = $store->create(1,'full',$config->target(),1,[]); $store->update($failed['id'],['status' => 'failed']); }
	$rows = index_rows($reporter->run($user));
	check($rows[1]['status'] === 'deposit_failed' && $rows[1]['preservation']['job_id'] === $first['id'] && $rows[1]['latest_attempt']['status'] === 'failed'
		&& $rows[1]['preservation']['freshness'] === 'out_of_date','Failure gets its own overall status while retaining older confirmed preservation and its freshness');
	fails(function() use ($service,$user) { $service->enqueue(2,'full',$user,[]); },'Only top-level archival collections');
	$catalogue->data['ca_collections'][2]['parent_id'] = 101;
	$child = $service->enqueue(2,'full',$user,[]); check($service->run($child['id'])['status'] === 'built','Independent root deposit build');
	$catalogue->data['ca_collections'][2]['parent_id'] = 1;
	$store->update($child['id'],['status' => 'uploaded']); $service->confirm($child['id'],$user,'synthetic child receipt');
	$rows = index_rows($reporter->run($user));
	check(!isset($rows[2]) && $rows[1]['status'] === 'deposit_failed' && $rows[1]['preservation']['freshness'] === 'out_of_date','A legacy subordinate deposit does not establish preservation of its parent or hide its failed attempt');
	check(count($reporter->run($user)['warnings']) === 1,'Historical subordinate deposits remain visible as warnings and retain their history');
	$new = $service->enqueue(1,'full',$user,[]); check($service->run($new['id'])['status'] === 'built','Updated full build'); $store->update($new['id'],['status' => 'uploaded']);
	$updated = $reporter->run($user); $rows = index_rows($updated);
	check($rows[1]['status'] === 'awaiting_confirmation' && $rows[1]['preservation']['freshness'] === 'out_of_date' && $rows[1]['latest_deposit']['freshness'] === 'current','A current upload does not hide outdated confirmed preservation');
	check(!isset($rows[3]),'The updated parent snapshot covers new subordinate records without separate report rows');
	$catalogue->forbidFiles = true;
	$history = $reporter->run($user,true); $rows = index_rows($history);
	check($history['freshness_check'] === 'history_only' && $rows[1]['status'] === 'not_checked' && !isset($rows[2]),'History-only mode follows the same eligibility rule without reading originals');
	$catalogue->forbidFiles = false;
	$json = json_decode(Report::render($updated,'json'),true,512,JSON_THROW_ON_ERROR);
	check($json['summary']['collections'] === 2 && strpos(Report::render($updated,'text'),'awaiting_confirmation') !== false,'JSON and human-readable outputs include eligible-root status');
	$path = $store->directory($new['id']).'/result.json'; rename($path,$path.'.saved');
	$broken = $reporter->run($user); $rows = index_rows($broken);
	check($rows[1]['status'] === 'error' && $rows[99]['coverage_incomplete'] && $broken['summary']['errors'] > 0,'Missing snapshots produce errors and unknown coverage at eligible roots');
	rename($path.'.saved',$path);
	$intact = file_get_contents($path); $corrupt = json_decode($intact,true); $corrupt['snapshot']['records']['ca_collections'][1]['row']['access'] = 1; Store::write($path,$corrupt);
	check(index_rows($reporter->run($user))[1]['status'] === 'error','Baseline fingerprint corruption cannot produce a clean report'); file_put_contents($path,$intact);
	$other_values = $values; $other_values['destination_id'] = 'different';
	$rows = index_rows((new Report(new Config($other_values),$readOnly,dirname(__DIR__),$factory))->run($user));
	check($rows[1]['status'] === 'never_deposited' && !isset($rows[2]),'Deposits for another destination do not satisfy coverage or expand the root listing');
	$nonadmin = new class(1) extends ca_users { public function canDoAction($action) { return $action !== 'is_administrator'; } };
	fails(function() use ($reporter,$nonadmin) { $reporter->run($nonadmin); },'active backend administrator');
	chmod($root,0500); $readonlyDirectory = new Store($config,false,true); check($readonlyDirectory->repositoryId() === $store->repositoryId(),'Reporting can use a private non-writable state directory'); chmod($root,0700);
	$mdpnValues = array_merge($values,['bagit_profile' => 'mdpn']);
	$mdpnConfig = new Config($mdpnValues,static function() { return file_get_contents(__DIR__.'/fixtures/mdpn-dart-settings.json'); });
	$mdpnConfig->refreshProfile();
	$offlineConfig = new Config($mdpnValues,static function() { throw new RuntimeException('A report must not use the network.'); });
	$beforeReport = private_tree($root);
	$rows = index_rows((new Report($offlineConfig,$readOnly,dirname(__DIR__),$factory))->run($user));
	check($rows[1]['status'] === 'awaiting_confirmation' && $rows[1]['latest_deposit']['freshness'] === 'current'
		&& $rows[1]['latest_deposit']['changes_in_deposit_root'] === [],'Changing packaging profiles does not make unchanged catalogue content stale or require another deposit');
	check(private_tree($root) === $beforeReport,'MDPN reporting reads the cached policy without network access or cache writes');
	$service->confirm($new['id'],$user,'synthetic current node receipt');
	$failedCurrent = $store->create(1,'full',$config->target(),1,[]); $store->update($failedCurrent['id'],['status' => 'failed']);
	$rows = index_rows($reporter->run($user));
	check($rows[1]['status'] === 'deposit_failed' && $rows[1]['preservation']['freshness'] === 'current'
		&& $rows[1]['latest_deposit']['job_id'] === $new['id'] && $rows[1]['attention_required'],'A failed later attempt stays visible without hiding current confirmed preservation');
	$catalogue->forbidFiles = true; $rows = index_rows($reporter->run($user,true)); $catalogue->forbidFiles = false;
	check($rows[1]['status'] === 'deposit_failed' && $rows[1]['preservation']['freshness'] === 'not_checked','History-only reports show recorded failures without asserting content freshness');
	rename($path,$path.'.saved'); $rows = index_rows($reporter->run($user)); rename($path.'.saved',$path);
	check($rows[1]['status'] === 'error','A failed latest attempt cannot hide a snapshot verification error');
	define('__CA_DISABLE_ACL__',true);
	fails(function() use ($reporter,$user) { $reporter->run($user); },'native ACL checks enabled');
	print "Preservation maintenance report, inherited coverage, freshness and read-only checks passed.\n";
} finally {
	chmod($root,0700);
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
	rmdir($root);
}
