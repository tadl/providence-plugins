<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace TADL\PublicOai;

require_once __DIR__.'/Catalogue.php';
require_once __DIR__.'/Metadata.php';

/** Public OAI-PMH transport owned entirely by this application plugin. */
final class Provider {
	private const OAI = 'http://www.openarchives.org/OAI/2.0/';
	private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';
	private const IDENTIFIER = 'http://www.openarchives.org/OAI/2.0/oai-identifier';
	private const CACHE_NAMESPACE = 'publicOaiProvider';
	private const FORMATS = [
		'oai_dc' => ['namespace' => Metadata::DC_NAMESPACE, 'schema' => Metadata::DC_SCHEMA, 'root' => 'dc'],
		'mods' => ['namespace' => Metadata::MODS_NAMESPACE, 'schema' => Metadata::MODS_SCHEMA, 'root' => 'mods']
	];
	private $config;
	private $request;
	private $dependencies;
	private $document;
	private $root;

	/** Dependency callbacks isolate the catalogue/cache/clock boundaries in offline tests. */
	public function __construct(array $config, $request=null, array $dependencies=[]) {
		$this->config = $config;
		$this->request = $request;
		$this->dependencies = $dependencies;
	}

	/** The controller reports raw duplicate/malformed parameter errors before flattening. */
	public function respond(array $parameters, ?string $requestError=null): string {
		$this->newDocument((string)($this->config['oai_base_url'] ?? ''));
		try {
			if ($requestError !== null) { throw new ProviderError('badArgument'); }
			$this->validateConfiguration();
			$this->validateParameters($parameters);
			$request = $this->root->getElementsByTagNameNS(self::OAI, 'request')->item(0);
			foreach ($parameters as $key => $value) { $request->setAttribute($key, $value); }
			switch ($parameters['verb']) {
				case 'Identify': $this->identify(); break;
				case 'ListMetadataFormats': $this->formats($parameters); break;
				case 'ListSets': throw new ProviderError('noSetHierarchy');
				case 'GetRecord': $this->getRecord($parameters); break;
				case 'ListRecords':
				case 'ListIdentifiers': $this->listRecords($parameters); break;
			}
		} catch (ProviderError $error) {
			$this->error($error->protocolCode);
		} catch (\Throwable $error) {
			$this->error('cannotDisseminateFormat');
		}
		return $this->document->saveXML();
	}

	/** Safe fallback for a controller unable to load its local configuration. */
	public static function unavailable(string $baseUrl=''): string {
		$provider = new self(['oai_base_url' => $baseUrl]);
		$provider->newDocument($baseUrl);
		$provider->error('cannotDisseminateFormat');
		return $provider->document->saveXML();
	}

	private function newDocument(string $baseUrl): void {
		$this->document = new \DOMDocument('1.0', 'UTF-8');
		$this->document->formatOutput = true;
		$this->root = $this->document->appendChild($this->document->createElementNS(self::OAI, 'OAI-PMH'));
		$this->root->setAttributeNS(self::XSI, 'xsi:schemaLocation', self::OAI.' '.self::OAI.'OAI-PMH.xsd');
		$this->element($this->root, 'responseDate', self::utc($this->now()));
		$this->element($this->root, 'request', $baseUrl);
	}

