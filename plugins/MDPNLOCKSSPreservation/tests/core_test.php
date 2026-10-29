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
	check($store->directory($first['id']) === $directory.'/jobs/'.$first['id'] && !file_exists($directory.'/jobs'),'Looking up a new job uses jobs/ without writing directories');
	$newDirectory = $store->directory($first['id'],true);
	check(is_dir($newDirectory) && (fileperms($newDirectory) & 0777) === 0700 && (fileperms($directory.'/jobs') & 0777) === 0700,'New jobs live in private jobs/ subdirectories');
	$legacyDirectory = $directory.'/'.$scan['id']; mkdir($legacyDirectory,0700); file_put_contents($legacyDirectory.'/result.json','synthetic retained result');
	check($store->directory($scan['id'],true) === $legacyDirectory && file_get_contents($legacyDirectory.'/result.json') === 'synthetic retained result','Legacy directories and their immutable requests remain in place');
	$readOnly = new Store($config,false,true);
	check($readOnly->directory($scan['id']) === $legacyDirectory && $readOnly->directory($first['id']) === $newDirectory,'Read-only history supports both layouts');
	mkdir($directory.'/jobs/'.$scan['id'],0700);
	fails(function() use ($store,$scan) { $store->directory($scan['id']); },'both directory layouts');
	rmdir($directory.'/jobs/'.$scan['id']);
	symlink($legacyDirectory,$directory.'/jobs/'.$next['id']);
	fails(function() use ($store,$next) { $store->directory($next['id'],true); },'Unsafe');
	unlink($directory.'/jobs/'.$next['id']);
	rename($directory.'/jobs',$directory.'/jobs.saved'); symlink($legacyDirectory,$directory.'/jobs');
	fails(function() use ($store,$first) { $store->directory($first['id']); },'Unsafe');
	unlink($directory.'/jobs'); rename($directory.'/jobs.saved',$directory.'/jobs');
	fails(function() use ($store) { $store->create(1,'supplemental','sftp:different',1,[]); },'full deposit');
	$lock = $store->lock(1); fails(function() use ($store) { $store->lock(1); },'active preservation worker'); fclose($lock);
	fails(function() use ($store) { $store->get('../escape'); },'Invalid');
	fails(function() { (new Config(['state_directory' => dirname(__DIR__,3)]))->stateDirectory(); },'outside the source repository');
	fails(function() use ($config) { $config->bagMetadata(['contact_name' => "Example\nExternal-Identifier: injected"]); },'single-line');
	fails(function() use ($config) { $config->bagMetadata(['contact_email' => 'invalid']); },'valid contact email');
	$mdpn = new Config(['source_organization' => 'Example Archive','contact_name' => 'Example Staff','contact_email' => 'preservation@example.org']);
	fails(function() use ($mdpn) { $mdpn->bagMetadata([]); },'permitted organization');
	foreach ([
		['application/pdf','image/jpeg','6','','document_page'],
		['application/pdf','image/jpeg','0','caDocumentPreviewSynthetic_0.jpg','document_page'],
		['video/mp4','image/jpeg','30.00s','','video_frame'],
		['video/mp4','image/jpeg','0.00s','caVideoPreviewSynthetic00001.jpg','video_frame'],
		['image/tiff','image/jpeg','0','caMultipagePreviewSynthetic_00000.jpg','document_page'],
		['application/vnd.openxmlformats-officedocument.wordprocessingml.document','image/jpeg','1','','document_page'],
		['application/pdf','image/jpeg','6','uploaded-master.jpg',null],
		['application/pdf','image/jpeg','/attachments/master','',null],
		['application/pdf','image/tiff','6','',null],
		['video/mp4','image/jpeg','30.00s','uploaded-frame.jpg',null],
		['audio/mpeg','image/jpeg','0','',null],
	] as $case) {
		$expected = array_pop($case);
		check(\MDPNPreservation\ProvidenceCatalogue::previewKind(...$case) === $expected,'Recognize native generated previews while retaining named uploads and non-preview attachments');
	}

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
	$previews = new SyntheticCatalogue();
	$previews->data['ca_object_representation_multifiles'] = [1 => ['multifile_id' => 1,'representation_id' => 20,'resource_path' => '1'],2 => ['multifile_id' => 2,'representation_id' => 20,'resource_path' => '/uploads']];
	$previews->generatedPreviews['ca_object_representation_multifiles:1'] = ['kind' => 'document_page','source_file_key' => 'ca_object_representations:20','resource_path' => '1','disposition' => 'regenerate'];
	$originalsOnly = (new Collector($previews))->capture(1,'synthetic');
	check(isset($originalsOnly['records']['ca_object_representation_multifiles'][1]['generated_preview']),'Generated-preview metadata records its source and regeneration policy');
	check(count($originalsOnly['files']) === 4 && !in_array('ca_object_representation_multifiles:1',array_column($originalsOnly['files'],'key'),true)
		&& in_array('ca_object_representation_multifiles:2',array_column($originalsOnly['files'],'key'),true),'Generated preview bytes are excluded while source originals and manual attachments remain');
	$previews->generatedPreviews['ca_object_representation_multifiles:1']['source_file_key'] = 'missing-source';
	fails(function() use ($previews) { (new Collector($previews))->capture(1,'synthetic'); },'no preserved source original');
	$catalogue->denied = ['ca_objects:11']; fails(function() use ($collector) { $collector->capture(1,'synthetic'); },'permission denied');
	check($catalogue->finished === 2,'Failed capture releases transaction');
	$catalogue->denied = []; $catalogue->data['ca_objects'][10]['parent_id'] = 11;
	fails(function() use ($collector) { $collector->capture(1,'synthetic'); },'hierarchy cycle');
	$broken = new SyntheticCatalogue(); $broken->data['ca_objects_x_collections'][1]['object_id'] = 999;
	fails(function() use ($broken) { (new Collector($broken))->capture(1,'synthetic'); },'missing object');
	print "Preservation storage, scope and security checks passed.\n";
} finally {
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
		if ($file->isLink() || !$file->isDir()) { unlink($file->getPathname()); } else { rmdir($file->getPathname()); }
	}
	rmdir($directory);
}
