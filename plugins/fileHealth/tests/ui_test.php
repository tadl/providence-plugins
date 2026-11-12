<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
// Synthetic controller/view checks. Never bootstrap a configured Providence site.
function _t($value) { return $value; }
function caNavUrl($request,$module,$controller,$action,$parameters=[]) { return '/'.$module.'/'.$controller.'/'.$action.'?'.http_build_query($parameters); }
function caEditorUrl($request,$table,$id) { return '/editor/'.$table.'/'.(int)$id; }
define('pInteger',1);
class ApplicationException extends RuntimeException {}
class HealthTestView {
	private $values = [];
	public function setVar($key,$value) { $this->values[$key] = $value; }
	public function getVar($key) { return $this->values[$key] ?? null; }
	public function render($file) { ob_start(); try { include dirname(__DIR__).'/views/'.$file; return ob_get_contents(); } finally { ob_end_clean(); } }
}
class ActionController {
	protected $request; protected $response; public $view; public $html = '';
	public function __construct(&$request,&$response,$paths=null) { $this->request=$request; $this->response=$response; $this->view=new HealthTestView(); }
	public function render($file) { $this->html=$this->view->render($file); }
}
class HealthTestUser {
	public $admin = true; public $active = true; public $id = 7;
	public function getPrimaryKey() { return $this->id; }
	public function get($key) { return $this->active; }
	public function canDoAction($action) { return $this->admin && $action === 'is_administrator'; }
}
class HealthTestRequest {
	public $user; public $loggedIn = true; public $parameters = [];
	public function __construct() { $this->user = new HealthTestUser(); }
	public function isLoggedIn() { return $this->loggedIn; }
	public function getParameter($key,$type) { return $this->parameters[$key] ?? null; }
}
class HealthTestResponse { public $headers = []; public function addHeader($key,$value) { $this->headers[$key]=$value; } }
class Configuration {
	public static $enabled=true;
	public static function load($path) { return new self(); }
	public function getScalar($key) { return $key==='enable' ? self::$enabled : false; }
}
class BaseApplicationPlugin {
	protected $description; public static $request;
	public function __construct() {}
	public function getRequest() { return self::$request; }
	public function getDescription() { return $this->description; }
}
require_once dirname(__DIR__).'/controllers/HealthController.php';
class TestHealthController extends HealthController {
	public static $config; public static $failStorage = false; public static $reads = 0;
	protected function configuration() { return self::$config; }
	protected function store() { self::$reads++; if (self::$failStorage) { throw new RuntimeException('/private/synthetic-secret: SQL diagnostic'); } return parent::store(); }
}
$assertions = 0;
function healthAssert($condition,$message) { global $assertions; $assertions++; if (!$condition) { throw new RuntimeException($message); } }
function healthDenied($request) {
	$response = new HealthTestResponse();
	try { new TestHealthController($request,$response); } catch (ApplicationException $e) { return true; }
	return false;
}
$directory = sys_get_temp_dir().'/file-health-ui-'.bin2hex(random_bytes(8)); mkdir($directory,0700); $directory=realpath($directory);
try {
	$config = new \FileHealth\Config(['enable'=>1,'state_directory'=>$directory,'versions'=>'original','worker_stale_seconds'=>60,'check_interval_seconds'=>60]);
	TestHealthController::$config=$config;
	$request=new HealthTestRequest(); $request->loggedIn=false; healthAssert(healthDenied($request),'Anonymous controller access was allowed.');
	$request=new HealthTestRequest(); $request->user->admin=false; healthAssert(healthDenied($request),'Non-administrator access was allowed.');
	$request=new HealthTestRequest(); $request->user->active=false; healthAssert(healthDenied($request),'Inactive administrator access was allowed.');
	$request=new HealthTestRequest(); $request->user->id=0; healthAssert(healthDenied($request),'Unidentified administrator access was allowed.');
	healthAssert(TestHealthController::$reads===0 && !file_exists($directory.'/health.sqlite'),'Unauthorized requests opened or initialized storage.');
	TestHealthController::$config=new \FileHealth\Config(['enable'=>0]); healthAssert(healthDenied(new HealthTestRequest()),'Disabled plugin allowed access.');
	TestHealthController::$config=$config;
	$store=new \FileHealth\Store($config,true); $run=$store->startRun(11,['original'],7);
	$store->record($run['id'],11,[['representation_id'=>11,'version'=>'original','status'=>'mismatch','reason'=>'checksum_mismatch',
		'label'=>'<script>alert("label")</script>','idno'=>'<img src=x onerror=alert(1)>','expected_md5'=>str_repeat('a',32)]]);
	$request=new HealthTestRequest(); $request->parameters=['representation_id'=>11]; $response=new HealthTestResponse();
	$controller=new TestHealthController($request,$response); $controller->Index(); $html=$controller->html;
	healthAssert(strpos($html,'&lt;script&gt;')!==false && strpos($html,'<script>')===false,'Representation label was not escaped.');
	healthAssert(strpos($html,'&lt;img src=x')!==false && strpos($html,'<img src=x')===false,'Representation identifier was not escaped.');
	healthAssert(strpos($html,'Checksum mismatch')!==false && strpos($html,'Check history for representation')!==false,'Findings or history missing.');
	healthAssert(strpos($html,'No completed inventory is recorded')!==false && strpos($html,'complete inventory is overdue')!==false,'Partial coverage was presented as complete.');
	healthAssert(stripos($html,'metadata file/media attributes and uploaded multifiles are excluded')!==false,'Scope limits missing.');
	healthAssert(strpos($html,$directory)===false,'Private state directory was exposed.');
	healthAssert(($response->headers['Cache-Control'] ?? null)==='no-store','Private results allowed HTTP caching.');
	$issueId=(int)$store->problems(1)[0]['issue_id'];
	$store->endRun($run['id'],'completed');
	$replacement=$store->startRun(11,['original'],7);
	$store->record($replacement['id'],11,[['representation_id'=>11,'version'=>'original','status'=>'verified','reason'=>'checksum_matches',
		'label'=>'Synthetic replacement','idno'=>'synthetic-11','expected_md5'=>str_repeat('b',32)]]);
	$store->endRun($replacement['id'],'completed');
	$controller=new TestHealthController($request,$response); $controller->Index();
	healthAssert(strpos($controller->html,'Earlier file generation.')!==false && strpos($controller->html,'#'.$issueId)!==false,'Unresolved older generation was hidden.');
	$store->reviewIssue($issueId,7,'<script>alert("review")</script>');
	$controller=new TestHealthController($request,$response); $controller->Index();
	healthAssert(strpos($controller->html,'Finding and review history')!==false && strpos($controller->html,'Administrator review by backend user #7')!==false,'Administrator review history was hidden.');
	healthAssert(strpos($controller->html,'&lt;script&gt;alert(&quot;review&quot;)&lt;/script&gt;')!==false && strpos($controller->html,'<script>')===false,'Administrator review note was not escaped.');
	$before=hash_file('sha256',$directory.'/health.sqlite');
	$controller=new TestHealthController($request,$response); $controller->Index();
	healthAssert(hash_equals($before,hash_file('sha256',$directory.'/health.sqlite')),'Page rendering changed the ledger.');
	TestHealthController::$failStorage=true; $controller=new TestHealthController($request,$response); $controller->Index();
	healthAssert(strpos($controller->html,'results are unavailable')!==false && strpos($controller->html,'synthetic-secret')===false,'Storage diagnostics leaked into the page.');
	healthAssert(\FileHealth\Presentation::reason('/private/synthetic-secret')==='Review this finding with an administrator.','Unknown reason exposed diagnostics.');
	healthAssert(\FileHealth\Presentation::status('<img>')==='Needs review','Unknown status was rendered verbatim.');
	healthAssert(\FileHealth\Presentation::checksum('<script>')==='Not recorded','Invalid checksum was exposed.');
	healthAssert(\FileHealth\Presentation::timestamp('<script>')==='Unknown','Invalid timestamp was exposed.');
	require_once dirname(__DIR__).'/fileHealthPlugin.php';
	Configuration::$enabled=false; $plugin=new fileHealthPlugin(dirname(__DIR__));
	healthAssert(!$plugin->checkStatus()['available'],'Disabled plugin was registered as available.');
	Configuration::$enabled=true; $plugin=new fileHealthPlugin(dirname(__DIR__));
	$menu=['manage'=>['navigation'=>['existing'=>['displayName'=>'Existing']]]];
	BaseApplicationPlugin::$request=new HealthTestRequest(); BaseApplicationPlugin::$request->user->admin=false;
	healthAssert($plugin->hookRenderMenuBar($menu)===$menu,'Non-administrator received file health navigation.');
	BaseApplicationPlugin::$request=new HealthTestRequest(); $navigation=$plugin->hookRenderMenuBar($menu);
	healthAssert(isset($navigation['manage']['navigation']['existing']) && ($navigation['manage']['navigation']['file_health']['default']['module'] ?? null)==='fileHealth','Plugin menu did not preserve native entries and route.');
	print "File health UI checks passed ($assertions assertions).\n";
} finally {
	unset($store,$controller);
	foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
	rmdir($directory);
}