	private function validateConfiguration(): void {
		$url = $this->config['oai_base_url'] ?? '';
		$parts = is_string($url) ? parse_url($url) : false;
		if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || !filter_var($url, FILTER_VALIDATE_URL)
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
			|| isset($parts['port']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
			|| ($this->config['enable'] ?? false) !== true || ($this->config['oai_enabled'] ?? false) !== true
			|| !is_string($this->config['repository_name'] ?? null) || trim($this->config['repository_name']) === ''
			|| !preg_match('//u', $this->config['repository_name']) || preg_match('/[\x00-\x1f\x7f]/', $this->config['repository_name'])
			|| !is_string($this->config['identifier_namespace'] ?? null)
			|| !preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]*$/D', $this->config['identifier_namespace'])
			|| !filter_var($this->config['admin_email'] ?? '', FILTER_VALIDATE_EMAIL)
			|| !is_string($this->config['earliest_datestamp'] ?? null) || strlen($this->config['earliest_datestamp']) !== 20
			|| $this->date($this->config['earliest_datestamp'], false) === null) {
			throw new ProviderError('cannotDisseminateFormat');
		}
		foreach (['records_per_page' => [1, 500], 'token_ttl' => [60, 86400]] as $key => [$minimum, $maximum]) {
			if (!is_int($this->config[$key] ?? null) || $this->config[$key] < $minimum || $this->config[$key] > $maximum) {
				throw new ProviderError('cannotDisseminateFormat');
			}
		}
	}

	private function validateParameters(array $parameters): void {
		foreach ($parameters as $key => $value) {
			if (!is_string($key) || !is_string($value) || $value === '' || !preg_match('//u', $value)
				|| preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new ProviderError('badArgument'); }
		}
		$verb = $parameters['verb'] ?? '';
		if (!in_array($verb, ['Identify', 'ListMetadataFormats', 'ListSets', 'GetRecord', 'ListRecords', 'ListIdentifiers'], true)) {
			throw new ProviderError('badVerb');
		}
		if (isset($parameters['resumptionToken'])) {
			if (!in_array($verb, ['ListRecords', 'ListIdentifiers'], true) || count($parameters) !== 2) { throw new ProviderError('badArgument'); }
			return;
		}
		$required = ['verb'];
		$optional = [];
		if ($verb === 'GetRecord') { $required = ['verb', 'metadataPrefix', 'identifier']; }
		if (in_array($verb, ['ListRecords', 'ListIdentifiers'], true)) {
			$required = ['verb', 'metadataPrefix']; $optional = ['from', 'until', 'set'];
		}
		if ($verb === 'ListMetadataFormats') { $optional = ['identifier']; }
		if (array_diff($required, array_keys($parameters)) || array_diff(array_keys($parameters), $required, $optional)) {
			throw new ProviderError('badArgument');
		}
		if (isset($parameters['metadataPrefix']) && !isset(self::FORMATS[$parameters['metadataPrefix']])) { throw new ProviderError('cannotDisseminateFormat'); }
		if (isset($parameters['set'])) { throw new ProviderError('noSetHierarchy'); }
		$from = $parameters['from'] ?? null; $until = $parameters['until'] ?? null;
		$start = $this->date($from, false); $end = $this->date($until, true);
		if (($from !== null && $start === null) || ($until !== null && $end === null)
			|| ($from !== null && $until !== null && strlen($from) !== strlen($until))
			|| ($start !== null && $end !== null && $start > $end)) { throw new ProviderError('badArgument'); }
	}

	private function identify(): void {
		$identify = $this->element($this->root, 'Identify');
		foreach (['repositoryName' => $this->config['repository_name'], 'baseURL' => $this->config['oai_base_url'],
			'protocolVersion' => '2.0', 'adminEmail' => $this->config['admin_email'],
			'earliestDatestamp' => $this->config['earliest_datestamp'], 'deletedRecord' => 'no',
			'granularity' => 'YYYY-MM-DDThh:mm:ssZ'] as $key => $value) { $this->element($identify, $key, $value); }
		$description = $this->element($identify, 'description');
		$identifier = $description->appendChild($this->document->createElementNS(self::IDENTIFIER, 'oai-identifier'));
		$identifier->setAttributeNS(self::XSI, 'xsi:schemaLocation', self::IDENTIFIER.' '.self::IDENTIFIER.'.xsd');
		foreach (['scheme' => 'oai', 'repositoryIdentifier' => $this->config['identifier_namespace'],
			'delimiter' => ':', 'sampleIdentifier' => $this->identifier(1)] as $key => $value) {
			$node = $identifier->appendChild($this->document->createElementNS(self::IDENTIFIER, $key));
			$node->appendChild($this->document->createTextNode($value));
		}
	}

	private function formats(array $parameters): void {
		if (isset($parameters['identifier']) && $this->record($this->localId($parameters['identifier'])) === null) {
			throw new ProviderError('idDoesNotExist');
		}
		$parent = $this->element($this->root, 'ListMetadataFormats');
		foreach (self::FORMATS as $prefix => $format) {
			$node = $this->element($parent, 'metadataFormat');
			foreach (['metadataPrefix' => $prefix, 'schema' => $format['schema'], 'metadataNamespace' => $format['namespace']] as $key => $value) {
				$this->element($node, $key, $value);
			}
		}
	}

	private function getRecord(array $parameters): void {
		$id = $this->localId($parameters['identifier']);
		$record = $this->record($id);
		if ($record === null) { throw new ProviderError('idDoesNotExist'); }
		$metadata = $this->metadata($record, $parameters['metadataPrefix']);
		$parent = $this->element($this->root, 'GetRecord');
		$this->appendRecord($parent, $record, $metadata);
	}

	private function listRecords(array $parameters): void {
		$verb = $parameters['verb']; $token = $parameters['resumptionToken'] ?? null;
		if ($token !== null) {
			if (!preg_match('/^[a-f0-9]{48}$/D', $token)) { throw new ProviderError('badResumptionToken'); }
			$state = $this->cacheGet($token);
			if (!is_array($state) || ($state['expires'] ?? 0) <= $this->now() || ($state['verb'] ?? '') !== $verb
				|| !isset(self::FORMATS[$state['prefix'] ?? '']) || ($state['binding'] ?? '') !== $this->binding($state['prefix'])) {
				throw new ProviderError('badResumptionToken');
			}
			$ids = $this->cacheGet($state['snapshot']);
			if (!is_array($ids)) { throw new ProviderError('badResumptionToken'); }
		} else {
			$ids = $this->candidateIds();
			$state = ['verb' => $verb, 'prefix' => $parameters['metadataPrefix'], 'offset' => 0, 'delivered' => 0,
				'expires' => $this->now() + $this->config['token_ttl'], 'snapshot' => bin2hex(random_bytes(24)),
				'from' => $this->date($parameters['from'] ?? null, false), 'until' => $this->date($parameters['until'] ?? null, true),
				'binding' => $this->binding($parameters['metadataPrefix'])];
		}
		$records = []; $offset = $state['offset'];
		while ($offset < count($ids) && count($records) < $this->config['records_per_page']) {
			$record = $this->record($ids[$offset++]);
			if (!$this->inRange($record, $state)) { continue; }
			$records[] = [$record, $verb === 'ListRecords' ? $this->metadata($record, $state['prefix']) : null];
		}
		// Avoid a needless empty final page caused by trailing ineligible candidates.
		while ($offset < count($ids) && !$this->inRange($this->record($ids[$offset]), $state)) { $offset++; }
		if (!$records) { throw new ProviderError('noRecordsMatch'); }
		$parent = $this->element($this->root, $verb);
		foreach ($records as [$record, $metadata]) { $this->appendRecord($parent, $record, $metadata, $verb === 'ListIdentifiers'); }
		if ($offset < count($ids)) {
			$next = bin2hex(random_bytes(24)); $ttl = max(1, $state['expires'] - $this->now());
			$nextState = array_replace($state, ['offset' => $offset, 'delivered' => $state['delivered'] + count($records)]);
			if (($token === null && !$this->cachePut($state['snapshot'], $ids, $ttl)) || !$this->cachePut($next, $nextState, $ttl)) {
				throw new \RuntimeException('Resumption cache is unavailable.');
			}
			$node = $this->element($parent, 'resumptionToken', $next);
			$node->setAttribute('expirationDate', self::utc($state['expires']));
			$node->setAttribute('cursor', (string)$state['delivered']);
		} elseif ($token !== null) { $this->element($parent, 'resumptionToken'); }
	}

	private function candidateIds(): array {
		if (isset($this->dependencies['candidates'])) { $ids = ($this->dependencies['candidates'])(); }
		else {
			$rows = (new \Db())->query('SELECT object_id FROM ca_objects WHERE access = 1 AND deleted = 0 ORDER BY object_id', []);
			$ids = []; while ($rows->nextRow()) { $ids[] = (int)$rows->get('object_id'); }
		}
		foreach ($ids as $id) { if (!is_int($id) || $id < 1) { throw new \RuntimeException('Invalid candidate identifier.'); } }
		$ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private function record(int $id): ?array {
		$record = isset($this->dependencies['record']) ? ($this->dependencies['record'])($id)
			: (new Catalogue($this->config, $this->request))->record($id);
		if ($record === null) { return null; }
		if (($record['id'] ?? null) !== $id || !is_int($record['modified'] ?? null) || $record['modified'] <= 0) {
			throw new \RuntimeException('Invalid public record.');
		}
		return $record;
	}

	private function metadata(array $record, string $prefix): \DOMNode {
		if (isset($this->dependencies['render'])) { $xml = ($this->dependencies['render'])($record, $prefix); }
		else {
			if (!class_exists('ExportXML')) { require_once __CA_LIB_DIR__.'/Export/ExportFormats/ExportXML.php'; }
			$xml = (new \ExportXML())->processExport(Metadata::render($record, $prefix), ['singleRecord' => true]);
		}
		if (!is_string($xml) || trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false) { throw new \RuntimeException('Invalid metadata XML.'); }
		$previous = libxml_use_internal_errors(true);
		try {
			$document = new \DOMDocument();
			if (!$document->loadXML($xml, LIBXML_NONET) || !$document->documentElement || $document->doctype
				|| $document->documentElement->namespaceURI !== self::FORMATS[$prefix]['namespace']
				|| $document->documentElement->localName !== self::FORMATS[$prefix]['root']) { throw new \RuntimeException('Invalid metadata XML.'); }
			return $this->document->importNode($document->documentElement, true);
		} finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
	}

	private function appendRecord(\DOMNode $parent, array $record, ?\DOMNode $metadata, bool $headerOnly=false): void {
		$node = $headerOnly ? $parent : $this->element($parent, 'record');
		$header = $this->element($node, 'header');
		$this->element($header, 'identifier', $this->identifier($record['id']));
		$this->element($header, 'datestamp', self::utc($record['modified']));
		if ($metadata !== null) { $this->element($node, 'metadata')->appendChild($metadata); }
	}

	private function localId(string $identifier): int {
		if (!preg_match('~^oai:'.preg_quote($this->config['identifier_namespace'], '~').':([1-9][0-9]*)$~D', $identifier, $matches)
			|| (string)(int)$matches[1] !== $matches[1]) { throw new ProviderError('idDoesNotExist'); }
		return (int)$matches[1];
	}
	private function identifier(int $id): string { return 'oai:'.$this->config['identifier_namespace'].':'.$id; }
	private function binding(string $prefix): string { return hash('sha256', serialize(['publicOai-1', $this->config, $prefix])); }
	private function now(): int { return isset($this->dependencies['now']) ? ($this->dependencies['now'])() : time(); }
	private static function utc(int $timestamp): string { return gmdate('Y-m-d\TH:i:s\Z', $timestamp); }
	private function cacheGet(string $key) { return isset($this->dependencies['cache_get']) ? ($this->dependencies['cache_get'])($key) : \ExternalCache::fetch($key, self::CACHE_NAMESPACE); }
	private function cachePut(string $key, array $value, int $ttl): bool {
		return isset($this->dependencies['cache_put']) ? ($this->dependencies['cache_put'])($key, $value, $ttl)
			: \ExternalCache::save($key, $value, self::CACHE_NAMESPACE, $ttl);
	}
	private function inRange(?array $record, array $state): bool {
		return $record !== null && ($state['from'] === null || $record['modified'] >= $state['from'])
			&& ($state['until'] === null || $record['modified'] <= $state['until']);
	}
	private function date(?string $value, bool $end): ?int {
		if ($value === null || $value === '' || !preg_match('/^(?:[0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)$/D', $value)
			|| (int)substr($value, 0, 4) < 1) { return null; }
		$day = strlen($value) === 10; $format = $day ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z';
		$date = \DateTimeImmutable::createFromFormat('!'.$format, $value, new \DateTimeZone('UTC'));
		if (!$date || $date->format($format) !== $value) { return null; }
		return $date->getTimestamp() + ($day && $end ? 86399 : 0);
	}
	private function element(\DOMNode $parent, string $name, ?string $text=null): \DOMElement {
		$node = $parent->appendChild($this->document->createElementNS(self::OAI, $name));
		if ($text !== null) { $node->appendChild($this->document->createTextNode($text)); }
		return $node;
	}
	private function error(string $code): void {
		foreach (iterator_to_array($this->root->childNodes) as $node) {
			if (!in_array($node->localName, ['responseDate', 'request'], true)) { $this->root->removeChild($node); }
		}
		if (in_array($code, ['badArgument', 'badVerb'], true)) {
			$request = $this->root->getElementsByTagNameNS(self::OAI, 'request')->item(0);
			while ($request->attributes->length) { $request->removeAttributeNode($request->attributes->item(0)); }
		}
		$error = $this->element($this->root, 'error', 'The public repository cannot complete this request.');
		$error->setAttribute('code', $code);
	}
}

/** Internal protocol control flow; its details are never copied from caught native errors. */
final class ProviderError extends \RuntimeException {
	public $protocolCode;
	public function __construct(string $code) { parent::__construct($code); $this->protocolCode = $code; }
}
