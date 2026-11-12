<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
// Synthetic controller/view checks; never bootstrap a configured Providence site.
function _t($value) { return $value; }
function caNavUrl($request,$module,$controller,$action,$parameters=[]) { return '/'.$module.'/'.$controller.'/'.$action.'?'.http_build_query($parameters); }
define('pString',1);
class ApplicationException extends RuntimeException {}
class HealthTestView {
	private $values = []; public $download;
	public function setVar($key,$value) { $this->values[$key] = $value; }
	public function getVar($key) { return $this->values[$key] ?? null; }
	public function render($file) {
		if ($file === 'bundles/download_file_binary.php') {
			$this->download=['path'=>$this->getVar('archive_path'),'name'=>$this->getVar('archive_name')]; return '';
		}
		ob_start(); try { include dirname(__DIR__).'/views/'.$file; return ob_get_contents(); } finally { ob_end_clean(); }
	}
}
class ActionController {
	protected $request; protected $response; public $view; public $html = '';
	public function __construct(&$request,&$response,$paths=null) { $this->request=$request; $this->response=$response; $this->view=new HealthTestView(); }
	public function render($file,$returnOnly=false) {
		$html=$this->view->render($file);
		if (!$returnOnly) { $this->html=$html; }
		return $html;
	}
}
class HealthTestUser {
	public $admin=true; public $active=true; public $id=7;
	public function getPrimaryKey() { return $this->id; }
	public function get($key) { return $this->active; }
	public function canDoAction($action) { return $this->admin && $action==='is_administrator'; }
}
class HealthTestRequest {
	public $user; public $loggedIn=true; public $parameters=[]; public $module='fileHealth'; public $controller='Health';
	public function __construct() { $this->user=new HealthTestUser(); }
	public function isLoggedIn() { return $this->loggedIn; }
	public function getParameter($key,$type) { return $this->parameters[$key] ?? null; }
	public function getModulePath() { return $this->module; }
	public function getController() { return $this->controller; }
}
class HealthTestResponse { public $headers=[]; public function addHeader($key,$value) { $this->headers[$key]=$value; } }
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
class HealthTestStore {
	public static $summary=[]; public static $runs=[]; public static $reads=0; public static $reportReads=0;
	public static $fail=false; public static $report; public static $name='native-fixity-report.txt';
	public function __construct() { self::$reads++; if (self::$fail) { throw new RuntimeException('/private/synthetic-secret: process diagnostic'); } }
	public function summary() { return self::$summary; }
	public function recentRuns($limit) { if ($limit!==25) { throw new RuntimeException('Unbounded UI history.'); } return self::$runs; }
	public function reportPath($id) {
		self::$reportReads++;
		if ($id!==str_repeat('a',32) && $id!=='legacy-'.str_repeat('c',64)) { throw new RuntimeException('/private/synthetic-secret: no such report'); }
		return self::$report;
	}
	public function reportDownloadName($id) { return self::$name; }
}
class TestHealthController extends HealthController {
	public static $config; public static $provider;
	protected function configuration() { return self::$config; }
	protected function store() { return self::$provider ?? new HealthTestStore(); }
}
$assertions=0;
function healthAssert($condition,$message) { global $assertions; $assertions++; if (!$condition) { throw new RuntimeException($message); } }
function healthDenied($request) {
	$response=new HealthTestResponse();
	try { new TestHealthController($request,$response); } catch (ApplicationException $error) { return true; }
	return false;
}
function healthDownloadDenied($controller) {
	try { $controller->Download(); } catch (ApplicationException $error) { return strpos($error->getMessage(),'synthetic-secret')===false; }
	return false;
}
$directory=sys_get_temp_dir().'/file-health-ui-'.bin2hex(random_bytes(8)); mkdir($directory,0700); $directory=realpath($directory);
try {
	TestHealthController::$config=new \FileHealth\Config(['enable'=>1]);
	$request=new HealthTestRequest(); $request->loggedIn=false; healthAssert(healthDenied($request),'Anonymous controller access was allowed.');
	$request=new HealthTestRequest(); $request->user->admin=false; healthAssert(healthDenied($request),'Non-administrator access was allowed.');
	$request=new HealthTestRequest(); $request->user->active=false; healthAssert(healthDenied($request),'Inactive administrator access was allowed.');
	$request=new HealthTestRequest(); $request->user->id=0; healthAssert(healthDenied($request),'Unidentified administrator access was allowed.');
	healthAssert(HealthTestStore::$reads===0,'Unauthorized requests opened the report provider.');
	TestHealthController::$config=new \FileHealth\Config(['enable'=>0]); healthAssert(healthDenied(new HealthTestRequest()),'Disabled plugin allowed access.');
	TestHealthController::$config=new \FileHealth\Config(['enable'=>1]);
	$scope=['kinds'=>['ca_object_representations','ca_attributes'],'versions'=>['original','<svg onload=alert(1)>']];
	$complete=['id'=>str_repeat('a',32),'status'=>'no_errors_reported','scope'=>$scope,'started_at'=>'2026-11-12T12:00:00Z',
		'finished_at'=>'2026-11-12T13:00:00Z','heartbeat_at'=>'2026-11-12T13:00:00Z','report_available'=>true];
	$legacy=['id'=>'legacy-'.str_repeat('c',64),'status'=>'untracked','scope'=>null,'started_at'=>null,
		'finished_at'=>null,'report_at'=>'2026-11-10T12:00:00Z','report_available'=>true];
	$failed=['id'=>str_repeat('b',32),'status'=>'failed','scope'=>$scope,'started_at'=>'2026-11-12T14:00:00Z',
		'finished_at'=>'2026-11-12T14:01:00Z','report_available'=>false,'reason'=>'/private/synthetic-secret: raw diagnostic'];
	$active=['id'=>str_repeat('d',32),'status'=>'running','scope'=>$scope,'started_at'=>'2026-11-12T15:00:00Z','heartbeat_at'=>'2026-11-12T15:01:00Z'];
	HealthTestStore::$summary=['latest_run'=>$active,'last_completed_run'=>$complete,'last_completed_at'=>$complete['finished_at'],
		'active_run'=>$active,'last_worker_at'=>$active['heartbeat_at'],'worker_stale'=>true,'overdue'=>true,'scope'=>$scope,
		'untracked_reports'=>1,'tracked_run_count'=>3];
	HealthTestStore::$runs=[$failed,$complete,$legacy];
	$request=new HealthTestRequest(); $response=new HealthTestResponse();
	$controller=new TestHealthController($request,$response); $controller->Index(); $html=$controller->html;
	$reads=HealthTestStore::$reads; $sidebar=$controller->Info([]);
	$sidebarDom=new DOMDocument(); @$sidebarDom->loadHTML($sidebar); $xpath=new DOMXPath($sidebarDom);
	$links=$xpath->query('//nav[@aria-label="File health"]//a');
	healthAssert($links->length===3 && strpos($sidebar,'Patrol status')!==false && strpos($sidebar,'Check history')!==false,'File health sidebar navigation is empty.');
	foreach (['file-health-status','file-health-history'] as $anchor) {
		healthAssert(strpos($sidebar,'#'.$anchor)!==false && strpos($html,'id="'.$anchor.'"')!==false,'Sidebar link has no matching page target.');
	}
	healthAssert($xpath->query('//a[@aria-current="page" and contains(@href,"#file-health-status")]')->length===1,'Current sidebar page is not identified.');
	healthAssert(strpos($sidebar,'//Dashboard/Index')!==false,'Sidebar dashboard route is missing.');
	healthAssert($controller->html===$html && HealthTestStore::$reads===$reads,'Sidebar replaced page content or opened patrol storage.');
	$request->user->admin=false;
	try { $controller->Info(); healthAssert(false,'Sidebar did not recheck administrator access.'); }
	catch (ApplicationException $error) { healthAssert(HealthTestStore::$reads===$reads,'Unauthorized sidebar opened patrol storage.'); }
	$request->user->admin=true;
	healthAssert(strpos($html,'No errors reported')!==false && strpos($html,'Last completed full patrol:')!==false,'Native patrol outcome or full-patrol date missing.');
	healthAssert(strpos($html,'Untracked report')!==false && strpos($html,'Completion was not recorded.')!==false && strpos($html,'Report date:')!==false,'Legacy report completion was misrepresented.');
	healthAssert(strpos($html,'Scope was not recorded')!==false,'Legacy report scope was inferred.');
	healthAssert(strpos($html,'&lt;svg onload=alert(1)&gt;')!==false && strpos($html,'<svg')===false,'Configured scope was not escaped.');
	healthAssert(strpos($html,'synthetic-secret')===false && strpos($html,'raw diagnostic')===false,'Private process diagnostics were exposed.');
	healthAssert(strpos($html,'full patrol is overdue')!==false && strpos($html,'heartbeat is missing')!==false,'Overdue patrol or stale heartbeat was hidden.');
	healthAssert(strpos($html,'Download report')!==false && strpos($html,'run_id=legacy-'.str_repeat('c',64))!==false,'Authenticated report links missing.');
	healthAssert(strpos($html,'Recorded MD5')===false && strpos($html,'Finding and review history')===false,'Per-file ledger UI remains.');
	healthAssert(($response->headers['Cache-Control'] ?? null)==='no-store','Private results allowed HTTP caching.');
	HealthTestStore::$summary['active_run']=null; HealthTestStore::$summary['untracked_active']=true;
	$controller=new TestHealthController($request,$response); $controller->Index();
	healthAssert(strpos($controller->html,'The patrol lock is in use, but no active run record is available. Start/completion details were not recorded.')!==false,'Untracked active patrol lock was hidden or misrepresented.');
	HealthTestStore::$summary['last_completed_at']=null; HealthTestStore::$summary['last_completed_run']=null;
	$controller=new TestHealthController($request,$response); $controller->Index();
	healthAssert(strpos($controller->html,'No completed full patrol is recorded.')!==false,'Missing full completion evidence was hidden.');
	HealthTestStore::$fail=true; $controller=new TestHealthController($request,$response); $controller->Index();
	healthAssert(strpos($controller->html,'Patrol results are unavailable')!==false && strpos($controller->html,'synthetic-secret')===false,'Report-directory errors leaked.');
	healthAssert(strpos($controller->html,'id="file-health-history"')!==false,'Unavailable history lost its sidebar destination.');
	HealthTestStore::$fail=false; HealthTestStore::$report=$directory.'/native-report.txt';
	file_put_contents(HealthTestStore::$report,"Filename,Issue\nsynthetic.tif,Checksum mismatch\n"); $before=hash_file('sha256',HealthTestStore::$report);
	$request->parameters=['run_id'=>str_repeat('a',32)]; $controller=new TestHealthController($request,$response); $controller->Download();
	healthAssert($controller->view->download===['path'=>HealthTestStore::$report,'name'=>'native-fixity-report.txt'],'Tracked report was not passed to native attachment streaming.');
	$request->parameters=['run_id'=>'legacy-'.str_repeat('c',64)]; $controller->Download();
	healthAssert($controller->view->download['path']===HealthTestStore::$report,'Authorized legacy report download was denied.');
	healthAssert(hash_equals($before,hash_file('sha256',HealthTestStore::$report)),'UI modified a native report.');
	$reads=HealthTestStore::$reportReads; $request->parameters=['run_id'=>'../../process.log'];
	healthAssert(healthDownloadDenied($controller) && HealthTestStore::$reportReads===$reads,'Path traversal reached the report provider.');
	$request->parameters=['run_id'=>str_repeat('e',32)]; healthAssert(healthDownloadDenied($controller),'Unknown report exposed diagnostics or was accepted.');
	$request->parameters=['run_id'=>str_repeat('a',32)]; HealthTestStore::$name="report.txt\r\nX-Injected: value";
	healthAssert(healthDownloadDenied($controller),'Unsafe attachment name was accepted.'); HealthTestStore::$name='native-fixity-report.txt';
	$request->user->admin=false; $reads=HealthTestStore::$reportReads;
	healthAssert(healthDownloadDenied($controller) && HealthTestStore::$reportReads===$reads,'Report download failed to recheck administrator access.');
	healthAssert(\FileHealth\Presentation::status('<img>')==='Needs review','Unknown status rendered verbatim.');
	healthAssert(\FileHealth\Presentation::timestamp('<script>')==='Unknown','Invalid timestamp rendered verbatim.');
	healthAssert(strpos(\FileHealth\Presentation::scope(['kinds'=>['all'],'versions'=>[]]),'all native media versions')!==false,'Native all-version scope was presented as unknown.');
	mkdir($directory.'/reports',0700); file_put_contents($directory.'/reports/old-native.txt',"Filename,Issue\nsynthetic.tif,Checksum mismatch\n");
	TestHealthController::$config=new \FileHealth\Config(['enable'=>1,'state_directory'=>$directory]);
	TestHealthController::$provider=new \FileHealth\Store(TestHealthController::$config,false,true);
	$request=new HealthTestRequest(); $controller=new TestHealthController($request,$response); $controller->Index();
	healthAssert(strpos($controller->html,'Untracked report')!==false && strpos($controller->html,'No completed full patrol is recorded.')!==false,'Existing native-only reports did not render without initialization.');
	healthAssert(!file_exists($directory.'/runs') && !file_exists($directory.'/logs') && !file_exists($directory.'/health.sqlite'),'Reading existing native reports initialized mutable state.');
	healthAssert(strpos($controller->html,$directory)===false,'Native report directory leaked into HTML.');
	TestHealthController::$provider=null;
	require_once dirname(__DIR__).'/fileHealthPlugin.php';
	Configuration::$enabled=false; $plugin=new fileHealthPlugin(dirname(__DIR__)); healthAssert(!$plugin->checkStatus()['available'],'Disabled plugin was available.');
	Configuration::$enabled=true; $plugin=new fileHealthPlugin(dirname(__DIR__)); $menu=['manage'=>['navigation'=>['existing'=>['displayName'=>'Existing']]]];
	BaseApplicationPlugin::$request=new HealthTestRequest(); BaseApplicationPlugin::$request->user->admin=false;
	healthAssert($plugin->hookRenderMenuBar($menu)===$menu,'Non-administrator received file health navigation.');
	BaseApplicationPlugin::$request=new HealthTestRequest(); $navigation=$plugin->hookRenderMenuBar($menu);
	healthAssert(isset($navigation['manage']['navigation']['existing']) && ($navigation['manage']['navigation']['file_health']['default']['module'] ?? null)==='fileHealth','Menu did not preserve native entries and route.');
	$widgets=['existing'=>['domain'=>['module'=>'other','controller'=>'Other']]];
	$revised=$plugin->hookRenderWidgets($widgets);
	healthAssert(($revised['existing'] ?? null)===$widgets['existing'] && ($revised['file_health_navigation']['handler'] ?? null)===
		['module'=>'fileHealth','controller'=>'Health','action'=>'Info','isplugin'=>true],'Sidebar did not register a native plugin Info handler or preserve existing widgets.');
	healthAssert(($revised['file_health_navigation']['domain'] ?? null)===['module'=>'fileHealth','controller'=>'Health'],'Sidebar handler has the wrong route domain.');
	BaseApplicationPlugin::$request->module='manage'; healthAssert($plugin->hookRenderWidgets($widgets)===$widgets,'Sidebar leaked onto another module.');
	BaseApplicationPlugin::$request->module='fileHealth'; BaseApplicationPlugin::$request->controller='Other'; healthAssert($plugin->hookRenderWidgets($widgets)===$widgets,'Sidebar leaked onto another controller.');
	BaseApplicationPlugin::$request->controller='Health'; BaseApplicationPlugin::$request->user->admin=false; healthAssert($plugin->hookRenderWidgets($widgets)===$widgets,'Non-administrator received sidebar registration.');
	BaseApplicationPlugin::$request->user->admin=true; Configuration::$enabled=false;
	$disabled=new fileHealthPlugin(dirname(__DIR__)); healthAssert($disabled->hookRenderWidgets($widgets)===$widgets,'Disabled plugin registered a sidebar.');
	healthAssert(!method_exists($plugin,'hookCLICaUtilsGetCommands'),'Custom checker CLI registration remains.');
	print "File health UI checks passed ($assertions assertions).\n";
} finally {
	foreach (glob($directory.'/reports/*') ?: [] as $file) { unlink($file); }
	if (is_dir($directory.'/reports')) { rmdir($directory.'/reports'); }
	foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
	rmdir($directory);
}
