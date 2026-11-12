<?php
/** Resumable scheduling with synthetic catalogues and a private temporary ledger. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Scheduler.php';
use FileHealth\Config;
use FileHealth\Store;
use FileHealth\Scheduler;

$checks = 0;
function verifyScheduler($condition, string $message) : void {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function failsScheduler(callable $operation, string $message) : void {
	try { $operation(); } catch (Throwable $error) { verifyScheduler(true,$message); return; }
	throw new RuntimeException($message);
}
final class SyntheticHealthCatalogue {
	public $ids;
	public $queries = [];
	public function __construct(array $ids) { $this->ids = $ids; }
	public function maxId() : int { return $this->ids ? max($this->ids) : 0; }
	public function batch(int $after, int $limit, ?int $max=null) : array {
		$this->queries[] = [$after,$limit,$max];
		$ids = array_values(array_filter($this->ids,static function($id) use ($after,$max) { return $id > $after && ($max === null || $id <= $max); }));
		sort($ids,SORT_NUMERIC);
		return array_slice($ids,0,$limit);
	}
}
final class SyntheticHealthChecker {
	public $calls = [];
	public $operation;
	public function check(int $id, string $version) : array {
		$this->calls[] = [$id,$version];
		if ($this->operation) { return ($this->operation)($id,$version); }
		return schedulerResult($id,$version);
	}
}
function schedulerResult(int $id, string $version, string $status='verified') : array {
	return ['representation_id' => $id,'version' => $version,'status' => $status,'reason' => $status,
		'fingerprint' => hash('sha256','synthetic:'.$id.':'.$version),'expected_md5' => md5('synthetic bytes '.$id),'bytes' => 3];
}
function schedulerFixture(array $overrides=[]) : array {
	$directory = realpath(sys_get_temp_dir()).'/file-health-scheduler-'.bin2hex(random_bytes(8));
	mkdir($directory,0700);
	$config = new Config(array_replace(['enable' => 1,'state_directory' => $directory,'versions' => 'original',
		'batch_size' => 2,'max_seconds' => 60,'check_interval_seconds' => 60,'worker_stale_seconds' => 60],$overrides));
	return [$directory,$config,new Store($config,true)];
}
function schedulerCleanup(string $directory) : void {
	foreach (scandir($directory) ?: [] as $name) {
		if ($name === '.' || $name === '..') { continue; }
		$path = $directory.'/'.$name;
		if (is_dir($path) && !is_link($path)) { schedulerCleanup($path); } else { unlink($path); }
	}
	rmdir($directory);
}

$directories = [];
try {
	[$directory,$config,$store] = schedulerFixture(); $directories[] = $directory;
	$catalogue = new SyntheticHealthCatalogue([1,2,4,5]); $checker = new SyntheticHealthChecker();
	$scheduler = new Scheduler($config,$store,$catalogue,$checker);
	$first = $scheduler->run(42); $runId = $store->activeRun()['id'];
	verifyScheduler($first['status'] === 'running' && $first['processed_representations'] === 2,'A scheduled invocation respects its representation batch size');
	verifyScheduler($store->activeRun()['cursor'] === 2 && $store->activeRun()['checked'] === 2,'A partial sweep retains its durable checkpoint');
	verifyScheduler($store->summary()['last_completed_at'] === null && $store->summary()['sweep_overdue'],'A partial initial sweep cannot stand in for completed coverage');
	$catalogue->ids[] = 6;
	$second = (new Scheduler($config,new Store($config),$catalogue,$checker))->run(42);
	verifyScheduler($second['status'] === 'completed' && $store->activeRun() === null,'A subsequent invocation completes the same sweep');
	verifyScheduler($store->recentRuns(1)[0]['id'] === $runId && $store->recentRuns(1)[0]['max_id'] === 5,'The inventory bound remains fixed for the sweep');
	verifyScheduler($checker->calls === [[1,'original'],[2,'original'],[4,'original'],[5,'original']],'Resume skips committed records and excludes later uploads from the current sweep');
	verifyScheduler(!$store->summary()['sweep_overdue'] && !$store->summary()['worker_stale'],'A completed recent sweep and worker have separate current status');
	$count = count($checker->calls); $notDue = $scheduler->run(42);
	verifyScheduler($notDue['status'] === 'not_due' && count($checker->calls) === $count,'A recent completed sweep avoids needless rehashing');
	$db = new PDO('sqlite:'.$directory.'/health.sqlite');
	$db->prepare('UPDATE runs SET started_at=?,finished_at=? WHERE id=?')->execute([gmdate('c',time()-120),gmdate('c',time()-120),$runId]);
	verifyScheduler($store->summary()['sweep_overdue'],'An old completed sweep becomes due');
	$again = $scheduler->run(42);
	verifyScheduler($again['status'] === 'running' && $store->activeRun()['max_id'] === 6,'The next sweep captures newly uploaded representations');
	$lock = $store->lock(); $count = count($checker->calls);
	failsScheduler(static function() use ($scheduler) { $scheduler->run(42); },'A concurrent invocation cannot enter a held worker lock');
	verifyScheduler(count($checker->calls) === $count,'Lock contention performs no duplicate checks');
	Store::unlock($lock);
	$old = $store->activeRun()['id'];
	$changedConfig = new Config(['enable' => 1,'state_directory' => $directory,'versions' => 'original,preview','batch_size' => 1]);
	$changedStore = new Store($changedConfig);
	(new Scheduler($changedConfig,$changedStore,$catalogue,$checker))->run(42);
	$runs = $changedStore->recentRuns();
	verifyScheduler($runs[0]['versions'] === ['original','preview'] && $runs[1]['id'] === $old && $runs[1]['status'] === 'superseded','Changing configured versions supersedes an incomplete sweep and starts a new scope');
	verifyScheduler(array_slice($checker->calls,-2) === [[1,'original'],[1,'preview']],'Every configured version is checked before a representation is checkpointed');

	[$directory,$config,$store] = schedulerFixture(['batch_size' => 10]); $directories[] = $directory;
	$catalogue = new SyntheticHealthCatalogue([1,2,3]); $checker = new SyntheticHealthChecker();
	$interrupted = $store->startRun(3,['original'],42);
	$store->record($interrupted['id'],1,[schedulerResult(1,'original','mismatch')]);
	verifyScheduler($store->summary()['unresolved_issues'] === 1,'A persisted problem is visible during an incomplete sweep');
	$result = (new Scheduler($config,new Store($config),$catalogue,$checker))->run(42);
	verifyScheduler($result['status'] === 'completed' && $checker->calls === [[2,'original'],[3,'original']],'A crash left after a committed record resumes at the next record');
	verifyScheduler($store->summary()['unresolved_issues'] === 1,'Resuming other records cannot clear an earlier mismatch');

	[$directory,$config,$store] = schedulerFixture(['batch_size' => 10]); $directories[] = $directory;
	$catalogue = new SyntheticHealthCatalogue([1,2]); $checker = new SyntheticHealthChecker();
	$checker->operation = static function($id,$version) {
		if ($id === 2) { throw new RuntimeException('Synthetic worker interruption.'); }
		return schedulerResult($id,$version,'missing');
	};
	failsScheduler(static function() use ($config,$store,$catalogue,$checker) { (new Scheduler($config,$store,$catalogue,$checker))->run(42); },'An infrastructure interruption is surfaced');
	$failed = $store->recentRuns(1)[0];
	verifyScheduler($failed['status'] === 'failed' && $failed['cursor'] === 1 && $failed['checked'] === 1,'A failed invocation retains only committed progress and is not completed');
	verifyScheduler($store->history(2) === [] && $store->summary()['unresolved_issues'] === 1 && $store->summary()['sweep_overdue'],'An interrupted file cannot become verified or erase an older problem');
	$checker->operation = null;
	$result = (new Scheduler($config,$store,$catalogue,$checker))->run(42);
	verifyScheduler($result['status'] === 'completed' && $store->recentRuns(2)[1]['status'] === 'failed','A later successful sweep preserves failed-run history');
	verifyScheduler($store->summary()['unresolved_issues'] === 0 && count($store->history(1)) === 2,'A subsequent native success resolves the same identity after failure');
	$lock = $store->lock(); Store::unlock($lock);
	verifyScheduler(true,'Exceptions release the worker lock');

	[$directory,$config,$store] = schedulerFixture(['batch_size' => 10,'max_seconds' => 1]); $directories[] = $directory;
	$catalogue = new SyntheticHealthCatalogue([1,2]); $checker = new SyntheticHealthChecker();
	$checker->operation = static function($id,$version) { usleep(1100000); return schedulerResult($id,$version); };
	$result = (new Scheduler($config,$store,$catalogue,$checker))->run(42);
	verifyScheduler($result['status'] === 'running' && $result['processed_representations'] === 1 && count($checker->calls) === 1,'The time budget stops between complete representation checks without advancing unchecked records');
	verifyScheduler($store->activeRun()['cursor'] === 1,'A long check is committed before a time-limited invocation exits');

	[$directory,$config,$store] = schedulerFixture(['batch_size' => 1]); $directories[] = $directory;
	$catalogue = new SyntheticHealthCatalogue([1,2]); $checker = new SyntheticHealthChecker();
	$scheduler = new Scheduler($config,$store,$catalogue,$checker);
	$scheduler->run(42); $originalRun = $store->activeRun()['id'];
	$scheduler->run(42,true); $forcedRun = $store->activeRun()['id'];
	verifyScheduler($forcedRun !== $originalRun && $store->recentRuns(2)[1]['status'] === 'superseded','A manual restart retains and supersedes an incomplete sweep');
	verifyScheduler($checker->calls === [[1,'original'],[1,'original']],'A forced repair check begins from the start instead of skipping previously committed records');
	$scheduler->run(42);
	verifyScheduler($store->activeRun() === null && $scheduler->run(42)['status'] === 'not_due','Normal scheduling still observes the configured interval after a forced sweep completes');
	$scheduler->run(42,true);
	verifyScheduler($store->activeRun()['id'] !== $forcedRun,'An explicit manual restart can recheck a recent completed sweep immediately');

	[$directory,$config,$store] = schedulerFixture(); $directories[] = $directory;
	$empty = new SyntheticHealthCatalogue([]); $checker = new SyntheticHealthChecker();
	$result = (new Scheduler($config,$store,$empty,$checker))->run(42);
	verifyScheduler($result['status'] === 'completed' && $result['processed_representations'] === 0 && !$checker->calls,'An empty inventory completes explicitly with zero file checks');
	$disabled = new Config(['enable' => 0,'state_directory' => $directory]);
	$before = count($store->recentRuns());
	failsScheduler(static function() use ($disabled,$store,$empty,$checker) { (new Scheduler($disabled,$store,$empty,$checker))->run(42); },'A disabled plugin cannot start scheduled checks');
	verifyScheduler(count($store->recentRuns()) === $before,'A disabled invocation writes no sweep history');
	echo "File Health scheduler: {$checks} checks passed\n";
} finally { foreach ($directories as $directory) { schedulerCleanup($directory); } }
