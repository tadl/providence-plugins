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
		'transport' => 'local','destination_id' => 'test','part_size_bytes' => 5000000,'bagit_profile' => 'generic'];
	$config = new Config($values); $store = new Store($config,true); $user = new ca_users(1);
	$catalogue = new ReportCatalogue(); $catalogue->source = $root.'/synthetic-original'; file_put_contents($catalogue->source,'original A');
	$factory = function($user) use ($catalogue) { return $catalogue; };
	$service = new Service($config,$store,dirname(__DIR__),$factory);
	$readOnly = new Store($config,false,true); $reporter = new Report($config,$readOnly,dirname(__DIR__),$factory);
	$initial = $reporter->run($user); $rows = index_rows($initial);
	check($initial['summary']['collections'] === 3 && $rows[1]['status'] === 'never_deposited' && $rows[2]['status'] === 'never_deposited','Every current collection, including non-public children, appears before any preservation');
	$first = $service->enqueue(1,'full',$user,[]);
	check($service->run($first['id'])['status'] === 'built','Synthetic local deposit must succeed');
	$before = private_tree($root); $queued = count(TaskQueue::$jobs);
	$local = $reporter->run($user); $rows = index_rows($local);
	check($rows[1]['status'] === 'local_only' && $rows[2]['status'] === 'local_only','Local packages do not claim remote preservation');
	check($rows[2]['latest_deposit']['inherited'] && $rows[2]['latest_deposit']['root_collection_id'] === 1,'A child is covered by its parent deposit');
	check($rows[99]['status'] === 'never_deposited','A boundary reference is not preservation coverage');
	check(private_tree($root) === $before && count(TaskQueue::$jobs) === $queued,'A fresh report changes no ledger, files, jobs or archives');
	fails(function() use ($readOnly,$config) { $readOnly->create(1,'full',$config->target(),1,[]); },'read-only');
	fails(function() use ($readOnly,$first) { $readOnly->update($first['id'],['status' => 'confirmed']); },'read-only');
	fails(function() use ($readOnly) { $readOnly->groupIdentifier(1); },'read-only');
	fails(function() use ($readOnly) { $readOnly->lock(1); },'read-only');
	fails(function() use ($readOnly,$first) { $readOnly->directory($first['id'],true); },'read-only');
	$store->update($first['id'],['status' => 'uploaded']); $service->confirm($first['id'],$user,'synthetic node receipt');
	$rows = index_rows($reporter->run($user));
	check($rows[1]['status'] === 'current' && $rows[2]['status'] === 'current' && $rows[2]['preservation']['inherited'],'Confirmed current parent coverage applies to existing children');
	$catalogue->data['ca_object_labels'][1]['name'] = 'Changed object metadata';
	$changed = $reporter->run($user); $rows = index_rows($changed);
	check($rows[1]['status'] === 'out_of_date' && $rows[1]['preservation']['changes_in_deposit_root']['records_changed'] === 1,'Metadata changes make confirmed preservation out of date');
	check($rows[2]['status'] === 'needs_review','A changed ancestor does not falsely assert which child changed');
	$catalogue->data['ca_object_labels'][1]['name'] = 'Synthetic object';
	file_put_contents($catalogue->source,'original B');
	$rows = index_rows($reporter->run($user));
	check($rows[1]['status'] === 'out_of_date' && $rows[1]['preservation']['changes_in_deposit_root']['files_changed'] === 3,'Same-sized file edits are detected by SHA-256 without a catalogue timestamp change');
	file_put_contents($catalogue->source,'original A');
	$catalogue->data['ca_collections'][3] = ['collection_id' => 3,'parent_id' => 1,'access' => 0];
	$catalogue->data['ca_collections'][4] = ['collection_id' => 4,'parent_id' => 1,'deleted' => 1];
	$rows = index_rows($reporter->run($user));
	check($rows[1]['status'] === 'out_of_date' && $rows[3]['status'] === 'never_deposited' && !isset($rows[4]),'New children are not treated as covered by an older deposit; deleted collections are excluded');
	for ($i=0; $i<102; $i++) { $failed = $store->create(1,'full',$config->target(),1,[]); $store->update($failed['id'],['status' => 'failed']); }
	$rows = index_rows($reporter->run($user));
	check($rows[1]['preservation']['job_id'] === $first['id'] && $rows[1]['latest_attempt']['status'] === 'failed','Confirmations older than the UI history cap are retained; failure does not replace the baseline');
	$child = $service->enqueue(2,'full',$user,[]); check($service->run($child['id'])['status'] === 'built','Child deposit build');
	$store->update($child['id'],['status' => 'uploaded']); $service->confirm($child['id'],$user,'synthetic child receipt');
	$rows = index_rows($reporter->run($user));
	check($rows[2]['status'] === 'current' && !$rows[2]['preservation']['inherited'] && $rows[1]['status'] === 'out_of_date','A directly confirmed child can be current while its ancestor needs another deposit');
	$new = $service->enqueue(1,'full',$user,[]); check($service->run($new['id'])['status'] === 'built','Updated full build'); $store->update($new['id'],['status' => 'uploaded']);
	$updated = $reporter->run($user); $rows = index_rows($updated);
	check($rows[1]['status'] === 'awaiting_confirmation' && $rows[1]['preservation']['freshness'] === 'out_of_date' && $rows[1]['latest_deposit']['freshness'] === 'current','A current upload does not hide outdated confirmed preservation');
	check($rows[3]['status'] === 'awaiting_confirmation','A new child is now covered by the uploaded parent, without claiming node confirmation');
	$catalogue->forbidFiles = true;
	$history = $reporter->run($user,true); $rows = index_rows($history);
	check($history['freshness_check'] === 'history_only' && $rows[1]['status'] === 'not_checked' && $rows[2]['status'] === 'not_checked','History-only mode does not read originals or claim freshness');
	$catalogue->forbidFiles = false;
	$json = json_decode(Report::render($updated,'json'),true,512,JSON_THROW_ON_ERROR);
	check($json['summary']['collections'] === 4 && strpos(Report::render($updated,'text'),'awaiting_confirmation') !== false,'JSON and human-readable outputs include actionable status');
	$path = $store->directory($new['id']).'/result.json'; rename($path,$path.'.saved');
	$broken = $reporter->run($user); $rows = index_rows($broken);
	check($rows[1]['status'] === 'error' && $rows[3]['status'] === 'needs_review' && $rows[99]['coverage_incomplete'] && $broken['summary']['errors'] > 0,'Missing historical membership produces visible errors and unknown coverage, not false never-deposited status');
	rename($path.'.saved',$path);
	$intact = file_get_contents($path); $corrupt = json_decode($intact,true); $corrupt['snapshot']['records']['ca_collections'][1]['row']['access'] = 1; Store::write($path,$corrupt);
	check(index_rows($reporter->run($user))[1]['status'] === 'error','Baseline fingerprint corruption cannot produce a clean report'); file_put_contents($path,$intact);
	$other_values = $values; $other_values['destination_id'] = 'different';
	$rows = index_rows((new Report(new Config($other_values),$readOnly,dirname(__DIR__),$factory))->run($user));
	check($rows[1]['status'] === 'never_deposited' && $rows[2]['status'] === 'never_deposited','Deposits for another destination do not satisfy coverage');
	$nonadmin = new class(1) extends ca_users { public function canDoAction($action) { return $action !== 'is_administrator'; } };
	fails(function() use ($reporter,$nonadmin) { $reporter->run($nonadmin); },'active backend administrator');
	chmod($root,0500); $readonlyDirectory = new Store($config,false,true); check($readonlyDirectory->repositoryId() === $store->repositoryId(),'Reporting can use a private non-writable state directory'); chmod($root,0700);
	define('__CA_DISABLE_ACL__',true);
	fails(function() use ($reporter,$user) { $reporter->run($user); },'native ACL checks enabled');
	print "Preservation maintenance report, inherited coverage, freshness and read-only checks passed.\n";
} finally {
	chmod($root,0700);
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
	rmdir($root);
}
