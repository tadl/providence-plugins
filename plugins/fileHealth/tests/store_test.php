<?php
/** Native run journals and existing reports, with synthetic private files only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Store.php';
use FileHealth\Config;
use FileHealth\Store;

$checks=0;
function verifyStore($condition,string $message) : void {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function failsStore(callable $operation,string $message) : void {
	try { $operation(); } catch (Throwable $error) { verifyStore(true,$message); return; }
	throw new RuntimeException($message);
}
function privateReport(string $path,string $text="Type,Error,Name,ID,Version,File path,Expected MD5,Actual MD5\n") : void {
	file_put_contents($path,$text); chmod($path,0600); clearstatcache(true,$path);
}
function fixtureEntries(string $directory) : array {
	$entries=[];
	foreach (scandir($directory) ?: [] as $name) {
		if ($name==='.' || $name==='..') { continue; }
		$path=$directory.'/'.$name;
		$entries[$name]=is_dir($path) && !is_link($path) ? fixtureEntries($path) : (is_link($path) ? ['link'=>readlink($path)] : hash_file('sha256',$path));
	}
	return $entries;
}
function removeFixture(string $directory) : void {
	foreach (scandir($directory) ?: [] as $name) {
		if ($name==='.' || $name==='..') { continue; }
		$path=$directory.'/'.$name;
		if (is_dir($path) && !is_link($path)) { removeFixture($path); } else { unlink($path); }
	}
	rmdir($directory);
}

$directory=realpath(sys_get_temp_dir()).'/file-health-reports-'.bin2hex(random_bytes(8));
mkdir($directory,0700);
$values=['enable'=>1,'state_directory'=>$directory,'kinds'=>'ca_object_representations','versions'=>'original',
	'patrol_interval_seconds'=>60,'overdue_grace_seconds'=>0,'worker_stale_seconds'=>10];
$config=new Config($values);
try {
	failsStore(static function() use ($config) { new Store($config,false,true); },'A missing report directory cannot be initialized by a read');
	verifyStore(scandir($directory)===['.','..'],'A failed read-only opening creates no state');
	failsStore(static function() use ($config) { new Store($config,true,true); },'Read-only initialization is refused');
	$store=new Store($config,true);
	foreach (['runs','reports','logs'] as $name) {
		verifyStore(is_dir($directory.'/'.$name) && (fileperms($directory.'/'.$name)&0777)===0700,'Native artifacts use private directories');
	}
	verifyStore(!file_exists($directory.'/health.sqlite'),'The report journal creates no independent checksum database');
	verifyStore($store->recentRuns()===[] && $store->summary()['overdue'] && $store->summary()['last_completed_at']===null,'An empty journal cannot imply a completed patrol');
	$legacy=$directory.'/reports/native-2026-01-01.txt';
	privateReport($legacy); touch($legacy,time()-3600); clearstatcache(true,$legacy);
	$legacyId='legacy-'.hash('sha256',basename($legacy));
	$imported=$store->recentRuns()[0];
	verifyStore($imported['id']===$legacyId && $imported['status']==='untracked' && $imported['scope']===null
		&& $imported['exit_code']===null && $imported['started_at']===null && $imported['finished_at']===null,
		'A preexisting header-only report has unknown scope and execution outcome');
	verifyStore($imported['report_available'] && $imported['report_at']!==null && $store->summary()['untracked_reports']===1,
		'Legacy report availability and file time are visible without inventing a run');
	verifyStore($store->summary()['last_completed_at']===null && $store->summary()['overdue'],'A recent legacy report cannot prove current native patrol completion');
	verifyStore($store->reportPath($legacyId)===$legacy && $store->reportDownloadName($legacyId)==='native-fixity-'.$legacyId.'.txt',
		'Imported reports are retrieved through a generated ID and safe download name');
	$before=fixtureEntries($directory);
	$reader=new Store($config,false,true); $reader->summary(); $reader->recentRuns(); $reader->reportPath($legacyId);
	verifyStore(fixtureEntries($directory)===$before,'Reading old reports does not modify or import their bytes into another store');
	foreach (['../native-2026-01-01.txt','../../outside','/etc/passwd','legacy-invalid',str_repeat('A',32),'legacy-'.str_repeat('0',64),str_repeat('a',32)] as $id) {
		failsStore(static function() use ($reader,$id) { $reader->reportPath($id); },'Report lookup rejects raw paths, malformed IDs and unknown reports');
	}
	$outside=$directory.'/outside.txt'; privateReport($outside,'Synthetic material outside the report directory.');
	symlink($outside,$directory.'/reports/linked.txt');
	$linkedId='legacy-'.hash('sha256','linked.txt');
	verifyStore(count($reader->recentRuns())===1,'A symlink cannot be imported as a native report');
	failsStore(static function() use ($reader,$linkedId) { $reader->reportPath($linkedId); },'A symlink cannot escape report retrieval');
	unlink($directory.'/reports/linked.txt');
	if (function_exists('posix_mkfifo')) {
		posix_mkfifo($directory.'/reports/pipe.txt',0600);
		verifyStore(count($reader->recentRuns())===1,'Nonregular report paths are ignored without opening a FIFO');
		failsStore(static function() use ($reader) { $reader->reportPath('legacy-'.hash('sha256','pipe.txt')); },'A FIFO cannot be downloaded as a report');
		unlink($directory.'/reports/pipe.txt');
	}
	verifyStore($reader->summary()['untracked_active']===false,'A free native patrol lock does not invent an active direct command');
	$external=fopen($directory.'/patrol.lock','c');
	if (!$external || !flock($external,LOCK_EX|LOCK_NB)) { throw new RuntimeException('Cannot acquire the synthetic direct-native lock.'); }
	$direct=$reader->summary();
	verifyStore($direct['untracked_active']===true && $direct['active_run']===null && $direct['last_completed_at']===null,
		'A direct native cron holding the shared lock is visible without inventing run metadata or completion');
	verifyStore(count($reader->recentRuns())===1,'Observing a direct native command creates no journal record');
	flock($external,LOCK_UN); fclose($external);
	verifyStore($reader->summary()['untracked_active']===false,'The direct native activity indication clears when its shared lock is free');
	$lock=$store->lock();
	verifyStore(is_file($directory.'/patrol.lock'),'The wrapper uses the existing native patrol lock name');
	failsStore(static function() use ($config) { (new Store($config))->lock(); },'A competing wrapper is excluded by the same patrol lock');
	$id=str_repeat('a',32); $run=$store->startRun($id,true);
	verifyStore($run['status']==='running' && $run['email_requested']===true && $run['scope']===$config->scope()
		&& $run['exit_code']===null && $run['finished_at']===null,'A tracked invocation begins with requested scope and no completion claim');
	verifyStore((fileperms($directory.'/runs/'.$id.'.json')&0777)===0600,'Run journals are private atomic JSON artifacts');
	$store->heartbeat($id,12345);
	$active=(new Store($config,false,true))->summary();
	verifyStore($active['active_run']['id']===$id && $active['active_run']['status']==='running','A read-only observer sees a live patrol while its lock is held');
	verifyStore($active['untracked_active']===false,'A tracked running wrapper is not also counted as an untracked direct command');
	verifyStore($reader->summary(time()+20)['worker_stale'],'A held but silent stale journal is flagged independently of completion');
	verifyStore($reader->summary()['last_completed_at']===null,'A started run cannot become the last completed patrol');
	failsStore(static function() use ($store,$id) { $store->finishRun($id,'no_errors_reported',0,'native_completed',true); },'Exit zero without an available report cannot be recorded as completed');
	privateReport($store->reportFile($id));
	failsStore(static function() use ($store,$id) { $store->finishRun($id,'no_errors_reported',255,'native_completed',true); },'A nonzero exit cannot be recorded as native success');
	$finished=$store->finishRun($id,'no_errors_reported',0,'native_completed',true);
	verifyStore($finished['status']==='no_errors_reported' && $finished['exit_code']===0 && $finished['finished_at']!==null,
		'Only observed exit zero and an existing report support no-errors-reported status');
	failsStore(static function() use ($store,$id) { $store->finishRun($id,'failed',1,'runner_failed',true); },'A completed invocation cannot be silently rewritten');
	verifyStore($reader->summary()['last_completed_run']['id']===$id && !$reader->summary()['overdue'],
		'A completed invocation supplies schedule freshness without asserting per-file health');
	verifyStore($reader->reportPath($id)===$store->reportFile($id),'A tracked native report has strict ID-based retrieval');
	verifyStore(count($reader->recentRuns())===2,'A tracked report is not duplicated as a legacy import');
	foreach ($reader->recentRuns() as $row) {
		verifyStore(!array_key_exists('_sort',$row) && !array_key_exists('format',$row) && !array_key_exists('process_id',$row),'Public history omits runner bookkeeping fields');
	}
	foreach (['latest_run','last_completed_run','active_run'] as $field) {
		$row=$reader->summary()[$field];
		verifyStore($row===null || (!array_key_exists('_sort',$row) && !array_key_exists('format',$row) && !array_key_exists('process_id',$row)),
			'Public summary rows omit runner bookkeeping fields');
	}
	failsStore(static function() use ($store,$id) { $store->startRun($id,false); },'Journal IDs and native report names cannot be reused');
	$secondId=str_repeat('b',32); $store->startRun($secondId,false);
	failsStore(static function() use ($store,$secondId) { $store->finishRun($secondId,'attention',null,'native_exit_nonzero',false); },'Unobserved native execution cannot be recorded as completed with attention');
	privateReport($store->reportFile($secondId),'[Object representation][MD5 mismatch] Synthetic representation; representation_id=1'."\n");
	failsStore(static function() use ($store,$secondId) { $store->finishRun($secondId,'attention',0,'native_exit_nonzero',true); },'Native exit zero cannot be recategorized as a nonzero native outcome');
	$attention=$store->finishRun($secondId,'attention',255,'native_exit_nonzero',true);
	verifyStore($attention['status']==='attention' && $attention['exit_code']===255 && $reader->summary()['last_completed_run']['id']===$secondId,
		'Native findings or errors remain distinct from no-errors-reported completion');
	$thirdId=str_repeat('c',32); $store->startRun($thirdId,false);
	$store->finishRun($thirdId,'failed',null,'runner_failed',false);
	verifyStore($reader->summary()['latest_run']['status']==='failed' && $reader->summary()['last_completed_run']['id']===$secondId,
		'A wrapper failure remains visible without inventing a new completed native check');
	$unresolvedId=str_repeat('d',32); $store->startRun($unresolvedId,false); $store->heartbeat($unresolvedId,12345);
	Store::unlock($lock);
	$abandoned=$reader->summary();
	verifyStore($abandoned['active_run']===null && $abandoned['latest_run']['status']==='interrupted',
		'An unfinished journal whose native lock is gone is shown as interrupted');
	$disk=json_decode(file_get_contents($directory.'/runs/'.$unresolvedId.'.json'),true,512,JSON_THROW_ON_ERROR);
	verifyStore($disk['status']==='running','Read-only interruption detection does not rewrite historical journal bytes');
	$lock=$store->lock(); $nextId=str_repeat('e',32); $store->startRun($nextId,false);
	$disk=json_decode(file_get_contents($directory.'/runs/'.$unresolvedId.'.json'),true,512,JSON_THROW_ON_ERROR);
	verifyStore($disk['status']==='interrupted','A new lock-owning wrapper records abandoned prior invocation state');
	$store->finishRun($nextId,'interrupted',143,'interrupted',false); Store::unlock($lock);
	failsStore(static function() use ($store) { $store->startRun(str_repeat('1',32),false); },'A journal writer must own the patrol lock before starting work');
	$lock=$store->lock(); $guardId=str_repeat('1',32); $store->startRun($guardId,false); Store::unlock($lock);
	failsStore(static function() use ($store,$guardId) { $store->heartbeat($guardId); },'An unlocked writer cannot impersonate a live native process');
	failsStore(static function() use ($store,$guardId) { $store->finishRun($guardId,'failed',1,'runner_failed',false); },'An unlocked writer cannot finalize another native execution');
	$lock=$store->lock(); $store->finishRun($guardId,'interrupted',143,'interrupted',false); Store::unlock($lock);
	verifyStore($reader->summary(time()+120)['overdue'],'Old native execution evidence becomes overdue after the configured interval');
	$changed=new Store(new Config(array_replace($values,['versions'=>'original,preview'])),false,true);
	verifyStore($changed->summary()['last_completed_at']===null && $changed->summary()['overdue'],
		'A different requested scope requires its own observed completed invocation');
	$before=fixtureEntries($directory);
	foreach ([static function() use ($reader) { $reader->lock(); },
		static function() use ($reader) { $reader->startRun(str_repeat('f',32),false); },
		static function() use ($reader,$id) { $reader->heartbeat($id); },
		static function() use ($reader,$id) { $reader->finishRun($id,'failed',1,'runner_failed',true); }] as $mutation) {
		failsStore($mutation,'A web/report reader cannot change the native run journal');
	}
	verifyStore(fixtureEntries($directory)===$before,'Rejected reader mutations leave all native report and journal bytes untouched');
	$corrupt=str_repeat('f',32); privateReport($directory.'/runs/'.$corrupt.'.json','not JSON');
	$rows=$reader->recentRuns(); $row=array_values(array_filter($rows,static function($item) use ($corrupt) { return $item['id']===$corrupt; }))[0];
	verifyStore($row['status']==='failed' && $row['reason']==='journal_unreadable','Unreadable journal metadata is visible as an error rather than omitted as healthy');
	privateReport($directory.'/reports/'.$corrupt.'.txt','Synthetic report with damaged execution metadata.');
	failsStore(static function() use ($reader,$corrupt) { $reader->reportPath($corrupt); },'A tracked ID with corrupt metadata cannot serve an unvalidated report');
	$report=$store->reportFile($id); unlink($report); symlink($outside,$report);
	failsStore(static function() use ($reader,$id) { $reader->reportPath($id); },'A tracked report replaced with a symlink cannot be retrieved');
	unlink($report); privateReport($report);
	$stateLink=$directory.'/alias'; symlink($directory,$stateLink);
	failsStore(static function() use ($stateLink) { (new Config(['state_directory'=>$stateLink]))->stateDirectory(); },'State roots cannot be symlink aliases');
	unlink($stateLink);
	$legacyOnly=$directory.'/legacy-state'; mkdir($legacyOnly,0700); mkdir($legacyOnly.'/reports',0700);
	privateReport($legacyOnly.'/reports/existing-native.txt','Synthetic existing native report.');
	$legacyBefore=fixtureEntries($legacyOnly);
	$legacyReader=new Store(new Config(array_replace($values,['state_directory'=>$legacyOnly])),false,true);
	verifyStore($legacyReader->recentRuns()[0]['status']==='untracked' && fixtureEntries($legacyOnly)===$legacyBefore,
		'A legacy reports-only state can be displayed without creating run or log directories');
	failsStore(static function() { (new Config(['state_directory'=>dirname(__DIR__,3)]))->stateDirectory(); },'State remains outside the source checkout');
	verifyStore((new Config(['versions'=>'']))->versions()===[],'An explicitly blank versions setting preserves the native all-version behavior');
	verifyStore((new Config(['kinds'=>'all']))->scope()===['kinds'=>['all'],'versions'=>['original']],'Native all-kind selection remains explicit');
	foreach ([['kinds'=>''],['kinds'=>'all,ca_attributes'],['kinds'=>'../invalid'],['versions'=>'original;touch'],['versions'=>'Original'],
		['patrol_interval_seconds'=>0],['overdue_grace_seconds'=>-1],['worker_stale_seconds'=>0]] as $override) {
		$invalid=new Config(array_replace($values,$override));
		$method=['kinds'=>'kinds','versions'=>'versions','patrol_interval_seconds'=>'intervalSeconds','overdue_grace_seconds'=>'graceSeconds','worker_stale_seconds'=>'staleSeconds'][array_key_first($override)];
		failsStore(static function() use ($invalid,$method) { $invalid->$method(); },'Invalid native scope and schedule settings fail explicitly');
	}
	echo "File Health native reports: {$checks} checks passed\n";
} finally { removeFixture($directory); }
