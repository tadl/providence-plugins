<?php
// Synthetic, in-memory native boundaries. Never bootstrap installation settings.
error_reporting(E_ALL & ~E_DEPRECATED); // 2.0.10 ACL code calls strlen(null).
define('__CA_APP_DIR__', dirname(__DIR__, 3));
define('__CA_LIB_DIR__', __DIR__.'/stubs/lib');
define('__CA_MODELS_DIR__', __DIR__.'/stubs/models');
define('__CA_BASE_DIR__', __DIR__);
define('__CA_ACL_EDIT_DELETE_ACCESS__', 3);
define('__CA_ACL_READONLY_ACCESS__', 1);
define('pInteger', 1);
function _t($text) { return $text; }
function caGetOption($key, $options, $default) { return $options[$key] ?? $default; }
function caAppIsProvidence() { return true; }
function caMakeRelationshipTypeIDList($table, $types) { return $types === ['part_of'] ? [1] : []; }
function caACLIsEnabled($model, $options) {
	$config = Configuration::load();
	return $config->get('perform_item_level_access_checking') && !$config->get($model->table.'_dont_do_item_level_access_control');
}
class Configuration {
	public static $app;
	public static $plugin;
	public $values;
	public function __construct($values) { $this->values = $values; }
	public static function load($path=null) { return $path && str_contains($path, '/plugins/') ? self::$plugin : self::$app; }
	public function get($key) { return $this->values[$key] ?? null; }
	public function getAssoc($key) { return $this->get($key); }
}
class SyntheticResult {
	private $rows;
	private $index = -1;
	public function __construct($rows) { $this->rows = $rows; }
	public function nextRow() { return isset($this->rows[++$this->index]); }
	public function getRow() { return $this->rows[$this->index]; }
	public function get($key) { return $this->getRow()[$key] ?? null; }
	public function getAllFieldValues($key) { return array_column($this->rows, $key); }
}
class Db {
	public static $pdo;
	public static $locked = false;
	public static $fail_on_acl_insert = false;
	public function query($sql, ...$args) {
		$params = count($args) === 1 && is_array($args[0]) ? $args[0] : $args;
		if (str_contains($sql, 'GET_LOCK(')) { return new SyntheticResult([['acquired' => self::$locked ? 0 : 1]]); }
		if (str_contains($sql, 'RELEASE_LOCK(')) { return new SyntheticResult([]); }
		if (self::$fail_on_acl_insert && str_starts_with($sql, 'INSERT INTO ca_acl')) { throw new RuntimeException('Synthetic write failure'); }
		$flat = [];
		$i = 0;
		$sql = preg_replace_callback('/\?/', function() use (&$params, &$flat, &$i) {
			$value = $params[$i++];
			if (is_array($value)) { array_push($flat, ...$value); return $value ? join(',', array_fill(0, count($value), '?')) : 'NULL'; }
			$flat[] = $value;
			return '?';
		}, $sql);
		$stmt = self::$pdo->prepare($sql);
		$stmt->execute($flat);
		return new SyntheticResult($stmt->fetchAll(PDO::FETCH_ASSOC));
	}
	public function numErrors() { return 0; }
	public function getTransactionCount() { return self::$pdo->inTransaction() ? 1 : 0; }
}
class SyntheticModel {
	public $table;
	public function __construct($table) { $this->table = $table; }
	public function getProperty($key) { return $key === 'SUPPORTS_ACL' && $this->table !== 'ca_users'; }
}
class Datamodel {
	const TABLES = ['ca_objects' => 57, 'ca_collections' => 13, 'ca_entities' => 20, 'ca_places' => 72, 'ca_occurrences' => 67, 'ca_users' => 1];
	public static function getTableNames() { return array_keys(self::TABLES); }
	public static function getTableNum($table) { return self::TABLES[$table]; }
	public static function getInstanceByTableName($table, $cache) { return new SyntheticModel($table); }
	public static function getInstanceByTableNum($num, $cache) { return new SyntheticModel(array_search($num, self::TABLES)); }
	public static function getInstance($num, $cache) { return self::getInstanceByTableNum($num, $cache); }
}
class ca_users {
	private $id;
	public function __construct($id=0) { $this->id = $id; }
	public function getPrimaryKey() { return $this->id; }
	public function canDoAction($action) { return $this->id === 90; }
	public function hasRole($role) { return in_array($this->id, [12, 34, 56]); }
	public function getDb() { return new Db(); }
	public static function clearCaches() {}
	public function getUserGroups() {
		$result = (new Db())->query('SELECT group_id FROM ca_users_x_groups WHERE user_id = ?', $this->id);
		return array_fill_keys($result->getAllFieldValues('group_id'), true);
	}
}
class ca_collections {
	public static function getHierarchyChildrenForIDs($roots, $options) {
		$rows = Db::$pdo->query('SELECT collection_id, parent_id FROM ca_collections WHERE deleted = 0')->fetchAll(PDO::FETCH_KEY_PAIR);
		$allowed = $roots;
		do {
			$old = $allowed;
			foreach ($rows as $id => $parent) { if (in_array($parent, $allowed)) { $allowed[] = $id; } }
			$allowed = array_values(array_unique($allowed));
		} while ($old !== $allowed);
		return $allowed;
	}
}
class ca_objects {
	private $id;
	public $fields = [];
	public function __construct($id=0) { $this->load($id); }
	public function load($id) { $this->id = $id; return (bool)$this->getPrimaryKey(); }
	public function getPrimaryKey() { return $this->id; }
	public function getTransaction() { return null; }
	public function getDb() { return new Db(); }
	public function set($key, $value) { $this->fields[$key] = $value; }
	public function get($field) {
		$r = (new Db())->query('SELECT '.$field.' FROM ca_objects WHERE object_id = ?', $this->id);
		return $r->nextRow() ? $r->get($field) : null;
	}
	public function getRelatedItems($table, $options) {
		$sql = 'SELECT collection_id FROM ca_objects_x_collections WHERE object_id = ?';
		if (isset($options['restrictToRelationshipTypes'])) { $sql .= ' AND type_id = 1'; }
		return (new Db())->query($sql, $this->id)->getAllFieldValues('collection_id');
	}
}
class ca_user_groups {
	private $values = [];
	private $id;
	public function setTransaction($transaction) {}
	public function set($key, $value) { $this->values[$key] = $value; }
	public function insert() {
		(new Db())->query('INSERT INTO ca_user_groups (name, code, description, for_public_use) VALUES (?, ?, ?, ?)', array_values($this->values));
		$this->id = Db::$pdo->lastInsertId();
	}
	public function numErrors() { return 0; }
	public function getPrimaryKey() { return $this->id; }
}
class MemoryCache { public static $flushed = []; public static function flush($ns) { self::$flushed[] = $ns; } }
class ExternalCache { public static function flush($ns) {} }
class Session {
	public static $vars = [];
	public static function getVar($key) { return static::$vars[$key] ?? null; }
	public static function setVar($key, $value) { static::$vars[$key] = $value; }
}
class ResultContextStorage extends Session {
	public static $vars = [];
	public static $saved = 0;
	public static $fail_save = false;
	public static function init($request) {}
	public static function save() { self::$saved++; return !self::$fail_save; }
}
class ApplicationException extends RuntimeException {}
class BaseApplicationPlugin {
	protected $description;
	public function __construct() {}
	public function getRequest() { return AppController::getInstance()->getRequest(); }
	public function getDescription() { return $this->description; }
}
class AppController {
	public static $instance;
	public $plugins = [];
	public $request;
	public static function instanceExists() { return self::$instance !== null; }
	public static function getInstance() { return self::$instance; }
	public function registerPlugin($plugin) { $plugin->request = $this->request; $this->plugins[] = $plugin; }
	public function getRequest() { return $this->request; }
}
class SyntheticRequest {
	public $user;
	public $method = 'GET';
	public $params = [];
	public function __construct($id=12) { $this->user = new ca_users($id); }
	public function getRequestMethod() { return $this->method; }
	public function getParameter($name, $type) { return $this->params[$name] ?? 0; }
}

