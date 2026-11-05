<?php
/** SPDX-License-Identifier: GPL-3.0-or-later. Native configuration parsing without application bootstrap. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$reference = getenv('PROVIDENCE_REFERENCE_ROOT') ?: dirname(__DIR__, 4).'/providence';
require_once $reference.'/app/lib/Configuration.php';
require_once __DIR__.'/../lib/ProviderConfig.php';
use TADL\PublicOai\ProviderConfig;

$checks = 0;
function transportCheck($condition, string $message): void {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function transportInvalid(array $values): bool {
	try { ProviderConfig::normalize($values); return false; } catch (InvalidArgumentException $error) { return true; }
}
function transportParser(string $path): Configuration {
	$class = new ReflectionClass(Configuration::class); $parser = $class->newInstanceWithoutConstructor();
	$class->getProperty('ops_config_settings')->setValue($parser, ['scalars' => [], 'lists' => [], 'assoc' => []]);
	transportCheck($parser->loadFile($path), 'Native parser accepts synthetic transport/metadata configuration');
	return $parser;
}
$valid = ['enable' => true, 'repository_name' => 'Example Archive', 'oai_enabled' => '1',
	'oai_base_url' => 'https://collections.example.org/service.php/publicOai/Repository/Index',
	'identifier_namespace' => 'collections.example.org', 'admin_email' => 'metadata@example.org',
	'earliest_datestamp' => '2000-01-01T00:00:00Z', 'records_per_page' => '50', 'token_ttl' => '86400'];
$normalized = ProviderConfig::normalize($valid);
transportCheck($normalized['oai_enabled'] && $normalized['records_per_page'] === 50 && $normalized['token_ttl'] === 86400, 'Transport settings normalize booleans and integers');
transportCheck(!ProviderConfig::normalize(['enable' => true])['oai_enabled'], 'Metadata configuration alone never enables the standalone transport');
transportCheck(transportInvalid(array_replace($valid, ['enable' => false])), 'Transport requires enabled public metadata');
foreach (['oai_base_url', 'identifier_namespace', 'admin_email', 'earliest_datestamp'] as $key) {
	transportCheck(transportInvalid(array_replace($valid, [$key => ''])), 'Enabled transport requires '.$key);
}
foreach (['http://collections.example.org/oai', 'https://user:secret@collections.example.org/oai',
	'https://collections.example.org:443/oai', 'https://collections.example.org/oai?verb=Identify',
	'https://collections.example.org/oai#fragment', 'https://collections.example.org/%2e%2e/private',
	'https://collections.example.org/%252e%252e/private', 'https://collections.example.org/a%2fb'] as $url) {
	transportCheck(transportInvalid(array_replace($valid, ['oai_base_url' => $url])), 'Unsafe canonical transport base is rejected');
}
foreach (['with:colon', 'has space', 'has_underscore', '../relative', ''] as $namespace) {
	transportCheck(transportInvalid(array_replace($valid, ['identifier_namespace' => $namespace])), 'Invalid stable namespace is rejected');
}
foreach (['not-an-email', "metadata@example.org\nInjected: value"] as $email) {
	transportCheck(transportInvalid(array_replace($valid, ['admin_email' => $email])), 'Invalid public contact email is rejected');
}
foreach (['2000-02-30T00:00:00Z', '2000-01-01', '2000-01-01T00:00:00+00:00'] as $date) {
	transportCheck(transportInvalid(array_replace($valid, ['earliest_datestamp' => $date])), 'Invalid earliest UTC datestamp is rejected');
}
foreach (['records_per_page' => [0, -1, 501, '1.5', '050', true], 'token_ttl' => [0, 59, 86401, '1e3', true]] as $key => $values) {
	foreach ($values as $value) { transportCheck(transportInvalid(array_replace($valid, [$key => $value])), 'Invalid transport bound is rejected: '.$key); }
}
transportCheck(ProviderConfig::normalize(array_replace($valid, ['records_per_page' => 1, 'token_ttl' => 60]))['records_per_page'] === 1, 'Minimum transport bounds are supported');
transportCheck(ProviderConfig::normalize(array_replace($valid, ['records_per_page' => 500]))['records_per_page'] === 500, 'Maximum page size is supported');

// Preload actual parsed native instances so native Configuration::load never
// constructs an installation config or touches persistent configuration caches.
class MemoryCache {
	public static $instances = [];
	public static function contains($key, $namespace) {
		if (!isset(self::$instances[$key])) { throw new RuntimeException('Unexpected configuration bootstrap.'); }
		return true;
	}
	public static function fetch($key, $namespace) { return self::$instances[$key]; }
}
$directory = sys_get_temp_dir().'/public-oai-config-'.bin2hex(random_bytes(8));
mkdir($directory, 0700); mkdir($directory.'/conf', 0700);
$files = ['plugin.conf' => 'plugin.conf', 'plugin.local.conf' => 'plugin.local.conf.example',
	'oai.conf' => 'oai.conf', 'oai.local.conf' => 'oai.local.conf.example'];
try {
	foreach ($files as $target => $source) {
		$path = $directory.'/conf/'.$target;
		file_put_contents($path, file_get_contents(__DIR__.'/../conf/'.$source));
		MemoryCache::$instances[$path] = transportParser($path);
	}
	$config = ProviderConfig::load($directory);
	transportCheck(!$config['enable'] && !$config['oai_enabled'], 'Actual shared/local source examples keep both publication switches disabled');
	transportCheck($config['admin_email'] === 'metadata@example.org' && $config['identifier_namespace'] === 'collections.example.org', 'Transport example uses explicit synthetic public identity');
	transportCheck($config['media_public_url_prefix'] === 'https://catalogue.example.org/media/public', 'Transport integration preserves metadata URL policy');
	foreach (['plugin.local.conf' => ['enable = 0', 'enable = 1'], 'oai.local.conf' => ['oai_enabled = 0', 'oai_enabled = 1']] as $name => [$from, $to]) {
		$path = $directory.'/conf/'.$name;
		file_put_contents($path, str_replace($from, $to, file_get_contents($path)));
		MemoryCache::$instances[$path] = transportParser($path);
	}
	$config = ProviderConfig::load($directory);
	transportCheck($config['enable'] && $config['oai_enabled'], 'Explicit local switches enable metadata and standalone transport independently');
	transportCheck($config['oai_base_url'] === $valid['oai_base_url'] && $config['records_per_page'] === 50, 'Native transport loader produces the canonical provider configuration');
} finally {
	foreach (array_keys($files) as $name) { unlink($directory.'/conf/'.$name); }
	rmdir($directory.'/conf'); rmdir($directory);
}
echo "Public OAI transport configuration: {$checks} checks passed\n";
