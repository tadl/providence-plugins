<?php
// Execute the trusted native XML formatter without bootstrapping installation settings.
error_reporting(E_ALL & ~E_DEPRECATED); // Providence 2.0.10 passes null to strlen().
require_once dirname(__DIR__).'/lib/Metadata.php';
use TADL\PublicOai\Metadata;

$reference = getenv('PROVIDENCE_REFERENCE_ROOT') ?: dirname(__DIR__, 4).'/providence';
$reference = realpath($reference);
if (!$reference || !is_file($reference.'/app/lib/Export/ExportFormats/ExportXML.php')) { throw new RuntimeException('Set PROVIDENCE_REFERENCE_ROOT to a trusted Providence reference checkout.'); }

/** Load only a named function from trusted source; do not load application settings. */
function loadNativeFunction(string $source, string $name): void {
	$tokens = token_get_all($source);
	for ($i = 0; $i < count($tokens); $i++) {
		if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
		$j = $i + 1;
		while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
		if (!is_array($tokens[$j]) || $tokens[$j][1] !== $name) { continue; }
		$body = ''; $depth = 0; $opened = false;
		for (; $i < count($tokens); $i++) {
			$token = $tokens[$i];
			$body .= is_array($token) ? $token[1] : $token;
			if ($token === '{') { $depth++; $opened = true; }
			if ($token === '}' && --$depth === 0 && $opened) { eval($body); return; }
		}
	}
	throw new RuntimeException('Native function not found: '.$name);
}
$helpers = file_get_contents($reference.'/app/helpers/utilityHelpers.php');
foreach (['caGetOption', 'caEscapeForXML', 'caMakeProperUTF8ForXML'] as $function) { loadNativeFunction($helpers, $function); }
function _t($text) { return $text; }
foreach (['FT_NUMBER', 'DT_CHECKBOXES'] as $constant) { define($constant, 0); }
$base = file_get_contents($reference.'/app/lib/Export/BaseExportFormat.php');
eval(substr($base, strpos($base, 'abstract class BaseExportFormat')));
$formatter = file_get_contents($reference.'/app/lib/Export/ExportFormats/ExportXML.php');
eval(substr($formatter, strpos($formatter, 'class ExportXML extends BaseExportFormat')));

$checks = 0;
function expectMetadata(bool $condition, string $message): void {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}

/** All schema imports resolve to pinned files, including obsolete HTTP import URLs. */
libxml_set_external_entity_loader(static function ($public, $system, $context) {
	$name = basename(parse_url($system, PHP_URL_PATH) ?: '');
	if (!in_array($name, ['mods-3-8.xsd', 'oai_dc.xsd', 'simpledc20021212.xsd', 'xlink.xsd', 'xml.xsd'], true)) { return null; }
	return fopen(__DIR__.'/schema/'.$name, 'r');
});
libxml_use_internal_errors(true);

function document(array $record, string $format): array {
	$tree = Metadata::render($record, $format);
	expectMetadata(count($tree) === 1, 'Native formatter receives one root.');
	$xml = (new ExportXML())->processExport($tree, ['singleRecord' => true]);
	$doc = new DOMDocument();
	expectMetadata($doc->loadXML($xml, LIBXML_NONET), 'Native formatter emits well-formed XML: '.json_encode(libxml_get_errors()));
	$schema = $format === 'mods' ? 'mods-3-8.xsd' : 'oai_dc.xsd';
	$valid = $doc->schemaValidate(__DIR__.'/schema/'.$schema);
	$errors = array_map(static fn($error) => trim($error->message), libxml_get_errors());
	libxml_clear_errors();
	expectMetadata($valid, 'Record validates against official '.$schema.': '.implode('; ', $errors));
	$xpath = new DOMXPath($doc);
	$xpath->registerNamespace('m', Metadata::MODS_NAMESPACE);
	$xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
	return [$doc, $xpath];
}

