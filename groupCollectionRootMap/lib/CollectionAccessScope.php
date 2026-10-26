<?php

require_once(__CA_MODELS_DIR__ . '/ca_collections.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_users.php');

class CollectionAccessScope {
	/** @var Configuration */
	private $base_config;
	/** @var Configuration|null */
	private $local_config = null;

	/** @var array<int, array> */
	private static $scope_cache = [];

	public function __construct($plugin_path=null) {
		if ($plugin_path instanceof Configuration) {
			$this->base_config = $plugin_path;
			return;
		}
		$plugin_path = $plugin_path ?: (__CA_APP_DIR__ . '/plugins/groupCollectionRootMap');
		$this->base_config = Configuration::load($plugin_path . '/conf/plugin.conf');

		$local_path = $plugin_path . '/conf/plugin.local.conf';
		if (file_exists($local_path)) {
			$this->local_config = Configuration::load($local_path);
		}
	}

	public function isEnabled() : bool {
		return (bool)$this->getConfig('enable');
	}

	public function bypassForAdministrators() : bool {
		return (bool)$this->getConfig('bypass_for_administrators');
	}

	public function requireMappingForAllUsers() : bool {
		return (bool)$this->getConfig('require_mapping_for_all_users');
	}

	public function allowRootParentAssignment() : bool {
		return (bool)$this->getConfig('allow_root_parent_assignment');
	}

	public function appliesToUser(?ca_users $user) : bool {
		if (!$this->isEnabled()) { return false; }
		if (!($user instanceof ca_users)) { return false; }
		if ($this->bypassForAdministrators() && $user->canDoAction('is_administrator')) { return false; }

		$required_role = trim((string)$this->getConfig('required_role'));
		if (!strlen($required_role)) { return true; }
		return (bool)$user->hasRole($required_role);
	}

	public function getScopeForUser(?ca_users $user) : array {
		if (!($user instanceof ca_users) || !($user_id = (int)$user->getPrimaryKey())) {
			return [
				'applies' => false,
				'restricted' => false,
				'user_id' => null,
				'allowed_root_ids' => [],
				'allowed_collection_ids' => []
			];
		}
		if (isset(self::$scope_cache[$user_id])) {
			return self::$scope_cache[$user_id];
		}

		$applies = $this->appliesToUser($user);
		$allowed_root_ids = $applies ? $this->getAllowedRootCollectionIdsForUserId($user_id) : [];
		$restricted = $applies && (sizeof($allowed_root_ids) > 0 || $this->requireMappingForAllUsers());
		$allowed_collection_ids = $restricted ? $this->getAllowedCollectionIdsForRoots($allowed_root_ids) : [];

		return self::$scope_cache[$user_id] = [
			'applies' => $applies,
			'restricted' => $restricted,
			'user_id' => $user_id,
			'allowed_root_ids' => $allowed_root_ids,
			'allowed_collection_ids' => $allowed_collection_ids
		];
	}

	public function getAssignedRootCollectionIdsForUser(?ca_users $user) : array {
		if (!($user instanceof ca_users) || !($user_id = (int)$user->getPrimaryKey())) {
			return [];
		}
		return $this->getAllowedRootCollectionIdsForUserId($user_id);
	}

	public function getAllowedCollectionIdLookup(array $scope) : array {
		$ids = $scope['allowed_collection_ids'] ?? [];
		if (!is_array($ids)) { return []; }
		return array_fill_keys(array_map('intval', $ids), true);
	}

	public function getAllowedRootIdLookup(array $scope) : array {
		$ids = $scope['allowed_root_ids'] ?? [];
		if (!is_array($ids)) { return []; }
		return array_fill_keys(array_map('intval', $ids), true);
	}

	public function getObjectCollectionRelationshipType() : ?string {
		$rel_type = trim((string)$this->getAppConfigValue('ca_objects_x_collections_hierarchy_relationship_type'));
		return strlen($rel_type) ? $rel_type : null;
	}

	public function isCollectionIDAllowed(int $collection_id, array $scope, array $allowed_lookup=[]) : bool {
		if ($collection_id <= 0) { return false; }
		if (!($scope['restricted'] ?? false)) { return true; }

		if (!sizeof($allowed_lookup)) {
			$allowed_lookup = $this->getAllowedCollectionIdLookup($scope);
		}
		return (bool)($allowed_lookup[$collection_id] ?? false);
	}

	public function canAccessCollection(?ca_users $user, $collection) : bool {
		$scope = $this->getScopeForUser($user);
		if (!($scope['restricted'] ?? false)) { return true; }

		$collection_id = $this->normalizeCollectionID($collection);
		return $this->isCollectionIDAllowed($collection_id, $scope);
	}

