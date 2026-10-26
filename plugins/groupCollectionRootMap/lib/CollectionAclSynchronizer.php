<?php
require_once __DIR__.'/CollectionAclPolicy.php';
require_once __CA_MODELS_DIR__.'/ca_acl.php';

/** Owns tagged ACL/group rows only. No catalogue labels, media or access values. */
class CollectionAclSynchronizer {
	private $scope;
	private $db;
	public static $dirty = false;
	public static $new_records = [];

	public function __construct(CollectionAccessScope $scope, $db=null) {
		$this->scope = $scope;
		$this->db = $db ?: new Db();
	}

	private function query(string $sql, array $params=[]) {
		$result = $this->db->query($sql, $params);
		if ($this->db->numErrors()) { throw new RuntimeException('Collection ACL database operation failed.'); }
		return $result;
	}

	private function rows(string $sql, array $params=[]) : array {
		$result = $this->query($sql, $params);
		$rows = [];
		while ($result->nextRow()) { $rows[] = $result->getRow(); }
		return $rows;
	}

	public static function requiredSettings() : array {
		$required = ['perform_item_level_access_checking' => 1, 'default_item_access_level' => 0,
			'ca_objects_dont_do_item_level_access_control' => 0, 'ca_collections_dont_do_item_level_access_control' => 0];
		foreach (Datamodel::getTableNames() as $table) {
			if (in_array($table, CollectionAclPolicy::TABLES, true)) { continue; }
			$model = Datamodel::getInstanceByTableName($table, true);
			if ($model && $model->getProperty('SUPPORTS_ACL')) { $required[$table.'_dont_do_item_level_access_control'] = 1; }
		}
		return $required;
	}

	public function configurationErrors() : array {
		$config = Configuration::load();
		$errors = [];
		foreach (self::requiredSettings() as $key => $value) {
			if ((int)$config->get($key) !== $value) { $errors[] = $key.' must equal '.$value; }
		}
		if (!$this->scope->bypassForAdministrators()) { $errors[] = 'Native ACLs always bypass administrators; administrator bypass must remain enabled.'; }
		if (defined('__CA_DISABLE_ACL__') && __CA_DISABLE_ACL__) { $errors[] = 'Item ACL checking has been disabled for this process.'; }
		return $errors;
	}

