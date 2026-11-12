<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace FileHealth;

/** Read-only presentation shared by the plugin page and optional dashboard widget. */
final class Presentation {
	public static function allowed($request) : bool {
		try {
			if (!$request || !$request->isLoggedIn()) { return false; }
			$user = $request->user;
			return $user && $user->getPrimaryKey() && $user->get('active') && $user->canDoAction('is_administrator');
		} catch (\Throwable $error) { return false; }
	}
	public static function escape($value) : string {
		return htmlspecialchars((string)$value,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
	}
	public static function status($value) : string {
		$labels = [
			'verified' => 'Verified',
			'mismatch' => 'Checksum mismatch','missing' => 'Missing file','unreadable' => 'Unreadable file',
			'changed' => 'Changed during check','unverified' => 'Not verified','error' => 'Check failed',
			'running' => 'Running','completed' => 'Completed','failed' => 'Failed','superseded' => 'Superseded',
		];
		return $labels[(string)$value] ?? 'Needs review';
	}
	public static function reason($value) : string {
		$labels = [
			'check_failed' => 'The check could not finish. Review the worker configuration.',
			'native_check_failed' => 'Providence could not complete its integrity check.',
			'confirmation_read_failed' => 'The confirming file read could not finish. Check the file again.',
			'file_missing' => 'The recorded local file could not be found.',
			'file_unreadable' => 'The worker could not read the recorded local file.',
			'not_regular_file' => 'The recorded path does not identify a regular file.',
			'changed_during_check' => 'The file or its catalogue description changed during the check. Check again.',
			'checksum_mismatch' => 'The file differs from its recorded Providence checksum.',
			'checksum_matches' => 'The file matches its recorded Providence checksum.',
			'representation_unavailable' => 'The representation is unavailable to the worker.',
			'version_unavailable' => 'This media version is not recorded.',
			'version_substituted' => 'Providence substitutes another version for this media version.',
			'icon_only' => 'This media version uses an icon instead of a stored file.',
			'externally_hosted' => 'This media version is hosted externally.',
			'processing_pending' => 'Media processing is still pending.',
			'multipage_version_unsupported' => 'This multipage media version is outside the checker scope.',
			'baseline_unavailable' => 'No trusted Providence checksum is recorded for this version.',
			'local_path_unavailable' => 'No local file is recorded for this version.',
		];
		return $labels[(string)$value] ?? 'Review this finding with an administrator.';
	}
	public static function checksum($value) : string {
		return is_string($value) && preg_match('/^[a-f0-9]{32}$/Di',$value) ? strtolower($value) : 'Not recorded';
	}
	public static function timestamp($value) : string {
		if (!is_string($value) || $value === '') { return 'Never'; }
		try { return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC'; }
		catch (\Throwable $error) { return 'Unknown'; }
	}
	public static function integer($value) : string { return number_format(max(0,(int)$value)); }
}
