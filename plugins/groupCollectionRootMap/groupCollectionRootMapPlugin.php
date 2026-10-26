<?php
/* ----------------------------------------------------------------------
 * groupCollectionRootMapPlugin.php
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__ . '/ca_collections.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_users.php');
require_once(__CA_LIB_DIR__ . '/ApplicationError.php');
require_once(__CA_LIB_DIR__ . '/Controller/Request/NotificationManager.php');
require_once(__CA_APP_DIR__ . '/plugins/groupCollectionRootMap/lib/CollectionAccessScope.php');

class groupCollectionRootMapPlugin extends BaseApplicationPlugin {
	/** @var CollectionAccessScope */
	private $scope;
	private $acl_runtime_registered = false;

	public function __construct($plugin_path) {
		$this->description = _t('Maps users to collection roots, with optional native ACL segmentation for collections and objects.');
		$this->scope = new CollectionAccessScope($plugin_path);
		parent::__construct();
		$this->registerAclRuntime();
	}

	private function registerAclRuntime() : void {
		if (!$this->acl_runtime_registered && $this->scope->usesNativeACL() && class_exists('AppController', false) && AppController::instanceExists()) {
			require_once __DIR__.'/lib/CollectionAclRuntime.php';
			AppController::getInstance()->registerPlugin(new CollectionAclRuntime($this->scope));
			$this->acl_runtime_registered = true;
		}
	}

	// index.php invokes this hook after creating AppController, even when models
	// initialized the application plugins earlier during bootstrap.
	public function hookAddDomainSecurityPolicy(&$params) {
		$this->registerAclRuntime();
		return is_array($params) ? $params : true;
	}

	public function checkStatus() {
		return [
			'description' => $this->getDescription(),
			'errors' => [],
			'warnings' => [],
			'available' => $this->scope->isEnabled()
		];
	}

	public function hookBeforeBundleInsert(&$params) {
		$this->prepareNativeAclRecord($params);
		$this->validateCollectionParent($params, true);
		return $params;
	}

	public function hookBeforeBundleUpdate(&$params) {
		$this->prepareNativeAclRecord($params);
		$this->validateCollectionParent($params, false);
		return $params;
	}

	public function hookEditItem(&$params) {
		$this->enforceEditorScope($params);
		$this->seedDefaultParentForNewCollection($params);
		return $params;
	}

	public function hookBeforeSaveItem(&$params) {
		$this->enforceSaveScope($params);
		return $params;
	}

	public function hookAfterBundleInsert(&$params) {
		$this->initializeNativeAclRecord($params);
		return $params;
	}

	public function hookAfterBundleUpdate(&$params) {
		$this->markNativeAclDirty($params);
		return $params;
	}

	public function hookDeleteItem(&$params) {
		$this->markNativeAclDirty($params);
		return $params;
	}

	public function hookCLICaUtilsGetCommands(&$commands) {
		$commands['groupCollectionRootMap']['sync_collection_scope_acl'] = [
			'Command' => 'sync-collection-scope-acl',
			'Options' => ['dry-run' => _t('Preview changes without writing (the default).'),
				'apply' => _t('Apply the reviewed native ACL projection.'),
				'backup-dir|b=s' => _t('Private existing directory outside the webroot for the mandatory ACL backup.'),
				'config' => _t('Print required Providence settings without modifying configuration.')],
			'Help' => _t('Synchronize collection/object ACLs from assigned roots. Shared authorities remain global. Defaults to dry run.'),
			'ShortHelp' => _t('Preview or synchronize native collection scope ACLs.')
		];
		return $commands;
	}

	public function hookCLICaUtilsGetToolWithSettings(&$params) {
		if (($params[0] ?? null) !== 'groupCollectionRootMap') { return $params; }
		require_once __DIR__.'/lib/CollectionAclTool.php';
		$params['tool'] = new CollectionAclTool($this->scope);
		return $params;
	}

	private function prepareNativeAclRecord(array $params) : void {
		if (!$this->scope->usesNativeACL()) { return; }
		$table = $params['table_name'] ?? '';
		if (!in_array($table, ['ca_objects', 'ca_collections'], true)) { return; }
		$subject = $params['instance'];
		// The projection follows native hierarchy membership itself. Native ACL
		// inheritance must not introduce additional, competing permission rows.
		$subject->set('acl_inherit_from_parent', 0);
		if ($table === 'ca_objects') { $subject->set('acl_inherit_from_ca_collections', 0); }
	}

	private function markNativeAclDirty(array $params) : void {
		if (!$this->scope->usesNativeACL()) { return; }
		if (in_array($params['table_name'] ?? '', ['ca_objects', 'ca_collections', 'ca_objects_x_collections'], true)) {
			require_once __DIR__.'/lib/CollectionAclSynchronizer.php';
			CollectionAclSynchronizer::$dirty = true;
		}
	}

	private function initializeNativeAclRecord(array $params) : void {
		$this->markNativeAclDirty($params);
		if (!$this->scope->usesNativeACL() || !in_array($table = $params['table_name'] ?? '', ['ca_objects', 'ca_collections'], true)) { return; }
		$subject = $params['instance'];
		$request = (class_exists('AppController', false) && AppController::instanceExists()) ? $this->getRequest() : null;
		$user = $request->user ?? null;
		$creator_id = $user ? (int)$user->getPrimaryKey() : (int)($GLOBALS['AUTH_CURRENT_USER_ID'] ?? 0);
		// The standard object editor attaches its collection in postSave(), after
		// the insert hook. Seed only validated pending membership for that form;
		// postDispatch replaces this with the actual persisted membership.
		$pending = ($table === 'ca_objects' && $request) ? (int)$request->getParameter('collection_id', pInteger) : 0;
		if ($pending && !$this->scope->canAccessCollection($user, $pending)) { $pending = 0; }
		$transaction = $subject->getTransaction();
		$db = $transaction ? $transaction->getDb() : $subject->getDb();
		$sync = new CollectionAclSynchronizer($this->scope, $db);
		$sync->initializeRecord($table, (int)$subject->getPrimaryKey(), $creator_id, $pending);
	}

	public function hookBundleFormHTML(&$params) {
		if (!$this->scope->isEnabled()) { return $params; }
		$subject = caGetOption('subject', $params, null);
		if (!($subject instanceof ca_collections) && !($subject instanceof ca_objects)) { return $params; }

		$request = $this->getRequest();
		if (!$request) { return $params; }
		$user = ($request && isset($request->user) && ($request->user instanceof ca_users)) ? $request->user : null;
		$scope = $this->scope->getScopeForUser($user);
		if (!($scope['restricted'] ?? false)) { return $params; }

		$core_urls = [
			'get' => caNavUrl($request, 'lookup', 'Collection', 'Get'),
			'level' => caNavUrl($request, 'lookup', 'Collection', 'GetHierarchyLevel'),
			'ancestors' => caNavUrl($request, 'lookup', 'Collection', 'GetHierarchyAncestorList'),
			'sort' => caNavUrl($request, 'lookup', 'Collection', 'SetSortOrder')
		];
		$core_mixed_urls = [
			'get' => caNavUrl($request, 'lookup', 'ObjectCollectionHierarchy', 'Get'),
			'level' => caNavUrl($request, 'lookup', 'ObjectCollectionHierarchy', 'GetHierarchyLevel'),
			'ancestors' => caNavUrl($request, 'lookup', 'ObjectCollectionHierarchy', 'GetHierarchyAncestorList'),
			'sort' => caNavUrl($request, 'lookup', 'ObjectCollectionHierarchy', 'SetSortOrder')
		];
		$restricted_urls = [
			'get' => caNavUrl($request, 'groupCollectionRootMap', 'RestrictedCollectionLookup', 'Get'),
			'level' => caNavUrl($request, 'groupCollectionRootMap', 'RestrictedCollectionLookup', 'GetHierarchyLevel'),
			'ancestors' => caNavUrl($request, 'groupCollectionRootMap', 'RestrictedCollectionLookup', 'GetHierarchyAncestorList'),
			'sort' => caNavUrl($request, 'groupCollectionRootMap', 'RestrictedCollectionLookup', 'SetSortOrder')
		];
		$restricted_mixed_urls = [
			'get' => caNavUrl($request, 'groupCollectionRootMap', 'RestrictedObjectCollectionHierarchy', 'Get'),
			'level' => caNavUrl($request, 'groupCollectionRootMap', 'RestrictedObjectCollectionHierarchy', 'GetHierarchyLevel'),
			'ancestors' => caNavUrl($request, 'groupCollectionRootMap', 'RestrictedObjectCollectionHierarchy', 'GetHierarchyAncestorList'),
			'sort' => caNavUrl($request, 'groupCollectionRootMap', 'RestrictedObjectCollectionHierarchy', 'SetSortOrder')
		];

		$bundles = caGetOption('bundles', $params, []);
		if (!is_array($bundles)) { return $params; }
		foreach($bundles as $bundle_code => $html) {
			if (!is_string($html) || !strlen($html)) { continue; }
			if (stripos($html, '/lookup/') !== false) {
				$html = str_replace($core_urls['get'], $restricted_urls['get'], $html);
				$html = str_replace($core_urls['level'], $restricted_urls['level'], $html);
				$html = str_replace($core_urls['ancestors'], $restricted_urls['ancestors'], $html);
				$html = str_replace($core_urls['sort'], $restricted_urls['sort'], $html);
				$html = str_replace($core_mixed_urls['get'], $restricted_mixed_urls['get'], $html);
				$html = str_replace($core_mixed_urls['level'], $restricted_mixed_urls['level'], $html);
				$html = str_replace($core_mixed_urls['ancestors'], $restricted_mixed_urls['ancestors'], $html);
				$html = str_replace($core_mixed_urls['sort'], $restricted_mixed_urls['sort'], $html);
				// Fallback rewrite for route variants (case/path-encoded params).
				// Only rewrite actions implemented by this plugin to avoid touching unrelated lookup endpoints.
				$html = preg_replace(
					'!(/lookup/)Collection/(Get|GetHierarchyLevel|GetHierarchyAncestorList|SetSortOrder)!i',
					'$1groupCollectionRootMap/RestrictedCollectionLookup/$2',
					$html
				);
				$html = preg_replace(
					'!(/lookup/)ObjectCollectionHierarchy/(Get|GetHierarchyLevel|GetHierarchyAncestorList|SetSortOrder)!i',
					'$1groupCollectionRootMap/RestrictedObjectCollectionHierarchy/$2',
					$html
				);
			}
			// Some hierarchy_location JS assumes id is a string and calls id.substr().
			// In collection-only hierarchy responses id may be numeric, which throws and leaves spinners.
			$html = preg_replace(
				"!if\\s*\\(\\s*id\\.substr\\(\\s*0\\s*,\\s*10\\s*\\)\\s*==\\s*'ca_objects'\\s*\\)!",
				"if ((typeof id === 'string') && (id.substr(0, 10) == 'ca_objects'))",
				$html
			);
			if (!$subject->getPrimaryKey()) {
				$html = $this->forceOpenHierarchyBrowserOnNewRecord($html);
			}
			$bundles[$bundle_code] = $html;
		}
		$params['bundles'] = $bundles;
		return $params;
	}

	private function validateCollectionParent(array &$params, bool $is_insert) : void {
		if (!$this->scope->isEnabled()) { return; }
		if ((caGetOption('table_name', $params, null) !== 'ca_collections')) { return; }

		/** @var ca_collections $t_collection */
		$t_collection = caGetOption('instance', $params, null);
		if (!($t_collection instanceof ca_collections)) { return; }

		$request = $this->getRequest();
		$user = ($request && isset($request->user) && ($request->user instanceof ca_users)) ? $request->user : null;
		$scope = $this->scope->getScopeForUser($user);
		if (!($scope['restricted'] ?? false)) { return; }

		$parent_field = $t_collection->getProperty('HIERARCHY_PARENT_ID_FLD') ?: 'parent_id';
		$parent_id = (int)$t_collection->get($parent_field);
		$allowed_root_ids = $scope['allowed_root_ids'] ?? [];
		$allow_root_parent = $this->scope->allowRootParentAssignment();

		$has_error = false;
		$message = null;

		if ($parent_id <= 0) {
			if (!$allow_root_parent) {
				$has_error = true;
				$message = _t('This collection cannot be saved at the top level. Please choose a valid parent collection.');
			}
		} else {
			$t_parent = new ca_collections();
			if (!$t_parent->load($parent_id)) {
				$has_error = true;
				$message = _t('The selected parent collection does not exist. Please choose another parent.');
			} elseif (sizeof($allowed_root_ids)) {
				$ancestor_ids = ca_collections::getHierarchyAncestorsForIDs([$parent_id], ['returnAs' => 'ids', 'includeSelf' => true]);
				if (!is_array($ancestor_ids)) { $ancestor_ids = []; }
				if (!sizeof(array_intersect($allowed_root_ids, $ancestor_ids))) {
					$has_error = true;
					$message = _t('You cannot assign this collection to the selected parent. Allowed root collections for your account: %1', $this->formatRootLabelList($allowed_root_ids));
				}
			}
		}

		if (!$has_error) { return; }

		if ($is_insert) {
			// Force insert() to fail deterministically on invalid parent assignment.
			$t_collection->set($parent_field, -1);
		} else {
			// Keep update safe by restoring the original parent when assignment is invalid.
			$t_collection->set($parent_field, (int)$t_collection->getOriginalValue($parent_field));
		}

		if ($request) {
			$request->setParameter($parent_field, $t_collection->get($parent_field), 'REQUEST');
			$request->setParameter($parent_field, $t_collection->get($parent_field), 'POST');
			$request->addActionErrors(
				[new ApplicationError(1100, $message, 'groupCollectionRootMapPlugin->validateCollectionParent()', 'ca_collections.'.$parent_field, false, false)],
				'hierarchy_location',
				'general'
			);
		}
	}

	private function enforceEditorScope(array &$params) : void {
		if (!$this->scope->isEnabled()) { return; }

		$instance = caGetOption('instance', $params, null);
		if (!($instance instanceof ca_collections) && !($instance instanceof ca_objects)) { return; }

		$request = caGetOption('request', $params, $this->getRequest());
		if (!($request instanceof RequestHTTP)) { return; }
		$user = (isset($request->user) && ($request->user instanceof ca_users)) ? $request->user : null;
		$scope = $this->scope->getScopeForUser($user);
		if (!($scope['restricted'] ?? false)) { return; }

		if (($instance instanceof ca_collections) && $instance->getPrimaryKey() && !$this->scope->canAccessCollection($user, $instance)) {
			$this->denyEditorAccess($request, _t('You do not have access to this collection or its hierarchy branch.'));
			return;
		}

		if ($instance instanceof ca_objects) {
			if ($instance->getPrimaryKey() && !$this->scope->canAccessObject($user, $instance)) {
				$this->denyEditorAccess($request, _t('You do not have access to this object because it is outside your allowed collection hierarchy.'));
				return;
			}

			$collection_id = (int)$request->getParameter('collection_id', pInteger);
			if (($collection_id > 0) && !$this->scope->isCollectionIDAllowed($collection_id, $scope)) {
				$this->denyEditorAccess($request, _t('You cannot create or edit an object under the selected collection.'));
				return;
			}
		}
	}

	private function enforceSaveScope(array &$params) : void {
		if (!$this->scope->isEnabled()) { return; }

		$instance = caGetOption('instance', $params, null);
		if (!($instance instanceof ca_collections) && !($instance instanceof ca_objects)) { return; }

		$request = caGetOption('request', $params, $this->getRequest());
		if (!($request instanceof RequestHTTP)) { return; }
		$user = (isset($request->user) && ($request->user instanceof ca_users)) ? $request->user : null;
		$scope = $this->scope->getScopeForUser($user);
		if (!($scope['restricted'] ?? false)) { return; }

		if (($instance instanceof ca_collections) && $instance->getPrimaryKey() && !$this->scope->canAccessCollection($user, $instance)) {
			$this->postScopeSaveError($instance, $request, _t('You cannot save this collection because it is outside your allowed collection hierarchy.'), 'ca_collections.collection_id');
			return;
		}

		if ($instance instanceof ca_objects) {
			if ($instance->getPrimaryKey() && !$this->scope->canAccessObject($user, $instance)) {
				$this->postScopeSaveError($instance, $request, _t('You cannot save this object because it is outside your allowed collection hierarchy.'), 'ca_objects.object_id');
				return;
			}

			$collection_id = (int)$request->getParameter('collection_id', pInteger);
			if (($collection_id > 0) && !$this->scope->isCollectionIDAllowed($collection_id, $scope)) {
				$this->postScopeSaveError($instance, $request, _t('You cannot assign this object to the selected collection.'), 'ca_objects.collection_id');
				return;
			}
		}
	}

	private function seedDefaultParentForNewCollection(array &$params) : void {
		if (!$this->scope->isEnabled()) { return; }
		if ((caGetOption('table_name', $params, null) !== 'ca_collections')) { return; }

		/** @var ca_collections|null $t_collection */
		$t_collection = caGetOption('instance', $params, null);
		if (!($t_collection instanceof ca_collections)) { return; }
		if ($t_collection->getPrimaryKey()) { return; } // existing record, nothing to seed

		$request = caGetOption('request', $params, $this->getRequest());
		if (!($request instanceof RequestHTTP)) { return; }
		$user = (isset($request->user) && ($request->user instanceof ca_users)) ? $request->user : null;
		$scope = $this->scope->getScopeForUser($user);
		if (!($scope['restricted'] ?? false)) { return; }

		$allowed_root_ids = array_values(array_map('intval', $scope['allowed_root_ids'] ?? []));
		if (!sizeof($allowed_root_ids)) { return; }

		$parent_field = $t_collection->getProperty('HIERARCHY_PARENT_ID_FLD') ?: 'parent_id';
		$current_parent = (int)$request->getParameter($parent_field, pInteger);
		if ($current_parent > 0) { return; }

		$default_parent = (int)array_shift($allowed_root_ids);
		if ($default_parent <= 0) { return; }

		$t_collection->set($parent_field, $default_parent);
		$request->setParameter($parent_field, $default_parent, 'REQUEST');
		$request->setParameter($parent_field, $default_parent, 'GET');
		$request->setParameter($parent_field, $default_parent, 'POST');
		$params['forced_values'][$parent_field] = $default_parent;
	}

	private function denyEditorAccess(RequestHTTP $request, string $message) : void {
		$notification = new NotificationManager($request);
		$notification->addNotification($message, __NOTIFICATION_TYPE_ERROR__);

		$response = null;
		if (($app = AppController::getInstance()) && method_exists($app, 'getResponse')) {
			$response = $app->getResponse();
		}
		if ($response) {
			$response->setRedirect($request->config->get('error_display_url').'/n/2580?r='.urlencode($request->getFullUrlPath()));
		}
	}

	private function postScopeSaveError($instance, RequestHTTP $request, string $message, string $source) : void {
		if (method_exists($instance, 'postError')) {
			$instance->postError(2580, $message, 'groupCollectionRootMapPlugin->enforceSaveScope()', $source);
		}
		$request->addActionErrors(
			[new ApplicationError(2580, $message, 'groupCollectionRootMapPlugin->enforceSaveScope()', $source, false, false)],
			'general',
			'general'
		);
	}

	private function forceOpenHierarchyBrowserOnNewRecord(string $html) : string {
		// On new records Providence may hide the hierarchy browser when no enclosure is inferred.
		// If this markup has no show/hide toggle, force-open the browser so scoped users can choose a parent.
		if (strpos($html, 'HierarchyBrowserContainer') === false) { return $html; }
		if (strpos($html, 'browseToggle') !== false) { return $html; }

		if (!preg_match('!id="([^"]*HierarchyBrowserContainer)"!', $html, $m_container)) { return $html; }
		$container_id = $m_container[1];
		$id_prefix = preg_replace('!HierarchyBrowserContainer$!', '', $container_id);
		$id_prefix_json = json_encode((string)$id_prefix);
		$script = "<script type=\"text/javascript\">jQuery(function(){var c=jQuery(\"#{$container_id}\");if(c.length){c.show();}var p={$id_prefix_json};['Explore','Move','Add','AddObject'].forEach(function(m){var fn='_init'+p+m+'HierarchyBrowser';if(typeof window[fn]==='function'){window[fn]();}});});</script>";
		return $html.$script;
	}

	private function formatRootLabelList(array $root_ids) : string {
		if (!sizeof($root_ids)) {
			return _t('none configured');
		}

		$labels = [];
		foreach($root_ids as $root_id) {
			$t_root = new ca_collections();
			if ($t_root->load((int)$root_id)) {
				$labels[] = sprintf('%s (#%d)', strip_tags((string)$t_root->getLabelForDisplay(true)), (int)$root_id);
			} else {
				$labels[] = sprintf('#%d', (int)$root_id);
			}
		}

		return join(', ', $labels);
	}
}