	public function inspect() : array {
		CollectionAccessScope::clearScopeCache();
		$collections = $objects = $flags = [];
		foreach ($this->rows('SELECT collection_id, parent_id, acl_inherit_from_parent FROM ca_collections WHERE deleted = 0') as $row) {
			$collections[(int)$row['collection_id']] = (int)$row['parent_id'];
			if ($row['acl_inherit_from_parent']) { $flags['ca_collections'][(int)$row['collection_id']] = $row; }
		}
		foreach ($this->rows('SELECT object_id, parent_id, acl_inherit_from_parent, acl_inherit_from_ca_collections FROM ca_objects WHERE deleted = 0') as $row) {
			$objects[(int)$row['object_id']] = (int)$row['parent_id'];
			if ($row['acl_inherit_from_parent'] || $row['acl_inherit_from_ca_collections']) { $flags['ca_objects'][(int)$row['object_id']] = $row; }
		}
		$link_sql = 'SELECT object_id, collection_id FROM ca_objects_x_collections';
		$link_params = [];
		if ($type = $this->scope->getObjectCollectionRelationshipType()) {
			$types = caMakeRelationshipTypeIDList('ca_objects_x_collections', [$type]);
			if (!$types) { throw new RuntimeException('The configured hierarchy relationship type is invalid.'); }
			$link_sql .= ' WHERE type_id IN (?)';
			$link_params[] = $types;
		}
		$links = [];
		foreach ($this->rows($link_sql, $link_params) as $row) { $links[] = [(int)$row['object_id'], (int)$row['collection_id']]; }
		$scopes = [];
		foreach ($this->rows('SELECT user_id FROM ca_users WHERE userclass = 0') as $row) {
			$id = (int)$row['user_id'];
			$user = new ca_users($id);
			$scopes[$id] = $this->scope->getScopeForUser($user);
		}
		$projection = CollectionAclPolicy::project($collections, $objects, $links, $scopes);
		$groups = $group_ids = $collisions = [];
		foreach ($this->rows('SELECT group_id, code, description, for_public_use FROM ca_user_groups') as $group) {
			if ($group['description'] === CollectionAclPolicy::OWNER) {
				$groups[$group['code']] = $group;
				$group_ids[(int)$group['group_id']] = $group['code'];
			} elseif (array_key_exists($group['code'], $projection['groups'])) { $collisions[] = 'A reserved group code is already in use.'; }
		}
		foreach ($groups as $group) {
			if ($group['for_public_use']) { $collisions[] = 'A managed group allows public self-enrollment.'; }
		}
		if ($group_ids && $this->rows('SELECT group_id FROM ca_groups_x_roles WHERE group_id IN (?)', [array_keys($group_ids)])) {
			$collisions[] = 'Managed ACL groups must not carry application roles.';
		}
		$actual_memberships = [];
		foreach ($this->rows('SELECT relation_id, user_id, group_id FROM ca_users_x_groups') as $row) {
			if (isset($group_ids[(int)$row['group_id']])) { $actual_memberships[(int)$row['relation_id']] = $row; }
		}
		$table_nums = [];
		foreach (CollectionAclPolicy::TABLES as $table) { $table_nums[Datamodel::getTableNum($table)] = $table; }
		$acl = $this->rows('SELECT * FROM ca_acl WHERE table_num IN (?)', [array_keys($table_nums)]);
		$actual = $remove = $creator = [];
		foreach ($acl as $row) {
			$table = $table_nums[(int)$row['table_num']];
			$id = (int)$row['acl_id'];
			$user_id = (int)$row['user_id'];
			if ($row['notes'] === CollectionAclPolicy::OWNER) {
				$code = $group_ids[(int)$row['group_id']] ?? null;
				$key = $table.':'.$row['row_id'].':'.$code;
				if (!$code || $user_id || (int)$row['access'] !== 3 || $row['inherited_from_table_num'] !== null || isset($actual[$key])) {
					$remove[] = $id;
				} else { $actual[$key] = $id; }
				continue;
			}
			// Replace only native creator grants inserted by this very request.
			// Keeping them would defeat later root/role reassignment.
			if ($user_id && isset($scopes[$user_id]) && $row['group_id'] === null
				&& (int)$row['access'] === 3 && $row['notes'] === '' && $row['inherited_from_table_num'] === null
				&& (self::$new_records[$table][(int)$row['row_id']] ?? null) === $user_id) {
				$creator[] = $id;
			} elseif ($user_id || $row['group_id'] !== null || (int)$row['access'] > 0) {
				// A positive grant can expose another tree. A deny can be trumped
				// by our grants, silently changing existing permission intent.
				$collisions[] = 'An unmanaged item ACL conflicts with collection segmentation.';
			}
		}
		$expected = CollectionAclPolicy::grantKeys($projection);
		$insert = array_keys(array_diff_key($expected, $actual));
		foreach (array_diff_key($actual, $expected) as $id) { $remove[] = $id; }
		$want_memberships = [];
		foreach ($projection['memberships'] as $user_id => $codes) {
			foreach ($codes as $code) { $want_memberships[$user_id.':'.$code] = true; }
		}
		$have_memberships = $remove_memberships = [];
		foreach ($actual_memberships as $id => $row) {
			$key = $row['user_id'].':'.$group_ids[(int)$row['group_id']];
			if (!isset($want_memberships[$key]) || isset($have_memberships[$key])) { $remove_memberships[] = $id; }
			else { $have_memberships[$key] = true; }
		}
		$add_memberships = array_keys(array_diff_key($want_memberships, $have_memberships));
		$create_groups = array_diff_key($projection['groups'], $groups);
		$summary = ['groups_to_create' => count($create_groups), 'acl_to_insert' => count($insert),
			'acl_to_remove' => count($remove) + count($creator), 'memberships_to_add' => count($add_memberships),
			'memberships_to_remove' => count($remove_memberships), 'inheritance_rows_to_update' => array_sum(array_map('count', $flags)),
			'conflicts' => count($collisions)];
		return compact('projection', 'scopes', 'groups', 'group_ids', 'acl', 'flags', 'actual_memberships', 'insert', 'remove',
			'creator', 'add_memberships', 'remove_memberships', 'create_groups', 'summary', 'collisions');
	}

