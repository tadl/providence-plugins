<?php
/** Synthetic OAI protocol checks; no application bootstrap, records or network. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL & ~E_DEPRECATED); // The trusted 2.0.10 XML formatter passes null to strlen().
require_once __DIR__.'/../lib/Provider.php';

use TADL\PublicOai\Provider;

// Exercise the actual native XML formatter, using only its trusted class and helpers.
$reference = realpath(getenv('PROVIDENCE_REFERENCE_ROOT') ?: dirname(__DIR__, 4).'/providence');
if (!$reference || !is_file($reference.'/app/lib/Export/ExportFormats/ExportXML.php')) { throw new RuntimeException('Set PROVIDENCE_REFERENCE_ROOT to a trusted Providence checkout.'); }
function providerNativeFunction(string $source, string $name): void {
	$tokens = token_get_all($source);
	for ($index = 0; $index < count($tokens); $index++) {
		if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) { continue; }
		$next = $index + 1;
		while (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) { $next++; }
		if (!is_array($tokens[$next]) || $tokens[$next][1] !== $name) { continue; }
		$body = ''; $depth = 0; $opened = false;
		for (; $index < count($tokens); $index++) {
			$token = $tokens[$index]; $body .= is_array($token) ? $token[1] : $token;
			if ($token === '{') { $depth++; $opened = true; }
			if ($token === '}' && --$depth === 0 && $opened) { eval($body); return; }
		}
	}
	throw new RuntimeException('Missing trusted formatter helper.');
}
$helpers = file_get_contents($reference.'/app/helpers/utilityHelpers.php');
foreach (['caGetOption', 'caEscapeForXML', 'caMakeProperUTF8ForXML'] as $function) { providerNativeFunction($helpers, $function); }
function _t($text) { return $text; }
foreach (['FT_NUMBER', 'DT_CHECKBOXES'] as $constant) { define($constant, 0); }
$source = file_get_contents($reference.'/app/lib/Export/BaseExportFormat.php');
eval(substr($source, strpos($source, 'abstract class BaseExportFormat')));
$source = file_get_contents($reference.'/app/lib/Export/ExportFormats/ExportXML.php');
eval(substr($source, strpos($source, 'class ExportXML extends BaseExportFormat')));

libxml_set_external_entity_loader(static function ($public, $system, $context) {
	$name = basename(parse_url($system, PHP_URL_PATH) ?: '');
	return in_array($name, ['OAI-PMH.xsd', 'oai-identifier.xsd', 'mods-3-8.xsd', 'oai_dc.xsd', 'simpledc20021212.xsd', 'xlink.xsd', 'xml.xsd'], true)
		? fopen(__DIR__.'/schema/'.$name, 'r') : null;
});
libxml_use_internal_errors(true);
set_error_handler(static function ($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) { return false; }
	throw new ErrorException($message, 0, $severity, $file, $line);
});

final class ProviderFixture {
	public static $config;
	public static $records;
	public static $candidates;
	public static $cache;
	public static $now;
	public static $recordCalls;
	public static $fault;
	public static $cacheFails;
	public static $ttls;
	public static function reset(): void {
		self::$config = ['enable' => true, 'oai_enabled' => true, 'provider_key' => 'objects', 'item_acl_policy' => 'native',
			'oai_base_url' => 'https://collections.example.org/service.php/OAI/objects', 'identifier_namespace' => 'collections.example.org',
			'admin_email' => 'repository@example.org', 'repository_name' => 'Synthetic & public',
			'earliest_datestamp' => '2024-01-01T00:00:00Z', 'records_per_page' => 2, 'token_ttl' => 86400];
		self::$now = 1735776000;
		self::$records = [];
		foreach (range(1, 8) as $id) {
			self::$records[$id] = ['id' => $id, 'modified' => self::$now + $id,
				'titles' => ['Synthetic '.$id.' & café <XML>'], 'description' => ['Synthetic public description.'],
				'landing_url' => 'https://catalogue.example.org/Detail/objects/'.$id,
				'media' => [['url' => 'https://media.example.org/synthetic-'.$id.'.tif', 'mimetype' => 'image/tiff',
					'context_url' => 'https://catalogue.example.org/Detail/objects/'.$id.'?representation_id='.($id * 10)]]];
		}
		// Catalogue owns these decisions; the provider must honor null in every verb/format.
		foreach ([2, 3, 4, 5, 6, 7] as $id) { self::$records[$id] = null; }
		self::$candidates = [8, 2, 3, 4, 5, 6, 7, 1, 8];
		self::$cache = []; self::$recordCalls = []; self::$fault = ''; self::$cacheFails = false; self::$ttls = [];
	}
	public static function dependencies(): array {
		return [
			'now' => static fn() => self::$now,
			'candidates' => static function () {
				if (self::$fault === 'candidates') { throw new RuntimeException('synthetic private failure'); }
				return self::$candidates;
			},
			'record' => static function ($id) {
				self::$recordCalls[] = $id;
				if (self::$fault === 'record') { throw new RuntimeException('synthetic private failure'); }
				$record = self::$records[$id] ?? null;
				if ($record !== null && self::$fault === 'timestamp') { $record['modified'] = 0; }
				if ($record !== null && self::$fault === 'identity') { $record['id']++; }
				return $record;
			},
			'cache_get' => static fn($key) => self::$cache[$key] ?? null,
			'cache_put' => static function ($key, $value, $ttl) {
				self::$ttls[] = $ttl;
				if (self::$cacheFails) { return false; }
				if ($ttl <= 0) { throw new RuntimeException('Cache TTL must be positive.'); }
				self::$cache[$key] = $value; return true;
			}
		];
	}
}

$checks = $envelopes = $payloads = 0;
function expectProvider(bool $condition, string $message): void {
	global $checks; $checks++;
	if (!$condition) { throw new RuntimeException($message); }
}
function providerValidate(string $xml): DOMDocument {
	global $envelopes, $payloads;
	static $protocol;
	if ($protocol === null) {
		$schema = new DOMDocument(); $schema->load(__DIR__.'/schema/OAI-PMH.xsd', LIBXML_NONET);
		// Validate protocol structure independently; actual metadata is strictly validated below.
		foreach ($schema->getElementsByTagNameNS('http://www.w3.org/2001/XMLSchema', 'any') as $any) { $any->setAttribute('processContents', 'lax'); }
		$protocol = $schema->saveXML();
	}
	$document = new DOMDocument();
	if (!$document->loadXML($xml, LIBXML_NONET) || !$document->schemaValidateSource($protocol)) {
		throw new RuntimeException('Protocol envelope failed official schema: '.json_encode(libxml_get_errors()));
	}
	$xpath = new DOMXPath($document); $xpath->registerNamespace('o', 'http://www.openarchives.org/OAI/2.0/');
	foreach ($xpath->query('//o:metadata/*') as $node) {
		$schema = $node->namespaceURI === 'http://www.loc.gov/mods/v3' ? 'mods-3-8.xsd' : 'oai_dc.xsd';
		$metadata = new DOMDocument(); $metadata->appendChild($metadata->importNode($node, true));
		if (!$metadata->schemaValidate(__DIR__.'/schema/'.$schema)) { throw new RuntimeException('Payload failed official schema.'); }
		$payloads++;
	}
	foreach ($document->getElementsByTagNameNS('http://www.openarchives.org/OAI/2.0/oai-identifier', 'oai-identifier') as $node) {
		$description = new DOMDocument(); $description->appendChild($description->importNode($node, true));
		if (!$description->schemaValidate(__DIR__.'/schema/oai-identifier.xsd')) { throw new RuntimeException('Identifier description failed official schema.'); }
	}
	libxml_clear_errors(); $envelopes++;
	return $document;
}
function providerRequest(array $parameters, array $dependencies=[], ?string $requestError=null): DOMDocument {
	$provider = new Provider(ProviderFixture::$config, (object)['user' => 'synthetic administrator'], array_replace(ProviderFixture::dependencies(), $dependencies));
	return providerValidate($provider->respond($parameters, $requestError));
}
function providerError(DOMDocument $document): ?string { return $document->getElementsByTagName('error')->item(0)?->getAttribute('code'); }
function providerIds(DOMDocument $document): array {
	$xpath = new DOMXPath($document); $xpath->registerNamespace('o', 'http://www.openarchives.org/OAI/2.0/');
	return array_map(static fn($node) => $node->textContent, iterator_to_array($xpath->query('//o:header/o:identifier')));
}
function providerToken(DOMDocument $document): ?string { return $document->getElementsByTagName('resumptionToken')->item(0)?->textContent; }
function providerList(array $extra=[]): DOMDocument { return providerRequest(array_replace(['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc'], $extra)); }
function providerGet($id, string $format='oai_dc'): DOMDocument {
	return providerRequest(['verb' => 'GetRecord', 'metadataPrefix' => $format, 'identifier' => 'oai:'.ProviderFixture::$config['identifier_namespace'].':'.$id]);
}

ProviderFixture::reset();
$identify = providerRequest(['verb' => 'Identify']);
expectProvider(providerError($identify) === null && $identify->getElementsByTagName('deletedRecord')->item(0)->textContent === 'no', 'Identify states honest withdrawal contract.');
expectProvider(str_contains($identify->saveXML(), 'Synthetic &amp; public'), 'Repository names are escaped as text.');
expectProvider($identify->getElementsByTagName('baseURL')->item(0)->textContent === ProviderFixture::$config['oai_base_url'], 'Identify advertises configured stable endpoint.');
$formats = providerRequest(['verb' => 'ListMetadataFormats']);
expectProvider($formats->getElementsByTagName('metadataFormat')->length === 2, 'Both formats are advertised.');
expectProvider(providerError(providerRequest(['verb' => 'ListSets'])) === 'noSetHierarchy', 'Sets are explicitly unsupported.');
$list = providerList();
$expectedIds = ['oai:collections.example.org:1', 'oai:collections.example.org:8'];
expectProvider(providerIds($list) === $expectedIds, 'Frozen candidates are deduplicated, ordered and Catalogue-filtered.');
expectProvider($list->getElementsByTagName('title')->length === 2, 'Native formatter serializes actual DC metadata.');
foreach ([2, 3, 4, 5, 6, 7, 999] as $id) { expectProvider(providerError(providerGet($id)) === 'idDoesNotExist', 'Ineligible direct records cannot bypass Catalogue.'); }
expectProvider(providerError(providerGet('1:trailing')) === 'idDoesNotExist', 'Malformed identifier suffix rejected.');
expectProvider(providerError(providerGet('01')) === 'idDoesNotExist', 'Noncanonical leading-zero identifier rejected.');
expectProvider(providerError(providerGet('999999999999999999999999999999')) === 'idDoesNotExist', 'Overflow identifier rejected.');
$single = providerGet(1);
expectProvider(providerIds($single) === [$expectedIds[0]], 'GetRecord uses the same stable identity.');
expectProvider($single->getElementsByTagName('datestamp')->item(0)->textContent === $list->getElementsByTagName('datestamp')->item(0)->textContent, 'GetRecord and ListRecords use identical datestamps.');
expectProvider(providerIds(providerList(['metadataPrefix' => 'mods'])) === $expectedIds, 'MODS and DC share item eligibility.');
expectProvider(providerIds(providerGet(1, 'mods')) === [$expectedIds[0]], 'Changing format does not change header identifier.');
$identifiers = providerRequest(['verb' => 'ListIdentifiers', 'metadataPrefix' => 'mods']);
expectProvider(providerIds($identifiers) === $expectedIds && $identifiers->getElementsByTagName('metadata')->length === 0, 'ListIdentifiers shares scope without metadata.');
expectProvider(providerError(providerRequest(['verb' => 'ListMetadataFormats', 'identifier' => $expectedIds[0]])) === null, 'Formats available for eligible item.');
expectProvider(providerError(providerRequest(['verb' => 'ListMetadataFormats', 'identifier' => 'oai:collections.example.org:3'])) === 'idDoesNotExist', 'Identifier-specific formats do not reveal excluded items.');

foreach ([['verb' => 'Unknown'], [], ['verb' => 'identify']] as $parameters) { expectProvider(providerError(providerRequest($parameters)) === 'badVerb', 'Invalid verb rejected.'); }
foreach ([['verb' => 'ListRecords'], ['verb' => 'Identify', 'extra' => 'value'], ['verb' => ['Identify']],
	['verb' => 'GetRecord', 'metadataPrefix' => 'oai_dc'], ['verb' => 'ListRecords', 'metadataPrefix' => ''],
	['verb' => 'Identify', 'extra' => "bad\nvalue"], ['verb' => 'Identify', 'extra' => "\xff"]] as $parameters) {
	expectProvider(providerError(providerRequest($parameters)) === 'badArgument', 'Malformed, unknown or missing argument rejected.');
}
expectProvider(providerError(providerRequest(['verb' => 'Identify'], [], 'badArgument')) === 'badArgument', 'Controller duplicate/raw parsing error remains protocol XML.');
expectProvider(providerError(providerList(['metadataPrefix' => 'unknown'])) === 'cannotDisseminateFormat', 'Unknown metadata format rejected.');
expectProvider(providerError(providerList(['set' => 'collection'])) === 'noSetHierarchy', 'Set filtering is never silently ignored.');

$timezone = date_default_timezone_get(); date_default_timezone_set('America/Detroit');
foreach ([['from' => '2025-02-30'], ['from' => '2025-13-01'], ['from' => '0000-01-01'],
	['from' => '2025-01-03', 'until' => '2025-01-02'], ['from' => '2025-01-02', 'until' => '2025-01-02T23:59:59Z']] as $range) {
	expectProvider(providerError(providerList($range)) === 'badArgument', 'Invalid or mixed-granularity dates rejected.');
}
expectProvider(providerError(providerList(['until' => '2025-01-01'])) === 'noRecordsMatch', 'Until-only range is applied.');
expectProvider(providerIds(providerList(['from' => '2025-01-02', 'until' => '2025-01-02'])) === $expectedIds, 'Date-only until covers inclusive full UTC day.');
expectProvider(providerIds(providerList(['until' => '2025-01-02T00:00:01Z'])) === [$expectedIds[0]], 'Second-precision until boundary is inclusive.');
expectProvider(providerIds(providerList(['from' => '2025-01-02T00:00:08Z'])) === [$expectedIds[1]], 'Second-precision from boundary is inclusive.');
expectProvider(providerError(providerList(['from' => '2030-01-01'])) === 'noRecordsMatch', 'Future from-only range returns protocol no-match.');
date_default_timezone_set($timezone);
ProviderFixture::$records[1]['modified'] += 100;
expectProvider(providerGet(1)->getElementsByTagName('datestamp')->item(0)->textContent === gmdate('Y-m-d\TH:i:s\Z', ProviderFixture::$records[1]['modified']), 'Catalogue dependency changes advance the OAI timestamp.');

ProviderFixture::reset(); ProviderFixture::$config['records_per_page'] = 1;
$first = providerList(); $token = providerToken($first);
expectProvider(providerIds($first) === [$expectedIds[0]] && strlen($token) === 48, 'First page has opaque random token.');
expectProvider(strtotime($first->getElementsByTagName('resumptionToken')->item(0)->getAttribute('expirationDate')) === ProviderFixture::$now + 86400, 'Token advertises actual explicit lifetime.');
expectProvider(ProviderFixture::$ttls === [86400, 86400], 'Snapshot and token have explicit matching TTLs.');
ProviderFixture::$records[9] = array_replace(ProviderFixture::$records[8], ['id' => 9]); ProviderFixture::$candidates[] = 9;
$second = providerRequest(['verb' => 'ListRecords', 'resumptionToken' => $token]);
expectProvider(providerIds($second) === [$expectedIds[1]] && providerToken($second) === '', 'Frozen ID snapshot ignores later insertions and closes final page.');
expectProvider(providerIds(providerRequest(['verb' => 'ListRecords', 'resumptionToken' => $token])) === [$expectedIds[1]], 'Resumption token replay is stable.');
expectProvider(providerError(providerRequest(['verb' => 'ListIdentifiers', 'resumptionToken' => $token])) === 'badResumptionToken', 'Token cannot switch verb.');
expectProvider(providerError(providerRequest(['verb' => 'ListRecords', 'resumptionToken' => '../bad'])) === 'badResumptionToken', 'Malformed token rejected.');
expectProvider(providerError(providerRequest(['verb' => 'ListRecords', 'resumptionToken' => $token, 'metadataPrefix' => 'mods'])) === 'badArgument', 'Token is exclusive of other harvest arguments.');
ProviderFixture::$config['oai_base_url'] = 'https://collections.example.org/other';
expectProvider(providerError(providerRequest(['verb' => 'ListRecords', 'resumptionToken' => $token])) === 'badResumptionToken', 'Token is bound to provider endpoint/configuration.');
ProviderFixture::$config['oai_base_url'] = 'https://collections.example.org/service.php/OAI/objects';
ProviderFixture::$now += 86401;
expectProvider(providerError(providerRequest(['verb' => 'ListRecords', 'resumptionToken' => $token])) === 'badResumptionToken', 'Expired token rejected.');

ProviderFixture::reset(); ProviderFixture::$config['records_per_page'] = 1;
$first = providerList(['metadataPrefix' => 'mods']);
$second = providerRequest(['verb' => 'ListRecords', 'resumptionToken' => providerToken($first)]);
expectProvider($second->getElementsByTagNameNS('http://www.loc.gov/mods/v3', 'mods')->length === 1, 'Token preserves nondefault metadata format.');
ProviderFixture::reset(); ProviderFixture::$config['records_per_page'] = 1;
$first = providerRequest(['verb' => 'ListIdentifiers', 'metadataPrefix' => 'mods']);
$second = providerRequest(['verb' => 'ListIdentifiers', 'resumptionToken' => providerToken($first)]);
expectProvider(providerIds($second) === [$expectedIds[1]] && !$second->getElementsByTagName('metadata')->length, 'Identifier pagination retains scope without rendering metadata.');
ProviderFixture::reset(); ProviderFixture::$config['records_per_page'] = 1;
$token = providerToken(providerList());
unset(ProviderFixture::$cache[ProviderFixture::$cache[$token]['snapshot']]);
expectProvider(providerError(providerRequest(['verb' => 'ListRecords', 'resumptionToken' => $token])) === 'badResumptionToken', 'Missing snapshot invalidates token safely.');
ProviderFixture::reset(); ProviderFixture::$config['records_per_page'] = 1;
$token = providerToken(providerList()); ProviderFixture::$records[8] = null;
expectProvider(providerError(providerRequest(['verb' => 'ListRecords', 'resumptionToken' => $token])) === 'noRecordsMatch', 'Withdrawn remaining candidates do not create empty successful list.');
ProviderFixture::reset(); ProviderFixture::$config['records_per_page'] = 1; ProviderFixture::$records[8] = null;
expectProvider(providerToken(providerList()) === null, 'Trailing exclusions do not create a needless final page.');
ProviderFixture::reset(); ProviderFixture::$config['records_per_page'] = 1; ProviderFixture::$cacheFails = true;
$failure = providerList();
expectProvider(providerError($failure) === 'cannotDisseminateFormat' && !$failure->getElementsByTagName('record')->length, 'Cache failure removes partial successful output.');

foreach (['candidates', 'record', 'timestamp', 'identity'] as $fault) {
	ProviderFixture::reset(); ProviderFixture::$fault = $fault;
	$failure = providerList();
	expectProvider(providerError($failure) === 'cannotDisseminateFormat' && !str_contains($failure->saveXML(), 'private failure'), 'Native/data failure returns safe protocol error.');
}
foreach (['', '<broken>', '<root xmlns="urn:wrong"/>', '<wrong xmlns="http://www.openarchives.org/OAI/2.0/oai_dc/"/>', '<!DOCTYPE dc [<!ENTITY secret SYSTEM "file:///synthetic-secret">]><dc xmlns="http://www.openarchives.org/OAI/2.0/oai_dc/">&secret;</dc>'] as $xml) {
	ProviderFixture::reset();
	$failure = providerRequest(['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc'], ['render' => static fn() => $xml]);
	expectProvider(providerError($failure) === 'cannotDisseminateFormat' && !$failure->getElementsByTagName('record')->length, 'Empty, malformed, wrong-namespace and external-entity XML fail closed.');
}
ProviderFixture::reset();
$failure = providerRequest(['verb' => 'GetRecord', 'metadataPrefix' => 'mods', 'identifier' => $expectedIds[0]], ['render' => static function () { throw new RuntimeException('synthetic private failure'); }]);
expectProvider(providerError($failure) === 'cannotDisseminateFormat' && !str_contains($failure->saveXML(), 'private failure'), 'Renderer exception never leaks details.');
foreach (['enable', 'oai_enabled'] as $gate) {
	ProviderFixture::reset(); ProviderFixture::$config[$gate] = false;
	expectProvider(providerError(providerRequest(['verb' => 'Identify'])) === 'cannotDisseminateFormat', 'Metadata and transport must both be enabled.');
}
foreach (['records_per_page' => 0, 'token_ttl' => 0, 'identifier_namespace' => 'bad:namespace', 'admin_email' => 'invalid', 'earliest_datestamp' => '2025-02-30T00:00:00Z', 'repository_name' => "bad\nname"] as $key => $value) {
	ProviderFixture::reset(); ProviderFixture::$config[$key] = $value;
	expectProvider(providerError(providerRequest(['verb' => 'Identify'])) === 'cannotDisseminateFormat', 'Invalid standalone transport configuration fails closed.');
}
$fallback = providerValidate(Provider::unavailable('https://collections.example.org/service.php/OAI/objects'));
expectProvider(providerError($fallback) === 'cannotDisseminateFormat' && $fallback->getElementsByTagName('request')->length === 1, 'Controller setup failure has a complete safe envelope.');

printf("Public OAI standalone provider: %d assertions, %d protocol envelopes, %d strictly validated payloads passed.\n", $checks, $envelopes, $payloads);
