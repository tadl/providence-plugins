<?php
/** Real child-process patrols against a synthetic caUtils executable; no CA bootstrap. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Patrol.php';
use FileHealth\Config;
use FileHealth\Patrol;
use FileHealth\Store;

$assertions = 0;
function check($condition,string $message) : void { global $assertions; $assertions++; if (!$condition) { throw new RuntimeException($message); } }
function fails(callable $call) : void { try { $call(); } catch (Throwable $error) { check(true,'Expected rejection.'); return; } throw new RuntimeException('Expected rejection.'); }
function remove_tree(string $path) : void {
	if (is_dir($path) && !is_link($path)) { foreach (new FilesystemIterator($path) as $child) { remove_tree($child->getPathname()); } rmdir($path); }
	elseif (file_exists($path) || is_link($path)) { unlink($path); }
}
function wait_for(callable $condition,float $seconds=5) {
	$until = microtime(true)+$seconds;
	do { $value = $condition(); if ($value) { return $value; } usleep(50000); } while (microtime(true)<$until);
	throw new RuntimeException('Synthetic process condition did not occur.');
}
function spawn_wrapper(string $application,string $state,string $mode,array $extra=[]) : array {
	putenv('FILE_HEALTH_TEST_MODE='.$mode);
	$process = proc_open(array_merge([PHP_BINARY,__DIR__.'/../support/run_native_patrol.php',
		'--application-root='.$application,'--state-directory='.$state],$extra),[0 => ['file','/dev/null','r'],1 => ['pipe','w'],2 => ['pipe','w']],$pipes);
	if (!is_resource($process)) { throw new RuntimeException('Cannot start synthetic wrapper.'); }
	return [$process,$pipes];
}
function finish_wrapper($process,array $pipes) : array {
	$state = wait_for(static function() use ($process) { $state = proc_get_status($process); return !$state['running'] ? $state : null; },12);
	$output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
	$closed = proc_close($process); return ['exit' => $state['exitcode'] >= 0 ? $state['exitcode'] : $closed,'output' => $output,'error' => $error];
}

$root = realpath(sys_get_temp_dir()).'/file-health-patrol-'.bin2hex(random_bytes(8)); mkdir($root,0700);
$application = $root.'/Native Application'; mkdir($application.'/support/bin',0700,true);
$stateRoot = $root.'/Private State'; mkdir($stateRoot,0700);
$invocations = $root.'/invocations.jsonl'; $marker = $root.'/native-ready.json';
putenv('FILE_HEALTH_TEST_INVOCATIONS='.$invocations); putenv('FILE_HEALTH_TEST_MARKER='.$marker);
file_put_contents($application.'/setup.php',"<?php file_put_contents(__DIR__.'/unexpected-bootstrap','bootstrapped');\n");
file_put_contents($application.'/support/bin/caUtils', <<<'PHP'
<?php
$options = [];
foreach (array_slice($argv,2) as $option) { if (preg_match('/^--([^=]+)=(.*)$/sD',$option,$m)) { $options[$m[1]] = $m[2]; } }
$descriptor = fopen('php://fd/3','r'); $lock = fstat($descriptor); $expected = stat(getcwd().'/patrol.lock'); fclose($descriptor);
file_put_contents(getenv('FILE_HEALTH_TEST_INVOCATIONS'),json_encode(['argv' => $argv,'cwd' => getcwd(),
    'application' => getenv('COLLECTIVEACCESS_HOME'),'lock_matches' => $lock['dev'] === $expected['dev'] && $lock['ino'] === $expected['ino']])."\n",FILE_APPEND);
file_put_contents(getenv('FILE_HEALTH_TEST_MARKER'),json_encode(['pid' => getmypid()]));
$mode = getenv('FILE_HEALTH_TEST_MODE');
if ($mode === 'hold') { while (true) { usleep(100000); } }
if ($mode === 'no_report') { exit(0); }
if ($mode === 'crash') { fwrite(STDERR,'SYNTHETIC_PRIVATE_ERROR'); exit(7); }
if ($mode === 'verbose') { for ($i=0;$i<400;$i++) { fwrite(STDOUT,str_repeat('SYNTHETIC_PRIVATE_OUTPUT ',300)); fwrite(STDERR,str_repeat('synthetic diagnostic ',300)); } }
// Match the native command's lowercasing behavior without reading its report.
file_put_contents(strtolower($options['file']),"Synthetic native report\n");
exit($mode === 'attention' ? 1 : 0);
PHP
);
$running = null; $runningPipes = []; $orphan = null;
try {
	$config = new Config(['enable' => 1,'state_directory' => $stateRoot,'email' => 'archivist@example.org','php_binary' => PHP_BINARY]);
	$store = new Store($config,true); $patrol = new Patrol($config,$store,$application);
	putenv('FILE_HEALTH_TEST_MODE=normal');
	$first = $patrol->run();
	check($first['status'] === 'no_errors_reported' && $first['exit_code'] === 0 && $first['report_available'],'Successful native execution is journaled without claiming every file was verified.');
	$lines = file($invocations,FILE_IGNORE_NEW_LINES); $call = json_decode($lines[0],true,512,JSON_THROW_ON_ERROR);
	check(count($lines) === 1 && $call['argv'][1] === 'check-media-fixity','Patrol invokes the native command exactly once.');
	check($call['application'] === $application && $call['cwd'] === $stateRoot,'Native child receives the intended installation and private working directory.');
	check($call['lock_matches'],'The native child inherits the exact common patrol.lock inode.');
	check(in_array('--email=archivist@example.org',$call['argv'],true),'Native email is forwarded unchanged.');
	check(in_array('--kinds=ca_object_representations',$call['argv'],true) && in_array('--versions=original',$call['argv'],true),'Default patrol scope matches the existing representations/original patrol.');
	check(in_array('--file=reports/'.$first['id'].'.txt',$call['argv'],true),'Lowercase relative output works with mixed-case private/installation paths.');
	check(!file_exists($application.'/unexpected-bootstrap'),'The wrapper never bootstraps application setup itself.');
	check((fileperms($store->reportFile($first['id'])) & 0777) === 0600 && (fileperms($store->logFile($first['id'])) & 0777) === 0600,'Artifacts remain private.');
	putenv('FILE_HEALTH_TEST_MODE=attention'); $attention = $patrol->run();
	check($attention['status'] === 'attention' && $attention['exit_code'] === 1 && $attention['report_available'],'Native nonzero exit with report is attention, including findings or email/runtime errors.');
	putenv('FILE_HEALTH_TEST_MODE=no_report'); $missing = $patrol->run();
	check($missing['status'] === 'failed' && $missing['reason'] === 'report_missing' && !$missing['report_available'],'A precreated empty report is never a completed patrol.');
	putenv('FILE_HEALTH_TEST_MODE=crash'); $crashed = $patrol->run();
	check($crashed['status'] === 'failed' && $crashed['exit_code'] === 7 && !$crashed['report_available'],'Native failure without report is retained as failed.');
	putenv('FILE_HEALTH_TEST_MODE=verbose'); $verbose = $patrol->run();
	check($verbose['status'] === 'no_errors_reported','Large stdout and stderr are drained without deadlocking the native process.');
	check(filesize($store->logFile($verbose['id'])) <= Patrol::MAX_LOG_BYTES && strpos(file_get_contents($store->logFile($verbose['id'])),'size limit reached') !== false,'Private diagnostics are bounded and explicitly truncated.');
	check(strpos(json_encode($verbose),'SYNTHETIC_PRIVATE') === false,'Journal/UI results never contain raw native diagnostic output.');
	$allConfig = new Config(['enable' => 1,'state_directory' => $stateRoot,'kinds' => 'all','versions' => '']);
	putenv('FILE_HEALTH_TEST_MODE=normal'); (new Patrol($allConfig,new Store($allConfig),$application))->run();
	$lines = file($invocations,FILE_IGNORE_NEW_LINES); $last = json_decode(end($lines),true,512,JSON_THROW_ON_ERROR);
	check(in_array('--kinds=all',$last['argv'],true) && !preg_grep('/^--versions=/',$last['argv']) && !preg_grep('/^--email=/',$last['argv']),'All-kind/all-version scope and disabled email are passed without default narrowing.');
	$lock = $store->lock(); $count = count($lines);
	try { fails(static function() use ($patrol) { $patrol->run(); }); } finally { Store::unlock($lock); }
	check(count(file($invocations)) === $count,'A busy common lock does not launch another patrol.');
	foreach ([SIGTERM,SIGINT] as $number) {
		if (is_file($marker)) { unlink($marker); }
		[$running,$runningPipes] = spawn_wrapper($application,$stateRoot,'hold');
		$native = wait_for(static function() use ($marker) { return is_file($marker) ? json_decode(file_get_contents($marker),true) : null; });
		if ($number === SIGTERM) {
			$before = $store->summary()['active_run'];
			wait_for(static function() use ($store,$before) { $active = $store->summary()['active_run']; return $active && $active['heartbeat_at'] !== $before['heartbeat_at']; },7);
			check(true,'A silent long-running native patrol receives timer-driven heartbeats.');
		}
		proc_terminate($running,$number); $ended = finish_wrapper($running,$runningPipes); $running = null; $runningPipes = [];
		$result = json_decode($ended['output'],true,512,JSON_THROW_ON_ERROR);
		check($result['status'] === 'interrupted' && $ended['exit'] === 128+$number,'Termination is forwarded and journaled as interrupted.');
		check(!posix_kill($native['pid'],0),'The interrupted native process was terminated and reaped before final journaling.');
	}
	if (is_file($marker)) { unlink($marker); }
	[$running,$runningPipes] = spawn_wrapper($application,$stateRoot,'hold');
	$native = wait_for(static function() use ($marker) { return is_file($marker) ? json_decode(file_get_contents($marker),true) : null; }); $orphan = $native['pid'];
	proc_terminate($running,SIGKILL); finish_wrapper($running,$runningPipes); $running = null; $runningPipes = [];
	$common = fopen($stateRoot.'/patrol.lock','c');
	check(!flock($common,LOCK_EX|LOCK_NB),'The native child retains the common lock even after abrupt wrapper death.'); fclose($common);
	posix_kill($orphan,SIGTERM);
	wait_for(static function() use ($stateRoot) { $lock = fopen($stateRoot.'/patrol.lock','c'); $free = flock($lock,LOCK_EX|LOCK_NB); if ($free) { flock($lock,LOCK_UN); } fclose($lock); return $free; }); $orphan = null;
	check(true,'The inherited lock releases when the surviving native process exits.');
	[$running,$runningPipes] = spawn_wrapper($application,$stateRoot,'normal',['--unexpected=value']);
	$bad = finish_wrapper($running,$runningPipes); $running = null; $runningPipes = [];
	check($bad['exit'] === 2 && strpos($bad['error'],'SYNTHETIC_PRIVATE') === false,'Unknown wrapper flags fail without exposing private diagnostics.');
	$badConfig = new Config(['enable' => 1,'state_directory' => $stateRoot,'email' => "bad@example.org\nInjected"]);
	fails(static function() use ($badConfig,$application) { (new Patrol($badConfig,new Store($badConfig),$application))->run(); });
	print 'File health native patrol runner: '.$assertions." assertions passed.\n";
} finally {
	if (is_resource($running)) { proc_terminate($running,SIGKILL); foreach ($runningPipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } proc_close($running); }
	if ($orphan) { posix_kill($orphan,SIGKILL); }
	foreach (['FILE_HEALTH_TEST_MODE','FILE_HEALTH_TEST_INVOCATIONS','FILE_HEALTH_TEST_MARKER'] as $key) { putenv($key); }
	remove_tree($root);
}
