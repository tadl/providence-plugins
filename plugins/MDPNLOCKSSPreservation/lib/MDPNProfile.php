<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace MDPNPreservation;

/** Import packaging policy only, never DART accounts, endpoints or operator values. */
final class MDPNProfile {
	public const URL = 'https://raw.githubusercontent.com/MiDPN/MDPN-Config/refs/heads/main/MDPN-DART-Settings.json';
	private const LIMIT = 1048576;
	private const CACHE = 'mdpn-settings-cache.json';

	public static function cached(string $directory) : array {
		$path = $directory.'/'.self::CACHE;
		if (is_link($path) || !is_file($path) || !is_readable($path) || (fileperms($path) & 0077) || filesize($path) > self::LIMIT * 2) {
			throw new Failure('MDPN settings cache is unavailable or not private. Run mdpn-preservation-refresh-profile --apply.');
		}
		try {
			$cache = json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
			if (($cache['format'] ?? null) !== 1 || ($cache['source'] ?? null) !== self::URL || !is_array($cache['profile'] ?? null)) { throw new \RuntimeException(); }
			return self::normalize($cache['profile']);
		} catch (\Throwable $e) { throw new Failure('MDPN settings cache is invalid. Run mdpn-preservation-refresh-profile --apply.'); }
	}

