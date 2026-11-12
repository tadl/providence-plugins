<?php
/** Synthetic ledger and configuration checks. No Providence bootstrap or network. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Store.php';
use FileHealth\Config;
use FileHealth\Store;

$checks = 0;
function verifyStore($condition, string $message) : void {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function failsStore(callable $operation, string $message) : void {
	try { $operation(); } catch (Throwable $error) { verifyStore(true,$message); return; }
	throw new RuntimeException($message);
}
function fileResult(int $id, string $version, string $status, string $identity='a', string $checksum='a') : array {
	return ['representation_id' => $id,'version' => $version,'status' => $status,'reason' => $status,
		'fingerprint' => str_repeat($identity,64),'expected_md5' => str_repeat($checksum,32),'bytes' => 3,
		'label' => 'Synthetic representation','idno' => 'EXAMPLE-001'];
}
function removeStoreFixture(string $directory) : void {
	foreach (scandir($directory) ?: [] as $name) {
		if ($name === '.' || $name === '..') { continue; }
		$path = $directory.'/'.$name;
		if (is_dir($path) && !is_link($path)) { removeStoreFixture($path); } else { unlink($path); }
	}
	rmdir($directory);
}

$directory = realpath(sys_get_temp_dir()).'/file-health-store-'.bin2hex(random_bytes(8));
mkdir($directory,0700);
$values = ['enable' => 1,'state_directory' => $directory,'versions' => 'original','check_interval_seconds' => 60,'worker_stale_seconds' => 60];
try {
	$config = new Config($values);
	failsStore(static function() use ($config) { new Store($config,false,true); },'Read-only opening cannot initialize missing storage');
	verifyStore(scandir($directory) === ['.','..'],'A missing read-only ledger produces no files');
	failsStore(static function() use ($config) { new Store($config,true,true); },'Read-only initialization is refused');
	$store = new Store($config,true);
	verifyStore((fileperms($directory.'/health.sqlite') & 0777) === 0600,'The ledger is created private');
	$summary = $store->summary();
	verifyStore($summary['total_targets'] === 0 && $summary['worker_stale'] && $summary['sweep_overdue'],'Uninitialized monitoring never reports a current sweep or worker');
	$lock = $store->lock();
	failsStore(static function() use ($config) { (new Store($config))->lock(); },'A competing worker is rejected');
	Store::unlock($lock);
	$lock = $store->lock(); Store::unlock($lock);
	verifyStore(true,'Releasing a lock permits a later invocation');
	$run = $store->startRun(5,['original'],42);
	verifyStore($run['cursor'] === 0 && $run['max_id'] === 5 && $run['user_id'] === 42,'A sweep records its bounded inventory and operator');
	failsStore(static function() use ($store) { $store->startRun(5,['original'],42); },'Only one sweep may be active');
	$store->record($run['id'],1,[fileResult(1,'original','mismatch')]);
	$active = $store->activeRun();
	verifyStore($active['cursor'] === 1 && $active['checked'] === 1 && $active['problems'] === 1,'A check event and checkpoint advance together');
	verifyStore($store->summary()['unresolved_issues'] === 1 && $store->problems()[0]['expected_md5'] === str_repeat('a',32),'A mismatch retains its expected upload checksum');
	failsStore(static function() use ($store,$run) { $store->record($run['id'],1,[fileResult(1,'original','verified')]); },'A committed representation cannot be silently overwritten');
	failsStore(static function() use ($store,$run) { $store->record($run['id'],6,[fileResult(6,'original','verified')]); },'A record outside the sweep bound is rejected');
	failsStore(static function() use ($store,$run) { $store->record($run['id'],2,[fileResult(2,'original','unknown')]); },'Unknown check outcomes are rejected');
	verifyStore($store->activeRun()['cursor'] === 1 && $store->history(2) === [],'Rejected outcomes leave no event or cursor advancement');
	$store->endRun($run['id'],'completed');
	$second = $store->startRun(5,['original'],42);
	$store->record($second['id'],1,[fileResult(1,'original','verified')]);
	verifyStore($store->summary()['unresolved_issues'] === 0 && count($store->history(1)) === 2,'Reverification of the same file identity resolves the issue without removing history');
	$store->endRun($second['id'],'completed');
	$third = $store->startRun(5,['original'],42);
	$store->record($third['id'],1,[fileResult(1,'original','missing')]);
	verifyStore($store->summary()['unresolved_issues'] === 1,'A recurring problem reopens its existing identity issue');
	$store->endRun($third['id'],'completed');
	$replacement = $store->startRun(5,['original'],42);
	$store->record($replacement['id'],1,[fileResult(1,'original','verified','b','b')]);
	verifyStore($store->summary()['counts']['verified'] === 1 && $store->summary()['unresolved_issues'] === 1,'A newly uploaded identity cannot resolve an older damaged version');
	verifyStore($store->problems()[0]['expected_md5'] === str_repeat('a',32),'An old failure still offers the original expected checksum after replacement');
	$store->endRun($replacement['id'],'completed');
	$issueId = (int)$store->problems()[0]['issue_id'];
	verifyStore($store->issue($issueId)['fingerprint'] === str_repeat('a',64),'An issue lookup retains the failed generation rather than the replacement baseline');
	failsStore(static function() use ($store) { $store->issue(999999); },'Unknown issues fail closed');
	failsStore(static function() use ($store,$issueId) { $store->reviewIssue($issueId,42,' '); },'Issue review requires a substantive note');
	failsStore(static function() use ($store,$issueId) { $store->reviewIssue($issueId,0,'Synthetic review'); },'Issue review requires a backend operator identity');
	$db = new PDO('sqlite:'.$directory.'/health.sqlite');
	$current = $store->history(1)[0];
	$db->prepare('UPDATE checks SET checked_at=? WHERE id=?')->execute([gmdate('c',time()-120),$current['id']]);
	failsStore(static function() use ($store,$issueId) { $store->reviewIssue($issueId,42,'Synthetic review'); },'A stale replacement check cannot justify closing an old issue');
	$db->prepare('UPDATE checks SET checked_at=? WHERE id=?')->execute([$current['checked_at'],$current['id']]);
	$historyCount = count($store->history(1));
	$store->reviewIssue($issueId,42,'Intentional replacement reviewed against the synthetic original.');
	$reviewed = $store->issue($issueId);
	verifyStore($reviewed['resolved_at'] !== null && strpos($reviewed['resolution'],'backend user 42') !== false,'Explicit replacement review records its operator and resolution');
	verifyStore(count($reviewed['reviews']) === 1 && (int)$reviewed['reviews'][0]['user_id'] === 42
		&& (int)$reviewed['reviews'][0]['check_id'] === (int)$current['id'] && $reviewed['reviews'][0]['reviewed_at'] !== '',
		'A review permanently links its operator, timestamp, note and successful replacement check');
	verifyStore(count($store->history(1)) === $historyCount && $reviewed['expected_md5'] === str_repeat('a',32),'Review preserves old file checks and their original expected checksum');
	failsStore(static function() use ($store,$issueId) { $store->reviewIssue($issueId,42,'Second review'); },'A resolved issue cannot be silently reviewed again');
	$reopened = $store->startRun(5,['original'],42);
	$store->record($reopened['id'],1,[fileResult(1,'original','mismatch')]);
	$reopenedIssue = $store->issue($issueId);
	verifyStore($reopenedIssue['resolved_at'] === null && $reopenedIssue['reviews'] === $reviewed['reviews'],
		'Reopening a previously reviewed issue preserves every prior administrator review');
	$store->endRun($reopened['id'],'completed');
	$repaired = $store->startRun(5,['original'],42);
	$store->record($repaired['id'],1,[fileResult(1,'original','verified')]);
	verifyStore($store->issue($issueId)['resolved_at'] !== null && $store->issue($issueId)['reviews'] === $reviewed['reviews'],
		'Later automatic reverification also retains explicit administrator review history');
	$store->endRun($repaired['id'],'completed');
	$multi = $store->startRun(5,['original','preview'],42);
	failsStore(static function() use ($store,$multi) { $store->record($multi['id'],2,[fileResult(2,'original','verified')]); },'A representation checkpoint requires every configured version');
	$bad = fileResult(2,'preview','mismatch'); $bad['fingerprint'] = 'invalid';
	failsStore(static function() use ($store,$multi,$bad) { $store->record($multi['id'],2,[fileResult(2,'original','verified'),$bad]); },'A late invalid version rolls back the whole checkpoint');
	verifyStore($store->activeRun()['cursor'] === 0 && $store->history(2) === [],'A failed multiversion transaction leaves no partial verification');
	failsStore(static function() use ($store,$multi) { $store->record($multi['id'],2,[fileResult(2,'original','verified'),fileResult(2,'original','verified')]); },'Duplicate versions do not satisfy a complete checkpoint');
	$store->record($multi['id'],2,[fileResult(2,'original','verified'),fileResult(2,'preview','unverified')]);
	verifyStore(count($store->history(2)) === 2 && $store->activeRun()['checked'] === 2,'Complete multiversion outcomes are retained together');
	$previewIssue = (int)$store->problems()[0]['issue_id'];
	failsStore(static function() use ($store,$previewIssue) { $store->reviewIssue($previewIssue,42,'Synthetic preview review'); },'An unverified current file cannot be reviewed as repaired');
	$store->endRun($multi['id'],'failed');
	verifyStore($store->activeRun() === null && $store->recentRuns(1)[0]['status'] === 'failed','A failed run remains visible but ceases to be active');
	failsStore(static function() use ($store,$multi) { $store->record($multi['id'],3,[fileResult(3,'original','verified'),fileResult(3,'preview','verified')]); },'Completed or failed runs cannot receive later checkpoints');
	$invalidVerified = $store->startRun(5,['original'],42);
	$missingChecksum = fileResult(3,'original','verified'); unset($missingChecksum['expected_md5']);
	failsStore(static function() use ($store,$invalidVerified,$missingChecksum) { $store->record($invalidVerified['id'],3,[$missingChecksum]); },'Verified outcomes require an actual recorded upload checksum');
	verifyStore($store->activeRun()['cursor'] === 0 && $store->history(3) === [],'A false verified result cannot advance the sweep');
	$store->endRun($invalidVerified['id'],'failed');
	$before = hash_file('sha256',$directory.'/health.sqlite');
	$readOnly = new Store($config,false,true);
	$readOnly->summary(); $readOnly->recentRuns(); $readOnly->history(1); $readOnly->problems();
	verifyStore(hash_file('sha256',$directory.'/health.sqlite') === $before,'Dashboard reads leave ledger bytes unchanged');
	foreach ([static function() use ($readOnly) { $readOnly->heartbeat(); },static function() use ($readOnly) { $readOnly->lock(); },
		static function() use ($readOnly) { $readOnly->startRun(5,['original'],42); },
		static function() use ($readOnly,$issueId) { $readOnly->reviewIssue($issueId,42,'Read-only synthetic review'); },
		static function() use ($readOnly,$multi) { $readOnly->endRun($multi['id'],'completed'); },
		static function() use ($readOnly,$multi) { $readOnly->record($multi['id'],3,[fileResult(3,'original','verified')]); }] as $write) {
		failsStore($write,'Every ledger mutation is rejected in read-only mode');
	}
	verifyStore(hash_file('sha256',$directory.'/health.sqlite') === $before,'Rejected read-only writes preserve history');
	verifyStore($readOnly->summary(time()+120)['worker_stale'] && $readOnly->summary(time()+120)['sweep_overdue'],'A quiet old ledger cannot keep reporting a live worker and current sweep');
	verifyStore($readOnly->summary(time()+120)['overdue'] > 0,'Old file checks become overdue independently of saved success');
	$changedScope = new Store(new Config(array_replace($values,['versions' => 'original,preview'])),false,true);
	verifyStore($changedScope->summary()['sweep_overdue'],'A newly configured version requires a matching completed sweep');
	foreach ([['versions' => ''],['versions' => '../original'],['versions' => 'original;touch'],['batch_size' => 0],['batch_size' => 1001],
		['max_seconds' => ''],['check_interval_seconds' => -1],['worker_stale_seconds' => 0]] as $override) {
		$invalid = new Config(array_replace($values,$override));
		$method = ['versions' => 'versions','batch_size' => 'batchSize','max_seconds' => 'maxSeconds','check_interval_seconds' => 'intervalSeconds','worker_stale_seconds' => 'staleSeconds'][array_key_first($override)];
		failsStore(static function() use ($invalid,$method) { $invalid->$method(); },'Invalid configuration must fail explicitly');
	}
	verifyStore((new Config(array_replace($values,['versions' => 'original, preview,original'])))->versions() === ['original','preview'],'Media version names are trimmed and deduplicated');
	failsStore(static function() { (new Config(['state_directory' => dirname(__DIR__,3)]))->stateDirectory(); },'State cannot live inside the source checkout');
	$link = $directory.'/alias'; symlink($directory,$link);
	failsStore(static function() use ($link) { (new Config(['state_directory' => $link]))->stateDirectory(); },'A symlink cannot become the private state root');
	unlink($link);
	chmod($directory.'/health.sqlite',0640); clearstatcache(true,$directory.'/health.sqlite');
	failsStore(static function() use ($config) { new Store($config,false,true); },'A readable but nonprivate ledger is rejected');
	chmod($directory.'/health.sqlite',0600); clearstatcache(true,$directory.'/health.sqlite');
	echo "File Health ledger: {$checks} checks passed\n";
} finally { removeStoreFixture($directory); }
