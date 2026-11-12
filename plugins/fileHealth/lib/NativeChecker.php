<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace FileHealth;
require_once __DIR__.'/Catalogue.php';

/** Adds coverage and race checks around Providence's unmodified native checker. */
final class NativeChecker {
	private $catalogue;
	public function __construct(Catalogue $catalogue) { $this->catalogue = $catalogue; }
	public function check(int $id, string $version='original') : array {
		$result = ['representation_id' => $id,'version' => $version,'status' => 'error','reason' => 'check_failed',
			'checked_at' => time(),'expected_md5' => null,'fingerprint' => null,'bytes' => null,'multifile_count' => 0];
		try {
			if (PHP_SAPI !== 'cli') { throw new \RuntimeException('Native checks require a CLI process.'); }
			$before = $this->catalogue->describe($id,$version);
			$result['fingerprint'] = self::fingerprint($before);
			$descriptor = self::descriptorFingerprint($before);
			$result['label'] = $before['label']; $result['idno'] = $before['idno'];
			$result['expected_md5'] = $before['expected_md5'];
			$result['multifile_count'] = $before['multifile_count'];
			if (!$before['supported']) { return self::finish($result,'unverified',$before['reason']); }
			$state = self::fileState($before['path']);
			if ($state['status'] !== 'ready') { return self::finish($result,$state['status'],$state['reason']); }
			$result['bytes'] = $state['identity']['size'];
			$native = $this->invokeNative($id,$version);
			// Native success has no positive checked-file counter: it also returns
			// success when a row, path or signature was skipped. A confirming read
			// against the SAME native baseline prevents those skips from going green.
			$confirmed = $native['completed'] && !$native['errors'] ? @md5_file($before['path']) : null;
			$after = $this->catalogue->describe($id,$version);
			$afterState = $after['supported'] ? self::fileState($after['path']) : null;
			if (self::descriptorFingerprint($after) !== $descriptor || !$afterState || $afterState['status'] !== 'ready' || $state['identity'] !== $afterState['identity']) {
				return self::finish($result,'changed','changed_during_check');
			}
			if (!$native['completed']) { return self::finish($result,'error','native_check_failed'); }
			if (!$native['errors'] && $confirmed === false) { return self::finish($result,'error','confirmation_read_failed'); }
			return $native['errors'] || $confirmed !== $before['expected_md5'] ? self::finish($result,'mismatch','checksum_mismatch') : self::finish($result,'verified','checksum_matches');
		} catch (\Throwable $e) {
			// Native diagnostics can contain private paths and record labels. Keep them
			// out of the event ledger and staff-visible messages.
			return self::finish($result,'error','check_failed');
		}
	}
	private function invokeNative(int $id, string $version) : array {
		if (!class_exists('CLIUtils')) { require_once __CA_LIB_DIR__.'/Utils/CLIUtils.php'; }
		if (!class_exists('Zend_Console_Getopt')) { throw new \RuntimeException('Native CLI options are unavailable.'); }
		$rules = \CLIUtils::check_media_fixityParamList() + ['quiet' => 'Suppress progress output.'];
		$options = new \Zend_Console_Getopt($rules,['--id='.$id,'--versions='.$version,'--kinds=ca_object_representations','--file=php://memory','--format=text','--quiet']);
		$options->parse();
		// Explicitly assert the effective scope: malformed options must never run
		// the native defaults and then produce a misleading success.
		if ((int)$options->getOption('id') !== $id || $options->getOption('versions') !== $version || $options->getOption('kinds') !== 'ca_object_representations') {
			throw new \RuntimeException('Native fixity scope was not accepted.');
		}
		\CLIUtils::clearErrors();
		$hadUser = array_key_exists('AUTH_CURRENT_USER_ID',$GLOBALS);
		$userId = $GLOBALS['AUTH_CURRENT_USER_ID'] ?? null;
		$server = $_SERVER; $request = $_REQUEST;
		$level = ob_get_level(); ob_start();
		set_error_handler(static function($severity,$message,$file,$line) { throw new \ErrorException('The native check emitted a diagnostic.',0,$severity,$file,$line); });
		try {
			// RequestHTTP assumes these web variables exist. Supply inert CLI
			// defaults for its report request and restore the complete server state.
			$_SERVER += ['REQUEST_URI' => __CA_URL_ROOT__.'/index.php','QUERY_STRING' => ''];
			$completed = \CLIUtils::check_media_fixity($options);
			$count = \CLIUtils::numErrors(); $errors = \CLIUtils::getErrors();
			return ['completed' => $completed === true && is_array($errors) && count($errors) === $count,'errors' => $count];
		} finally {
			restore_error_handler();
			while (ob_get_level() > $level) { ob_end_clean(); }
			\CLIUtils::clearErrors();
			// The native method creates a simulated RequestHTTP for optional email
			// delivery even with email disabled. Its initialization resets the global
			// current user. Restore this authorized CLI context before the next load.
			if ($hadUser) { $GLOBALS['AUTH_CURRENT_USER_ID'] = $userId; } else { unset($GLOBALS['AUTH_CURRENT_USER_ID']); }
			$_SERVER = $server; $_REQUEST = $request;
		}
	}
	public static function fingerprint(array $description) : string {
		// Processing metadata may change without replacing the expected bytes.
		// Keep generation identity separate from the full descriptor race guard.
		$identity = ['representation_id' => $description['representation_id'],'version' => $description['version'],
			'expected_md5' => $description['expected_md5'],'path' => $description['path']];
		return hash('sha256',serialize(self::canonical($identity)));
	}
	private static function descriptorFingerprint(array $description) : string {
		return hash('sha256',serialize(self::canonical(['descriptor' => $description['descriptor'],
			'expected_md5' => $description['expected_md5'],'path' => $description['path'],
			'supported' => $description['supported'],'reason' => $description['reason']])));
	}
	private static function canonical($value) {
		if (!is_array($value)) { return $value; }
		if (array_keys($value) !== range(0,count($value)-1)) { ksort($value,SORT_STRING); }
		foreach ($value as $key => $item) { $value[$key] = self::canonical($item); }
		return $value;
	}
	private static function fileState(string $path) : array {
		clearstatcache(true,$path);
		$stat = @stat($path);
		if ($stat === false) { return ['status' => 'missing','reason' => 'file_missing']; }
		if (($stat['mode'] & 0170000) !== 0100000) { return ['status' => 'unverified','reason' => 'not_regular_file']; }
		if (!is_readable($path)) { return ['status' => 'unreadable','reason' => 'file_unreadable']; }
		$real = realpath($path);
		if ($real === false) { return ['status' => 'unreadable','reason' => 'file_unreadable']; }
		$identity = ['realpath' => $real];
		foreach (['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'] as $key) { $identity[$key] = $stat[$key]; }
		return ['status' => 'ready','reason' => '','identity' => $identity];
	}
	private static function finish(array $result, string $status, string $reason) : array {
		$result['status'] = $status; $result['reason'] = $reason; $result['checked_at'] = time();
		return $result;
	}
}