	public static function refresh(string $directory, ?callable $fetcher=null) : array {
		$raw = $fetcher ? $fetcher(self::URL) : self::download();
		if (!is_string($raw) || strlen($raw) > self::LIMIT) { throw new Failure('Canonical MDPN settings exceed the supported size.'); }
		try { $settings = json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
		catch (\Throwable $e) { throw new Failure('Canonical MDPN settings are not valid JSON. The existing cache was retained.'); }
		$profiles = $settings['bagItProfiles'] ?? null;
		if (!is_array($profiles)) { throw new Failure('Canonical MDPN settings contain no BagIt profiles.'); }
		$matches = array_values(array_filter($profiles,static function($p) { return is_array($p) && ($p['name'] ?? null) === 'MDPN'; }));
		if (count($matches) !== 1) { throw new Failure('Canonical settings must contain exactly one MDPN profile.'); }
		$selected = self::policy($matches[0]); $profile = self::normalize($selected);
		$path = $directory.'/'.self::CACHE;
		if (is_link($path)) { throw new Failure('MDPN settings cache must not be a symbolic link.'); }
		$temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
		$old = umask(0077);
		try {
			$data = json_encode(['format' => 1,'source' => self::URL,'fetched_at' => gmdate('c'),'profile' => $selected],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
			if (file_put_contents($temporary,$data,LOCK_EX) !== strlen($data) || !chmod($temporary,0600) || !rename($temporary,$path)) {
				throw new Failure('Cannot publish the private MDPN settings cache.');
			}
		} finally { umask($old); if (file_exists($temporary)) { unlink($temporary); } }
		return $profile;
	}

	private static function download() : string {
		if (!function_exists('curl_init')) { throw new Failure('PHP cURL is required to fetch canonical MDPN settings.'); }
		$client = curl_init(self::URL); $body = '';
		curl_setopt_array($client,[CURLOPT_FOLLOWLOCATION => false,CURLOPT_CONNECTTIMEOUT => 5,CURLOPT_TIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => true,CURLOPT_SSL_VERIFYHOST => 2,CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_USERAGENT => 'MDPNLOCKSSPreservation',CURLOPT_WRITEFUNCTION => static function($curl,$bytes) use (&$body) {
				if (strlen($body)+strlen($bytes) > self::LIMIT) { return 0; } $body .= $bytes; return strlen($bytes);
			}]);
		try {
			if (!curl_exec($client) || curl_getinfo($client,CURLINFO_HTTP_CODE) !== 200) {
				throw new Failure('Could not download canonical MDPN settings over HTTPS. No stale settings were used; retry when the service is available.');
			}
		} finally { unset($client); }
		return $body;
	}

	private static function strings($value) : array {
		if (!is_array($value) || !array_is_list($value)) { throw new Failure('Unsupported list in the canonical MDPN profile.'); }
		foreach ($value as $item) {
			if (!is_string($item) || strlen($item) > 500 || preg_match('/[\x00-\x1f\x7f]/',$item)) { throw new Failure('Unsafe value in the canonical MDPN profile.'); }
		}
		return $value;
	}
	private static function policy(array $profile) : array {
		$result = array_intersect_key($profile,array_flip(['name','acceptBagItVersion','acceptSerialization','serialization','baseProfileId',
			'manifestsAllowed','manifestsRequired','tagManifestsAllowed','tagManifestsRequired','tagFilesAllowed','bagItProfileInfo','tags']));
		if (is_array($result['bagItProfileInfo'] ?? null)) {
			$result['bagItProfileInfo'] = array_intersect_key($result['bagItProfileInfo'],array_flip(['bagItProfileIdentifier','bagItProfileVersion']));
		}
		if (is_array($result['tags'] ?? null)) {
			$result['tags'] = array_map(static function($tag) {
				return is_array($tag) ? array_intersect_key($tag,array_flip(['tagFile','tagName','required','values','defaultValue'])) : $tag;
			},$result['tags']);
		}
		return $result;
	}

	/** Reject requirements the worker cannot satisfy instead of silently weakening them. */
	public static function normalize(array $profile) : array {
		$profile = self::policy($profile);
		if (($profile['name'] ?? null) !== 'MDPN' || !empty($profile['baseProfileId'])
			|| !in_array('1.0',self::strings($profile['acceptBagItVersion'] ?? null),true)
			|| !in_array('application/tar',self::strings($profile['acceptSerialization'] ?? null),true)
			|| !in_array($profile['serialization'] ?? null,['required','optional'],true)) { throw new Failure('Canonical MDPN profile requires unsupported packaging.'); }
		foreach (['manifests','tagManifests'] as $kind) {
			if (!in_array('sha256',self::strings($profile[$kind.'Allowed'] ?? null),true)
				|| array_diff(self::strings($profile[$kind.'Required'] ?? null),['sha256'])) { throw new Failure('Canonical MDPN profile requires unsupported checksums.'); }
		}
		$tagFiles = self::strings($profile['tagFilesAllowed'] ?? null);
		if (!in_array('*',$tagFiles,true) && array_diff(['bagit.txt','bag-info.txt','manifest-sha256.txt','tagmanifest-sha256.txt'],$tagFiles)) { throw new Failure('Canonical MDPN profile excludes required tag files.'); }
		$identifier = $profile['bagItProfileInfo']['bagItProfileIdentifier'] ?? null;
		if (!is_string($identifier) || !filter_var($identifier,FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f]/',$identifier)) { throw new Failure('Canonical MDPN profile identifier is invalid.'); }
		$version = $profile['bagItProfileInfo']['bagItProfileVersion'] ?? '';
		if (!is_string($version) || strlen($version) > 500 || preg_match('/[\x00-\x1f\x7f]/',$version)) { throw new Failure('Canonical MDPN profile version is invalid.'); }
		$order = $required = $defaults = $restrictions = $seen = [];
		if (!is_array($profile['tags'] ?? null)) { throw new Failure('Canonical MDPN profile has no tag definitions.'); }
		foreach ($profile['tags'] as $tag) {
			if (!is_array($tag) || !is_string($name = $tag['tagName'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9-]{0,127}$/D',$name)
				|| !in_array($file = $tag['tagFile'] ?? null,['bagit.txt','bag-info.txt'],true) || !is_bool($tag['required'] ?? null)
				|| isset($seen[$file.':'.$name])) { throw new Failure('Unsupported or duplicate tag definition in the canonical MDPN profile.'); }
			$seen[$file.':'.$name] = true; $values = self::strings($tag['values'] ?? []);
			if ($file === 'bagit.txt') {
				$actual = ['BagIt-Version' => '1.0','Tag-File-Character-Encoding' => 'UTF-8'][$name] ?? null;
				if ($actual === null || ($values && !in_array($actual,$values,true))) { throw new Failure('Canonical MDPN profile requires unsupported BagIt declarations.'); }
				continue;
			}
			$order[] = $name;
			if ($tag['required']) { $required[] = $name; }
			$default = $tag['defaultValue'] ?? '';
			if (!is_string($default) || strlen($default) > 500 || preg_match('/[\x00-\x1f\x7f]/',$default)) { throw new Failure('Unsafe default in the canonical MDPN profile.'); }
			if ($default !== '' && $values && !in_array($default,$values,true)) { throw new Failure('Canonical MDPN default is not an allowed value.'); }
			if ($default !== '') { $defaults[$name] = $default; }
			if ($values) { $restrictions[$name] = $values; }
		}
		$automatic = ['Source-Organization','Contact-Name','Contact-Email','External-Description','Internal-Sender-Description','Bag-Count','Bag-Size','Bagging-Date',
			'Bagging-Software','External-Identifier','Internal-Sender-Identifier','Payload-Oxum','BagIt-Profile-Identifier'];
		foreach ($required as $name) {
			if (!in_array($name,$automatic,true) && empty($defaults[$name])) { throw new Failure('Canonical MDPN profile requires an unsupported field: '.$name.'.'); }
		}
		foreach (['Source-Organization','Contact-Name','Contact-Email','External-Identifier','Bagging-Date','Payload-Oxum'] as $name) {
			if (!in_array($name,$order,true)) { throw new Failure('Canonical MDPN profile omits a required transfer field.'); }
		}
		if (array_search('External-Identifier',$order,true) !== 10 || in_array('Bag-Group-Identifier',$required,true)) { throw new Failure('Canonical MDPN grouping or field order needs a compatibility update.'); }
		$result = ['name' => 'MDPN','source' => self::URL,'bagit_version' => '1.0','serialization' => 'application/tar','manifest_algorithm' => 'sha256',
			'bagit_profile_identifier' => $identifier,'bag_info_order' => $order,'required_tags' => array_values(array_unique(array_merge($required,
				['Source-Organization','Contact-Name','Contact-Email','External-Identifier','Bagging-Date','Payload-Oxum'])))];
		if ($defaults) { $result['bag_info_defaults'] = $defaults; }
		if ($restrictions) { $result['tag_values'] = $restrictions; }
		if (!empty($restrictions['Source-Organization'])) { $result['source_organizations'] = $restrictions['Source-Organization']; }
		if ($version !== '') { $result['source_profile_version'] = $version; }
		$result['source_profile_sha256'] = hash('sha256',json_encode($profile,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
		return $result;
	}
}