$special = "Synthetic café & <angle> \"quotes\" 'apostrophe' ]]> 😀\x00\x01\x0b\xEF\xBF\xBE";
$record = [
	'id' => 1001, 'titles' => [$special, $special], 'alternate_titles' => ['Alternate title'],
	'identifiers' => [['type' => 'local', 'value' => 'SYNTHETIC.0001'], ['type' => 'omeka', 'value' => 'SYNTHETIC-LEGACY']],
	'names' => [
		['name' => 'Fictional Person', 'type' => 'ind', 'roles' => ['creator', 'interviewer']],
		['name' => 'Example Press', 'type' => 'org', 'roles' => ['publisher']],
		['name' => 'Example Family', 'type' => 'fam', 'roles' => ['contributor']],
	],
	'dates' => [
		['value' => 'circa 1900–1905', 'type' => 'created', 'start' => '1900', 'end' => '1905', 'qualifier' => 'approximate'],
		['value' => 'Early twentieth century', 'type' => 'not_set'],
		['value' => '1903', 'type' => 'publication'],
	],
	'language' => ['English', 'Czech'], 'description' => ['Full descriptive paragraph.'],
	'notes' => [['type' => 'inscription', 'value' => 'Synthetic inscription'], ['type' => 'source', 'value' => 'Example source'], ['type' => 'coverage', 'value' => 'Example region']],
	'type' => 'still_image', 'genre' => ['Photographs'],
	'subjects' => [['type' => 'topic', 'value' => 'Synthetic subject', 'authority' => 'lcsh', 'uri' => 'https://example.org/authority/1'], ['type' => 'geographic', 'value' => 'Example Town'], ['type' => 'name', 'value' => 'Fictional Person']],
	'physical' => ['form' => ['Photographic print'], 'extent' => ['10 x 15 cm'], 'internet_media_type' => []],
	'rights' => [['value' => 'Synthetic rights statement', 'uri' => 'https://example.org/rights/1']],
	'relations' => [['type' => 'host', 'title' => 'Example Collection', 'url' => 'https://example.org/collections/1', 'identifier' => 'SYNTHETIC.COLLECTION'], ['type' => 'depicts', 'title' => 'Related record']],
	'landing_url' => 'https://example.org/objects/1001?view=full&lang=en',
	'preview_urls' => ['https://example.org/media/1001-small.jpg'],
	'media' => [['url' => 'https://example.org/media/1001.tif', 'mimetype' => 'image/tiff', 'title' => 'Front', 'caption' => 'Synthetic caption']],
	'repository' => 'Example Library',
];

[$mods, $mx] = document($record, 'mods');
expectMetadata($mx->evaluate('count(/m:mods/m:titleInfo[not(@type)])') === 1.0, 'Duplicate titles are removed.');
expectMetadata($mx->evaluate('string(/m:mods/m:titleInfo/m:title)') === preg_replace('/[\x00\x01\x0b]|\x{FFFE}/u', '', $special), 'Unicode and XML punctuation survive; XML 1.0 controls are removed.');
expectMetadata($mx->evaluate('count(/m:mods/m:name[@type="personal"]/m:role)') === 2.0, 'Names retain separate creator and interviewer roles.');
expectMetadata($mx->evaluate('string(/m:mods/m:originInfo/m:publisher)') === 'Example Press', 'Publisher appears in origin information.');
expectMetadata($mx->evaluate('count(/m:mods/m:originInfo/m:dateCreated[@encoding="w3cdtf"])') === 2.0, 'Creation date preserves encoded start and end.');
expectMetadata($mx->evaluate('string(/m:mods/m:originInfo/m:dateOther[@type="not_set"])') === 'Early twentieth century', 'Untyped dates are preserved without invented creation semantics.');
expectMetadata($mx->evaluate('count(/m:mods/m:subject[@authority="lcsh"][@valueURI])') === 1.0, 'Subjects retain authority and validated URI.');
expectMetadata($mx->evaluate('count(/m:mods/m:location/m:url[@access="object in context"][@usage="primary"])') === 1.0, 'MODS 3.8 distinguishes primary contextual landing URL.');
expectMetadata($mx->evaluate('count(/m:mods/m:location/m:url[@access="preview"])') === 1.0, 'Preview is distinct from landing and original URLs.');
expectMetadata($mx->evaluate('count(/m:mods/m:location/m:url[@access="raw object"])') === 1.0, 'One-file object exposes its raw original URL.');
expectMetadata($mx->evaluate('count(/m:mods/m:relatedItem[@type="constituent"])') === 0.0, 'One-file object requires no constituent records.');
expectMetadata($mx->evaluate('count(/m:mods/m:relatedItem[@type="host"])') === 1.0, 'Collection context is a host relation.');
expectMetadata(!str_contains($mods->saveXML(), "\x00"), 'Output contains no NUL byte.');

