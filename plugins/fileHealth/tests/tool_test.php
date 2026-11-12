<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
require_once __DIR__.'/../lib/Tool.php';
function _t($value) { return $value; }
$assertions = 0;
function check($condition, $message) { global $assertions; $assertions++; if (!$condition) { throw new RuntimeException($message); } }
function refuses(callable $operation, $message) {
	try { $operation(); } catch (\FileHealth\Failure $e) { check(true,$message); return; }
	throw new RuntimeException($message);
}
class HealthUser {
	private $active; private $admin;
	public function __construct($active,$admin) { $this->active=$active; $this->admin=$admin; }
	public function getPrimaryKey() { return 7; }
	public function get($field) { return $field === 'active' && $this->active; }
	public function canDoAction($action) { check($action === 'is_administrator','Uses real native administrator action.'); return $this->admin; }
}
class CLIUtils { public static $errors=[]; public static function addError($message) { self::$errors[]=$message; } }
class ca_users extends HealthUser { public function __construct($id) { parent::__construct($id===7,$id===7); } }
refuses(static function() { \FileHealth\Tool::authorize(null); },'Missing user denied.');
refuses(static function() { \FileHealth\Tool::authorize(new HealthUser(false,true)); },'Inactive administrator denied.');
refuses(static function() { \FileHealth\Tool::authorize(new HealthUser(true,false)); },'Non-administrator denied.');
\FileHealth\Tool::authorize(new HealthUser(true,true));
check($GLOBALS['AUTH_CURRENT_USER_ID'] === 7,'Worker uses validated administrator identity.');
$commands = \FileHealth\Tool::commands()['fileHealth'];
check(count($commands) === 5,'All CLI commands registered.');
check(isset($commands['file_health_check']['Options']['restart']),'Manual fresh sweep available.');
$directory = sys_get_temp_dir().'/file-health-candidate-'.bin2hex(random_bytes(6));
mkdir($directory,0700);
file_put_contents($directory.'/ca_users.php','<?php // Synthetic user class is defined in the test.');
define('__CA_MODELS_DIR__',$directory);
$path = $directory.'/replacement.pdf';
try {
	$config = new \FileHealth\Config(['enable'=>1,'state_directory'=>realpath($directory)]);
	$tool = new \FileHealth\Tool($config,dirname(__DIR__));
	check(!$tool->run('file_health_init',['user-id'=>'7']),'Initialization requires explicit apply.');
	check(!is_file($directory.'/health.sqlite'),'Missing apply never initializes storage.');
	check(!$tool->run('file_health_init',['user-id'=>'8','apply'=>'1']),'Inactive or unauthorized CLI identity denied.');
	check(!$tool->run('file_health_init',['user-id'=>'7junk','apply'=>'1']),'Malformed CLI identity rejected.');
	ob_start(); $initialized=$tool->run('file_health_init',['user-id'=>'7','apply'=>'1']); $initializedOutput=ob_get_clean();
	check($initialized && json_decode($initializedOutput,true)['initialized'],'Explicit authorized initialization succeeds.');
	$ledgerHash = hash_file('sha256',$directory.'/health.sqlite');
	ob_start(); $status=$tool->run('file_health_status',['user-id'=>'7']); $statusOutput=ob_get_clean();
	check($status && isset(json_decode($statusOutput,true)['summary']),'Saved status reads through the real command dispatcher.');
	check(hash_file('sha256',$directory.'/health.sqlite')===$ledgerHash,'Status dispatch never changes private state.');
	$disabled = new \FileHealth\Tool(new \FileHealth\Config(['enable'=>0]),dirname(__DIR__));
	check(!$disabled->run('file_health_status',['user-id'=>'7']),'Disabled plugin fails closed in CLI.');
	file_put_contents($path,'Synthetic original bytes');
	$issue = ['issue_id' => 3,'representation_id' => 12,'version' => 'original','expected_md5' => md5('Synthetic original bytes')];
	$before = hash_file('sha256',$path);
	$result = \FileHealth\Tool::verifyCandidate($issue,$path);
	check($result['verified'] && $result['bytes'] === 24,'Matching candidate verified.');
	check(hash_file('sha256',$path) === $before,'Verification never changes candidate.');
	check(!isset($result['path']),'Private candidate path omitted from result.');
	file_put_contents($path,'Different replacement bytes');
	check(!\FileHealth\Tool::verifyCandidate($issue,$path)['verified'],'Wrong candidate rejected against original checksum.');
	refuses(static function() use ($issue) { \FileHealth\Tool::verifyCandidate($issue,'relative.pdf'); },'Relative paths rejected.');
	refuses(static function() use ($issue,$directory) { \FileHealth\Tool::verifyCandidate($issue,$directory.'/missing'); },'Missing candidate rejected.');
	refuses(static function() use ($issue,$directory) { \FileHealth\Tool::verifyCandidate($issue,$directory); },'Directory rejected.');
	refuses(static function() use ($issue,$path) { $issue['expected_md5']=null; \FileHealth\Tool::verifyCandidate($issue,$path); },'No baseline cannot pass.');
	if (function_exists('posix_mkfifo')) {
		$fifo = $directory.'/candidate-pipe'; posix_mkfifo($fifo,0600);
		try { refuses(static function() use ($issue,$fifo) { \FileHealth\Tool::verifyCandidate($issue,$fifo); },'FIFO rejected without blocking on open.'); }
		finally { unlink($fifo); }
	}
} finally { foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); } rmdir($directory); }
print 'File Health tool: '.$assertions." assertions passed.\n";
