<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace MDPNPreservation;

/** SFTP is delivery to staging, never evidence of LOCKSS replication. */
final class SftpTransport {
	private $client;
	private $directory;
	private $class;
	public function __construct(Config $config) {
		$credential_path = (string)$config->get('credentials_file');
		if (!$credential_path || is_link($credential_path) || !is_file($credential_path) || realpath($credential_path) !== $credential_path || (fileperms($credential_path) & 0077) !== 0
			|| Config::insideSourceRepository($credential_path)
			|| (defined('__CA_BASE_DIR__') && Config::within($credential_path,realpath(__CA_BASE_DIR__)))) {
			throw new Failure('Configure a private credentials_file outside the web root, mode 0600.');
		}
		try { $credentials = json_decode(file_get_contents($credential_path),true,512,JSON_THROW_ON_ERROR); }
		catch (\Throwable $e) { throw new Failure('SFTP credentials_file must contain valid JSON.'); }
		$username = $credentials['sftp_username'] ?? null; $password = $credentials['sftp_password'] ?? null;
		if (!is_string($username) || !is_string($password) || $username === '' || $password === '') { throw new Failure('SFTP credentials are incomplete.'); }
		$this->class = class_exists('phpseclib3\\Net\\SFTP') ? 'phpseclib3\\Net\\SFTP' : 'phpseclib\\Net\\SFTP';
		if (!class_exists($this->class)) { throw new Failure('Providence phpseclib is required for SFTP.'); }
		$host = (string)$config->get('sftp_host'); $port = (int)$config->get('sftp_port',22);
		$directory = (string)$config->get('sftp_directory'); $this->directory = rtrim($directory,'/');
		$expected = (string)$config->get('sftp_host_key_sha256');
		if (!preg_match('/^[A-Za-z0-9.-]+$/D',$host) || $port < 1 || $port > 65535 || !preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D',$expected)
			|| !$directory || ($directory !== '.' && $directory[0] !== '/') || preg_match('/[\r\n\x00]/',$directory)) { throw new Failure('SFTP destination or verified host-key configuration is incomplete.'); }
		try {
			$class = $this->class; $this->client = new $class($host,$port,30);
			$key = $this->client->getServerPublicHostKey();
			$fields = preg_split('/\s+/',trim((string)$key));
			$raw = isset($fields[1]) ? base64_decode($fields[1],true) : false;
			$actual = $raw === false ? '' : 'SHA256:'.rtrim(base64_encode(hash('sha256',$raw,true)),'=');
			if (!hash_equals($expected,$actual)) { throw new Failure('SFTP server identity does not match the configured host key.'); }
			if (!$this->client->login($username,$password)) { throw new Failure('SFTP authentication failed.'); }
		} catch (\Throwable $e) {
			if ($e instanceof Failure) { throw $e; }
			throw new Failure('SFTP connection failed. Check private server configuration.');
		}
	}
	public function send(string $local, array $part) : void {
		if (basename($part['filename']) !== $part['filename'] || !preg_match('/^[A-Za-z0-9_-]+\.tar$/D',$part['filename'])
			|| !hash_equals($part['sha256'],hash_file('sha256',$local))) { throw new Failure('A local preservation archive changed before transfer.'); }
		$final = $this->directory.'/'.$part['filename']; $partial = $final.'.partial';
		try {
			$this->client->clearStatCache();
			if ($this->client->file_exists($final)) {
				if (!$this->matches($final,$local,true)) { throw new Failure('The destination already contains a different archive with this name. It was not overwritten.'); }
				return;
			}
			$class = $this->class; $mode = $class::SOURCE_LOCAL_FILE;
			if ($this->client->file_exists($partial)) {
				if (!$this->matches($partial,$local,false)) { throw new Failure('An existing partial upload does not match this archive. Operator review is required.'); }
				$mode |= $class::RESUME;
			}
			if (!$this->client->put($partial,$local,$mode)) { throw new Failure('SFTP upload was interrupted. Retry the retained package.'); }
			if (!$this->matches($partial,$local,true)) { throw new Failure('The uploaded archive did not pass byte-for-byte verification.'); }
			// Recheck the immutable name immediately before finalization. The account
			// must have one writer; SFTP has no portable atomic no-replace rename.
			if ($this->client->file_exists($final) || !$this->client->rename($partial,$final)) { throw new Failure('SFTP finalization failed. Operator review is required.'); }
		} catch (\Throwable $e) {
			if ($e instanceof Failure) { throw $e; }
			throw new Failure('SFTP transfer failed. Retry the retained package.');
		}
	}
	private function matches(string $remote, string $local, bool $complete) : bool {
		$this->client->clearStatCache(); $size = $this->client->filesize($remote); $local_size = filesize($local);
		if (!is_int($size) || $size < 0 || $size > $local_size || ($complete && $size !== $local_size)) { return false; }
		$input = fopen($local,'rb');
		try {
			for ($offset=0; $offset<$size; $offset += strlen($block)) {
				$block = $this->client->get($remote,false,$offset,min(1048576,$size-$offset));
				if (!is_string($block) || $block === '' || $block !== fread($input,strlen($block))) { return false; }
			}
			return true;
		} finally { fclose($input); }
	}
}