[$dc, $dx] = document($record, 'oai_dc');
expectMetadata($dx->evaluate('count(/*/dc:title)') === 2.0, 'DC retains primary and alternate title.');
expectMetadata($dx->evaluate('string(/*/dc:creator)') === 'Fictional Person', 'DC creator is a readable name.');
expectMetadata($dx->evaluate('count(/*/dc:contributor[text()="Interviewer: Fictional Person"])') === 1.0, 'DC retains a contributor role in readable text.');
expectMetadata($dx->evaluate('count(/*/dc:identifier)') === 4.0, 'DC retains catalogue, legacy, landing and original identifiers.');
expectMetadata($dx->evaluate('string(/*/dc:source)') === 'Example source', 'Source note remains DC source.');
expectMetadata($dx->evaluate('count(/*/dc:coverage)') === 2.0, 'DC coverage contains structured geographic and legacy coverage values.');

$multiple = $record;
$multiple['media'][] = ['url' => 'https://example.org/media/1001-back.tif', 'mimetype' => 'image/tiff', 'title' => 'Back', 'caption' => 'Second caption'];
$multiple['media'][] = $multiple['media'][0];
[, $many] = document($multiple, 'mods');
expectMetadata($many->evaluate('count(/m:mods/m:relatedItem[@type="constituent"])') === 2.0, 'Multi-file object has one constituent per distinct public file.');
expectMetadata($many->evaluate('count(/m:mods/m:location/m:url[@access="raw object"])') === 0.0, 'Multi-file originals are never labeled as the complete object.');
expectMetadata($many->evaluate('count(/m:mods/m:relatedItem[@type="constituent"]/m:location/m:url[@access="raw object"])') === 2.0, 'Each constituent has its own raw file link.');
expectMetadata($many->evaluate('count(/m:mods/m:physicalDescription/m:internetMediaType)') === 1.0, 'Repeated file MIME types are deduplicated at object level.');

// Viewer URLs are supplied by the adapter; these opaque fixtures assert no route convention.
$withViewer = $record;
$withViewer['landing_url'] = 'https://catalogue.example.org/?synthetic-object=1001';
$withViewer['media'][0]['context_url'] = 'https://catalogue.example.org/?synthetic-viewer=one';
[, $singleViewer] = document($withViewer, 'mods');
expectMetadata($singleViewer->evaluate('string(/m:mods/m:location/m:url[@displayLabel="View representation"])') === $withViewer['media'][0]['context_url'], 'Single-file representation viewer uses the caller-supplied public frontend URL.');
expectMetadata($singleViewer->evaluate('count(/m:mods/m:location/m:url[@access="object in context"][@usage="primary"])') === 1.0, 'Representation viewer does not displace the primary object landing URL.');
expectMetadata($singleViewer->evaluate('string(/m:mods/m:location/m:url[@access="raw object"])') === $record['media'][0]['url'], 'Technical original-file URL remains separately labeled.');
[, $singleViewerDc] = document($withViewer, 'oai_dc');
expectMetadata($singleViewerDc->evaluate('count(/*/dc:identifier)') === 5.0, 'DC retains object landing, representation viewer and original-file identifiers.');
$withViewer['media'][] = ['url' => 'https://media.example.org/second-original.tif', 'mimetype' => 'image/tiff', 'context_url' => 'https://catalogue.example.org/?synthetic-viewer=two'];
[, $multipleViewers] = document($withViewer, 'mods');
expectMetadata($multipleViewers->evaluate('count(/m:mods/m:relatedItem[@type="constituent"]/m:location/m:url[@access="object in context"])') === 2.0, 'Multi-file constituents each expose their own public contextual viewer.');
expectMetadata($multipleViewers->evaluate('count(/m:mods/m:relatedItem[@type="constituent"]/m:location/m:url[@access="raw object"])') === 2.0, 'Contextual viewers keep original-file links distinct within each constituent.');
$withViewer['media'][0]['context_url'] = 'javascript:alert(1)';
[, $unsafeViewer] = document($withViewer, 'mods');
expectMetadata($unsafeViewer->evaluate('count(//m:url[@displayLabel="View representation"])') === 1.0, 'Unsafe optional contextual viewer is omitted while valid media remains available.');

