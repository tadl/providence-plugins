<?php
/** Isolated native bootstrap: preserve ACL checks even under process-task-queue. */
// SPDX-License-Identifier: GPL-3.0-or-later
if (PHP_SAPI !== 'cli' || count($argv) !== 3 || !preg_match('/^[a-f0-9]{32}$/D',$argv[1])) { exit(2); }
$installation = realpath($argv[2]);
if (!$installation || !is_file($installation.'/setup.php') || realpath($installation.'/app/plugins/MDPNLOCKSSPreservation/worker/run.php') !== __FILE__) { exit(2); }
define('__CA_DISABLE_ACL__',false);
putenv('COLLECTIVEACCESS_HOME='.$installation);
$argv = [$installation.'/support/bin/caUtils','mdpn-preservation-run','--job='.$argv[1],'--apply','--quiet'];
$_SERVER['argv'] = $argv; $argc = count($argv); $_SERVER['argc'] = $argc;
require $installation.'/support/bin/caUtils';
