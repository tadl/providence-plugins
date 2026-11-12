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
			'running' => 'Running','no_errors_reported' => 'No errors reported','attention' => 'Needs attention',
			'failed' => 'Failed','interrupted' => 'Interrupted','untracked' => 'Untracked report',
		];
		return $labels[(string)$value] ?? 'Needs review';
	}
	public static function timestamp($value) : string {
		if (!is_string($value) || $value === '') { return 'Never'; }
		try { return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC'; }
		catch (\Throwable $error) { return 'Unknown'; }
	}
	public static function scope($scope) : string {
		if (!is_array($scope) || empty($scope['kinds']) || !is_array($scope['kinds']) || !isset($scope['versions']) || !is_array($scope['versions'])) { return 'Scope was not recorded'; }
		foreach (array_merge($scope['kinds'],$scope['versions']) as $value) { if (!is_string($value)) { return 'Scope was not recorded'; } }
		$labels = ['ca_object_representations' => 'representation media','ca_attributes' => 'metadata file/media attributes','all' => 'all native media kinds'];
		$kinds = array_map(static function($kind) use ($labels) { return $labels[(string)$kind] ?? 'other configured native media'; },$scope['kinds']);
		return implode(', ',$kinds).'; versions: '.($scope['versions'] ? implode(', ',$scope['versions']) : 'all native media versions');
	}
	public static function integer($value) : string { return number_format(max(0,(int)$value)); }
}