$sparse = ['titles' => ['Minimal synthetic record']];
document($sparse, 'mods');
document($sparse, 'oai_dc');
$badDates = $sparse + ['dates' => [['value' => 'Uncertain source date', 'start' => '1900-13-99', 'end' => '1900-02-30', 'qualifier' => 'invalid']]];
[, $dates] = document($badDates, 'mods');
expectMetadata($dates->evaluate('count(//m:dateOther[@encoding or @qualifier])') === 0.0, 'Malformed normalized dates and unknown qualifiers are not asserted as structured dates.');
expectMetadata($dates->evaluate('string(//m:dateOther)') === 'Uncertain source date', 'The original uncertain date remains intact.');
$displayDates = $sparse + ['dates' => array_map(static fn($type) => ['value' => '1901', 'type' => $type], ['Date created', 'Date issued', 'Date copyrighted', 'Date modified', 'Recording date', 'Inclusive', 'Bulk Date'])];
[, $typedDates] = document($displayDates, 'mods');
expectMetadata($typedDates->evaluate('count(//m:dateCreated)') === 2.0 && $typedDates->evaluate('count(//m:dateIssued|//m:copyrightDate|//m:dateModified)') === 3.0, 'Verified live display labels map to the appropriate MODS date element.');
expectMetadata($typedDates->evaluate('count(//m:dateOther[@type="Inclusive"]|//m:dateOther[@type="Bulk Date"])') === 2.0, 'Archival inclusive and bulk dates preserve their distinctions.');
$invalid = $sparse + ['landing_url' => 'javascript:alert(1)', 'preview_urls' => ['data:image/png,abc', "https://example.org/\x00private"], 'media' => [['url' => 'https://user:password@example.org/file'], ['url' => '//example.org/file']], 'rights' => [['value' => 'Text only', 'uri' => 'file:///private/secret']]];
[, $safe] = document($invalid, 'mods');
expectMetadata($safe->evaluate('count(//m:url|//@valueURI)') === 0.0, 'Unsafe schemes, relative URLs and URL credentials are omitted.');
$invalid['titles'] = ["Broken UTF-8 \xC0\x80 and valid café"];
document($invalid, 'mods');
document($invalid, 'oai_dc');
$unsupported = false;
try { Metadata::render($record, 'unknown'); } catch (InvalidArgumentException $error) { $unsupported = true; }
expectMetadata($unsupported, 'Unsupported metadata prefixes fail explicitly.');

// The two importable TSV placeholders read only a non-sensitive primary key.
foreach (['tadl_public_oai_mods', 'tadl_public_oai_dc'] as $code) {
	$handle = fopen(dirname(__DIR__).'/mappings/'.$code.'.tsv', 'r');
	$rows = [];
	while (($row = fgetcsv($handle, 0, "\t", '"', '')) !== false) { $rows[] = $row; }
	fclose($handle);
	$settings = []; $ids = [];
	foreach (array_slice($rows, 1) as $row) {
		expectMetadata(count($row) === 9, 'TSV rows retain all nine native columns.');
		if ($row[0] === 'Setting') { $settings[$row[1]] = $row[2]; continue; }
		expectMetadata($row[2] === '' || isset($ids[$row[2]]), 'Native mapping parents precede children.');
		$ids[$row[1]] = true;
		expectMetadata($row[0] === 'Constant' || $row[4] === '' || $row[4] === 'ca_objects.object_id', 'Placeholder cannot export unreviewed descriptive data.');
	}
	expectMetadata($settings['code'] === $code && $settings['table'] === 'ca_objects' && $settings['exporter_format'] === 'XML', 'TSV defines the exact public object XML mapping.');
}
libxml_set_external_entity_loader(null);
echo 'Public OAI metadata: '.$checks." checks passed.\n";
