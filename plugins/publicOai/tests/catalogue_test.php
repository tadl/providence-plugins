<?php
/** Synthetic native boundaries: no installation bootstrap, records, or network. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL & ~E_DEPRECATED);
define('__CA_ACL_READONLY_ACCESS__', 1);
define('__CA_ACL_EDIT_DELETE_ACCESS__', 3);
define('__CA_BUNDLE_ACCESS_READONLY__', 1);
function caGetOption($key, $options, $default=null) { return $options[$key] ?? $default; }
function caACLIsEnabled($model, $options=[]) { return true; }
function caSourceAccessControlIsEnabled($model) { return false; }
function caGetMediaIconForSize($code, $width, $height) { return false; }

// Use the real BaseModel media API; SearchResult-only APIs must not be stubbed here.
$reference = getenv('PROVIDENCE_REFERENCE_ROOT') ?: dirname(__DIR__, 4).'/providence';
$baseSource = file_get_contents($reference.'/app/lib/BaseModel.php');
$mediaMethods = '';
foreach (['getMediaInfo', 'getMediaUrl'] as $method) {
	if (!preg_match('/\tpublic function\s+&?'.preg_quote($method, '/').'\(.*?(?=\n\t(?:\/\*\*|# -))/s', $baseSource, $match)) {
		throw new RuntimeException('Trusted native model media method was not found: '.$method);
	}
	$mediaMethods .= $match[0];
}
eval('trait NativeModelMedia { '.$mediaMethods.' }');
class Configuration {
	public static function load($path=null) { return new self(); }
	public function get($key) { return $key === 'default_item_access_level' ? 1 : false; }
}
class SyntheticResult {
	private $rows; private $position = -1;
	public function __construct(array $rows) { $this->rows = $rows; }
	public function nextRow() { return isset($this->rows[++$this->position]); }
	public function getRow() { return $this->rows[$this->position]; }
	public function get($field) { return $this->getRow()[$field] ?? null; }
}
class Db {
	public static $pdo;
	public function query($sql, ...$params) {
		if (count($params) === 1 && is_array($params[0])) { $params = $params[0]; }
		$statement = self::$pdo->prepare($sql); $statement->execute($params);
		return new SyntheticResult($statement->fetchAll(PDO::FETCH_ASSOC));
	}
	public function numErrors() { return 0; }
}
class ca_users {
	private $id; public static $denied = [];
	public function __construct($id=0) { $this->id = $id; }
	public function getPrimaryKey() { return $this->id; }
	public function getDb() { return new Db(); }
	public function getUserGroups() { return []; }
	public function getBundleAccessLevel($table, $bundle) { return in_array($table.'.'.$bundle, self::$denied, true) ? 0 : 1; }
	public function getTypeAccessLevel($table, $type) { return 1; }
	public function getSourceAccessLevel($table, $type) { return 1; }
}
class Datamodel {
	public const TABLES = ['ca_objects' => 57, 'ca_object_representations' => 56, 'ca_entities' => 20,
		'ca_places' => 72, 'ca_collections' => 13, 'ca_list_items' => 33, 'ca_lists' => 32];
	public static function getInstanceByTableName($table, $cache=false) { return new SyntheticModel($table); }
	public static function getInstance($number, $cache=false) { return new SyntheticModel(array_search($number, self::TABLES, true)); }
}
class SyntheticModel {
	use NativeModelMedia;
	public static $records = [];
	public static $loadedWithCurrentUser = 0;
	private $table; private $id;
	private $aclDisabled = false;
	public function __construct($table) { $this->table = $table; }
	public function disableACL($disabled=true) { $this->aclDisabled = $disabled; }
	public function load($id) {
		if (!$this->aclDisabled) { self::$loadedWithCurrentUser++; return false; }
		$this->id = $id; return isset(self::$records[$this->table][$id]);
	}
	public function tableName() { return $this->table; }
	public function tableNum() { return Datamodel::TABLES[$this->table]; }
	public function getPrimaryKey() { return $this->id; }
	public function getDb() { return new Db(); }
	public function getAppConfig() { return new Configuration(); }
	public function hasField($field) { return in_array($field, ['access', 'deleted', 'type_id', 'idno', 'list_id', 'list_code'], true); }
	public function hasElement($field) {
		foreach (array_keys(self::$records[$this->table][$this->id]) as $key) {
			if ($key === $field || strpos($key, $field.'.') === 0) { return true; }
		}
		return false;
	}
	public function get($field, $options=[]) {
		$field = preg_replace('/^'.preg_quote($this->table, '/').'\./', '', $field);
		if ($field === 'media') {
			$descriptor = self::$records[$this->table][$this->id]['media_descriptor'] ?? null;
			if (!is_array($descriptor)) { return []; }
			foreach (self::$records[$this->table][$this->id]['media_urls'] ?? [] as $version => $url) {
				$descriptor[$version]['EXTERNAL_URL'] = $url;
			}
			return [$descriptor];
		}
		return self::$records[$this->table][$this->id][$field] ?? null;
	}
	public function getLastChangeTimestamp() { return ['timestamp' => (int)$this->get('modified')]; }
	public function getTypeName() { return $this->get('type_name') ?: 'Still image'; }
	public function getTypeCode() { return $this->get('type_code') ?: 'still_image'; }
	public function getTypeID() { return 1; }
}

// Execute the native anonymous/world ACL implementation against SQLite.
$source = file_get_contents($reference.'/app/models/ca_acl.php');
if (!preg_match('/\tpublic static function accessForRow\(.*?(?=\n\t# -)/s', $source, $match)) {
	throw new RuntimeException('Trusted native ACL method was not found.');
}
eval('class ca_acl { public static $s_acl_access_value_cache = []; '.$match[0].'}');
require_once __DIR__.'/../lib/Catalogue.php';
use TADL\PublicOai\Catalogue;

$checks = 0;
function check($condition, string $message): void {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
$modelSource = $baseSource.file_get_contents($reference.'/app/lib/BaseModelWithAttributes.php').file_get_contents($reference.'/app/lib/LabelableBaseModelWithAttributes.php').file_get_contents($reference.'/app/lib/BundlableLabelableBaseModelWithAttributes.php');
foreach (['load', 'disableACL', 'hasField', 'hasElement', 'get', 'getDb', 'getLastChangeTimestamp', 'getTypeName', 'getTypeCode', 'getTypeID', 'getPrimaryKey', 'tableName', 'tableNum'] as $method) {
	check((bool)preg_match('/function\s+&?'.preg_quote($method, '/').'\s*\(/', $modelSource), 'Catalogue method exists on trusted native models: '.$method);
}
function configuration(): array {
	return ['enable' => true, 'provider_key' => 'objects', 'public_base_url' => 'https://catalogue.example.org',
		'media_base_url' => 'https://media.example.org', 'allowed_media_hosts' => ['media.example.org'], 'repository_name' => 'Example Archive', 'item_acl_policy' => 'native'];
}
function fixture(): void {
	ca_users::$denied = []; ca_acl::$s_acl_access_value_cache = [];
	SyntheticModel::$loadedWithCurrentUser = 0;
	Db::$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	Db::$pdo->exec('CREATE TABLE ca_acl (table_num INTEGER,row_id INTEGER,user_id INTEGER,group_id INTEGER,access INTEGER);
		CREATE TABLE ca_objects_x_object_representations (relation_id INTEGER,object_id INTEGER,representation_id INTEGER,is_primary INTEGER,rank INTEGER);
		CREATE TABLE ca_objects_x_entities (relation_id INTEGER,object_id INTEGER,entity_id INTEGER,type_id INTEGER,rank INTEGER);
		CREATE TABLE ca_objects_x_places (relation_id INTEGER,object_id INTEGER,place_id INTEGER,type_id INTEGER,rank INTEGER);
		CREATE TABLE ca_objects_x_collections (relation_id INTEGER,object_id INTEGER,collection_id INTEGER,type_id INTEGER,rank INTEGER);
		CREATE TABLE ca_objects_x_vocabulary_terms (relation_id INTEGER,object_id INTEGER,item_id INTEGER,type_id INTEGER,rank INTEGER);
		CREATE TABLE ca_objects_x_objects (relation_id INTEGER,object_left_id INTEGER,object_right_id INTEGER,type_id INTEGER,rank INTEGER);
		CREATE TABLE ca_relationship_types (type_id INTEGER,type_code TEXT);
		INSERT INTO ca_relationship_types VALUES (1,"creator"),(2,"part_of"),(3,"depicts");
		INSERT INTO ca_objects_x_object_representations VALUES (1,100,200,1,0),(2,100,201,0,1),(3,100,202,0,2),(4,101,201,1,0),(5,103,200,1,0),(6,104,200,1,0),(7,105,200,1,0);
		INSERT INTO ca_objects_x_entities VALUES (1,100,300,1,0),(2,100,301,1,1),(3,100,302,1,2);
		INSERT INTO ca_objects_x_places VALUES (1,100,400,3,0);
		INSERT INTO ca_objects_x_collections VALUES (1,100,500,2,0);
		INSERT INTO ca_objects_x_vocabulary_terms VALUES (1,100,600,3,0),(2,100,601,3,1);
		INSERT INTO ca_objects_x_objects VALUES (1,100,102,3,0);
		INSERT INTO ca_acl VALUES (57,104,NULL,NULL,0),(20,302,NULL,NULL,0),(20,302,99,NULL,3);');
	$public = ['access' => 1, 'deleted' => 0, 'modified' => 100];
	$object = $public + ['idno' => 'EX.001', 'omeka_id' => 77, 'preferred_labels.name' => ['Example photograph'],
		'nonpreferred_labels.name' => ['Alternate title'], 'description' => ['<p>Public description.</p>'],
		'date' => [100 => [1 => ['dates_value' => 'circa 1940', 'dc_dates_types' => 'Inclusive'], 2 => ['dates_value' => '1942', 'dc_dates_types' => 'Created']]],
		'language' => ['English'], 'subjects_text' => ['Free-text subject'], 'lcsh_terms' => ['Public heading'],
		'description_source' => ['Example catalogue'], 'coverageNotes' => ['Northern region'],
		'dimensions.dimensions_text' => ['20 x 30 cm'], 'dimensions.dimensions_height' => ['20 cm'],
		'dimensions.dimensions_width' => ['30 cm'], 'rights.rightsText' => ['Example use statement'],
		'external_link.url_entry' => ['https://reference.example.org/resource'], 'internal_notes' => 'SECRET staff notes'];
	SyntheticModel::$records = [
		'ca_objects' => [100 => $object, 101 => $public, 102 => $public + ['preferred_labels.name' => 'Related public object'],
			103 => array_replace($object, ['access' => 0]), 104 => $object, 105 => array_replace($object, ['deleted' => 1])],
		'ca_object_representations' => [
			200 => $public + ['preferred_labels.name' => 'Public original', 'media_caption' => ['Public caption.'], 'media_descriptor' => ['original' => ['FILENAME' => 'public.tif'], 'thumbnail' => ['FILENAME' => 'thumb.jpg'], 'INPUT' => ['MIMETYPE' => 'image/tiff']], 'media_urls' => ['original' => 'https://media.example.org/media/public.tif', 'thumbnail' => 'https://media.example.org/media/thumb.jpg']],
			201 => array_replace($public, ['access' => 0, 'modified' => 600, 'preferred_labels.name' => 'SECRET paired JPEG', 'media_descriptor' => ['original' => ['FILENAME' => 'copy.jpg']], 'media_urls' => ['original' => 'https://media.example.org/media/private.jpg']]),
			202 => array_replace($public, ['deleted' => 1, 'modified' => 500])],
		'ca_entities' => [300 => $public + ['preferred_labels.displayname' => 'Fictional Creator', 'type_code' => 'ind'],
			301 => array_replace($public, ['access' => 0, 'modified' => 700, 'preferred_labels.displayname' => 'SECRET person']),
			302 => $public + ['preferred_labels.displayname' => 'SECRET ACL person']],
		'ca_places' => [400 => $public + ['preferred_labels.name' => 'Fictional place']],
		'ca_collections' => [500 => $public + ['preferred_labels.name' => 'Example collection', 'idno' => 'COLL.001']],
		'ca_list_items' => [600 => $public + ['preferred_labels.name_plural' => 'Example subjects', 'list_id' => 800],
			601 => array_replace($public, ['access' => 0, 'preferred_labels.name_plural' => 'SECRET subject', 'list_id' => 800])],
		'ca_lists' => [800 => ['list_code' => 'subject_terms']]
	];
}
fixture();
$anonymousRequest = (object)['user' => new ca_users()];
$staffRequest = (object)['user' => new ca_users(99)];
$GLOBALS['AUTH_CURRENT_USER_ID'] = 99;
$record = (new Catalogue(configuration(), $staffRequest))->record(100);
check($record !== null, 'A public object with a usable public representation is eligible');
check(count($record['media']) === 1 && $record['media'][0]['url'] === 'https://media.example.org/media/public.tif', 'Only public nondeleted representations produce file links');
check($record['preview_urls'] === ['https://media.example.org/media/thumb.jpg'], 'Preview comes from a public representation');
check($record['media'][0]['caption'] === 'Public caption.', 'Public descriptive representation captions are retained');
check($record['media'][0]['context_url'] === 'https://catalogue.example.org/Detail/objects/100?representation_id=200', 'Representation viewer link uses the public object route');
check($record['landing_url'] === 'https://catalogue.example.org/Detail/objects/100', 'Landing URL comes from canonical configured base');
check($record['description'] === ['Public description.'], 'Descriptive HTML becomes plain text');
check($record['modified'] === 700, 'Now-private dependencies still advance the public export datestamp');
check($record['names'] === [['name' => 'Fictional Creator', 'type' => 'ind', 'roles' => ['creator']]], 'Public names retain roles and anonymous ACLs exclude staff-only records');
check(!str_contains(json_encode($record), 'SECRET'), 'Private records and staff fields never enter the public DTO');
check($record['dates'] === [['value' => 'circa 1940', 'type' => 'Inclusive'], ['value' => '1942', 'type' => 'Created']], 'Date text, repeats and type stay aligned');
check($record['physical']['extent'] === ['20 x 30 cm', 'Height: 20 cm', 'Width: 30 cm'], 'Textual and structured physical dimensions are preserved');
check($record['type'] === 'still_image' && $record['genre'] === ['Still image'], 'Resource type code and public type label remain distinct');
check(count(array_filter($record['relations'], static fn($link) => ($link['type'] ?? '') === 'depicts')) === 1, 'Native related-object relationship semantics are retained');
check($record['notes'][0]['type'] === 'source of description', 'Source of description is not mislabeled as resource source');
check((new Catalogue(configuration(), $anonymousRequest))->record(100) === $record, 'Authenticated staff and anonymous requests receive identical public metadata');
check($GLOBALS['AUTH_CURRENT_USER_ID'] === 99 && $staffRequest->user->getPrimaryKey() === 99, 'Reading public metadata never mutates request or authentication state');
check(SyntheticModel::$loadedWithCurrentUser === 0, 'Candidate loads do not borrow or depend on the signed-in editor rights');
foreach ([101, 103, 104, 105, 999, 0] as $id) {
	check((new Catalogue(configuration(), $staffRequest))->record($id) === null, 'Private-only media, private/deleted/ACL-denied/missing objects are excluded: '.$id);
}
$publicFlags = configuration(); $publicFlags['item_acl_policy'] = 'public_flags';
check((new Catalogue($publicFlags))->record(104) !== null, 'Explicit public-flags policy permits public objects whose backend staff ACL denies anonymous access');
foreach ([101, 103, 105] as $id) { check((new Catalogue($publicFlags))->record($id) === null, 'Public-flags policy still excludes private/deleted objects and private-only representations: '.$id); }
$flaggedRecord = (new Catalogue($publicFlags, $staffRequest))->record(100);
check((new Catalogue($publicFlags, $anonymousRequest))->record(100) === $flaggedRecord, 'Explicit public-flags policy remains independent of signed-in account');
ca_users::$denied = ['ca_object_representations.media'];
check((new Catalogue(configuration()))->record(100) === null, 'Denied representation media bundle excludes the object');
ca_users::$denied = ['ca_objects.description', 'ca_objects.ca_entities'];
$restricted = (new Catalogue(configuration()))->record(100);
check($restricted['description'] === [] && $restricted['names'] === [], 'Denied descriptive and relationship bundles are omitted');
ca_users::$denied = ['ca_object_representations.media_caption'];
check((new Catalogue(configuration()))->record(100)['media'][0]['caption'] === '', 'Denied caption bundle does not export its text');
ca_users::$denied = [];
foreach (['http://media.example.org/media/public.tif', 'https://other.example.org/media/public.tif',
	'https://staff:secret@media.example.org/media/public.tif', 'javascript:alert(1)', '//media.example.org/media/public.tif',
	'https://media.example.org:444/media/public.tif', 'https://media.example.org/media/../private.tif',
	'https://media.example.org/media/%2e%2e/private.tif', "https://media.example.org/media/bad\nname.tif"] as $url) {
	SyntheticModel::$records['ca_object_representations'][200]['media_urls']['original'] = $url;
	check((new Catalogue(configuration()))->record(100) === null, 'Unsafe or unapproved media URL cannot make an object eligible');
}
SyntheticModel::$records['ca_object_representations'][200]['media_urls']['original'] = '/media/public.tif';
check((new Catalogue(configuration()))->record(100)['media'][0]['url'] === 'https://media.example.org/media/public.tif', 'Relative native media paths use the configured media base');
SyntheticModel::$records['ca_object_representations'][200]['media_descriptor']['original']['USE_ICON'] = true;
SyntheticModel::$records['ca_object_representations'][200]['media_descriptor']['original']['WIDTH'] = 100;
SyntheticModel::$records['ca_object_representations'][200]['media_descriptor']['original']['HEIGHT'] = 100;
check((new Catalogue(configuration()))->record(100) === null, 'An icon does not count as usable original media');
unset(SyntheticModel::$records['ca_object_representations'][200]['media_descriptor']['original']['USE_ICON']);
Db::$pdo->exec('DELETE FROM ca_objects_x_object_representations WHERE object_id = 100');
check((new Catalogue(configuration()))->record(100) === null, 'Removing representation relationships removes object eligibility');
fixture();
SyntheticModel::$records['ca_objects'][100]['external_link.url_entry'] = ['https://staff:secret@example.org/item', 'file:///private/report', 'https://127.0.0.1/private', 'https://intranet.local/private', 'https://reference.example.org/public'];
$links = (new Catalogue(configuration()))->record(100)['relations'];
check(count(array_filter($links, static fn($link) => ($link['url'] ?? '') === 'https://reference.example.org/public')) === 1 && !str_contains(json_encode($links), 'secret'), 'External references reject credentials and local targets');
$disabled = configuration(); $disabled['enable'] = false;
check((new Catalogue($disabled))->record(100) === null, 'Disabled plugin disseminates no records');
print 'Public OAI catalogue: '.$checks." checks passed.\n";
