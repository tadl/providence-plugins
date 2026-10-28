<?php
/** Synthetic storage, scope and security tests. No CA bootstrap or network. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Store.php';
require_once __DIR__.'/../lib/Catalogue.php';
use MDPNPreservation\Config;
use MDPNPreservation\Store;
use MDPNPreservation\Collector;

function check($condition, string $message) : void { if (!$condition) { throw new RuntimeException($message); } }
function fails(callable $call, string $contains) : void {
	try { $call(); } catch (Throwable $e) { check(strpos($e->getMessage(),$contains) !== false,'Unexpected failure: '.$e->getMessage()); return; }
	throw new RuntimeException('Expected failure: '.$contains);
}

require_once __DIR__.'/SyntheticCatalogue.php';

$directory = realpath(sys_get_temp_dir()).'/mdpn-test-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
try {
	$config = new Config(['state_directory' => $directory,'organization_code' => 'EXAMPLE','source_organization' => 'Example Archive','contact_name' => 'Example Staff','contact_email' => 'preservation@example.org','transport' => 'local','destination_id' => 'test','bagit_profile' => 'generic']);
	fails(function() use ($config) { new Store($config); },'not initialized');
	$store = new Store($config,true); $repository = $store->repositoryId();
	check(strlen($repository) === 32 && (new Store($config))->repositoryId() === $repository,'Permanent installation identity');
	$group = $store->groupIdentifier(1);
	$other_config = new Config(['state_directory' => $directory,'organization_code' => 'CHANGED']);
	check((new Store($other_config))->groupIdentifier(1) === $group,'Collection group identity survives organization-code edits');
	$first = $store->create(1,'full',$config->target(),1,$config->bagMetadata([]));
	check($first['revision'] === 1,'First revision');
	fails(function() use ($store,$config) { $store->create(1,'full',$config->target(),1,[]); },'UNIQUE');
	$store->update($first['id'],['status' => 'built']);
	$scan = $store->create(1,'scan',$config->target(),1,[]); $store->update($scan['id'],['status' => 'scanned']);
	$next = $store->create(1,'supplemental',$config->target(),1,[]);
	check($next['revision'] === 2 && $next['parent_id'] === $first['id'],'Scan does not consume a revision; supplement references baseline');
	$store->update($next['id'],['status' => 'failed']);
	check($store->baseline(1,$config->target())['id'] === $first['id'],'Failed supplement never advances baseline');
	fails(function() use ($store) { $store->create(1,'supplemental','sftp:different',1,[]); },'full deposit');
	$lock = $store->lock(1); fails(function() use ($store) { $store->lock(1); },'active preservation worker'); fclose($lock);
	fails(function() use ($store) { $store->get('../escape'); },'Invalid');
	fails(function() { (new Config(['state_directory' => dirname(__DIR__,3)]))->stateDirectory(); },'outside the source repository');
	fails(function() use ($config) { $config->bagMetadata(['contact_name' => "Example\nExternal-Identifier: injected"]); },'single-line');
	fails(function() use ($config) { $config->bagMetadata(['contact_email' => 'invalid']); },'valid contact email');
	$mdpn = new Config(['source_organization' => 'Example Archive','contact_name' => 'Example Staff','contact_email' => 'preservation@example.org']);
	fails(function() use ($mdpn) { $mdpn->bagMetadata([]); },'permitted organization');

	$catalogue = new SyntheticCatalogue(); $collector = new Collector($catalogue); $snapshot = $collector->capture(1,'synthetic');
	check(count($snapshot['records']['ca_collections']) === 2,'Collection descendants included');
	check(count($snapshot['records']['ca_objects']) === 2,'Object children and directly linked child deduplicated');
	check(count($snapshot['records']['ca_object_representations']) === 1 && count($snapshot['files']) === 3,'Shared representation, sidecar and attribute multifile preserved once');
	check($snapshot['records']['ca_objects'][10]['row']['access'] === 0,'Private originals and metadata included');
	check(isset($snapshot['records']['ca_entities'][30]),'Related authority included');
	check(isset($snapshot['records']['ca_places'][40]),'Authority lookup attributes retain referenced records');
	check(!isset($snapshot['records']['ca_collections'][99]) && in_array(['table' => 'ca_collections','id' => 99],$snapshot['external_references'],true),'External collection remains an explicit boundary reference');
	check($snapshot['relationships']['ca_objects_x_object_representations'][2]['row']['object_id'] === 11,'Both shared representation links preserved');
	check($snapshot['records']['ca_objects'][10]['attributes'][0]['values'][0]['value_blob']['$base64'] === base64_encode("\xff\x00"),'Binary native values preserved losslessly');
	check($catalogue->finished === 1,'Read transaction released');
	$catalogue->denied = ['ca_objects:11']; fails(function() use ($collector) { $collector->capture(1,'synthetic'); },'permission denied');
	check($catalogue->finished === 2,'Failed capture releases transaction');
	$catalogue->denied = []; $catalogue->data['ca_objects'][10]['parent_id'] = 11;
	fails(function() use ($collector) { $collector->capture(1,'synthetic'); },'hierarchy cycle');
	$broken = new SyntheticCatalogue(); $broken->data['ca_objects_x_collections'][1]['object_id'] = 999;
	fails(function() use ($broken) { (new Collector($broken))->capture(1,'synthetic'); },'missing object');
	print "Preservation storage, scope and security checks passed.\n";
} finally {
	foreach (glob($directory.'/*') as $file) { if (is_file($file)) { unlink($file); } }
	rmdir($directory);
}
