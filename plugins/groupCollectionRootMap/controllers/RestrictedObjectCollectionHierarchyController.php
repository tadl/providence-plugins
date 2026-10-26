<?php

require_once(__CA_APP_DIR__ . '/controllers/lookup/ObjectCollectionHierarchyController.php');
require_once(__CA_MODELS_DIR__ . '/ca_users.php');
require_once(__CA_MODELS_DIR__ . '/ca_collections.php');
require_once(__CA_APP_DIR__ . '/plugins/groupCollectionRootMap/lib/CollectionAccessScope.php');

class RestrictedObjectCollectionHierarchyController extends ObjectCollectionHierarchyController {
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

	public function GetHierarchyLevel() {
		$scope = $this->getScope();
		if (!($scope['restricted'] ?? false)) {
			return parent::GetHierarchyLevel();
		}

		$raw = parent::GetHierarchyLevel();
		$this->response->clearContent();
		$data = $this->decodeJSONPayload((string)$raw);
		if (!is_array($data)) {
			caLogEvent('WARN', 'RestrictedObjectCollectionHierarchyController->GetHierarchyLevel(): parent lookup returned non-JSON response', 'groupCollectionRootMap');
			return $this->returnJSON([]);
		}

		$allowed = $this->scope_helper->getAllowedCollectionIdLookup($scope);
		$filtered = [];
		foreach($data as $level_key => $level_data) {
			if (!is_array($level_data)) {
				$filtered[$level_key] = $level_data;
				continue;
			}
			$item_id = explode(':', (string)$level_key)[0] ?? '0';
			$level_info = $this->parseMixedItemID((string)$item_id);
			$parent_table = $level_info['table'];
			$parent_id = (int)$level_info['id'];

			if ($parent_id === 0) {
				$filtered[$level_key] = $this->buildEntryPointLevelForScope($scope);
				continue;
			}
			if (($parent_table === 'ca_collections') && !$this->isAllowedCollectionId($parent_id, $scope, $allowed)) {
				$filtered[$level_key] = [
					'_sortOrder' => [],
					'_primaryKey' => (string)($level_data['_primaryKey'] ?? 'collection_id'),
					'_itemCount' => 0
				];
				continue;
			}
			if ($parent_table === 'ca_collections') {
				$filtered[$level_key] = $this->filterLevelRowsToScope($level_data, $scope, $allowed);
				continue;
			}
			$filtered[$level_key] = $level_data;
		}

		return $this->returnJSON($filtered);
	}

	public function GetHierarchyAncestorList() {
		$scope = $this->getScope();
		if (!($scope['restricted'] ?? false)) {
			return parent::GetHierarchyAncestorList();
		}

		$raw = parent::GetHierarchyAncestorList();
		$this->response->clearContent();
		$ancestors = $this->decodeJSONPayload((string)$raw);
		if (!is_array($ancestors)) {
			caLogEvent('WARN', 'RestrictedObjectCollectionHierarchyController->GetHierarchyAncestorList(): parent lookup returned non-JSON response', 'groupCollectionRootMap');
			return $this->returnJSON([]);
		}

		$allowed = $this->scope_helper->getAllowedCollectionIdLookup($scope);
		$filtered = [];
		foreach($ancestors as $ancestor_item_id) {
			$info = $this->parseMixedItemID((string)$ancestor_item_id);
			if ($info['table'] === 'ca_collections') {
				if (!$this->isAllowedCollectionId((int)$info['id'], $scope, $allowed)) { continue; }
			}
			$filtered[] = (string)$ancestor_item_id;
		}

		return $this->returnJSON($filtered);
	}

	public function SetSortOrder() {
		$scope = $this->getScope();
		if (!($scope['restricted'] ?? false)) {
			return parent::SetSortOrder();
		}

		$allowed = $this->scope_helper->getAllowedCollectionIdLookup($scope);
		$id_info = $this->parseMixedItemID((string)$this->request->getParameter('id', pString));
		$after_info = $this->parseMixedItemID((string)$this->request->getParameter('after_id', pString));

		if (
			(($id_info['table'] === 'ca_collections') && ((int)$id_info['id'] > 0) && !$this->isAllowedCollectionId((int)$id_info['id'], $scope, $allowed))
			||
			(($after_info['table'] === 'ca_collections') && ((int)$after_info['id'] > 0) && !$this->isAllowedCollectionId((int)$after_info['id'], $scope, $allowed))
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

	private function parseMixedItemID(string $item_id) : array {
		$item_id = trim($item_id);
		$tmp = explode(':', $item_id);
		$main = (string)($tmp[0] ?? '');
		$tmp2 = explode('-', $main, 2);
		if (sizeof($tmp2) === 2) {
			return ['table' => (string)$tmp2[0], 'id' => (int)$tmp2[1]];
		}
		return ['table' => 'ca_collections', 'id' => (int)$main];
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

	private function returnJSON($payload) : string {
		$json = json_encode($payload);
		if ($json === false) { $json = '[]'; }

		$this->response->setContentType('application/json');
		$this->response->clearContent();
		$this->response->addContent($json, 'view');
		return $json;
	}

	private function filterLevelRowsToScope(array $level_data, array $scope, array $allowed_lookup) : array {
		$out = [];
		$sort_order = is_array($level_data['_sortOrder'] ?? null) ? $level_data['_sortOrder'] : [];
		$item_count = (int)($level_data['_itemCount'] ?? 0);

		foreach($sort_order as $entry_key) {
			if (!isset($level_data[$entry_key]) || !is_array($level_data[$entry_key])) { continue; }
			$row = $level_data[$entry_key];
			$row_item_info = $this->parseMixedItemID((string)($row['item_id'] ?? $entry_key));
			if (($row_item_info['table'] === 'ca_collections') && !$this->isAllowedCollectionId((int)$row_item_info['id'], $scope, $allowed_lookup)) {
				continue;
			}
			$out[$entry_key] = $row;
		}

		$out['_sortOrder'] = array_values(array_filter($sort_order, function($k) use ($out) { return isset($out[$k]); }));
		$out['_primaryKey'] = $level_data['_primaryKey'] ?? 'collection_id';
		$out['_itemCount'] = min($item_count, sizeof($out['_sortOrder']));
		return $out;
	}

	private function buildEntryPointLevelForScope(array $scope) : array {
		$rows = [];
		$sort_order = [];
		$allowed_lookup = $this->scope_helper->getAllowedCollectionIdLookup($scope);
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
					if ($this->isAllowedCollectionId($child_id, $scope, $allowed_lookup)) {
						$children = 1;
						break;
					}
				}
			}

			$key = 'ca_collections-'.$root_id;
			$rows[$key] = [
				'collection_id' => $root_id,
				'item_id' => $key,
				'parent_id' => 0,
				'idno' => $idno,
				'name' => $label,
				'children' => $children
			];
			$sort_order[] = $key;
		}

		$rows['_sortOrder'] = $sort_order;
		$rows['_primaryKey'] = 'collection_id';
		$rows['_itemCount'] = sizeof($sort_order);
		return $rows;
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
