<?php
/** SPDX-License-Identifier: GPL-3.0-or-later. Synthetic configuration and hook checks. */
require_once __DIR__.'/../lib/Config.php';

use TADL\PublicOai\Config;

$checks = 0;
function verify($condition, string $message): void {
	global $checks;
	$checks++;
	if (!$condition) { throw new RuntimeException($message); }
}
function invalidConfig(array $values): bool {
	try { Config::normalize($values); return false; }
	catch (InvalidArgumentException $error) { return true; }
}
$valid = ['enable' => '1', 'provider_key' => 'objects', 'item_acl_policy' => 'native', 'public_base_url' => 'https://catalogue.example.org',
	'media_base_url' => 'https://media.example.org', 'allowed_media_hosts' => ['media.example.org'],
	'repository_name' => 'Example Digital Collections'];
verify(Config::normalize($valid)['enable'], 'Explicitly configured plugin can be enabled.');
verify(!Config::normalize(['repository_name' => 'Example'])['enable'], 'Shared defaults are disabled.');
foreach (['http://catalogue.example.org', 'https://alice:secret@example.org', 'https://catalogue.example.org/?key=synthetic',
	'https://catalogue.example.org/#fragment', 'https://catalogue.example.org:8443', 'https://catalogue.example.org/%2e%2e/private',
	"https://catalogue.example.org/\nheader", 'https://catalogue.example.org/back\\slash'] as $url) {
	verify(invalidConfig(array_replace($valid, ['public_base_url' => $url])), 'Unsafe base URL rejected.');
}
verify(invalidConfig(array_replace($valid, ['allowed_media_hosts' => []])), 'Empty enabled media allowlist rejected.');
verify(invalidConfig(array_replace($valid, ['allowed_media_hosts' => ['other.example.org']])), 'Media base host must be allowed.');
verify(invalidConfig(array_replace($valid, ['provider_key' => '../objects'])), 'Provider key cannot contain a route.');
verify(invalidConfig(array_replace($valid, ['repository_name' => "Example\nLibrary"])), 'Repository name cannot contain controls.');
verify(Config::mappingFormat('tadl_public_oai_mods') === 'mods', 'MODS mapping recognized.');
verify(Config::mappingFormat('tadl_public_oai_dc') === 'oai_dc', 'DC mapping recognized.');
verify(Config::mappingFormat('unrelated_export') === null, 'Other exporters stay outside plugin scope.');
verify(Config::normalize($valid)['item_acl_policy'] === 'native', 'Native anonymous item ACLs are the default.');
verify(invalidConfig(array_replace($valid, ['item_acl_policy' => 'skip_everything'])), 'Unknown publication policy is rejected.');

class Configuration {
	public static $values = [];
	public static function load($path) { return new self(); }
	public function getScalar($key) { return self::$values[$key] ?? false; }
	public function getList($key) { return self::$values[$key] ?? null; }
	public function exists($key) { return array_key_exists($key, self::$values); }
}
class BaseApplicationPlugin {
	protected $description;
	public function __construct() {}
	public function getDescription() { return $this->description; }
	public function getRequest() { return null; }
}
function _t($text) { return $text; }
require_once __DIR__.'/../publicOaiPlugin.php';
class SyntheticPublicOaiPlugin extends publicOaiPlugin {
	public static $record;
	public static $reads = 0;
	protected function catalogue() {
		return new class {
			public function record($id) { SyntheticPublicOaiPlugin::$reads++; return SyntheticPublicOaiPlugin::$record; }
		};
	}
}
Configuration::$values = $valid;
$plugin = new SyntheticPublicOaiPlugin(dirname(__DIR__));
verify($plugin->checkStatus()['available'], 'Valid enabled plugin reports availability.');
$probe = ['provider' => 'objects', 'table' => 'ca_objects', 'mapping' => 'tadl_public_oai_dc', 'record_id' => 0, 'info' => []];
$plugin->hookOAIRecordInfo($probe);
verify($probe['info'] === ['handled' => true, 'eligible' => false, 'datestamp' => 0], 'Readiness probe handles policy without publishing a record.');
verify(SyntheticPublicOaiPlugin::$reads === 0, 'Readiness probe does not read a catalogue record.');
$unrestricted = $probe;
$unrestricted['info'] = [];
$unrestricted['mapping'] = 'unrelated_internal_export';
$plugin->hookOAIRecordInfo($unrestricted);
verify($unrestricted['info'] === [], 'An unrelated exporter cannot satisfy the public metadata policy.');
$other = ['provider' => 'other', 'table' => 'ca_objects', 'record_id' => 7, 'info' => []];
$plugin->hookOAIRecordInfo($other);
verify($other['info'] === [], 'Other provider scope is untouched.');
$other = ['provider' => 'objects', 'table' => 'ca_entities', 'record_id' => 7, 'info' => []];
$plugin->hookOAIRecordInfo($other);
verify($other['info'] === [], 'Other record tables are untouched.');
$other = ['provider' => 'objects', 'table' => 'ca_objects', 'record_id' => 0, 'item_acl_policy' => 'public_flags', 'info' => []];
$plugin->hookOAIRecordInfo($other);
verify($other['info'] === [], 'A mismatched service/plugin item policy cannot pass readiness.');
SyntheticPublicOaiPlugin::$record = ['id' => 7, 'modified' => 1700000000, 'titles' => ['Synthetic title'],
	'landing_url' => 'https://catalogue.example.org/Detail/objects/7'];
$info = ['provider' => 'objects', 'table' => 'ca_objects', 'mapping' => 'tadl_public_oai_dc', 'record_id' => 7, 'info' => []];
$plugin->hookOAIRecordInfo($info);
verify($info['info']['eligible'] && $info['info']['datestamp'] === 1700000000, 'Eligibility hook supplies dependency timestamp.');
$exporter = new class {
	public $code = 'unrelated_export';
	public function get($field) { return $this->code; }
};
$export = ['exporter_instance' => $exporter, 'record_id' => 7, 'export' => ['unchanged']];
$plugin->hookExportRecord($export);
verify($export['export'] === ['unchanged'], 'Unrelated export content is preserved.');
foreach (['tadl_public_oai_mods', 'tadl_public_oai_dc'] as $code) {
	$exporter->code = $code;
	$export['export'] = [];
	$plugin->hookExportRecord($export);
	verify(count($export['export']) === 1, 'Known mapping produces one native metadata root.');
}
SyntheticPublicOaiPlugin::$record = null;
$plugin->hookOAIRecordInfo($info);
verify(!$info['info']['eligible'], 'A subsequent eligibility read can withdraw publication.');
$rejected = false;
try { $plugin->hookExportRecord($export); } catch (RuntimeException $error) { $rejected = true; }
verify($rejected, 'An ineligible record cannot be serialized after its eligibility changes.');
Configuration::$values['enable'] = '0';
$disabled = new SyntheticPublicOaiPlugin(dirname(__DIR__));
verify(!$disabled->checkStatus()['available'], 'Disabled plugin reports unavailable.');
$disabledProbe = ['provider' => 'objects', 'table' => 'ca_objects', 'record_id' => 0, 'info' => []];
$disabled->hookOAIRecordInfo($disabledProbe);
verify($disabledProbe['info'] === [], 'Disabled plugin does not satisfy required policy hook.');
echo "Public OAI plugin: {$checks} checks passed\n";