	public static function needsChanges(array $plan) : bool {
		$summary = $plan['summary'];
		unset($summary['conflicts']);
		return array_sum($summary) > 0;
	}

	/** Called inside the model's insert transaction, before it is committed. */
	public function initializeRecord(string $table, int $id, int $creator_id, int $pending_collection=0) : void {
		if ($this->configurationErrors()) { throw new RuntimeException('Native segmentation configuration is not ready for record creation.'); }
		if (!in_array($table, CollectionAclPolicy::TABLES, true) || $id <= 0) { throw new RuntimeException('Invalid new ACL record.'); }
		self::$new_records[$table][$id] = $creator_id;
		$plan = $this->inspect();
		if (!isset($plan['groups'][CollectionAclPolicy::ALL_GROUP]) || $plan['collisions']) {
			throw new RuntimeException('Native collection permissions need initialization or conflict resolution.');
		}
		$codes = $plan['projection']['grants'][$table][$id] ?? [];
		if ($table === 'ca_objects' && $pending_collection) {
			$codes = array_merge($codes, $plan['projection']['grants']['ca_collections'][$pending_collection] ?? []);
		}
		$number = Datamodel::getTableNum($table);
		foreach (array_unique($codes) as $code) {
			$group_id = $plan['groups'][$code]['group_id'] ?? null;
			if (!$group_id) { throw new RuntimeException('A mapped collection group is missing.'); }
			$existing = $this->rows('SELECT acl_id FROM ca_acl WHERE table_num = ? AND row_id = ? AND group_id = ? AND notes = ?',
				[$number, $id, $group_id, CollectionAclPolicy::OWNER]);
			if (!$existing) {
				$this->query('INSERT INTO ca_acl (table_num, row_id, group_id, user_id, access, notes) VALUES (?, ?, ?, NULL, 3, ?)',
					[$number, $id, $group_id, CollectionAclPolicy::OWNER]);
			}
		}
		if ($plan['creator']) { $this->query('DELETE FROM ca_acl WHERE acl_id IN (?)', [$plan['creator']]); }
		ca_acl::clearAccessValueCache();
	}

	/** Caller must explicitly authorize apply; preview never calls this method. */
	public function apply(array $plan, ?string $backup_dir=null, bool $automatic=false) : void {
		// Serialize our writers and re-read under the lock: previews and other
		// requests can have changed since the caller inspected the catalogue.
		$lock = $this->query("SELECT GET_LOCK(?, 10) AS acquired", ['groupCollectionRootMap.acl']);
		if (!$lock->nextRow() || (int)$lock->get('acquired') !== 1) { throw new RuntimeException('Another collection ACL synchronization is running.'); }
		try {
			$plan = $this->inspect();
			if ($automatic && !isset($plan['groups'][CollectionAclPolicy::ALL_GROUP])) {
				throw new RuntimeException('Initialize collection permissions with a backed-up CLI apply.');
			}
			$this->applyLocked($plan, $backup_dir, $automatic);
		} finally { $this->query('SELECT RELEASE_LOCK(?)', ['groupCollectionRootMap.acl']); }
	}

