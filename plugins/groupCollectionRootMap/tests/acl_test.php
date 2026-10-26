<?php
require_once __DIR__.'/bootstrap.php';

$checks = 0;
function expect($condition, $message) {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function refuses($fn, $message) {
	try { $fn(); } catch (RuntimeException $error) { expect(true, $message); return; }
	expect(false, $message);
}
function fixture() {
	Db::$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	Db::$pdo->exec('CREATE TABLE ca_collections (collection_id INTEGER PRIMARY KEY, parent_id INTEGER, deleted INTEGER DEFAULT 0, acl_inherit_from_parent INTEGER DEFAULT 1);
		CREATE TABLE ca_objects (object_id INTEGER PRIMARY KEY, parent_id INTEGER, deleted INTEGER DEFAULT 0, acl_inherit_from_parent INTEGER DEFAULT 1, acl_inherit_from_ca_collections INTEGER DEFAULT 1);
		CREATE TABLE ca_objects_x_collections (object_id INTEGER, collection_id INTEGER, type_id INTEGER);
		CREATE TABLE ca_users (user_id INTEGER PRIMARY KEY, userclass INTEGER DEFAULT 0);
		CREATE TABLE ca_user_groups (group_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, code TEXT UNIQUE, description TEXT, for_public_use INTEGER DEFAULT 0);
		CREATE TABLE ca_groups_x_roles (group_id INTEGER, role_id INTEGER);
		CREATE TABLE ca_users_x_groups (relation_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, group_id INTEGER);
		CREATE TABLE ca_acl (acl_id INTEGER PRIMARY KEY AUTOINCREMENT, table_num INTEGER, row_id INTEGER, user_id INTEGER, group_id INTEGER, access INTEGER, notes TEXT DEFAULT \'\' CHECK (length(notes) <= '.NATIVE_ACL_NOTE_LIMIT.'), inherited_from_table_num INTEGER, inherited_from_row_id INTEGER);
		INSERT INTO ca_collections (collection_id, parent_id) VALUES (101,NULL),(102,101),(103,102),(202,NULL),(203,202),(999,NULL);
		INSERT INTO ca_collections (collection_id,parent_id,deleted) VALUES (404,NULL,1);
		INSERT INTO ca_objects (object_id,parent_id) VALUES (1001,NULL),(1002,1001),(2001,NULL),(3001,NULL),(4001,NULL),(5001,NULL),(6001,NULL);
		INSERT INTO ca_objects_x_collections VALUES (1001,103,1),(2001,203,1),(3001,102,1),(3001,203,1),(4001,999,1),(6001,102,2);
		INSERT INTO ca_users VALUES (12,0),(34,0),(56,0),(78,0),(90,0),(91,1);');
	Configuration::$app = new Configuration(['ca_objects_x_collections_hierarchy_relationship_type' => 'part_of']);
	foreach (CollectionAclSynchronizer::requiredSettings() as $key => $value) { Configuration::$app->values[$key] = $value; }
	Configuration::$plugin = new Configuration(['enable' => 1, 'native_acl_segmentation' => 1, 'required_role' => 'cataloguer',
		'bypass_for_administrators' => 1, 'require_mapping_for_all_users' => 1, 'user_root_collection_map' => [12 => [101], 34 => [202]]]);
	CollectionAccessScope::clearScopeCache();
	CollectionAclSynchronizer::$new_records = [];
	CollectionAclSynchronizer::$dirty = false;
	Session::$vars = ResultContextStorage::$vars = [];
	ca_acl::clearAccessValueCache();
	return new CollectionAccessScope(Configuration::$plugin);
}
function snapshot() { return Db::$pdo->query('SELECT * FROM ca_acl ORDER BY acl_id')->fetchAll(PDO::FETCH_ASSOC); }
function hits($user, $table, $ids) {
	$hits = array_map('intval', (new NativeFindFixture())->filterHitsByACL($ids, Datamodel::getTableNum($table), $user));
	sort($hits);
	return $hits;
}
function permitted($user, $table, $id) { return (int)ca_acl::accessForRow(new ca_users($user), Datamodel::getTableNum($table), $id); }

$backup = sys_get_temp_dir().'/collection-acl-test-'.bin2hex(random_bytes(8));
mkdir($backup, 0700);
try {
	$scope = fixture();
	expect(strlen(CollectionAclPolicy::OWNER) <= NATIVE_ACL_NOTE_LIMIT, 'Ownership marker fits the native ACL notes column');
	$sync = new CollectionAclSynchronizer($scope);
	$plan = $sync->inspect();
	expect($plan['summary']['groups_to_create'] === 3, 'One unrestricted group and two root groups');
	expect($plan['projection']['memberships'][12] === ['gcrm_r_101'], 'Assigned user belongs only to their root group');
	expect($plan['projection']['memberships'][56] === [], 'Required-role user without mapping receives no grants');
	expect($plan['projection']['memberships'][78] === ['gcrm_all'], 'Other roles remain unrestricted');
	expect($plan['projection']['memberships'][90] === ['gcrm_all'], 'Administrators remain unrestricted');
	expect(!isset($plan['projection']['memberships'][91]), 'Public users never join managed groups');
	expect(!isset($plan['projection']['grants']['ca_entities']), 'Only collections and objects receive managed ACLs');
	expect(count($plan['projection']['grants']['ca_objects'][3001]) === 3, 'Shared object receives both tree grants');
	expect($plan['projection']['grants']['ca_objects'][1002] === $plan['projection']['grants']['ca_objects'][1001], 'Object children inherit tree membership');
	expect($plan['projection']['grants']['ca_objects'][6001] === ['gcrm_all'], 'Other relationship types do not add tree membership');
	ob_start();
	$ok = (new CollectionAclTool($scope))->run('sync-collection-scope-acl', []);
	$report = json_decode(ob_get_clean(), true);
	expect($ok && $report['mode'] === 'dry-run', 'CLI defaults to a preview');
	expect(snapshot() === [] && !glob($backup.'/*'), 'Preview writes neither ACLs nor backups');
	refuses(fn() => $sync->apply($plan), 'First apply requires a private backup directory');
	refuses(fn() => $sync->apply($plan, null, true), 'Automatic requests cannot perform initial migration');
	expect(snapshot() === [], 'Rejected apply leaves ACLs untouched');
	chmod($backup, 0755);
	clearstatcache();
	refuses(fn() => $sync->apply($plan, $backup), 'World-readable backup directory is rejected');
	chmod($backup, 0700);
	clearstatcache();
	$sync->apply($plan, $backup);
	$files = glob($backup.'/*');
	expect(count($files) === 1 && (fileperms($files[0]) & 0077) === 0, 'Initial ACL backup is private');
	$journal = json_decode(file_get_contents($files[0]), true);
	expect(isset($journal['inheritance']['ca_objects'][1001]), 'Journal records original inheritance flags');
	expect(!$sync::needsChanges($sync->inspect()), 'Apply is idempotent');
	$before = snapshot();
	$sync->apply($sync->inspect(), $backup);
	expect(snapshot() === $before && count(glob($backup.'/*')) === 1, 'No-op apply creates no duplicate ACLs or backups');
	expect(!in_array('Session', MemoryCache::$flushed), 'ACL sync never flushes sessions');

	$objects = [1001,1002,2001,3001,4001,5001,6001];
	$collections = [101,102,103,202,203,999];
	expect(hits(12, 'ca_objects', $objects) === [1001,1002,3001], 'Native search/browse ACL filter isolates first tree including children and shared object');
	expect(hits(34, 'ca_objects', $objects) === [2001,3001], 'Shared object stays visible in second tree');
	expect(hits(12, 'ca_collections', $collections) === [101,102,103], 'Native collection result filter includes assigned root and descendants only');
	expect(hits(56, 'ca_objects', $objects) === [], 'Unmapped restricted user has no objects');
	expect(hits(78, 'ca_objects', $objects) === $objects, 'Unrestricted users retain orphan and outside objects');
	expect(hits(90, 'ca_objects', $objects) === $objects, 'Native administrator bypass is preserved');
	foreach ([12,34,56,78] as $user) {
		foreach ($objects as $id) { expect((permitted($user, 'ca_objects', $id) > 0) === in_array($id, hits($user, 'ca_objects', $objects)), 'Native direct access and result filtering agree'); }
	}
	foreach (['ca_entities','ca_places','ca_occurrences'] as $table) {
		expect(caACLIsEnabled(Datamodel::getInstanceByTableName($table,true), []) === false, $table.' remains global');
		expect(permitted(12, $table, 8001) > 0, 'Native authority ACL check remains open');
	}
	expect($scope->canAccessObject(new ca_users(12), 1002), 'Plugin editor guard agrees for child objects');
	expect(!$scope->canAccessObject(new ca_users(34), 1002), 'Child object editor still excludes other trees');

	AppController::$instance = null;
	$plugin = new groupCollectionRootMapPlugin(dirname(__DIR__));
	AppController::$instance = new AppController();
	AppController::$instance->request = new SyntheticRequest();
	$domains = ['example.org'];
	expect($plugin->hookAddDomainSecurityPolicy($domains) === $domains, 'Late registration preserves security policy domains');
	$plugin->hookAddDomainSecurityPolicy($domains);
	expect(count(AppController::$instance->plugins) === 1, 'Late bootstrap registers runtime exactly once');
	$runtime = AppController::$instance->plugins[0];
	ResultContextStorage::$vars = ['used_find_types' => ['basic_search' => 1, 'browse/objects' => 1],
		'result_context_ca_objects_basic_search' => ['result_list' => [4001], 'search_expression' => 'archive'],
		'result_context_ca_collections_browse_objects' => ['result_list' => [999]],
		'result_context_ca_entities_basic_search' => ['result_list' => [8001]]];
	$runtime->routeStartup();
	$context = ResultContextStorage::$vars['result_context_ca_objects_basic_search'];
	expect($context['result_list'] === [] && $context['param_invalid_cache'] === 1 && $context['search_expression'] === 'archive', 'Stale object results are discarded while preserving query');
	expect(ResultContextStorage::$vars['result_context_ca_collections_browse_objects']['result_list'] === [], 'Typed collection result contexts are invalidated');
	expect(ResultContextStorage::$vars['result_context_ca_entities_basic_search']['result_list'] === [8001], 'Authority result contexts stay global and intact');
	expect(ResultContextStorage::$saved > 0, 'Cleared contexts are persisted before marking the revision current');
	ResultContextStorage::$fail_save = true;
	refuses(fn() => CollectionAclRuntime::refreshResultContexts($runtime->request, 'new-revision'), 'Saved-context persistence failure blocks access');
	expect(Session::getVar('groupCollectionRootMap_acl_revision') !== 'new-revision', 'A failed saved-context update is never marked current');
	ResultContextStorage::$fail_save = false;

	// A pending collection attached by the native editor in postSave must grant
	// the creator access during the rest of the insert transaction.
	Db::$pdo->beginTransaction();
	Db::$pdo->exec("INSERT INTO ca_objects VALUES (8001,NULL,0,0,0);
		INSERT INTO ca_acl (table_num,row_id,user_id,access) VALUES (57,8001,12,3);
		INSERT INTO ca_acl (table_num,row_id,access) VALUES (57,8001,0)");
	$runtime->request->params['collection_id'] = 102;
	$subject = new ca_objects(8001);
	$params = ['table_name' => 'ca_objects', 'instance' => $subject];
	$plugin->hookBeforeBundleInsert($params);
	expect($subject->fields === ['acl_inherit_from_parent' => 0, 'acl_inherit_from_ca_collections' => 0], 'Model hook suppresses competing native inheritance');
	$plugin->hookAfterBundleInsert($params);
	expect(permitted(12,'ca_objects',8001) === 3, 'Scoped creator can finish saving a pending collection object');
	expect((int)Db::$pdo->query('SELECT count(*) FROM ca_acl WHERE row_id=8001 AND user_id=12')->fetchColumn() === 0, 'Temporary native creator grant is replaced with group grants');
	Db::$pdo->exec('INSERT INTO ca_objects_x_collections VALUES (8001,102,1)');
	Db::$pdo->commit();
	CollectionAclSynchronizer::$dirty = true;
	$runtime->postDispatch();
	expect(!$sync::needsChanges($sync->inspect()), 'Post-save synchronization settles persisted membership');
	Db::$pdo->exec('DELETE FROM ca_objects_x_collections WHERE object_id = 8001');
	refuses(fn() => $runtime->routeStartup(), 'A stale relationship deletion blocks subsequent reads');
	$runtime->request->method = 'POST';
	$runtime->postDispatch();
	expect(permitted(12,'ca_objects',8001) === 0, 'Native relationship deletion removes previous tree access');
	Db::$pdo->exec('UPDATE ca_collections SET parent_id=202 WHERE collection_id=102');
	$runtime->postDispatch();
	expect(hits(12,'ca_objects',$objects) === [], 'Moving a collection removes its subtree from the previous root');
	expect(hits(34,'ca_objects',$objects) === [1001,1002,2001,3001], 'Collection move transfers objects and children to the new tree');
	Db::$pdo->exec('UPDATE ca_collections SET parent_id=101 WHERE collection_id=102');
	$runtime->postDispatch();

	Configuration::$plugin->values['user_root_collection_map'][12] = [202];
	refuses(fn() => $runtime->routeStartup(), 'Changing mappings blocks requests until reconciled');
	$sync->apply($sync->inspect(), $backup);
	$runtime->routeStartup();
	expect(hits(12,'ca_objects',$objects) === [2001,3001], 'Reassigned user loses old tree and gains new tree');
	Db::$locked = true;
	refuses(fn() => $sync->apply($sync->inspect(),$backup), 'Concurrent writer lock is required');
	Db::$locked = false;
	Configuration::$plugin->values['user_root_collection_map'][12] = [101,202];
	$sync->apply($sync->inspect(), $backup);
	expect(hits(12,'ca_objects',$objects) === [1001,1002,2001,3001], 'Multiple assigned roots form a union');
	Configuration::$plugin->values['user_root_collection_map'][12] = [102];
	$sync->apply($sync->inspect(), $backup);
	expect(hits(12,'ca_collections',$collections) === [102,103], 'A nested assigned root never grants its ancestor');

	$scope = fixture(); $sync = new CollectionAclSynchronizer($scope);
	Db::$fail_on_acl_insert = true;
	refuses(fn() => $sync->apply($sync->inspect(),$backup), 'Write failure aborts migration');
	Db::$fail_on_acl_insert = false;
	expect(snapshot() === [] && (int)Db::$pdo->query('SELECT count(*) FROM ca_user_groups')->fetchColumn() === 0, 'ACL and group changes roll back together');
	expect((int)Db::$pdo->query('SELECT acl_inherit_from_parent FROM ca_objects WHERE object_id=1001')->fetchColumn() === 1, 'Failed migration preserves inheritance flags');

	// Every conflict test must leave the unmanaged record unchanged.
	foreach ([
		'INSERT INTO ca_acl (table_num,row_id,access) VALUES (57,1001,1)',
		'INSERT INTO ca_acl (table_num,row_id,user_id,access) VALUES (57,1001,12,3)',
		'INSERT INTO ca_acl (table_num,row_id,group_id,access) VALUES (57,1001,987,3)',
		'INSERT INTO ca_acl (table_num,row_id,user_id,access) VALUES (57,1001,12,0)',
		'INSERT INTO ca_acl (table_num,row_id,user_id,access) VALUES (57,1001,78,0)',
		'INSERT INTO ca_acl (table_num,row_id,group_id,access) VALUES (57,1001,987,0)',
		"INSERT INTO ca_groups_x_roles SELECT group_id,1 FROM ca_user_groups WHERE code='gcrm_all'",
		"UPDATE ca_user_groups SET for_public_use=1 WHERE code='gcrm_all'"
	] as $sql) {
		$scope = fixture(); $sync = new CollectionAclSynchronizer($scope); $sync->apply($sync->inspect(),$backup);
		Db::$pdo->exec($sql);
		$before = snapshot();
		expect((bool)$sync->inspect()['collisions'], 'Unsafe existing grants/group state are detected');
		refuses(fn() => $sync->apply($sync->inspect(),$backup), 'Conflicting state blocks apply');
		expect(snapshot() === $before, 'Conflicting unmanaged ACLs are never overwritten');
	}
	$scope = fixture(); $sync = new CollectionAclSynchronizer($scope);
	Db::$pdo->exec("INSERT INTO ca_user_groups (code,description) VALUES ('gcrm_all','unrelated')");
	expect((bool)$sync->inspect()['collisions'], 'Reserved group code collision is detected');
	$scope = fixture(); $sync = new CollectionAclSynchronizer($scope);
	Configuration::$plugin->values['user_root_collection_map'][12] = [404];
	refuses(fn() => $sync->inspect(), 'Deleted mapped roots fail closed');
	Configuration::$plugin->values['user_root_collection_map'][12] = [101];
	Db::$pdo->exec('UPDATE ca_collections SET parent_id=103 WHERE collection_id=101');
	refuses(fn() => $sync->inspect(), 'Collection cycles fail closed');
	$scope = fixture(); $sync = new CollectionAclSynchronizer($scope);
	Db::$pdo->exec('UPDATE ca_objects SET parent_id=1002 WHERE object_id=1001');
	refuses(fn() => $sync->inspect(), 'Object cycles fail closed');
	$scope = fixture(); $sync = new CollectionAclSynchronizer($scope);
	Configuration::$app->values['ca_entities_dont_do_item_level_access_control'] = 0;
	expect((bool)$sync->configurationErrors(), 'Accidental authority segmentation is rejected');
	refuses(fn() => $sync->apply($sync->inspect(),$backup), 'Incomplete configuration cannot apply');
	Configuration::$plugin->values['native_acl_segmentation'] = 0;
	expect(!$scope->usesNativeACL(), 'Opt-out preserves legacy operation');
	AppController::$instance = new AppController();
	new groupCollectionRootMapPlugin(dirname(__DIR__));
	expect(AppController::$instance->plugins === [], 'Legacy mode registers no native ACL runtime');
	$commands = [];
	$plugin->hookCLICaUtilsGetCommands($commands);
	expect(isset($commands['groupCollectionRootMap']['sync_collection_scope_acl']['Options']['backup-dir|b=s']), 'caUtils registers required backup path option');
	$params = ['groupCollectionRootMap',[], 'CLI'];
	$plugin->hookCLICaUtilsGetToolWithSettings($params);
	expect($params['tool'] instanceof CollectionAclTool, 'caUtils resolves the plugin command');
} finally {
	foreach (glob($backup.'/*') as $file) { unlink($file); }
	rmdir($backup);
}
print "groupCollectionRootMap: {$checks} assertions passed\n";
