<?php
/** SPDX-License-Identifier: GPL-3.0-or-later. Standalone controller boundary checks. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
class BaseServiceController {
	public $request; public $response;
	public function __construct($request, $response, $paths=null) { $this->request = $request; $this->response = $response; }
}
require_once __DIR__.'/../controllers/RepositoryController.php';

class ControllerRequest {
	public $method;
	public function __construct(string $method) { $this->method = $method; }
	public function getRequestMethod() { return $this->method; }
}
class ControllerResponse {
	public $code; public $type; public $headers = []; public $content = 'SECRET previous response';
	public function setHTTPResponseCode($code, $message) { $this->code = $code; }
	public function setContentType($type) { $this->type = $type; return true; }
	public function addHeader($name, $value) { $this->headers[$name] = $value; }
	public function clearContent() { $this->content = ''; }
	public function addContent($content) { $this->content .= $content; }
}
class TestRepositoryController extends RepositoryController {
	public $query = ''; public $body = ''; public $type = '';
	public static $seen = []; public static $configFails = false; public static $providerFails = false; public static $enabled = true;
	protected function configuration(): array {
		if (self::$configFails) { throw new RuntimeException('SECRET configuration detail'); }
		return ['enable' => true, 'oai_enabled' => self::$enabled, 'repository_name' => 'Example Archive',
			'oai_base_url' => 'https://collections.example.org/service.php/publicOai/Repository/Index',
			'identifier_namespace' => 'collections.example.org', 'admin_email' => 'metadata@example.org',
			'earliest_datestamp' => '2000-01-01T00:00:00Z', 'records_per_page' => 50, 'token_ttl' => 86400];
	}
	protected function provider(array $config) {
		return new class($config) {
			private $config;
			public function __construct(array $config) { $this->config = $config; }
			public function respond(array $parameters, ?string $error=null): string {
				TestRepositoryController::$seen = [$parameters, $error];
				if (TestRepositoryController::$providerFails) { throw new RuntimeException('SECRET provider detail'); }
				return (new \TADL\PublicOai\Provider($this->config, null, ['record' => static fn($id) => null,
					'candidates' => static fn() => []]))->respond($parameters, $error);
			}
		};
	}
	protected function queryString(): string { return $this->query; }
	protected function contentType(): string { return $this->type; }
	protected function requestBody(): string { return $this->body; }
}
$checks = 0;
function controllerCheck($condition, string $message): void {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function controllerCall(string $query, string $method='GET', string $body='', string $type=''): ControllerResponse {
	$response = new ControllerResponse();
	$controller = new TestRepositoryController(new ControllerRequest($method), $response);
	$controller->query = $query; $controller->body = $body; $controller->type = $type;
	$controller->Index();
	controllerCheck($response->code === 200 && $response->type === 'text/xml; charset=UTF-8', 'Protocol responses use HTTP 200 and UTF-8 XML');
	controllerCheck($response->headers['Cache-Control'] === 'no-store' && !str_contains($response->content, 'SECRET'), 'Only fresh XML is returned without internal details');
	return $response;
}
function controllerError(ControllerResponse $response): string {
	$xml = new DOMDocument(); controllerCheck($xml->loadXML($response->content), 'Controller returns well-formed XML');
	return $xml->getElementsByTagNameNS('http://www.openarchives.org/OAI/2.0/', 'error')->item(0)?->getAttribute('code') ?? '';
}

// Match each native boundary method instead of inventing synthetic-only APIs.
$reference = getenv('PROVIDENCE_REFERENCE_ROOT') ?: dirname(__DIR__, 4).'/providence';
foreach (['Controller/Request/RequestHTTP.php' => ['getRequestMethod'],
	'Controller/Response/ResponseHTTP.php' => ['setHTTPResponseCode', 'setContentType', 'addHeader', 'clearContent', 'addContent']] as $file => $methods) {
	$source = file_get_contents($reference.'/app/lib/'.$file);
	foreach ($methods as $method) { controllerCheck((bool)preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $source), 'Controller API exists in native source: '.$method); }
}

$_COOKIE = ['verb' => 'GetRecord', 'identifier' => 'SECRET cookie'];
$_GET = $_POST = $_REQUEST = ['verb' => 'GetRecord'];
$response = controllerCall('verb=Identify');
controllerCheck(controllerError($response) === '' && TestRepositoryController::$seen === [['verb' => 'Identify'], null], 'Raw GET parameters exclude cookies and already-flattened request globals');
$response = controllerCall('', 'POST', 'verb=Identify', 'application/x-www-form-urlencoded; charset="UTF-8"');
controllerCheck(controllerError($response) === '' && TestRepositoryController::$seen === [['verb' => 'Identify'], null], 'UTF-8 form POST works');
$response = controllerCall('verb=GetRecord', 'POST', 'identifier=oai%3Acollections.example.org%3A7&metadataPrefix=oai_dc', 'application/x-www-form-urlencoded');
controllerCheck(controllerError($response) === 'idDoesNotExist' && TestRepositoryController::$seen[0]['identifier'] === 'oai:collections.example.org:7', 'Unique query/body parameters combine without losing encoded identifier characters');

$cases = [
	['verb=Identify&verb=Identify'], ['verb=Identify&v%65rb=Identify'], ['verb%5B%5D=Identify'],
	['verb=Identify&%76erb%5Bx%5D=Identify'], ['verb=Ident%zzify'], ['verb=%FF'], ['verb=Identify%00'], ['verb=Identify&&'],
	['verb=Identify', 'POST', 'verb=Identify', 'application/x-www-form-urlencoded'],
	['', 'POST', 'verb=Identify', 'application/json'],
	['', 'POST', 'verb=Identify', 'application/x-www-form-urlencoded; charset=ISO-8859-1'],
	['', 'POST', 'verb=Identify', 'application/x-www-form-urlencoded; charset=UTF-8; charset=UTF-8'],
	['verb=Identify', 'DELETE'], ['verb=Identify', 'PUT'], ['verb=Identify', 'HEAD'],
	['verb='.str_repeat('x', 4097)], ['verb='.str_repeat('x', 17000)],
	['', 'POST', 'verb='.str_repeat('x', 17000), 'application/x-www-form-urlencoded']
];
foreach ($cases as $case) {
	$response = controllerCall(...$case);
	controllerCheck(controllerError($response) === 'badArgument' && TestRepositoryController::$seen === [[], 'badArgument'], 'Malformed/duplicate/oversized request fails before argument flattening');
}
$pairs = []; for ($i=0; $i<17; $i++) { $pairs[] = 'key'.$i.'=value'; }
controllerCheck(controllerError(controllerCall(join('&', $pairs))) === 'badArgument' && TestRepositoryController::$seen[0] === [], 'Parameter-count limit is enforced before provider dispatch');
controllerCheck(controllerError(controllerCall('')) === 'badVerb', 'Missing verb remains an OAI protocol error');
TestRepositoryController::$configFails = true;
controllerCheck(controllerError(controllerCall('verb=Identify')) === 'cannotDisseminateFormat', 'Configuration failure returns a safe protocol error');
TestRepositoryController::$configFails = false; TestRepositoryController::$providerFails = true;
controllerCheck(controllerError(controllerCall('verb=Identify')) === 'cannotDisseminateFormat', 'Unexpected provider failure returns a safe protocol error');
TestRepositoryController::$providerFails = false; TestRepositoryController::$enabled = false;
controllerCheck(controllerError(controllerCall('verb=Identify')) === 'cannotDisseminateFormat', 'Disabled transport never serves records');
echo "Public OAI controller: {$checks} checks passed\n";