	private function applyLocked(array $plan, ?string $backup_dir, bool $automatic) : void {
		if ($plan['collisions']) { throw new RuntimeException('Unmanaged ACL conflicts prevent synchronization; no changes applied.'); }
		if (!$this->scope->usesNativeACL() || $this->configurationErrors()) { throw new RuntimeException('Native segmentation configuration is not ready.'); }
		if (!self::needsChanges($plan)) { return; }
		if ($this->db->getTransactionCount()) { throw new RuntimeException('Finish the catalogue transaction before synchronizing ACLs.'); }
		if (!$automatic) { $this->writeBackup($plan, $backup_dir); }
		require_once __CA_LIB_DIR__.'/Db/Transaction.php';
		require_once __CA_MODELS_DIR__.'/ca_user_groups.php';
		$transaction = new Transaction($this->db);
		if ($this->db->numErrors()) { throw new RuntimeException('Could not start ACL transaction.'); }
		try {
			$groups = $plan['groups'];
			foreach ($plan['create_groups'] as $code => $root) {
				$group = new ca_user_groups();
				$group->setTransaction($transaction);
				$group->set('name', 'Collection scope: '.$code);
				$group->set('code', $code);
				$group->set('description', CollectionAclPolicy::OWNER);
				$group->set('for_public_use', 0);
				$group->insert();
				if ($group->numErrors() || !$group->getPrimaryKey()) { throw new RuntimeException('Could not create a private ACL group.'); }
				$groups[$code] = ['group_id' => (int)$group->getPrimaryKey()];
			}
			$delete = array_merge($plan['remove'], $plan['creator']);
			if ($delete) { $this->query('DELETE FROM ca_acl WHERE acl_id IN (?)', [$delete]); }
			foreach (array_chunk($plan['insert'], 500) as $chunk) {
				$values = $params = [];
				foreach ($chunk as $key) {
					[$table, $row_id, $code] = explode(':', $key);
					$values[] = '(?, ?, ?, NULL, 3, ?)';
					array_push($params, Datamodel::getTableNum($table), (int)$row_id, $groups[$code]['group_id'], CollectionAclPolicy::OWNER);
				}
				$this->query('INSERT INTO ca_acl (table_num, row_id, group_id, user_id, access, notes) VALUES '.join(', ', $values), $params);
			}
			if ($plan['remove_memberships']) { $this->query('DELETE FROM ca_users_x_groups WHERE relation_id IN (?)', [$plan['remove_memberships']]); }
			foreach (array_chunk($plan['add_memberships'], 500) as $chunk) {
				$values = $params = [];
				foreach ($chunk as $key) {
					[$user_id, $code] = explode(':', $key);
					$values[] = '(?, ?)';
					array_push($params, (int)$user_id, $groups[$code]['group_id']);
				}
				$this->query('INSERT INTO ca_users_x_groups (user_id, group_id) VALUES '.join(', ', $values), $params);
			}
			foreach ($plan['flags'] as $table => $records) {
				$pk = $table === 'ca_objects' ? 'object_id' : 'collection_id';
				$extra = $table === 'ca_objects' ? ', acl_inherit_from_ca_collections = 0' : '';
				$this->query("UPDATE {$table} SET acl_inherit_from_parent = 0{$extra} WHERE {$pk} IN (?)", [array_keys($records)]);
			}
			$transaction->commit();
			if ($this->db->numErrors()) { throw new RuntimeException('Could not commit ACL transaction.'); }
		} catch (Throwable $error) { $transaction->rollback(); throw $error; }
		self::invalidateCaches();
	}

	private function writeBackup(array $plan, ?string $directory) : void {
		$real = $directory ? realpath($directory) : false;
		$base = realpath(__CA_BASE_DIR__);
		if (!$real || !is_dir($real) || ($real === $base) || strpos($real, $base.DIRECTORY_SEPARATOR) === 0
			|| (fileperms($real) & 0077)) { throw new RuntimeException('Apply requires a private existing backup directory outside the webroot (mode 0700).'); }
		$previous_umask = umask(0077);
		try {
			$path = $real.'/collection-acl-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
			$file = fopen($path, 'x');
			if (!$file) { throw new RuntimeException('Could not create ACL backup.'); }
			try {
				$data = json_encode(['format' => 1, 'created_at' => gmdate('c'), 'acl' => $plan['acl'],
					'groups' => $plan['groups'], 'memberships' => $plan['actual_memberships'], 'inheritance' => $plan['flags']], JSON_THROW_ON_ERROR);
				if (fwrite($file, $data) !== strlen($data) || !fflush($file) || !fsync($file)) { throw new RuntimeException('Could not durably save ACL backup.'); }
			} finally { fclose($file); }
		} finally { umask($previous_umask); }
	}

	public static function invalidateCaches() : void {
		foreach (['SearchCache', 'BrowseFacets', 'BrowseResults', 'BrowseParams', 'BrowseTypeRestrictions', 'BrowseSourceRestrictions'] as $namespace) {
			MemoryCache::flush($namespace);
			ExternalCache::flush($namespace);
		}
		ca_users::clearCaches();
		ca_acl::clearAccessValueCache();
		CollectionAccessScope::clearScopeCache();
	}
}
