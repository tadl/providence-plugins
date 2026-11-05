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

$provider = nativeConfiguration();
verifyConfiguration($provider->loadFile($reference.'/app/conf/oai_provider.conf'), 'Stock provider configuration parses');
verifyConfiguration($provider->loadFile(__DIR__.'/../conf/oai_provider.conf.example'), 'Public provider example parses');
$providers = $provider->getAssoc('providers');
verifyConfiguration(array_keys($providers) === ['objects'], 'Example replaces the stock provider list instead of enabling stock dc');
verifyConfiguration($provider->getScalar('enabled') === '0', 'Provider example is disabled');
verifyConfiguration($provider->getScalar('maxRecordsPerRequest') === '50', 'Page size is explicit');
$objects = $providers['objects'];
verifyConfiguration($objects['setFacet'] === '', 'Disabled sets parse as a truly empty scalar');
verifyConfiguration($objects['query'] === '*', 'Strict provider uses the fixed all-object candidate scope');
foreach (['restrictToTypes', 'excludeTypes', 'privileged_networks'] as $key) {
	verifyConfiguration($objects[$key] === [], 'Provider list is explicitly empty: '.$key);
}
foreach (['publicObjectsWithRepresentations', 'requireRecordInfoHook'] as $key) {
	verifyConfiguration($objects[$key] === '1', 'Required publication protection is enabled: '.$key);
}
foreach (['dont_enforce_access_settings', 'show_deleted', 'dontFilterByACL'] as $key) {
	verifyConfiguration($objects[$key] === '0', 'Provider access bypass remains disabled: '.$key);
}
verifyConfiguration($objects['publicItemACLPolicy'] === 'native', 'Shared example defaults to native item ACLs');
verifyConfiguration($objects['public_access_settings'] === ['1'] && $objects['privileged_access_settings'] === ['1'], 'Access values remain public-only');
verifyConfiguration($objects['default_format'] === 'oai_dc', 'Dublin Core is the explicit default format');
verifyConfiguration($objects['identiferNamespace'] === 'collections.example.org', 'Identifier namespace is synthetic and uses the native key spelling');
verifyConfiguration(array_keys($objects['formats']) === ['mods', 'oai_dc'], 'Both formats are advertised');
foreach (['mods' => ['tadl_public_oai_mods', 'http://www.loc.gov/mods/v3'],
	'oai_dc' => ['tadl_public_oai_dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/']] as $format => [$mapping, $namespace]) {
	verifyConfiguration($objects['formats'][$format]['mapping'] === $mapping, 'Format selects its authorized mapping: '.$format);
	verifyConfiguration($objects['formats'][$format]['metadataNamespace'] === $namespace, 'Format namespace is exact: '.$format);
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

// Preserve the exact native parser regression: an unquoted blank is one space.
$blank = nativeConfiguration();
parseConfigurationText($blank, "providers = {\n objects = {\n setFacet = ,\n }\n}\n");
verifyConfiguration($blank->getAssoc('providers')['objects']['setFacet'] === ' ', 'Native parser distinguishes unquoted blanks from quoted empties');
echo "Public OAI native configuration: {$checks} checks passed\n";
