<?php
// No Providence bootstrap, connection settings, sessions or databases are loaded.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});
define('__CA_MODELS_DIR__', __DIR__.'/stubs');
define('__CA_BUNDLE_ACCESS_EDIT__', 2);
define('__CA_ACL_READONLY_ACCESS__', 1);
define('__CA_NAV_ICON_ADD__', 'add');

class Configuration {
	public static $enabled = null;
	private $values;
	public function __construct($values=[]) { $this->values = $values; }
	public static function load($path) {
		preg_match('/^enable\s*=\s*([01])\s*$/m', file_get_contents($path), $match);
		$value = (int)($match[1] ?? 0);
		if (basename($path) === 'plugin.conf' && self::$enabled !== null) { $value = self::$enabled; }
		return new self(['enable' => $value]);
	}
	public function get($key) { return $this->values[$key] ?? false; }
	public function getScalar($key) { return $this->get($key); }
	public function set($key, $value) { $this->values[$key] = $value; }
}

class BaseApplicationPlugin {
	protected $description;
	public function __construct() {}
	public function getDescription() { return $this->description; }
	public function getRequest() { return null; }
}

class SyntheticUser {
	public $can_create = true;
	public $bundle_access = 2;
	public function canDoAction($action) { return $action === 'can_create_ca_objects' && $this->can_create; }
	public function getBundleAccessLevel($table, $bundle) { return $this->bundle_access; }
}

class SyntheticRequest {
	public $user;
	public $editor_url = '/index.php/editor/objects/ObjectEditor/Edit/object_id/0';
	private $config;
	public function __construct() {
		$this->user = new SyntheticUser();
		$this->config = new Configuration([
			'ca_objects_x_collections_hierarchy_enabled' => 1,
			'ca_objects_enforce_strict_type_hierarchy' => 0,
			'ca_objects_x_collections_hierarchy_relationship_type' => 'part_of'
		]);
	}
	public function getAppConfig() { return $this->config; }
}

class Session {
	public static function getVar($key) { return 'under'; }
}

function _t($message, ...$values) {
	foreach ($values as $i => $value) { $message = str_replace('%'.($i + 1), $value, $message); }
	return $message;
}
function caEditorUrl($request, $table, $id) { return $request->editor_url; }
function caNavIcon($icon, $size) { return '<span class="synthetic-icon" aria-hidden="true">+</span>'; }
function caHTMLSelect($name, $choices, $attributes, $options) {
	$html = '<select name="'.$name.'" id="'.$attributes['id'].'">';
	foreach ($choices as $label => $value) {
		$html .= '<option value="'.$value.'"'.($options['value'] === $value ? ' selected' : '').'>'.$label.'</option>';
	}
	return $html.'</select>';
}

require_once dirname(__DIR__).'/collectionSiblingPlugin.php';

class SyntheticHierarchyView {
	public $request;
	public function __construct($request) { $this->request = $request; }
	public function render($fragment, $variables) {
		extract($variables);
		ob_start();
		try { eval('?>'.$fragment); return ob_get_contents(); }
		finally { ob_end_clean(); }
	}
}

function nativeHierarchyFixture($request, $parent_id=0, $show_add=true, $prefix='P17ObjectEditorForm') {
	$reference = getenv('PROVIDENCE_REFERENCE_ROOT') ?: dirname(__DIR__, 4).'/providence';
	$source = file_get_contents($reference.'/themes/default/views/bundles/hierarchy_location.php');
	$marker = strpos($source, '<div id="<?= $id_prefix; ?>HierarchyBrowserTabs-add"');
	$start = $marker === false ? false : strrpos(substr($source, 0, $marker), "\tif (\$show_add) {");
	$end = $start === false ? false : strpos($source, "\tif (\$show_add_object) {", $start);
	$js_start = strpos($source, "\tif (typeof  _navigateToNewForm != 'function') {");
	$js_end = $js_start === false ? false : strpos($source, "\tif (typeof  _navigateToNewObjectForm != 'function') {", $js_start);
	if ($start === false || $end === false || $js_start === false || $js_end === false) {
		throw new RuntimeException('Native hierarchy template changed: review the plugin integration.');
	}
	$variables = [
		'show_add' => $show_add, 'id_prefix' => $prefix, 'strict_type_hierarchy' => false,
		'parent_id' => $parent_id, 'id' => 101, 't_subject' => new ca_objects(), 'hier_browser_dim_style' => '',
		'subject_label' => 'Synthetic photograph', 'subject_table' => 'ca_objects',
		'type_selector' => '<select id="'.$prefix.'typeList" aria-label="Object type"><option value="11">Still image</option><option value="12">Document</option></select>'
	];
	$view = new SyntheticHierarchyView($request);
	$html = $view->render('<?php'."\n".substr($source, $start, $end - $start), $variables);
	$script = $view->render(substr($source, $js_start, $js_end - $js_start), $variables);
	return $html.'<script>'.$script.'</script>';
}
