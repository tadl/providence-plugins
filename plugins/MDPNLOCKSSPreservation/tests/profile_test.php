<?php
/** Canonical settings import, cache safety and offline reads with synthetic data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Config.php';
require_once __DIR__.'/../lib/MDPNProfile.php';
use MDPNPreservation\Config;
use MDPNPreservation\Failure;
use MDPNPreservation\MDPNProfile;

function check($condition,string $message) : void { if (!$condition) { throw new RuntimeException($message); } }
function fails(callable $call,string $expected) : void {
	try { $call(); } catch (Throwable $e) { check(strpos($e->getMessage(),$expected) !== false,'Unexpected failure: '.$e->getMessage()); return; }
	throw new RuntimeException('Expected failure: '.$expected);
}
$root = realpath(sys_get_temp_dir()).'/mdpn-profile-'.bin2hex(random_bytes(6)); mkdir($root,0700);
try {
	$settings = json_decode(file_get_contents(__DIR__.'/fixtures/mdpn-dart-settings.json'),true,512,JSON_THROW_ON_ERROR);
	$values = ['bagit_profile' => 'mdpn','state_directory' => $root];
	$offline = static function() { throw new Failure('Synthetic network outage'); };
	check((new Config(['bagit_profile' => 'generic'],$offline))->refreshProfile()['name'] === 'Generic LOCKSS staging','Generic mode must never access the MDPN service');
	fails(function() use ($root) { MDPNProfile::cached($root); },'refresh-profile');
	$calls = 0;
	$fetch = static function($url) use ($settings,&$calls) {
		check($url === MDPNProfile::URL,'Only the canonical HTTPS source may be fetched'); $calls++; return json_encode($settings);
	};
	$config = new Config($values,$fetch); $first = $config->refreshProfile();
	check($calls === 1 && $first === MDPNProfile::cached($root),'Downloaded rules round trip through the validated private cache');
	check($first['bag_info_order'][10] === 'External-Identifier' && $first['source_organizations'] === ['Example Member Archive','Other Member Archive'],'Import upstream ordering and institution values');
	check((fileperms($root.'/mdpn-settings-cache.json') & 0777) === 0600,'The cache is private');
	$rawCache = file_get_contents($root.'/mdpn-settings-cache.json');
	check(strpos($rawCache,'SYNTHETIC_OPERATOR_VALUE_TO_IGNORE') === false && strpos($rawCache,'SYNTHETIC_NOT_A_CREDENTIAL') === false,'Operator values and DART transport accounts must not enter the cache or normalized policy');
	$readonly = new Config($values,$offline);
	check($readonly->profile() === $first && file_get_contents($root.'/mdpn-settings-cache.json') === $rawCache,'Reading policy performs no download or cache write');
	$changed = $settings; $changed['bagItProfiles'][0]['tags'][2]['values'][] = 'New Member Archive';
	$next = MDPNProfile::refresh($root,static function() use ($changed) { return json_encode($changed); });
	check(in_array('New Member Archive',(new Config($values,$offline))->profile()['source_organizations'],true),'A new process sees updated canonical membership without a source update');
	check($config->profile() === $first && $readonly->profile() === $first,'A running capture keeps its pinned policy if another process refreshes the cache');
	$changed['storageServices'][0]['sftp_password'] = 'ANOTHER_SYNTHETIC_IGNORED_VALUE';
	$changed['bagItProfiles'][0]['tags'][2]['userValue'] = 'IGNORED_OPERATOR_EDIT';
	check(MDPNProfile::refresh($root,static function() use ($changed) { return json_encode($changed,JSON_PRETTY_PRINT); }) === $next,'Refresh times, formatting, accounts and operator values do not change the policy fingerprint');
	$stableCache = file_get_contents($root.'/mdpn-settings-cache.json');
	fails(function() use ($root,$offline) { MDPNProfile::refresh($root,$offline); },'Synthetic network outage');
	fails(function() use ($root) { MDPNProfile::refresh($root,static function() { return '{invalid'; }); },'not valid JSON');
	fails(function() use ($root) { MDPNProfile::refresh($root,static function() { return str_repeat(' ',1048577); }); },'supported size');
	$mutations = [
		'unsupported packaging' => static function(&$p) { $p['acceptBagItVersion'] = ['0.97']; },
		'unsupported checksums' => static function(&$p) { $p['manifestsRequired'] = ['sha512']; },
		'unsupported field' => static function(&$p) { $p['tags'][] = ['tagFile'=>'bag-info.txt','tagName'=>'Unknown-Required','required'=>true,'values'=>[]]; },
		'Unsafe value' => static function(&$p) { $p['tags'][2]['values'][] = "Injected\nfield"; },
		'duplicate tag' => static function(&$p) { $p['tags'][] = $p['tags'][2]; },
		'not an allowed value' => static function(&$p) { $p['tags'][10]['values'] = ['allowed']; $p['tags'][10]['defaultValue'] = 'different'; },
		'field order' => static function(&$p) { $swap = $p['tags'][2]; $p['tags'][2] = $p['tags'][12]; $p['tags'][12] = $swap; },
	];
	foreach ($mutations as $expected => $mutate) {
		$invalid = $settings; $mutate($invalid['bagItProfiles'][0]);
		fails(function() use ($root,$invalid) { MDPNProfile::refresh($root,static function() use ($invalid) { return json_encode($invalid); }); },$expected);
		check(file_get_contents($root.'/mdpn-settings-cache.json') === $stableCache,'Invalid canonical updates must not replace the last valid cache');
	}
	$duplicate = $settings; $duplicate['bagItProfiles'][] = $duplicate['bagItProfiles'][0];
	fails(function() use ($root,$duplicate) { MDPNProfile::refresh($root,static function() use ($duplicate) { return json_encode($duplicate); }); },'exactly one');
	$extended = $settings; $extended['bagItProfiles'][0]['tags'][] = ['tagFile'=>'bag-info.txt','tagName'=>'Transfer-Policy','required'=>false,'values'=>['Example policy'],'defaultValue'=>'Example policy'];
	$extension = MDPNProfile::refresh($root,static function() use ($extended) { return json_encode($extended); });
	check($extension['bag_info_defaults']['Transfer-Policy'] === 'Example policy' && $extension['tag_values']['Transfer-Policy'] === ['Example policy'],'Supported new optional defaults and restrictions are imported');
	unlink($root.'/mdpn-settings-cache.json'); symlink(__FILE__,$root.'/mdpn-settings-cache.json');
	fails(function() use ($root) { MDPNProfile::cached($root); },'unavailable');
	fails(function() use ($root,$fetch) { MDPNProfile::refresh($root,$fetch); },'symbolic link');
	print "Canonical MDPN policy import, refresh, compatibility and cache safety checks passed.\n";
} finally {
	foreach (glob($root.'/*') as $path) { unlink($path); } rmdir($root);
}
