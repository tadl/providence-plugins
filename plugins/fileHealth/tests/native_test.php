<?php
/** Execute the trusted native fixity method with synthetic catalogue/files only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL & ~E_DEPRECATED);
define('__CA_URL_ROOT__','');
$reference = getenv('PROVIDENCE_REFERENCE_ROOT') ?: dirname(__DIR__,4).'/providence';
$maintenance = $reference.'/app/lib/Utils/CLIUtils/Maintenance.php';
if (!is_file($maintenance)) { throw new RuntimeException('A trusted Providence reference with Maintenance.php is required.'); }
$zendRoots = array_filter([getenv('ZEND_REFERENCE_ROOT'),$reference.'/vendor/magento/zendframework1/library',
	dirname(__DIR__,4).'/pawtucket2/vendor/magento/zendframework1/library']);
$zend = null;
foreach ($zendRoots as $candidate) { if (is_file($candidate.'/Zend/Console/Getopt.php')) { $zend = $candidate; break; } }
if (!$zend) { throw new RuntimeException('Set ZEND_REFERENCE_ROOT to a trusted directory containing Zend/Console/Getopt.php.'); }
require_once $zend.'/Zend/Exception.php';
require_once $zend.'/Zend/Console/Getopt/Exception.php';
require_once $zend.'/Zend/Console/Getopt.php';
require_once $maintenance;

// caGetOption specifically recognizes the real Zend options object. Reuse the
// native helper without running setup.php or including private configuration.
$helpers = file_get_contents($reference.'/app/helpers/utilityHelpers.php');
if (!preg_match('/\tfunction caGetOption\(.*?(?=\n\t# -)/s',$helpers,$match)) { throw new RuntimeException('Native caGetOption helper not found.'); }
eval($match[0]);
function _t($text,...$args) { foreach ($args as $i => $value) { $text = str_replace('%'.($i+1),(string)$value,$text); } return $text; }
class RequestHTTP {
	public function __construct(...$args) {
		$unused = $_SERVER['REQUEST_URI'].$_SERVER['QUERY_STRING'];
		$GLOBALS['AUTH_CURRENT_USER_ID'] = null;
		$_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_REQUEST = [];
	}
}
class CLIUtils {
	use CLIUtilsMaintenance;
	private static $errors = [];
	public static function clearErrors() { self::$errors = []; }
	public static function numErrors() { return count(self::$errors); }
	public static function getErrors() { return self::$errors; }
	public static function addError($message) { self::$errors[] = $message; print $message; }
	public static function addMessage($message) { print $message; }
}
class NativeFixtureResult {
	private $rows; private $position = -1;
	private $skipPath;
	public function __construct(array $rows,bool $skipPath=false) { $this->rows = $rows; $this->skipPath = $skipPath; }
	public function nextRow() { return isset($this->rows[++$this->position]); }
	public function numRows() { return count($this->rows); }
	public function get($key) { return $this->rows[$this->position][$key] ?? null; }
	public function getMediaVersions($field) { return array_keys(unserialize($this->get($field),['allowed_classes' => false])); }
	public function getMediaPath($field,$version) { return $this->skipPath ? false : ($this->media($field)[$version]['test_path'] ?? null); }
	public function getMediaInfo($field,$version,$property) { return $this->media($field)[$version][$property] ?? null; }
	private function media($field) { return unserialize($this->get($field),['allowed_classes' => false]); }
}
class Db {
	public static $pdo;
	public static $nativeQueries = [];
	public static $onNative;
	public static $nativeMode;
	public static $fail = false;
	public function query($sql,...$params) {
		if (self::$fail) { return false; }
		if (count($params) === 1 && is_array($params[0])) { $params = $params[0]; }
		if (strpos($sql,'SELECT o_r.representation_id') !== false) {
			self::$nativeQueries[] = $params;
			if (self::$onNative) { $hook = self::$onNative; self::$onNative = null; $hook(); }
			if (self::$nativeMode === 'empty') { self::$nativeMode = null; return new NativeFixtureResult([]); }
			if (self::$nativeMode === 'skip_path') {
				self::$nativeMode = null; $s = self::$pdo->prepare($sql); $s->execute($params);
				return new NativeFixtureResult($s->fetchAll(PDO::FETCH_ASSOC),true);
			}
		}
		$s = self::$pdo->prepare($sql); $s->execute($params);
		return new NativeFixtureResult($s->fetchAll(PDO::FETCH_ASSOC));
	}
	public function numErrors() { return self::$fail ? 1 : 0; }
}
class ca_object_representations {
	private $row;
	public function load($id) {
		$q = Db::$pdo->prepare('SELECT * FROM ca_object_representations WHERE representation_id=? AND deleted=0'); $q->execute([$id]);
		$this->row = $q->fetch(PDO::FETCH_ASSOC); return (bool)$this->row;
	}
	public function getPrimaryKey() { return $this->row['representation_id'] ?? null; }
	public function get($key) { return $key === 'ca_objects.preferred_labels.name' ? 'Fictional archive' : ($this->row[$key] ?? 'SYNTHETIC'); }
	public function getLabelForDisplay() { return '<b>Fictional representation</b>'; }
	public function getMediaInfo($field,$version=null,$property=null) {
		$media = unserialize($this->row['media'],['allowed_classes' => false]);
		if ($version === null) { return $media; }
		return $property === null ? ($media[$version] ?? null) : ($media[$version][$property] ?? null);
	}
	public function getMediaPath($field,$version) { return $this->getMediaInfo($field)[$version]['test_path'] ?? null; }
}
require_once __DIR__.'/../lib/NativeChecker.php';
use FileHealth\Catalogue;
use FileHealth\NativeChecker;

$assertions = 0;
function check($condition,string $message) : void { global $assertions; $assertions++; if (!$condition) { throw new RuntimeException($message); } }
function fails(callable $call) : void { try { $call(); } catch (Throwable $e) { check(true,'Expected rejected input.'); return; } throw new RuntimeException('Expected rejected input.'); }
function insert_rep(int $id,array $media,int $deleted=0) : void {
	$q = Db::$pdo->prepare('INSERT OR REPLACE INTO ca_object_representations VALUES(?,?,?,?)'); $q->execute([$id,'SYNTHETIC-'.$id,serialize($media),$deleted]);
}
function media(string $path,?string $md5=null) : array {
	return ['ORIGINAL_FILENAME' => 'synthetic.pdf','INPUT' => ['MIMETYPE' => 'application/pdf'],
		'original' => ['test_path' => $path,'MD5' => $md5 ?? md5_file($path),'FILENAME' => basename($path),'VOLUME' => 'synthetic']];
}
function status(NativeChecker $checker,int $id,string $expected,string $version='original') : array {
	$result = $checker->check($id,$version);
	check($result['status'] === $expected,'Expected '.$expected.' for '.$id.', got '.json_encode($result));
	check(!isset($result['path'],$result['diagnostics'],$result['native_errors']),'Private paths and native diagnostics never escape the checker.');
	check(is_string($result['fingerprint']) && preg_match('/^[a-f0-9]{64}$/D',$result['fingerprint']),'Every known identity has a generation fingerprint.');
	return $result;
}

$root = realpath(sys_get_temp_dir()).'/file-health-native-'.bin2hex(random_bytes(8)); mkdir($root,0700);
try {
	Db::$pdo = new PDO('sqlite::memory:'); Db::$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	Db::$pdo->exec('CREATE TABLE ca_object_representations(representation_id INTEGER PRIMARY KEY,idno TEXT,media BLOB,deleted INTEGER)');
	Db::$pdo->exec('CREATE TABLE ca_object_representation_multifiles(multifile_id INTEGER PRIMARY KEY,representation_id INTEGER)');
	$path = $root.'/synthetic, original'."\n".'file.pdf'; file_put_contents($path,'original bytes');
	$original = media($path); insert_rep(1,$original);
	$catalogue = new Catalogue(); $checker = new NativeChecker($catalogue);
	$GLOBALS['AUTH_CURRENT_USER_ID'] = 123; $_SERVER['SCRIPT_NAME'] = '/synthetic-cli'; $_REQUEST = ['synthetic' => 'request'];
	$result = status($checker,1,'verified');
	check($GLOBALS['AUTH_CURRENT_USER_ID'] === 123 && $_SERVER['SCRIPT_NAME'] === '/synthetic-cli' && $_REQUEST === ['synthetic' => 'request'],'Native RequestHTTP initialization cannot erase the authorized CLI user or request context.');
	check($result['expected_md5'] === md5('original bytes') && $result['bytes'] === strlen('original bytes'),'Uses the native uploaded checksum and reports checked bytes.');
	check(Db::$nativeQueries === [[1,1]],'Native command ran and bounded its real query to exactly one representation.');
	check($catalogue->describe(1,'original')['label'] === 'Fictional representation','Native labels are plain text at the boundary.');
	file_put_contents($path,'corrupted bytes'); $mismatch = status($checker,1,'mismatch');
	check($mismatch['reason'] === 'checksum_mismatch' && $mismatch['expected_md5'] === $result['expected_md5'],'Mismatch never changes the expected baseline.');
	check($mismatch['fingerprint'] === $result['fingerprint'],'Content corruption retains the same expected generation.');
	check(CLIUtils::numErrors() === 0,'Native static errors are scoped to the isolated check.');
	Db::$nativeMode = 'empty'; status($checker,1,'mismatch');
	check(CLIUtils::numErrors() === 0,'A zero-row native success cannot hide corruption.');
	Db::$nativeMode = 'skip_path'; status($checker,1,'mismatch');
	check(CLIUtils::numErrors() === 0,'A silent native path skip cannot hide corruption.');
	file_put_contents($path,'original bytes'); status($checker,1,'verified');
	unlink($path); status($checker,1,'missing'); file_put_contents($path,'original bytes');
	$noBaseline = $original; unset($noBaseline['original']['MD5']); insert_rep(2,$noBaseline);
	$queries = count(Db::$nativeQueries); $unverified = status($checker,2,'unverified');
	check($unverified['reason'] === 'baseline_unavailable' && count(Db::$nativeQueries) === $queries,'No-baseline native skips cannot report verified.');
	status($checker,999,'unverified');
	foreach (['SKIP' => 'version_substituted','EXTERNAL_URL' => 'externally_hosted','QUEUED' => 'processing_pending','USE_ICON' => 'icon_only','PAGES' => 'multipage_version_unsupported'] as $flag => $reason) {
		$unsupported = $original; $unsupported['original'][$flag] = $flag === 'PAGES' ? 2 : 1; insert_rep(3,$unsupported);
		check(status($checker,3,'unverified')['reason'] === $reason,'Unsupported version is explicitly excluded: '.$flag);
	}
	insert_rep(3,$original); status($checker,3,'unverified','nonexistent');
	$badPath = $original; $badPath['original']['test_path'] = 'https://example.org/file.pdf'; insert_rep(4,$badPath); status($checker,4,'unverified');
	$badPath['original']['test_path'] = $root; insert_rep(4,$badPath); status($checker,4,'unverified');
	$empty = $root.'/empty.pdf'; file_put_contents($empty,''); insert_rep(5,media($empty)); status($checker,5,'verified');
	$other = $root.'/other.pdf'; file_put_contents($other,'wrong derivative');
	$derivatives = $original; $derivatives['thumbnail'] = ['test_path' => $other,'MD5' => md5('expected derivative'),'VOLUME' => 'synthetic']; insert_rep(6,$derivatives);
	status($checker,6,'verified'); status($checker,6,'mismatch','thumbnail');
	Db::$pdo->exec('INSERT INTO ca_object_representation_multifiles VALUES(1,6)');
	check(status($checker,6,'verified')['multifile_count'] === 1,'Original can verify while separate multifile coverage is explicitly counted as excluded.');
	Db::$onNative = static function() use ($original) { $changed = $original; $changed['original']['MD5'] = md5('changed baseline'); insert_rep(1,$changed); };
	status($checker,1,'changed'); insert_rep(1,$original);
	Db::$onNative = static function() use ($path) { rename($path,$path.'.old'); file_put_contents($path,'original bytes'); };
	status($checker,1,'changed'); unlink($path.'.old');
	Db::$onNative = static function() use ($path) { unlink($path); };
	status($checker,1,'error'); file_put_contents($path,'original bytes');
	Db::$onNative = static function() { Db::$pdo->exec('DELETE FROM ca_object_representations WHERE representation_id=1'); };
	status($checker,1,'changed'); insert_rep(1,$original);
	$replacement = media($path,md5('replacement bytes')); insert_rep(1,$replacement);
	check(status($checker,1,'mismatch')['fingerprint'] !== $result['fingerprint'],'Native baseline replacement creates another generation without resolving previous evidence.');
	insert_rep(10,$original,1);
	check($catalogue->maxId() === 6 && $catalogue->batch(0,2) === [1,2] && $catalogue->batch(2,100,5) === [3,4,5],'Bounded keyset inventory excludes deleted rows and honors the captured maximum.');
	fails(static function() use ($catalogue) { $catalogue->batch(0,0); });
	fails(static function() use ($catalogue) { $catalogue->batch(0,1001); });
	fails(static function() use ($catalogue) { $catalogue->describe(1,'original,thumbnail'); });
	$bad = $checker->check(1,'original,thumbnail'); check($bad['status'] === 'error','Malformed native flags are rejected before running default scope.');
	$first = $catalogue->describe(1,'original'); $reordered = $first; $reordered['descriptor'] = array_reverse($first['descriptor'],true);
	check(NativeChecker::fingerprint($first) === NativeChecker::fingerprint($reordered),'Fingerprint canonicalization is stable for descriptor map order.');
	$reprocessed = $first; $reprocessed['descriptor']['entry']['FILE_LAST_MODIFIED'] = 123456789;
	check(NativeChecker::fingerprint($first) === NativeChecker::fingerprint($reprocessed),'Processing metadata does not create a different file generation.');
	chmod($path,0000); clearstatcache(true,$path);
	if (!is_readable($path)) { status($checker,1,'unreadable'); }
	chmod($path,0600);
	Db::$fail = true; $failed = $checker->check(1); check($failed['status'] === 'error' && $failed['reason'] === 'check_failed','Database failure cannot become a clean result.'); Db::$fail = false;
	print 'File health native integration: '.$assertions." assertions passed.\n";
} finally {
	foreach (glob($root.'/*') ?: [] as $file) { if (is_file($file)) { chmod($file,0600); unlink($file); } }
	rmdir($root);
}
