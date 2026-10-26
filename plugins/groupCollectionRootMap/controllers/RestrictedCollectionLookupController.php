<?php

require_once(__CA_LIB_DIR__ . '/BaseLookupController.php');
require_once(__CA_MODELS_DIR__ . '/ca_users.php');
require_once(__CA_MODELS_DIR__ . '/ca_collections.php');
require_once(__CA_APP_DIR__ . '/plugins/groupCollectionRootMap/lib/CollectionAccessScope.php');
require_once(__CA_APP_DIR__ . '/plugins/groupCollectionRootMap/controllers/RestrictedObjectCollectionHierarchyController.php');

class RestrictedCollectionLookupController extends BaseLookupController {
	protected $opb_uses_hierarchy_browser = false;
	protected $ops_table_name = 'ca_collections';
	protected $ops_name_singular = 'collection';
	protected $ops_search_class = 'CollectionSearch';

	/** @var CollectionAccessScope */
	private $scope_helper;
	/** @var array|null */
	private $scope = null;
	/** @var array<int, bool> */
	private $membership_cache = [];

	public function __construct(&$request, &$response, $view_paths=null) {
		parent::__construct($request, $response, $view_paths);
		$this->scope_helper = new CollectionAccessScope();
		$this->setLookupViewPaths($request);
	}

	public function Get(?array $additional_query_params=null, ?array $options=null) {
		$scope = $this->getScope();
		if (!($scope['restricted'] ?? false)) {
			return parent::Get($additional_query_params, $options);
		}

		$raw = parent::Get($additional_query_params, $options);
		$this->response->clearContent();
		$items = $this->decodeJSONPayload((string)$raw);
		if (!is_array($items)) {
			caLogEvent('WARN', 'RestrictedCollectionLookupController->Get(): parent lookup returned non-JSON response', 'groupCollectionRootMap');
			return $this->returnJSON([]);
		}

		$allowed = $this->scope_helper->getAllowedCollectionIdLookup($scope);
		$filtered = [];
		foreach($items as $item) {
			$item_id = (int)caGetOption('id', $item, 0);
			if (($item_id > 0) && !$this->isAllowedCollectionId($item_id, $scope, $allowed)) { continue; }
			$filtered[] = $item;
		}

		return $this->returnJSON($filtered);
	}

	public function GetHierarchyLevel() {
		if ($this->requestHasMixedHierarchyIDs()) {
			return $this->delegateToMixedHierarchyController('GetHierarchyLevel');
		}

		$scope = $this->getScope();
		if (!($scope['restricted'] ?? false)) {
			return parent::GetHierarchyLevel();
		}

		$raw = parent::GetHierarchyLevel();
		$this->response->clearContent();
		$data = $this->decodeJSONPayload((string)$raw);
		if (!is_array($data)) {
			caLogEvent('WARN', 'RestrictedCollectionLookupController->GetHierarchyLevel(): parent lookup returned non-JSON response', 'groupCollectionRootMap');
			return $this->returnJSON([]);
		}

		$allowed = $this->scope_helper->getAllowedCollectionIdLookup($scope);
		$filtered = [];
		foreach($data as $level_key => $level_data) {
			if (!is_array($level_data)) {
				$filtered[$level_key] = $level_data;
				continue;
			}

			$tmp = explode(':', (string)$level_key);
			$parent_id = (int)($tmp[0] ?? 0);
			if ($parent_id === 0) {
				$filtered[$level_key] = $this->buildEntryPointLevelForScope($scope);
				continue;
			}
			if (!$this->isAllowedCollectionId($parent_id, $scope, $allowed)) {
				$filtered[$level_key] = [
					'_sortOrder' => [],
					'_primaryKey' => (string)($level_data['_primaryKey'] ?? 'collection_id'),
					'_itemCount' => 0
				];
				continue;
			}
			// Parent is in-scope. Base lookup already returns direct children of this parent,
			// so the entire level can be returned as-is.
			$filtered[$level_key] = $level_data;
		}

		$filtered = $this->ensureRequestedHierarchyLevels($filtered, 'collection_id');

		return $this->returnJSON($filtered);
	}

