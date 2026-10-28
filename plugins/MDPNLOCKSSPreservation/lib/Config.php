<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace MDPNPreservation;

final class Failure extends \RuntimeException {}

final class Config {
	private $values;
	public function __construct(array $values) { $this->values = $values; }
	public static function load(string $root) : self {
		$shared = \Configuration::load($root.'/conf/plugin.conf');
		$local = is_file($root.'/conf/plugin.local.conf') ? \Configuration::load($root.'/conf/plugin.local.conf') : null;
		$values = [];
		foreach (['enable', 'state_directory', 'python_binary', 'organization_code', 'source_organization', 'contact_name', 'contact_email',
			'part_size_bytes', 'bagit_profile', 'transport', 'destination_id', 'sftp_host', 'sftp_port', 'sftp_directory', 'sftp_host_key_sha256', 'credentials_file'] as $key) {
			$v = $local ? $local->get($key) : false;
			$values[$key] = ($v !== false) ? $v : $shared->get($key);
		}
		return new self($values);
	}
	public function get(string $key, $default=null) { return $this->values[$key] ?? $default; }
	public function enabled() : bool { return (bool)$this->get('enable', false); }
	public function profile() : array {
		$name = $this->get('bagit_profile','mdpn');
		if ($name === 'mdpn') { return json_decode(file_get_contents(dirname(__DIR__).'/profiles/mdpn.json'),true,512,JSON_THROW_ON_ERROR); }
		if ($name !== 'generic') { throw new Failure('bagit_profile must be mdpn or generic.'); }
		return ['name' => 'Generic LOCKSS staging','bagit_version' => '1.0','serialization' => 'application/tar','manifest_algorithm' => 'sha256',
			'required_tags' => ['Source-Organization','Contact-Name','Contact-Email','External-Identifier','Bagging-Date','Payload-Oxum']];
	}
	public function stateDirectory(bool $writable=true) : string {
		$path = rtrim((string)$this->get('state_directory'), '/');
		if (!$path || $path[0] !== '/' || !is_dir($path) || is_link($path) || realpath($path) !== $path) {
			throw new Failure('Configure an existing canonical absolute state_directory outside the web root.');
		}
		if (defined('__CA_BASE_DIR__') && self::within($path, realpath(__CA_BASE_DIR__))) {
			throw new Failure('state_directory must be outside the Providence web root.');
		}
		if (self::insideSourceRepository($path)) { throw new Failure('state_directory must be outside the source repository.'); }
		if ((fileperms($path) & 0077) !== 0 || !is_readable($path) || ($writable && !is_writable($path))) {
			throw new Failure($writable ? 'state_directory must be writable by the worker and private (0700).' : 'state_directory must be readable by the report account and private (0700 or 0500).');
		}
		return $path;
	}
	public static function within(string $path, string $root) : bool { return $path === $root || strpos($path, rtrim($root, '/').'/') === 0; }
	public static function insideSourceRepository(string $path) : bool {
		$source = dirname(__DIR__);
		while ($source !== dirname($source)) {
			if (file_exists($source.'/.git')) { return self::within($path,$source); }
			$source = dirname($source);
		}
		return false;
	}
	public function target() : string {
		$transport = $this->get('transport', 'local');
		if (!in_array($transport, ['local', 'sftp'], true)) { throw new Failure('transport must be local or sftp.'); }
		$id = (string)$this->get('destination_id', '');
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) { throw new Failure('Configure a stable destination_id.'); }
		return $transport.':'.$id.':'.hash('sha256', json_encode([$this->get('sftp_host'), $this->get('sftp_port'), $this->get('sftp_directory')]));
	}
	public function bagMetadata(array $input) : array {
		$metadata = [];
		foreach (['Source-Organization' => 'source_organization', 'Contact-Name' => 'contact_name', 'Contact-Email' => 'contact_email'] as $tag => $key) {
			$value = trim((string)($input[$key] ?? $this->get($key, '')));
			if ($value === '' || strlen($value) > 500 || preg_match('/[\r\n\x00-\x1f\x7f]/', $value)) { throw new Failure('Complete the required organization and contact fields using single-line values of at most 500 bytes.'); }
			$metadata[$tag] = $value;
		}
		if (!filter_var($metadata['Contact-Email'], FILTER_VALIDATE_EMAIL)) { throw new Failure('Enter a valid contact email address.'); }
		$profile = $this->profile();
		if (isset($profile['source_organizations']) && !in_array($metadata['Source-Organization'],$profile['source_organizations'],true)) { throw new Failure('Source organization must exactly match a permitted organization in the MDPN profile.'); }
		$description = trim((string)($input['description'] ?? ''));
		if (strlen($description) > 2000 || preg_match('/[\r\n\x00-\x1f\x7f]/', $description)) { throw new Failure('The deposit description must be a single line of at most 2000 bytes.'); }
		if ($description !== '') { $metadata['External-Description'] = $description; }
		$code = (string)$this->get('organization_code', '');
		if (!preg_match('/^[A-Z0-9]{2,12}$/D', $code)) { throw new Failure('Configure organization_code using 2–12 uppercase letters or digits.'); }
		return $metadata;
	}
}
