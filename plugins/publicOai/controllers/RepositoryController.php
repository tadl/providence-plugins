<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
if (!class_exists('BaseServiceController', false)) { require_once __CA_LIB_DIR__.'/Service/BaseServiceController.php'; }
require_once dirname(__DIR__).'/lib/ProviderConfig.php';
require_once dirname(__DIR__).'/lib/Provider.php';

/** Public module controller; all record authorization belongs to Catalogue. */
class RepositoryController extends BaseServiceController {
	private const MAX_BYTES = 16384;
	private const MAX_PARAMETERS = 16;

	public function Index() {
		$config = []; $parameters = []; $requestError = null;
		try { $parameters = $this->parameters(); }
		catch (\InvalidArgumentException $error) { $requestError = 'badArgument'; }
		try {
			$config = $this->configuration();
			$xml = $this->provider($config)->respond($parameters, $requestError);
		} catch (\Throwable $error) {
			$xml = \TADL\PublicOai\Provider::unavailable((string)($config['oai_base_url'] ?? ''));
		}
		$this->response->setHTTPResponseCode(200, 'OK');
		$this->response->setContentType('text/xml; charset=UTF-8');
		$this->response->addHeader('Cache-Control', 'no-store');
		$this->response->clearContent();
		$this->response->addContent($xml);
	}

	protected function configuration(): array { return \TADL\PublicOai\ProviderConfig::load(dirname(__DIR__)); }
	protected function provider(array $config) { return new \TADL\PublicOai\Provider($config, $this->request); }
	protected function queryString(): string { return (string)($_SERVER['QUERY_STRING'] ?? ''); }
	protected function contentType(): string { return (string)($_SERVER['CONTENT_TYPE'] ?? ''); }
	protected function requestBody(): string {
		$body = file_get_contents('php://input', false, null, 0, self::MAX_BYTES + 1);
		if (!is_string($body)) { throw new \InvalidArgumentException('Cannot read request body.'); }
		return $body;
	}

	private function parameters(): array {
		$method = strtoupper((string)$this->request->getRequestMethod());
		if (!in_array($method, ['GET', 'POST'], true)) { throw new \InvalidArgumentException('Unsupported method.'); }
		$query = $this->queryString(); $body = '';
		if ($method === 'POST') {
			$pieces = explode(';', $this->contentType());
			if (strtolower(trim(array_shift($pieces))) !== 'application/x-www-form-urlencoded') { throw new \InvalidArgumentException('Unsupported request encoding.'); }
			$charsetSeen = false;
			foreach ($pieces as $piece) {
				if (!preg_match('/^\s*charset\s*=\s*(?:"UTF-8"|UTF-8)\s*$/iD', $piece) || $charsetSeen) { throw new \InvalidArgumentException('Unsupported charset.'); }
				$charsetSeen = true;
			}
			$body = $this->requestBody();
		}
		if (strlen($query) + strlen($body) > self::MAX_BYTES) { throw new \InvalidArgumentException('Request is too large.'); }
		$parameters = [];
		foreach ([$query, $body] as $encoded) {
			if ($encoded === '') { continue; }
			foreach (explode('&', $encoded) as $pair) {
				if ($pair === '' || strpos($pair, '=') === false || preg_match('/%(?![0-9a-f]{2})/i', $pair)) { throw new \InvalidArgumentException('Malformed parameter.'); }
				[$key, $value] = array_map('urldecode', explode('=', $pair, 2));
				if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $key) || array_key_exists($key, $parameters)
					|| preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)
					|| strlen($value) > 4096 || count($parameters) >= self::MAX_PARAMETERS) { throw new \InvalidArgumentException('Invalid parameter.'); }
				$parameters[$key] = $value;
			}
		}
		return $parameters;
	}
}