	public function GetHierarchyAncestorList() {
		if ($this->requestHasMixedHierarchyIDs()) {
			return $this->delegateToMixedHierarchyController('GetHierarchyAncestorList');
		}

		$scope = $this->getScope();
		if (!($scope['restricted'] ?? false)) {
			return parent::GetHierarchyAncestorList();
		}

		$raw = parent::GetHierarchyAncestorList();
		$this->response->clearContent();
		$ancestors = $this->decodeJSONPayload((string)$raw);
		if (!is_array($ancestors)) {
			caLogEvent('WARN', 'RestrictedCollectionLookupController->GetHierarchyAncestorList(): parent lookup returned non-JSON response', 'groupCollectionRootMap');
			return $this->returnJSON([]);
		}

		$allowed = $this->scope_helper->getAllowedCollectionIdLookup($scope);
		$filtered = [];
		foreach($ancestors as $ancestor_id) {
			$ancestor_id = (int)$ancestor_id;
			if (!$this->isAllowedCollectionId($ancestor_id, $scope, $allowed)) { continue; }
			$filtered[] = $ancestor_id;
		}

		return $this->returnJSON($filtered);
	}

	public function SetSortOrder() {
		if ($this->requestHasMixedHierarchyIDs()) {
			return $this->delegateToMixedHierarchyController('SetSortOrder');
		}

		$scope = $this->getScope();
		if (!($scope['restricted'] ?? false)) {
			return parent::SetSortOrder();
		}

		$allowed = $this->scope_helper->getAllowedCollectionIdLookup($scope);
		$id = (int)$this->request->getParameter('id', pInteger);
		$after_id = (int)$this->request->getParameter('after_id', pInteger);
		if (
			(($id > 0) && !$this->isAllowedCollectionId($id, $scope, $allowed))
			||
			(($after_id > 0) && !$this->isAllowedCollectionId($after_id, $scope, $allowed))
		) {
			return $this->returnJSON(['ok' => 0, 'errors' => [_t('Sort operation is not allowed for this collection scope.')], 'timestamp' => null]);
		}

		return parent::SetSortOrder();
	}

	private function getScope() : array {
		if (is_array($this->scope)) { return $this->scope; }
		$user = (isset($this->request->user) && ($this->request->user instanceof ca_users)) ? $this->request->user : null;
		return $this->scope = $this->scope_helper->getScopeForUser($user);
	}

	private function buildEntryPointLevelForScope(array $scope) : array {
		$rows = [];
		$sort_order = [];
		$allowed_ids = $this->scope_helper->getAllowedCollectionIdLookup($scope);
		$root_ids = array_values(array_unique(array_map('intval', $scope['allowed_root_ids'] ?? [])));

		foreach($root_ids as $root_id) {
			if ($root_id <= 0) { continue; }
			$t_root = new ca_collections();
			if (!$t_root->load($root_id)) { continue; }

			$label = strip_tags((string)$t_root->getLabelForDisplay(true));
			$idno = (string)$t_root->get('idno');
			if (!strlen($label)) { $label = strlen($idno) ? $idno : ('??? '.$root_id); }

			$children = 0;
			$child_ids = $t_root->getHierarchyChildren($root_id, ['idsOnly' => true]);
			if (is_array($child_ids)) {
				foreach($child_ids as $child_id) {
					$child_id = (int)$child_id;
					if (($child_id <= 0) || ($child_id === $root_id)) { continue; }
					if ($this->isAllowedCollectionId($child_id, $scope, $allowed_ids)) {
						$children = 1;
						break;
					}
				}
			}

			$rows[$root_id] = [
				'collection_id' => $root_id,
				'item_id' => $root_id,
				'parent_id' => 0,
				'idno' => $idno,
				'name' => $label,
				'children' => $children
			];
			if ($t_root->hasField('is_enabled')) {
				$rows[$root_id]['is_enabled'] = (int)$t_root->get('is_enabled');
			}
			$sort_order[] = $root_id;
		}

		$rows['_sortOrder'] = $sort_order;
		$rows['_primaryKey'] = 'collection_id';
		$rows['_itemCount'] = sizeof($sort_order);
		return $rows;
	}

