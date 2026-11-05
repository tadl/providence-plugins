<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace TADL\PublicOai;
require_once __DIR__.'/Config.php';

/** Standalone transport settings; native oai_provider.conf is never consulted. */
final class ProviderConfig {
	public static function load(string $root): array {
		$values = Config::load($root);
		$shared = \Configuration::load($root.'/conf/oai.conf');
		$local = is_file($root.'/conf/oai.local.conf') ? \Configuration::load($root.'/conf/oai.local.conf') : null;
		foreach (['oai_enabled', 'oai_base_url', 'identifier_namespace', 'admin_email', 'earliest_datestamp', 'records_per_page', 'token_ttl'] as $key) {
			$value = $local ? $local->getScalar($key) : false;
			$values[$key] = $value !== false ? $value : $shared->getScalar($key);
		}
		return self::normalize($values);
	}

	public static function normalize(array $values): array {
		$values['oai_enabled'] = (string)($values['oai_enabled'] ?? '0') === '1';
		foreach (['oai_base_url', 'identifier_namespace', 'admin_email', 'earliest_datestamp'] as $key) {
			$values[$key] = trim((string)($values[$key] ?? ''));
		}
		$url = $values['oai_base_url'];
		if ($url !== '') {
			$parts = parse_url($url);
			if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !filter_var($url, FILTER_VALIDATE_URL)
				|| !filter_var($parts['host'] ?? '', FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
				|| strpos($parts['host'] ?? '', '.') === false
				|| isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
				|| preg_match('/[\x00-\x20\x7f\\\\]/', $url) || !Config::safeMediaPath($parts['path'] ?? '')) {
				throw new \InvalidArgumentException('oai_base_url must be a canonical HTTPS URL.');
			}
		}
		if ($values['identifier_namespace'] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,254}$/D', $values['identifier_namespace'])) {
			throw new \InvalidArgumentException('identifier_namespace must be a stable identifier without colons or whitespace.');
		}
		if ($values['admin_email'] !== '' && (!filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\x00-\x20\x7f]/', $values['admin_email']))) {
			throw new \InvalidArgumentException('admin_email must be a valid email address.');
		}
		if ($values['earliest_datestamp'] !== '') {
			$date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $values['earliest_datestamp'], new \DateTimeZone('UTC'));
			if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $values['earliest_datestamp']) {
				throw new \InvalidArgumentException('earliest_datestamp must be a valid UTC date and time.');
			}
		}
		foreach (['records_per_page' => [50, 1, 500], 'token_ttl' => [86400, 60, 86400]] as $key => [$default, $minimum, $maximum]) {
			$value = $values[$key] ?? $default;
			if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)
				|| (float)$value < $minimum || (float)$value > $maximum) {
				throw new \InvalidArgumentException($key.' is outside the supported range.');
			}
			$values[$key] = (int)$value;
		}
		if ($values['oai_enabled']) {
			if (empty($values['enable'])) { throw new \InvalidArgumentException('Enable public metadata before enabling the OAI transport.'); }
			foreach (['oai_base_url', 'identifier_namespace', 'admin_email', 'earliest_datestamp'] as $key) {
				if ($values[$key] === '') { throw new \InvalidArgumentException('Enabled OAI transport requires '.$key.'.'); }
			}
		}
		return $values;
	}
}