// Execute only these two methods from the trusted reference, without loading
// its bootstrap, database credentials or any other installation configuration.
$reference = getenv('PROVIDENCE_REFERENCE_ROOT') ?: dirname(__DIR__, 4).'/providence';
function nativeMethod($path, $method) {
	$source = file_get_contents($path);
	if (!$source || !preg_match('/\tpublic (?:static )?function '.preg_quote($method, '/').'\(.*?(?=\n\t# -)/s', $source, $matches)) {
		throw new RuntimeException('Trusted Providence ACL method not found: '.$method);
	}
	return $matches[0];
}
eval('class ca_acl { public static $s_acl_access_value_cache = []; public static function clearAccessValueCache() { self::$s_acl_access_value_cache = []; } '.nativeMethod($reference.'/app/models/ca_acl.php', 'accessForRow').'}');
eval('class NativeFindFixture {
	public $db;
	public function __construct() { $this->db = new Db(); }
	public function loadListIntoTemporaryResultTable($hits, $key) {
		Db::$pdo->exec("CREATE TEMP TABLE native_hits (row_id INTEGER)");
		foreach ($hits as $id) { $this->db->query("INSERT INTO native_hits VALUES (?)", $id); }
		return "native_hits";
	}
	public function cleanupTemporaryResultTable() { Db::$pdo->exec("DROP TABLE native_hits"); }
	'.nativeMethod($reference.'/app/lib/BaseFindEngine.php', 'filterHitsByACL').'}');

require_once dirname(__DIR__).'/groupCollectionRootMapPlugin.php';
require_once dirname(__DIR__).'/lib/CollectionAclRuntime.php';
require_once dirname(__DIR__).'/lib/CollectionAclTool.php';