	private function setLookupViewPaths($request) : void {
		$paths = $this->getViewPaths();
		if (!is_array($paths)) { $paths = []; }
		$lookup_paths = [];
		if (is_object($request) && method_exists($request, 'getViewsDirectoryPath')) {
			$lookup_paths[] = rtrim((string)$request->getViewsDirectoryPath(), '/').'/lookup';
		}
		$lookup_paths[] = __CA_THEMES_DIR__.'/default/views/lookup';

		$merged = [];
		foreach(array_merge($lookup_paths, $paths) as $p) {
			$p = (string)$p;
			if (!strlen($p)) { continue; }
			if (in_array($p, $merged, true)) { continue; }
			$merged[] = $p;
		}
		$this->setViewPath($merged);
	}

	private function decodeJSONPayload(string $raw) : ?array {
		$raw = trim($raw);
		if (!strlen($raw)) { return null; }

		$decoded = json_decode($raw, true);
		if (is_array($decoded)) { return $decoded; }

		$starts = [];
		$pos_obj = strpos($raw, '{');
		$pos_arr = strpos($raw, '[');
		if ($pos_obj !== false) { $starts[] = ['pos' => $pos_obj, 'open' => '{', 'close' => '}']; }
		if ($pos_arr !== false) { $starts[] = ['pos' => $pos_arr, 'open' => '[', 'close' => ']']; }
		if (!sizeof($starts)) { return null; }

		usort($starts, function($a, $b) { return $a['pos'] <=> $b['pos']; });
		foreach($starts as $s) {
			$end = strrpos($raw, $s['close']);
			if (($end === false) || ($end < $s['pos'])) { continue; }
			$snippet = trim(substr($raw, (int)$s['pos'], ((int)$end - (int)$s['pos']) + 1));
			if (!strlen($snippet)) { continue; }
			$decoded = json_decode($snippet, true);
			if (is_array($decoded)) { return $decoded; }
		}

		return null;
	}

	private function requestHasMixedHierarchyIDs() : bool {
		foreach(['id', 'root_item_id', 'after_id'] as $param) {
			$v = (string)$this->request->getParameter($param, pString);
			if (!strlen($v)) { continue; }
			if (preg_match('!ca_[a-z_]+-\d+!i', $v)) {
				return true;
			}
		}
		return false;
	}

	private function delegateToMixedHierarchyController(string $method) {
		$request = $this->request;
		$response = $this->response;
		$controller = new RestrictedObjectCollectionHierarchyController($request, $response, $this->getViewPaths());
		return $controller->{$method}();
	}

	private function ensureRequestedHierarchyLevels(array $levels, string $default_pk='collection_id') : array {
		$requested = trim((string)$this->request->getParameter('id', pString));
		if (!strlen($requested)) { return $levels; }
		$requested_ids = preg_split('![;]+!', $requested);
		if (!is_array($requested_ids)) { return $levels; }

		foreach($requested_ids as $rid) {
			$rid = trim((string)$rid);
			if (!strlen($rid)) { continue; }
			if (array_key_exists($rid, $levels)) { continue; }
			$levels[$rid] = [
				'_sortOrder' => [],
				'_primaryKey' => $default_pk,
				'_itemCount' => 0
			];
		}
		return $levels;
	}

	private function returnJSON($payload) : string {
		$json = json_encode($payload);
		if ($json === false) { $json = '[]'; }

		$this->response->setContentType('application/json');
		$this->response->clearContent();
		$this->response->addContent($json, 'view');
		return $json;
	}

	private function isAllowedCollectionId(int $collection_id, array $scope, array $allowed_lookup=[]) : bool {
		if ($collection_id <= 0) { return false; }
		if ($allowed_lookup[$collection_id] ?? false) { return true; }
		if (array_key_exists($collection_id, $this->membership_cache)) {
			return (bool)$this->membership_cache[$collection_id];
		}

		$root_ids = array_values(array_unique(array_map('intval', $scope['allowed_root_ids'] ?? [])));
		if (!sizeof($root_ids)) {
			return $this->membership_cache[$collection_id] = false;
		}
		if (in_array($collection_id, $root_ids, true)) {
			return $this->membership_cache[$collection_id] = true;
		}

		$ancestor_ids = ca_collections::getHierarchyAncestorsForIDs([$collection_id], ['returnAs' => 'ids', 'includeSelf' => true]);
		if (!is_array($ancestor_ids)) { $ancestor_ids = []; }
		$ancestor_ids = array_map('intval', $ancestor_ids);

		return $this->membership_cache[$collection_id] = (sizeof(array_intersect($root_ids, $ancestor_ids)) > 0);
	}

}
