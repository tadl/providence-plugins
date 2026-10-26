<?php
require_once __DIR__.'/bootstrap.php';

$checks = 0;
function expect($condition, $message) {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function scenario($modify=null, $native_add=true) {
	Configuration::$enabled = 1;
	ca_collections::$exists = true;
	ca_collections::$deleted = false;
	ca_collections::$access = 1;
	$subject = new ca_objects();
	$request = new SyntheticRequest();
	$params = [
		'subject' => $subject,
		'options' => ['request' => $request],
		'bundles' => ['hierarchy' => nativeHierarchyFixture($request, 0, $native_add), 'other' => '<p>Unrelated bundle</p>']
	];
	if ($modify) { $modify($params, $subject, $request); }
	$original = $params['bundles'];
	$plugin = new collectionSiblingPlugin(dirname(__DIR__));
	$result = $plugin->hookBundleFormHTML($params);
	return [$result, $original, $plugin, $subject];
}

Configuration::$enabled = null;
expect(!(new collectionSiblingPlugin(dirname(__DIR__)))->checkStatus()['available'], 'Shipped defaults must be disabled');
$local_test = sys_get_temp_dir().'/collectionSibling-'.bin2hex(random_bytes(8));
mkdir($local_test.'/conf', 0700, true);
try {
	file_put_contents($local_test.'/conf/plugin.conf', "enable = 0\n");
	file_put_contents($local_test.'/conf/plugin.local.conf', "enable = 1\n");
	expect((new collectionSiblingPlugin($local_test))->checkStatus()['available'], 'Local enable overrides shared disable');
	file_put_contents($local_test.'/conf/plugin.conf', "enable = 1\n");
	file_put_contents($local_test.'/conf/plugin.local.conf', "enable = 0\n");
	expect(!(new collectionSiblingPlugin($local_test))->checkStatus()['available'], 'Local disable overrides shared enable');
} finally {
	unlink($local_test.'/conf/plugin.conf');
	unlink($local_test.'/conf/plugin.local.conf');
	rmdir($local_test.'/conf');
	rmdir($local_test);
}

[$result, $original, $plugin, $subject] = scenario();
expect($plugin->checkStatus()['available'], 'Enabled plugin must be available');
expect(strpos($result['bundles']['hierarchy'], 'data-collection-sibling') !== false, 'Collection object receives the enhancement');
expect(strpos($result['bundles']['hierarchy'], $original['hierarchy']) === 0, 'Native markup and inline handlers remain byte-for-byte intact');
expect($result['bundles']['other'] === $original['other'], 'Unrelated bundle stays intact');
expect($subject->related_options === ['idsOnly' => true, 'restrictToRelationshipTypes' => ['part_of']], 'Only the hierarchy relationship type supplies the destination');
expect(strpos($result['bundles']['hierarchy'], '"collectionId":501') !== false, 'Destination is the existing collection');
$again = $plugin->hookBundleFormHTML($result);
expect($again === $result, 'Repeated hooks must be idempotent');

$skips = [
	'disabled' => function() { Configuration::$enabled = 0; },
	'new object' => function($p, $s) { $s->id = null; },
	'object parent' => function($p, $s) { $s->parent = 91; },
	'no collection' => function($p, $s) { $s->collections = []; },
	'ambiguous collection' => function($p, $s) { $s->collections = [501, 502]; },
	'failed lookup' => function($p, $s) { $s->collections = false; },
	'invalid ids' => function($p, $s) { $s->collections = [0, -5]; },
	'no create privilege' => function($p, $s, $r) { $r->user->can_create = false; },
	'readonly bundle' => function($p, $s, $r) { $r->user->bundle_access = 1; },
	'no bundle access' => function($p, $s, $r) { $r->user->bundle_access = 0; },
	'no user' => function($p, $s, $r) { $r->user = null; },
	'mixed hierarchy off' => function($p, $s, $r) { $r->getAppConfig()->set('ca_objects_x_collections_hierarchy_enabled', 0); },
	'strict hierarchy' => function($p, $s, $r) { $r->getAppConfig()->set('ca_objects_enforce_strict_type_hierarchy', 1); },
	'partial strict hierarchy' => function($p, $s, $r) { $r->getAppConfig()->set('ca_objects_enforce_strict_type_hierarchy', '~'); },
	'missing relationship type' => function($p, $s, $r) { $r->getAppConfig()->set('ca_objects_x_collections_hierarchy_relationship_type', ''); },
	'collection access denied' => function() { ca_collections::$access = 0; },
	'deleted collection' => function() { ca_collections::$deleted = true; },
	'missing collection' => function() { ca_collections::$exists = false; },
	'batch' => function(&$p) { $p['options']['batch'] = true; },
	'collection editor' => function(&$p) { $p['subject'] = new ca_collections(501); },
	'no request' => function(&$p) { unset($p['options']['request']); },
	'missing native controls' => function(&$p) { $p['bundles']['hierarchy'] = '<p>Different hierarchy markup</p>'; }
];
foreach ($skips as $name => $modify) {
	[$result, $original] = scenario($modify);
	expect($result['bundles'] === $original, $name.' must leave the bundles unchanged');
}
[$result, $original] = scenario(null, false);
expect($result['bundles'] === $original, 'Native placement readonly/no Add row must stay untouched');
[$result, $original, $plugin, $subject] = scenario(function(&$p) { $p['bundles']['hierarchy'] = '<p>Media screen</p>'; });
expect($subject->related_options === null, 'Screens without native Add controls must not query collections');
[$result] = scenario(function($p, $s) { $s->collections = [501, '501', 0]; });
expect(strpos($result['bundles']['hierarchy'], 'data-collection-sibling') !== false, 'Duplicate links to the same collection are unambiguous');
[$result, $original] = scenario(function($p, $s, $r) { $r->editor_url = '/synthetic/"\'</script><script>invalid()</script>&'; });
expect(substr_count($result['bundles']['hierarchy'], '</script>') === substr_count($original['hierarchy'], '</script>') + 1, 'JSON must not allow script termination');
expect(strpos($result['bundles']['hierarchy'], '\\u003C\\/script\\u003E') !== false, 'HTML delimiters in embedded data must be encoded');

// Other plugins can rewrite lookup URLs before or after this hook.
[$result, $original, $plugin] = scenario(function(&$p) {
	$p['bundles']['hierarchy'] .= '<script>window.syntheticLookup = "/lookup/groupCollectionRootMap/RestrictedObjectCollectionHierarchy/Get";</script>';
});
expect(strpos($result['bundles']['hierarchy'], $original['hierarchy']) === 0, 'Earlier collection-scope lookup rewrites remain intact');
$result['bundles']['hierarchy'] = str_replace('/lookup/groupCollectionRootMap/', '/lookup/syntheticUpdatedScope/', $result['bundles']['hierarchy']);
expect($plugin->hookBundleFormHTML($result) === $result, 'Later lookup rewrites are preserved on repeated hook dispatch');

if (in_array('--fixtures', $argv, true)) {
	[$normal] = scenario();
	[$readonly] = scenario(null, false);
	$native_parent = nativeHierarchyFixture(new SyntheticRequest(), 91);
	$fixtures = ['normal' => $normal['bundles']['hierarchy'], 'readonly' => $readonly['bundles']['hierarchy'], 'nativeParent' => $native_parent];
	$fixtures['twoPlacements'] = $normal['bundles']['hierarchy'].str_replace('P17ObjectEditorForm', 'P18ObjectEditorForm', $normal['bundles']['hierarchy']);
	print json_encode($fixtures, JSON_THROW_ON_ERROR);
} else {
	print "PASS: {$checks} plugin assertions, using the native hierarchy Add template\n";
}