	public function getCollectionIDsForObject($object, ?string $relationship_type=null) : array {
		$t_object = $this->normalizeObjectInstance($object);
		if (!($t_object instanceof ca_objects) || !($t_object->getPrimaryKey())) { return []; }

		$options = ['idsOnly' => true];
		if ($relationship_type === null) {
			$relationship_type = $this->getObjectCollectionRelationshipType();
		}
		if ($relationship_type) {
			$options['restrictToRelationshipTypes'] = [$relationship_type];
		}

		$collection_ids = $t_object->getRelatedItems('ca_collections', $options);
		if (!is_array($collection_ids)) { return []; }

		$collection_ids = array_values(array_unique(array_filter(array_map('intval', $collection_ids), function($id) {
			return ($id > 0);
		})));
		sort($collection_ids, SORT_NUMERIC);
		return $collection_ids;
	}

	public function isObjectIDAllowed(int $object_id, array $scope, ?string $relationship_type=null) : bool {
		if ($object_id <= 0) { return false; }
		if (!($scope['restricted'] ?? false)) { return true; }

		$collection_lookup = $this->getAllowedCollectionIdLookup($scope);
		foreach($this->getCollectionIDsForObject($object_id, $relationship_type) as $collection_id) {
			if ($this->isCollectionIDAllowed($collection_id, $scope, $collection_lookup)) {
				return true;
			}
		}
		return false;
	}

	public function canAccessObject(?ca_users $user, $object, ?string $relationship_type=null) : bool {
		$scope = $this->getScopeForUser($user);
		if (!($scope['restricted'] ?? false)) { return true; }

		$object_id = $this->normalizeObjectID($object);
		return $this->isObjectIDAllowed($object_id, $scope, $relationship_type);
	}

	private function getAllowedRootCollectionIdsForUserId(int $user_id) : array {
		$map = $this->getConfigAssoc('user_root_collection_map');
		if (!is_array($map) || !sizeof($map) || ($user_id <= 0)) { return []; }

		$normalized_map = [];
		foreach($map as $map_user_id => $root_ids) {
			$root_ids = is_array($root_ids) ? $root_ids : [$root_ids];
			$root_ids = array_values(array_unique(array_filter(array_map('intval', $root_ids), function($id) {
				return ($id > 0);
			})));
			if (!sizeof($root_ids)) { continue; }

			$key = (int)$map_user_id;
			if ($key <= 0) { continue; }
			$normalized_map[$key] = $root_ids;
		}

		$allowed = $normalized_map[$user_id] ?? [];
		$allowed = array_values(array_unique(array_map('intval', $allowed)));
		sort($allowed, SORT_NUMERIC);
		return $allowed;
	}

	private function getAllowedCollectionIdsForRoots(array $root_ids) : array {
		if (!sizeof($root_ids)) { return []; }
		$descendants = ca_collections::getHierarchyChildrenForIDs($root_ids, ['includeSelf' => true, 'returnAs' => 'ids']);
		if (!is_array($descendants)) { $descendants = []; }
		$all = array_values(array_unique(array_merge($root_ids, array_map('intval', $descendants))));
		sort($all, SORT_NUMERIC);
		return $all;
	}

	private function normalizeCollectionID($collection) : int {
		if ($collection instanceof ca_collections) {
			return (int)$collection->getPrimaryKey();
		}
		return (int)$collection;
	}

	private function normalizeObjectID($object) : int {
		if ($object instanceof ca_objects) {
			return (int)$object->getPrimaryKey();
		}
		return (int)$object;
	}

	private function normalizeObjectInstance($object) : ?ca_objects {
		if ($object instanceof ca_objects) {
			return $object;
		}
		$object_id = (int)$object;
		if ($object_id <= 0) { return null; }

		$t_object = new ca_objects();
		if (!$t_object->load($object_id)) { return null; }
		return $t_object;
	}

	private function getAppConfigValue(string $key) {
		static $config = null;
		if (!$config) {
			$config = Configuration::load(__CA_APP_DIR__ . '/conf/app.conf');
		}
		return $config->get($key);
	}

	private function getConfig(string $key) {
		if ($this->local_config) {
			$local_scalar = $this->local_config->getScalar($key);
			if ($local_scalar !== false) { return $local_scalar; }

			$local_list = $this->local_config->getList($key);
			if (is_array($local_list)) { return $local_list; }

			$local_assoc = $this->local_config->getAssoc($key);
			if (is_array($local_assoc)) { return $local_assoc; }
		}
		return $this->base_config->get($key);
	}

	private function getConfigAssoc(string $key) : ?array {
		if ($this->local_config) {
			$local_assoc = $this->local_config->getAssoc($key);
			if (is_array($local_assoc)) { return $local_assoc; }
		}
		return $this->base_config->getAssoc($key);
	}
}
