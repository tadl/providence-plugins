<?php
/** Runs the PHP orchestration and real Python subprocess with synthetic data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Service.php';
require_once __DIR__.'/SyntheticCatalogue.php';
define('__CA_MODELS_DIR__',__DIR__.'/stubs');
define('__CA_LIB_DIR__',__DIR__.'/stubs/lib');
define('__CA_ACL_READONLY_ACCESS__',1);
define('pInteger',1); define('pString',2);
require_once __CA_MODELS_DIR__.'/ca_users.php';

function check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
function fails($call,$message) {
	try { $call(); } catch (Throwable $e) { check(strpos($e->getMessage(),$message) !== false,'Unexpected error: '.$e->getMessage()); return; }
	throw new RuntimeException('Expected failure: '.$message);
}
class RuntimeCatalogue extends SyntheticCatalogue {
	public $source;
	public function files(string $table,array $row,array $attributes) : array {
		$files = parent::files($table,$row,$attributes);
		foreach ($files as &$file) { $file['source_path'] = $this->source; $file['source_size'] = filesize($this->source); $file['expected_md5'] = md5_file($this->source); } unset($file);
		return $files;
	}
}
class Configuration {
	public static $values;
	public static function load($path) { return new self(); }
	public function get($key) { return self::$values[$key] ?? false; }
}
class ApplicationException extends RuntimeException {}
class SyntheticRequest {
	public $user;
	public $parameters = [];
	public $method = 'GET';
	public $csrf = true;
	public $module = 'MDPNLOCKSSPreservation';
	public $controller = 'Preservation';
	public $action = 'Index';
	public $actionExtra = '';
	public $isPlugin = true;
	public $controllerUrl = '/MDPNLOCKSSPreservation/Preservation';
	public function __construct($user) { $this->user = $user; }
	public function getParameter($key,$type) { return $this->parameters[$key] ?? null; }
	public function getRequestMethod() { return $this->method; }
	public function getModulePath() { return $this->module; }
	public function getController() { return $this->controller; }
	public function setModulePath($value) { $this->module = $value; }
	public function setController($value) { $this->controller = $value; }
	public function setAction($value) { $this->action = $value; }
	public function setActionExtra($value) { $this->actionExtra = $value; }
	public function setIsApplicationPlugin($value) { $this->isPlugin = $value; }
	public function setControllerUrl($value) { $this->controllerUrl = $value; }
}
class SyntheticResponse {
	public $redirect;
	public function setRedirect($url) { $this->redirect = $url; }
}
class SyntheticView {
	public $request;
	public $values = [];
	public function __construct($request) { $this->request = $request; }
	public function setVar($key,$value) { $this->values[$key] = $value; }
	public function getVar($key) { return $this->values[$key] ?? null; }
	public function render($file) { include __DIR__.'/../views/'.$file; }
}
class ActionController {
	protected $request;
	protected $response;
	protected $view;
	public function __construct(&$request,&$response,$paths) { $this->request = $request; $this->response = $response; $this->view = new SyntheticView($request); }
	public function render($file) { $this->view->render($file); }
}
class BaseApplicationPlugin {
	protected $description;
	public static $request;
	public function __construct() {}
	public function getRequest() { return self::$request; }
}
function caValidateCSRFToken($request) { return $request->csrf; }
function caNavUrl($request,$module,$controller,$action,$params=[]) { return '/'.$module.'/'.$controller.'/'.$action.'?'.http_build_query($params); }
function caNavLink($request,$label,$class,$module,$controller,$action,$params=[]) { return '<a href="'.htmlspecialchars(caNavUrl($request,$module,$controller,$action,$params),ENT_QUOTES).'">'.$label.'</a>'; }
function caFormTag($request,$action,$name,$controller,$method,$enctype,$target,$options) { return '<form method="post" action="/'.htmlspecialchars($controller.'/'.$action,ENT_QUOTES).'"><input type="hidden" name="csrfToken" value="synthetic">'; }
function _t($text,...$values) { foreach ($values as $i => $value) { $text = str_replace('%'.($i+1),$value,$text); } return $text; }

$root = realpath(sys_get_temp_dir()).'/mdpn-service-'.bin2hex(random_bytes(8)); mkdir($root,0700);
try {
	$values = ['enable' => 1,'state_directory' => $root,'python_binary' => getenv('TEST_PYTHON_BINARY') ?: '/usr/bin/python3','organization_code' => 'EXAMPLE',
		'source_organization' => 'Example Archive','contact_name' => 'Example Staff','contact_email' => 'preservation@example.org',
		'transport' => 'local','destination_id' => 'test','part_size_bytes' => 5000000,'bagit_profile' => 'generic'];
	Configuration::$values = $values;
	$config = new \MDPNPreservation\Config($values); $store = new \MDPNPreservation\Store($config,true);
	$catalogue = new RuntimeCatalogue(); $catalogue->source = $root.'/synthetic-original'; file_put_contents($catalogue->source,'synthetic original content');
	$service = new \MDPNPreservation\Service($config,$store,dirname(__DIR__),function($user) use ($catalogue) { return $catalogue; });
	$user = new ca_users(1);
	unset($GLOBALS['AUTH_CURRENT_USER_ID']);
	$first = $service->enqueue(1,'full',$user,[]);
	check($first['status'] === 'queued' && count(TaskQueue::$jobs) === 1,'Job must persist before queue dispatch');
	check($GLOBALS['AUTH_CURRENT_USER_ID'] === 1,'CLI authorization must establish native requester identity before loading ACL-protected models');
	unset($GLOBALS['AUTH_CURRENT_USER_ID']);
	$report = $service->run($first['id']);
	check($report['status'] === 'built','PHP-to-Python build failed: '.($store->get($first['id'])['error'] ?? 'unknown'));
	check($GLOBALS['AUTH_CURRENT_USER_ID'] === 1,'An isolated worker must restore the freshly loaded requester identity');
	$built = $store->get($first['id']);
	check(count($built['result']['parts']) === 1 && $built['result']['files'] === 3,'Completed local package recorded');
	check(count($built['result']['recovery_chain']) === 1,'Recovery roadmap includes full deposit');
	check($service->run($first['id'])['status'] === 'built','Duplicate queue delivery is idempotent');
	$scan = $service->enqueue(1,'scan',$user,[]); check($service->run($scan['id'])['status'] === 'scanned','Scan completes');
	check($store->get($scan['id'])['result']['changed'] === false,'Unchanged scan matches baseline');
	$catalogue->data['ca_object_labels'][1]['name'] = 'Updated synthetic object';
	$supplement = $service->enqueue(1,'supplemental',$user,[]); check($service->run($supplement['id'])['status'] === 'built','Metadata supplement completes');
	check(count($store->get($supplement['id'])['result']['recovery_chain']) === 2,'Recovery roadmap includes supplement');
	fails(function() use ($service,$user,$first) { $service->confirm($first['id'],$user,'synthetic node receipt'); },'uploaded deposit');
	$store->update($first['id'],['status' => 'uploaded']); $service->confirm($first['id'],$user,'synthetic node receipt');
	check($store->get($first['id'])['status'] === 'confirmed','Preservation requires separate explicit confirmation');
	$store->update($first['id'],['status' => 'failed']); $service->retry($first['id'],$user);
	check($service->run($first['id'])['status'] === 'built','Retry reuses immutable retained package');
	$queued = $service->enqueue(1,'full',$user,[]); ca_users::$deny = true;
	check($service->run($queued['id'])['status'] === 'failed','Worker must recheck requester permission'); ca_users::$deny = false;

	require_once __DIR__.'/../controllers/PreservationController.php';
	$request = new SyntheticRequest($user); $response = new SyntheticResponse();
	$request->parameters = ['collection_id' => 1,'mode' => 'full'];
	$controller = new PreservationController($request,$response);
	fails(function() use ($controller) { $controller->Submit(); },'valid form submission');
	$request->method = 'POST'; $request->csrf = false;
	fails(function() use ($controller) { $controller->Submit(); },'valid form submission');
	$request->csrf = true; $request->parameters = ['collection_id' => 2,'job_id' => $first['id'],'part' => 0];
	fails(function() use ($controller) { $controller->Download(); },'another collection');
	ca_users::$deny = true; fails(function() use ($controller) { $controller->Index(); },'Preservation permission'); ca_users::$deny = false;
	$request->parameters = ['collection_id' => 1];
	ob_start(); $controller->Index(); $html = ob_get_clean();
	check(strpos($html,'<script>synthetic</script>') === false && strpos($html,'&lt;script&gt;synthetic&lt;/script&gt;') !== false,'Collection labels must be escaped');
	check(strpos($html,'name="csrfToken"') !== false && strpos($html,'Local testing') !== false,'Forms include CSRF and distinguish local testing');
	check(strpos($html,'worker-result.json') === false && strpos($html,$root) === false,'Private server paths must not leak into the page');
	$sidebar = $controller->Info();
	check(strpos($sidebar,'Native collection inspector') !== false && strpos($sidebar,'Basic Info') !== false,'Preservation retains the native inspector and editor screens');
	check(strpos($sidebar,'data-screen="SyntheticScreen"') !== false,'Resolve the native screen before the inspector builds collection navigation URLs');
	check(strpos($sidebar,'sf-menu-selected') === false,'The underlying editor screen must not be highlighted on the preservation page');
	check($request->module === 'MDPNLOCKSSPreservation' && $request->controller === 'Preservation' && $request->action === 'Index' && $request->isPlugin,'Sidebar rendering must not mutate the real request or form routing');
	check(AppNavigation::$lastRequest->module === 'editor/collections' && AppNavigation::$lastRequest->controller === 'CollectionEditor' && !AppNavigation::$lastRequest->isPlugin,'Native navigation receives an isolated collection-editor request');
	ca_users::$deny = true; fails(function() use ($controller) { $controller->Info(); },'Preservation permission'); ca_users::$deny = false;
	require_once __DIR__.'/../MDPNLOCKSSPreservationPlugin.php';
	BaseApplicationPlugin::$request = $request; $plugin = new MDPNLOCKSSPreservationPlugin(dirname(__DIR__));
	$widgets = $plugin->hookRenderWidgets(['existing' => []]);
	check(isset($widgets['existing'],$widgets['mdpn_preservation_collection']) && $widgets['mdpn_preservation_collection']['handler']['isplugin'],'The preservation widget uses the supported plugin hook and preserves other widgets');
	$inspector = $plugin->hookAppendToEditorInspector(['t_item' => new ca_collections(1)])['caEditorInspectorAppend'];
	check(strpos($inspector,'<h2 ') === 0 && strpos($inspector,'>Preservation</a>') !== false && strpos($inspector,'aria-current="page"') !== false,'Preservation uses the native heading and active-page marker');
	$request->module = 'editor/collections';
	check($plugin->hookRenderWidgets(['existing' => []]) === ['existing' => []],'Other editor routes must not receive the preservation sidebar');
	$request->module = 'MDPNLOCKSSPreservation';
	require_once __DIR__.'/../lib/Tool.php';
	$tool = new \MDPNPreservation\Tool($config,dirname(__DIR__));
	ob_start(); $created = $tool->run('mdpn_preservation_create',['apply' => '1','collection-id' => '1','user-id' => '1','mode' => '',
		'contact-name' => '','contact-email' => '','description' => '']); $cli_output = ob_get_clean();
	check($created,'CLI defaults must survive empty strings from native caUtils');
	$cli_job = json_decode($cli_output,true); $store->update($cli_job['job_id'],['status' => 'cancelled']);
	ob_start(); $processed = $tool->run('mdpn_preservation_process_queue',['apply' => '1']); $queue_output = ob_get_clean();
	check($processed && TaskQueue::$processedHandlers === ['MDPNLOCKSSPreservation'],'CLI must use the native SQL handler filter, so unrelated tasks cannot block or run');
	check(strpos($queue_output,'Preservation queue processed') !== false,'Queue command reports completion without claiming a deposit succeeded');
	TaskQueue::$queueSucceeds = false;
	ob_start(); $processed = $tool->run('mdpn-preservation-process-queue',['apply' => '1']); ob_end_clean();
	check(!$processed,'A failed queue run must return a failing command status');
	TaskQueue::$queueSucceeds = true;
	define('__CA_DISABLE_ACL__',true);
	fails(function() use ($user) { \MDPNPreservation\Service::authorize($user,1); },'native item-level permission checks');
	print "Preservation subprocess, history, permissions, CSRF and editor view checks passed.\n";
} finally {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
	rmdir($root);
}
