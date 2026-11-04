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
require_once __CA_LIB_DIR__.'/TaskQueue.php';

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
	public static $paths = [];
	private $settings;
	public function __construct(array $settings) { $this->settings = $settings; }
	public static function load($path) { return new self(self::$paths[$path] ?? self::$values); }
	public function get($key) { $value = $this->getScalar($key); return ($value === false || $value === '') ? null : $value; }
	public function getScalar($key) { return $this->settings[$key] ?? false; }
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
	$configurationRoot = $root.'/configuration'; mkdir($configurationRoot.'/conf',0700,true);
	file_put_contents($configurationRoot.'/conf/plugin.local.conf','# Synthetic partial local configuration');
	Configuration::$paths[$configurationRoot.'/conf/plugin.conf'] = array_merge($values,['part_size_bytes' => '50000000000']);
	Configuration::$paths[$configurationRoot.'/conf/plugin.local.conf'] = ['enable' => '0','contact_name' => ''];
	$inherited = \MDPNPreservation\Config::load($configurationRoot);
	check($inherited->partSizeBytes() === 50000000000,'Missing local keys must inherit shared defaults when native get() returns null');
	check(!$inherited->enabled() && $inherited->get('contact_name','') === '','Explicit zero and blank local overrides must not inherit shared values');
	Configuration::$paths[$configurationRoot.'/conf/plugin.local.conf']['part_size_bytes'] = '7000000';
	check(\MDPNPreservation\Config::load($configurationRoot)->partSizeBytes() === 7000000,'An explicit local byte limit must override the shared default');
	Configuration::$paths[$configurationRoot.'/conf/plugin.local.conf']['part_size_bytes'] = '';
	fails(function() use ($configurationRoot) { \MDPNPreservation\Config::load($configurationRoot)->partSizeBytes(); },'positive whole number of bytes');
	foreach ([null,0,'0',-1,1.25,'50GB','9223372036854775808'] as $invalid) {
		fails(function() use ($invalid) { (new \MDPNPreservation\Config(['part_size_bytes' => $invalid]))->partSizeBytes(); },'positive whole number of bytes');
	}
	$config = new \MDPNPreservation\Config($values); $store = new \MDPNPreservation\Store($config,true);
	$catalogue = new RuntimeCatalogue(); $catalogue->source = $root.'/synthetic-original'; file_put_contents($catalogue->source,'synthetic original content');
	$catalogue->data['ca_object_representation_multifiles'] = [1 => ['multifile_id' => 1,'representation_id' => 20,'resource_path' => '1']];
	$catalogue->generatedPreviews['ca_object_representation_multifiles:1'] = ['kind' => 'document_page','source_file_key' => 'ca_object_representations:20','resource_path' => '1','disposition' => 'regenerate'];
	$service = new \MDPNPreservation\Service($config,$store,dirname(__DIR__),function($user) use ($catalogue) { return $catalogue; });
	$user = new ca_users(1);
	$reference = getenv('PROVIDENCE_REFERENCE_ROOT') ?: dirname(__DIR__,4).'/providence';
	check(is_readable($reference.'/install/inc/schema_mysql.sql'),'A trusted Providence SQL schema is required for queue compatibility checks');
	$schema = file_get_contents($reference.'/install/inc/schema_mysql.sql');
	check(preg_match('/create\s+table\s+ca_task_queue\s*\((.*?)\)\s*engine/is',$schema,$table) && preg_match('/\bhandler\s+varchar\((\d+)\)/i',$table[1],$field),'Native queue handler width must be discoverable');
	check(strlen(\MDPNPreservation\Service::QUEUE_HANDLER) <= (int)$field[1],'The internal handler must fit the native task queue column without a schema change');
	check(is_file(__DIR__.'/../queue/'.\MDPNPreservation\Service::QUEUE_HANDLER.'.php'),'The native handler identifier must resolve to its plugin file');
	unset($GLOBALS['AUTH_CURRENT_USER_ID']);
	foreach (['full','supplemental','scan'] as $mode) {
		fails(function() use ($service,$user,$mode) { $service->enqueue(2,$mode,$user,[]); },'Only top-level archival collections');
	}
	check(!$store->history(2) && !TaskQueue::$jobs,'Subordinate requests fail before history or queue writes');
	$submittedDescription = str_repeat('Synthetic transfer description. ',180).'Complete transfer ending.';
	$submittedInternalDescription = 'Fictional collection. Complete internal narrative.';
	$first = $service->enqueue(1,'full',$user,['description' => $submittedDescription,'internal_description' => $submittedInternalDescription]);
	check($first['status'] === 'queued' && count(TaskQueue::$jobs) === 1,'Job must persist before queue dispatch');
	check(TaskQueue::$addedHandlers === [\MDPNPreservation\Service::QUEUE_HANDLER],'Queue insertion must use the short native handler identifier');
	check($GLOBALS['AUTH_CURRENT_USER_ID'] === 1,'CLI authorization must establish native requester identity before loading ACL-protected models');
	unset($GLOBALS['AUTH_CURRENT_USER_ID']);
	$report = $service->run($first['id']);
	check($report['status'] === 'built','PHP-to-Python build failed: '.($store->get($first['id'])['error'] ?? 'unknown'));
	check($GLOBALS['AUTH_CURRENT_USER_ID'] === 1,'An isolated worker must restore the freshly loaded requester identity');
	$built = $store->get($first['id']);
	check($built['metadata']['External-Description'] === $submittedDescription,'Queued metadata retains the complete description beyond 2000 bytes');
	check($built['metadata']['Internal-Sender-Description'] === $submittedInternalDescription,'Queued metadata retains the separately reviewed internal description');
	check(count($built['result']['parts']) === 1 && $built['result']['files'] === 3,'Completed local package recorded');
	$saved = json_decode(file_get_contents($store->directory($first['id']).'/result.json'),true,512,JSON_THROW_ON_ERROR);
	check($saved['parts'][0]['filename'] === 'EXAMPLE_C1_001.tar','PHP-to-Python packaging omits the part suffix for a single bag');
	check($built['result']['bag_info'] === $saved['bag_info'] && strpos($built['result']['bag_info']['EXAMPLE_C1_001'],
		'Internal-Sender-Description: '.$submittedInternalDescription."\n") !== false,'The ledger keeps the exact generated bag-info text separately from the recovery roadmap');
	check($saved['snapshot']['records']['ca_object_representation_multifiles'][1]['generated_preview']['disposition'] === 'regenerate'
		&& !in_array('ca_object_representation_multifiles:1',array_column($saved['snapshot']['files'],'key'),true),'PHP-to-Python packaging retains preview regeneration metadata without copying generated media');
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
	$moved = $service->enqueue(99,'full',$user,[]); $catalogue->data['ca_collections'][99]['parent_id'] = 1;
	check($service->run($moved['id'])['status'] === 'failed' && !is_file($store->directory($moved['id']).'/request.json'),'Worker eligibility is rechecked after a root is moved beneath another collection');
	$catalogue->data['ca_collections'][99]['parent_id'] = null;
	$store->update($first['id'],['status' => 'failed']); $catalogue->data['ca_collections'][1]['parent_id'] = 99;
	fails(function() use ($service,$first,$user) { $service->retry($first['id'],$user); },'Only top-level archival collections');
	check($store->get($first['id'])['status'] === 'failed','Ineligible retries do not mutate their recorded status');
	$catalogue->data['ca_collections'][1]['parent_id'] = null; $service->retry($first['id'],$user); $service->run($first['id']);
	$queued = $service->enqueue(1,'full',$user,[]); ca_users::$deny = true;
	check($service->run($queued['id'])['status'] === 'failed','Worker must recheck requester permission'); ca_users::$deny = false;
	$capturedInvalid = false;
	$invalidCatalogue = new class extends RuntimeCatalogue {
		public $onCapture;
		public function rows(string $table, ?string $field=null, array $ids=[]) : array { ($this->onCapture)(); throw new RuntimeException('Invalid limits must be rejected before capture.'); }
	};
	$invalidCatalogue->onCapture = function() use (&$capturedInvalid) { $capturedInvalid = true; };
	$invalidService = new \MDPNPreservation\Service(new \MDPNPreservation\Config(array_merge($values,['part_size_bytes' => null])),$store,dirname(__DIR__),function($user) use ($invalidCatalogue) { return $invalidCatalogue; });
	$invalidJob = $invalidService->enqueue(1,'full',$user,[]);
	check($invalidService->run($invalidJob['id'])['status'] === 'failed' && !$capturedInvalid,'An invalid part limit must fail before catalogue capture and original-file staging');
	check(strpos($store->get($invalidJob['id'])['error'],'positive whole number of bytes') !== false && !is_file($store->directory($invalidJob['id']).'/request.json'),'Invalid-limit failures must be actionable and write no metadata request');

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
	$longDescription = str_repeat('Synthetic narrative. ',180).'Complete description ending.';
	ca_collections::$descriptionFields = ['ca_collections.description' => ['<p>Short fictional summary.</p><p>'.$longDescription.'</p>'],
		'ca_collections.collection_scope_content' => ['Fictional scope and content.'],'ca_collections.provenance' => ['Example provenance.']];
	ob_start(); $controller->Index(); $html = ob_get_clean();
	check(strpos($html,'<script>synthetic</script>') === false && strpos($html,'&lt;script&gt;synthetic&lt;/script&gt;') !== false,'Collection labels must be escaped');
	check(strpos($html,'name="csrfToken"') !== false && strpos($html,'Local testing') !== false,'Forms include CSRF and distinguish local testing');
	check(strpos($html,'worker-result.json') === false && strpos($html,$root) === false,'Private server paths must not leak into the page');
	$dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
	$descriptionField = (new DOMXPath($dom))->query('//textarea[@id="mdpn-description"]')->item(0);
	$internalField = (new DOMXPath($dom))->query('//textarea[@id="mdpn-internal-description"]')->item(0);
	check($descriptionField && $descriptionField->textContent === 'Short fictional summary.' && !$descriptionField->hasAttribute('readonly'),'The external description is prefilled from the first paragraph and remains editable');
	check($internalField && !$internalField->hasAttribute('maxlength') && !$internalField->hasAttribute('readonly') && strpos($internalField->textContent,$longDescription) !== false
		&& strpos($internalField->textContent,'Scope and content: Fictional scope and content.') !== false
		&& strpos($internalField->textContent,'Provenance: Example provenance.') !== false,'Editor exposes the complete, editable internal narrative without truncation');
	$bagInfo = (new DOMXPath($dom))->query('//textarea[starts-with(@id,"mdpn-bag-info-")]');
	check($bagInfo->length > 0,'Completed bags expose their bag-info.txt in history');
	foreach ($bagInfo as $field) {
		check($field->hasAttribute('readonly') && !$field->hasAttribute('name'),'Bag-info text is selectable and read-only, without becoming form input');
		$details = $field->parentNode->parentNode;
		check($details->nodeName === 'details' && !$details->hasAttribute('open'),'Each bag-info section is collapsed by default and uses native expandable controls');
	}
	$unsafe = $store->get($first['id'])['result']; $safe = $unsafe;
	$unsafe['bag_info']['EXAMPLE_C1_001'] = "External-Description: </textarea><script>unsafeBagInfo()</script>\n";
	$store->update($first['id'],['result' => $unsafe]); ob_start(); $controller->Index(); $unsafeHtml = ob_get_clean();
	check(strpos($unsafeHtml,'<script>unsafeBagInfo()</script>') === false && strpos($unsafeHtml,'&lt;/textarea&gt;&lt;script&gt;unsafeBagInfo()&lt;/script&gt;') !== false,'Bag-info text cannot escape its textarea or execute HTML');
	$store->update($first['id'],['result' => $safe]);
	ca_collections::$descriptionFields = [];
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
	Configuration::$values = array_merge($values,['organizational_unit_element' => 'internal_notes']);
	ca_collections::$parents = [1 => 100,2 => 1]; ca_collections::$notes = [100 => '[[organizational_unit]]'];
	$policyPlugin = new MDPNLOCKSSPreservationPlugin(dirname(__DIR__));
	check(isset($policyPlugin->hookAppendToEditorInspector(['t_item' => new ca_collections(1)])['caEditorInspectorAppend']),'Eligible collection below an organizational unit retains Preservation');
	check(!isset($policyPlugin->hookAppendToEditorInspector(['t_item' => new ca_collections(2)])['caEditorInspectorAppend']),'Subordinate records have no Preservation menu item');
	check(!isset($policyPlugin->hookAppendToEditorInspector(['t_item' => new ca_collections(100)])['caEditorInspectorAppend']),'Organizational units have no Preservation menu item');
	$request->parameters = ['collection_id' => 2]; $readOnlyController = new PreservationController($request,$response);
	ob_start(); $readOnlyController->Index(); $childHtml = ob_get_clean();
	check(strpos($childHtml,'mdpnCreateDeposit') === false && strpos($childHtml,'mdpnScanCollection') === false && strpos($childHtml,'View preservation for the archival collection') !== false,'Direct subordinate pages show the parent link and no preservation forms');
	Configuration::$values = $values; ca_collections::$parents = [2 => 1]; ca_collections::$notes = []; $request->parameters = ['collection_id' => 1];
	require_once __DIR__.'/../lib/Tool.php';
	$tool = new \MDPNPreservation\Tool($config,dirname(__DIR__),function($user) use ($catalogue) { return $catalogue; });
	ob_start(); $created = $tool->run('mdpn_preservation_create',['apply' => '1','collection-id' => '1','user-id' => '1','mode' => '',
		'contact-name' => '','contact-email' => '','description' => '']); $cli_output = ob_get_clean();
	check($created,'CLI defaults must survive empty strings from native caUtils');
	$cli_job = json_decode($cli_output,true); $store->update($cli_job['job_id'],['status' => 'cancelled']);
	ob_start(); $processed = $tool->run('mdpn_preservation_process_queue',['apply' => '1']); $queue_output = ob_get_clean();
	check($processed && TaskQueue::$processedHandlers === [\MDPNPreservation\Service::QUEUE_HANDLER],'CLI must select the same native handler used when queueing jobs');
	check(strpos($queue_output,'Preservation queue processed') !== false,'Queue command reports completion without claiming a deposit succeeded');
	TaskQueue::$queueSucceeds = false;
	ob_start(); $processed = $tool->run('mdpn-preservation-process-queue',['apply' => '1']); ob_end_clean();
	check(!$processed,'A failed queue run must return a failing command status');
	TaskQueue::$queueSucceeds = true;
	$mdpnRoot = $root.'/mdpn'; mkdir($mdpnRoot,0700); $fetches = 0; $networkAvailable = true;
	$mdpnValues = array_merge($values,['state_directory' => $mdpnRoot,'bagit_profile' => 'mdpn','source_organization' => 'Example Member Archive']);
	$mdpnConfig = new \MDPNPreservation\Config($mdpnValues,static function() use (&$fetches,&$networkAvailable) {
		$fetches++; if (!$networkAvailable) { throw new \MDPNPreservation\Failure('Synthetic upstream outage'); }
		return file_get_contents(__DIR__.'/fixtures/mdpn-dart-settings.json');
	});
	$mdpnStore = new \MDPNPreservation\Store($mdpnConfig,true);
	$mdpnTool = new \MDPNPreservation\Tool($mdpnConfig,dirname(__DIR__));
	ob_start(); $initialized = $mdpnTool->run('mdpn-preservation-init',['apply' => '1']); ob_end_clean();
	check($initialized && $fetches === 1,'MDPN initialization seeds the canonical settings cache');
	ob_start(); $refreshed = $mdpnTool->run('mdpn-preservation-refresh-profile',['apply' => '1']); ob_end_clean();
	check($refreshed && $fetches === 2 && !$mdpnStore->history(1),'The explicit refresh command updates policy without creating preservation jobs');
	$mdpnService = new \MDPNPreservation\Service($mdpnConfig,$mdpnStore,dirname(__DIR__),function($user) use ($catalogue) { return $catalogue; });
	$mdpnJob = $mdpnService->enqueue(1,'full',$user,[]);
	check($fetches === 3 && $mdpnService->run($mdpnJob['id'])['status'] === 'built' && $fetches === 4,'MDPN submission and fresh packaging validate the current canonical policy');
	$mdpnResult = json_decode(file_get_contents($mdpnStore->directory($mdpnJob['id']).'/result.json'),true);
	check(isset($mdpnResult['snapshot']['schema']['bagit_profile']['source_profile_sha256']),'Each deposit records the exact imported policy and its fingerprint');
	$networkAvailable = false; $mdpnStore->update($mdpnJob['id'],['status' => 'failed']);
	$mdpnService->retry($mdpnJob['id'],$user);
	check($mdpnService->run($mdpnJob['id'])['status'] === 'built' && $fetches === 4,'Retained-package retries use immutable bytes without downloading new policy');
	$count = count($mdpnStore->history(1));
	fails(function() use ($mdpnService,$user) { $mdpnService->enqueue(1,'full',$user,[]); },'Synthetic upstream outage');
	check(count($mdpnStore->history(1)) === $count,'An upstream outage cannot create a new job using stale cached settings');
	define('__CA_DISABLE_ACL__',true);
	fails(function() use ($user) { \MDPNPreservation\Service::authorize($user,1); },'native item-level permission checks');
	print "Preservation subprocess, history, permissions, CSRF and editor view checks passed.\n";
} finally {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
	rmdir($root);
}
