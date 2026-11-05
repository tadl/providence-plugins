<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace TADL\PublicOai;

final class Config {
	public static function load(string $root): array {
		$shared = \Configuration::load($root.'/conf/plugin.conf');
		$local = is_file($root.'/conf/plugin.local.conf') ? \Configuration::load($root.'/conf/plugin.local.conf') : null;
		$values = [];
		foreach (['enable', 'provider_key', 'public_base_url', 'media_base_url', 'media_source_url_prefix',
			'media_public_url_prefix', 'repository_name', 'item_acl_policy'] as $key) {
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
		foreach (['media_source_url_prefix', 'media_public_url_prefix'] as $key) {
			$values[$key] = self::mediaPrefix((string)($values[$key] ?? ''));
		}
		$rewrite = $values['media_source_url_prefix'] !== '';
		if ($rewrite !== ($values['media_public_url_prefix'] !== '')) {
			throw new \InvalidArgumentException('Media source and public URL prefixes must be configured together.');
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
		if ($rewrite && !in_array(strtolower(parse_url($values['media_public_url_prefix'], PHP_URL_HOST)), $values['allowed_media_hosts'], true)) {
			throw new \InvalidArgumentException('The public media URL prefix must use an allowed media host.');
		}
		$values['repository_name'] = trim((string)($values['repository_name'] ?? ''));
		if ($values['repository_name'] === '' || !preg_match('//u', $values['repository_name']) || preg_match('/[\x00-\x1f\x7f]/', $values['repository_name'])) {
			throw new \InvalidArgumentException('repository_name must be nonempty, valid UTF-8 text.');
		}
		if ($values['enable']) {
			if (!$values['public_base_url'] || !$values['media_base_url'] || !$values['allowed_media_hosts']) {
				throw new \InvalidArgumentException('Public OAI requires public and media base URLs and explicit allowed media hosts.');
			}
			if (!in_array(strtolower(parse_url($values['media_base_url'], PHP_URL_HOST)), $values['allowed_media_hosts'], true)
				&& (!$rewrite || !self::containsPrefix($values['media_base_url'], $values['media_source_url_prefix']))) {
				throw new \InvalidArgumentException('media_base_url must use an allowed host or contain the configured media source prefix.');
			}
		}
		return $values;
	}

	public static function mappingFormat(string $code): ?string {
		return ['tadl_public_oai_mods' => 'mods', 'tadl_public_oai_dc' => 'oai_dc'][$code] ?? null;
	}

	/** Validate encoded paths without changing their public filename spelling. */
	public static function safeMediaPath(string $path): bool {
		if (preg_match('/%(?![0-9a-f]{2})/i', $path)) { return false; }
		for ($depth = 0; $depth < 5; $depth++) {
			if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || preg_match('/%(?:2f|5c)/i', $path)
				|| preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~', $path)) { return false; }
			$decoded = rawurldecode($path);
			if ($decoded === $path) { return true; }
			$path = $decoded;
		}
		return false;
	}

	private static function mediaPrefix(string $url): string {
		if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
			throw new \InvalidArgumentException('Media URL prefixes cannot contain whitespace or controls.');
		}
		$url = self::baseUrl($url);
		if ($url !== '' && (!parse_url($url, PHP_URL_PATH) || !self::safeMediaPath(parse_url($url, PHP_URL_PATH)))) {
			throw new \InvalidArgumentException('Media URL prefixes require a safe nonempty path.');
		}
		return $url;
	}

	private static function containsPrefix(string $base, string $source): bool {
		$parent = parse_url($base); $child = parse_url($source);
		if (strtolower($parent['host']) !== strtolower($child['host'])) { return false; }
		$path = $parent['path'] ?? ''; $sourcePath = $child['path'] ?? '';
		return $sourcePath === $path || strpos($sourcePath, $path.'/') === 0;
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
