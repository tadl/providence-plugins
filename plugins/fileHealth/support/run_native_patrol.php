#!/usr/bin/env php
<?php
/** Standalone patrol journal; deliberately never bootstraps Providence itself. */
// SPDX-License-Identifier: GPL-3.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Patrol.php';

try {
	$values = [];
	$allowed = ['application-root','state-directory','email','php-binary','kinds','versions'];
	foreach (array_slice($argv,1) as $argument) {
		if (!preg_match('/^--([a-z-]+)=(.*)$/sD',$argument,$parts) || !in_array($parts[1],$allowed,true) || array_key_exists($parts[1],$values)) {
			throw new \FileHealth\Failure('Use explicit --application-root=PATH --state-directory=PATH options; each option may occur once.');
		}
		$values[$parts[1]] = $parts[2];
	}
	if (empty($values['application-root']) || empty($values['state-directory'])) { throw new \FileHealth\Failure('Both --application-root and --state-directory are required.'); }
	$config = new \FileHealth\Config(['enable' => 1,'state_directory' => $values['state-directory'],
		'email' => $values['email'] ?? '', 'php_binary' => $values['php-binary'] ?? PHP_BINARY,
		'kinds' => $values['kinds'] ?? 'ca_object_representations','versions' => $values['versions'] ?? 'original']);
	$store = new \FileHealth\Store($config,true);
	$result = (new \FileHealth\Patrol($config,$store,$values['application-root']))->run();
	print json_encode(['id' => $result['id'],'status' => $result['status'],'exit_code' => $result['exit_code']],JSON_THROW_ON_ERROR)."\n";
	$exit = $result['status'] === 'no_errors_reported' ? 0 : ($result['status'] === 'interrupted' ? (int)$result['exit_code'] : 1);
	exit($exit);
} catch (\Throwable $error) {
	// Native output and installation paths belong only in the private artifacts.
	fwrite(STDERR,"The native patrol could not run. Check its configuration, permissions and patrol lock.\n");
	exit(2);
}
