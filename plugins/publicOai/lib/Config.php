<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace TADL\PublicOai;

final class Config {
	public static function load(string $root): array {
		$shared = \Configuration::load($root.'/conf/plugin.conf');
		$local = is_file($root.'/conf/plugin.local.conf') ? \Configuration::load($root.'/conf/plugin.local.conf') : null;
		$values = [];
		foreach (['enable', 'provider_key', 'public_base_url', 'media_base_url', 'repository_name', 'item_acl_policy'] as $key) {
			$value = $local ? $local->getScalar($key) : false;
			$values[$key] = $value !== false ? $value : $shared->getScalar($key);
		}
		$values['allowed_media_hosts'] = $local && $local->exists('allowed_media_hosts')
			? $local->getList('allowed_media_hosts') : $shared->getList('allowed_media_hosts');
		return self::normalize($values);
	}

	public static function normalize(array $values): array {
		$values['enable'] = (string)($values['enable'] ?? '0') === '1';
		$values['provider_key'] = trim((string)($values['provider_key'] ?? 'objects'));
		$values['item_acl_policy'] = $values['item_acl_policy'] ?? 'native';
		if (!in_array($values['item_acl_policy'], ['native', 'public_flags'], true)) {
			throw new \InvalidArgumentException('item_acl_policy must be native or public_flags.');
		}
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $values['provider_key'])) {
			throw new \InvalidArgumentException('provider_key must be a simple provider code.');
		}
		foreach (['public_base_url', 'media_base_url'] as $key) {
			$values[$key] = self::baseUrl((string)($values[$key] ?? ''));
		}
		$hosts = $values['allowed_media_hosts'] ?? [];
		if (!is_array($hosts)) { throw new \InvalidArgumentException('allowed_media_hosts must be a list of host names.'); }
		$values['allowed_media_hosts'] = [];
		foreach ($hosts as $host) {
			$host = strtolower(trim((string)$host));
			if (!self::host($host)) { throw new \InvalidArgumentException('allowed_media_hosts contains an invalid host name.'); }
			$values['allowed_media_hosts'][] = $host;
		}
		$values['allowed_media_hosts'] = array_values(array_unique($values['allowed_media_hosts']));
		$values['repository_name'] = trim((string)($values['repository_name'] ?? ''));
		if ($values['repository_name'] === '' || !preg_match('//u', $values['repository_name']) || preg_match('/[\x00-\x1f\x7f]/', $values['repository_name'])) {
			throw new \InvalidArgumentException('repository_name must be nonempty, valid UTF-8 text.');
		}
		if ($values['enable']) {
			if (!$values['public_base_url'] || !$values['media_base_url'] || !$values['allowed_media_hosts']) {
				throw new \InvalidArgumentException('Public OAI requires public and media base URLs and explicit allowed media hosts.');
			}
			if (!in_array(strtolower(parse_url($values['media_base_url'], PHP_URL_HOST)), $values['allowed_media_hosts'], true)) {
				throw new \InvalidArgumentException('media_base_url must use an allowed media host.');
			}
		}
		return $values;
	}

	public static function mappingFormat(string $code): ?string {
		return ['tadl_public_oai_mods' => 'mods', 'tadl_public_oai_dc' => 'oai_dc'][$code] ?? null;
	}

	private static function baseUrl(string $url): string {
		$url = rtrim(trim($url), '/');
		if ($url === '') { return ''; }
		$parts = parse_url($url);
		if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !self::host(strtolower($parts['host'] ?? ''))
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
			|| isset($parts['port']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
			|| preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~', rawurldecode($parts['path'] ?? ''))) {
			throw new \InvalidArgumentException('Base URLs must be canonical HTTPS URLs without credentials, query, fragment, port, or dot segments.');
		}
		return $url;
	}

	private static function host(string $host): bool {
		return (bool)filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
			&& strpos($host, '.') !== false && substr($host, -1) !== '.';
	}
}
