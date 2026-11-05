<?php
/** SPDX-License-Identifier: GPL-3.0-or-later. Native parser checks without application bootstrap. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$reference = getenv('PROVIDENCE_REFERENCE_ROOT') ?: dirname(__DIR__, 4).'/providence';
require_once $reference.'/app/lib/Configuration.php';
require_once __DIR__.'/../lib/Config.php';

$checks = 0;
function verifyConfiguration($condition, string $message): void {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function nativeConfiguration(): Configuration {
	// The constructor loads application settings/caches; the parser itself does not.
	$class = new ReflectionClass(Configuration::class);
	$parser = $class->newInstanceWithoutConstructor();
	$class->getProperty('ops_config_settings')->setValue($parser, ['scalars' => [], 'lists' => [], 'assoc' => []]);
	return $parser;
}
function parseConfigurationText(Configuration $parser, string $text): void {
	$file = tmpfile();
	if (!$file) { throw new RuntimeException('Cannot create synthetic configuration fixture.'); }
	try {
		fwrite($file, $text); fflush($file);
		verifyConfiguration($parser->loadFile(stream_get_meta_data($file)['uri']), 'Synthetic configuration parses');
	} finally { fclose($file); }
}

$plugin = nativeConfiguration();
verifyConfiguration($plugin->loadFile(__DIR__.'/../conf/plugin.conf'), 'Shared plugin configuration parses');
verifyConfiguration($plugin->getScalar('enable') === '0' && $plugin->getList('allowed_media_hosts') === [], 'Shared plugin defaults are disabled and allow no media hosts');
verifyConfiguration($plugin->getScalar('media_source_url_prefix') === '' && $plugin->getScalar('media_public_url_prefix') === '', 'Shared media prefix translation defaults to disabled');
verifyConfiguration($plugin->loadFile(__DIR__.'/../conf/plugin.local.conf.example'), 'Local plugin example parses');
verifyConfiguration($plugin->getScalar('enable') === '0', 'Local plugin example remains disabled');
verifyConfiguration($plugin->getList('allowed_media_hosts') === ['catalogue.example.org'], 'Local example allows only the public media host');
$values = [];
foreach (['enable', 'provider_key', 'item_acl_policy', 'public_base_url', 'media_base_url', 'media_source_url_prefix', 'media_public_url_prefix', 'repository_name'] as $key) { $values[$key] = $plugin->getScalar($key); }
$values['allowed_media_hosts'] = $plugin->getList('allowed_media_hosts');
$normalized = \TADL\PublicOai\Config::normalize($values);
verifyConfiguration($normalized['public_base_url'] === 'https://catalogue.example.org', 'Public record URL base survives native parsing');
verifyConfiguration($normalized['media_base_url'] === 'https://backend.example.org', 'Native media fallback base survives native parsing');
verifyConfiguration($normalized['media_source_url_prefix'] === 'https://backend.example.org/media/collection' && $normalized['media_public_url_prefix'] === 'https://catalogue.example.org/media/public', 'Native parser preserves paired source and public media prefixes');

parseConfigurationText($plugin, "allowed_media_hosts = []\n");
verifyConfiguration($plugin->exists('allowed_media_hosts') && $plugin->getList('allowed_media_hosts') === [], 'An explicit empty local list clears earlier allowed hosts');
$values['allowed_media_hosts'] = $plugin->getList('allowed_media_hosts');
$values['enable'] = '1'; $refused = false;
try { \TADL\PublicOai\Config::normalize($values); } catch (InvalidArgumentException $error) { $refused = true; }
verifyConfiguration($refused, 'A cleared media allowlist cannot silently enable publication');

echo "Public OAI native configuration: {$checks} checks passed\n";
