<?php
require_once __CA_LIB_DIR__.'/Controller/AppController/AppControllerPlugin.php';
require_once __DIR__.'/CollectionAclSynchronizer.php';

/** Request boundary checks; stale permissions block access rather than leak. */
class CollectionAclRuntime extends AppControllerPlugin {
	private $scope;

	public function __construct(CollectionAccessScope $scope) { $this->scope = $scope; parent::__construct(); }

	public function routeStartup() {
		$request = $this->getRequest();
		$user = $request->user ?? null;
		if (!$user || !$user->getPrimaryKey() || $user->canDoAction('is_administrator')) { return; }
		$sync = new CollectionAclSynchronizer($this->scope);
		if ($sync->configurationErrors()) { throw new ApplicationException(_t('Collection segmentation configuration is incomplete. Contact an administrator.')); }
		$plan = $sync->inspect();
		if ($plan['collisions'] || CollectionAclSynchronizer::needsChanges($plan)) {
			throw new ApplicationException(_t('Collection permissions need synchronization. Contact an administrator.'));
		}
		self::refreshResultContexts($request, hash('sha256', serialize($plan['projection'])));
	}

	public function postDispatch() {
		if (!CollectionAclSynchronizer::$dirty && strtolower($this->getRequest()->getRequestMethod()) === 'get') { return; }
		$sync = new CollectionAclSynchronizer($this->scope);
		if ($sync->configurationErrors()) { return; }
		$plan = $sync->inspect();
		// Initial migration must be an explicit backed-up CLI apply.
		if (!isset($plan['groups'][CollectionAclPolicy::ALL_GROUP])) {
			throw new ApplicationException(_t('Initialize native collection permissions using the ACL synchronization command.'));
		}
		$sync->apply($plan, null, true);
		CollectionAclSynchronizer::$dirty = false;
	}

	public static function refreshResultContexts($request, string $fingerprint) : void {
		require_once __CA_LIB_DIR__.'/ResultContext.php';
		ResultContextStorage::init($request);
		if (Session::getVar('groupCollectionRootMap_acl_revision') === $fingerprint) { return; }
		$types = array_keys(ResultContextStorage::getVar('used_find_types') ?: []);
		foreach (CollectionAclPolicy::TABLES as $table) {
			$keys = ['result_context_'.$table];
			foreach ($types as $type) { $keys[] = 'result_context_'.$table.'_'.str_replace('/', '_', $type); }
			foreach ($keys as $key) {
				$context = ResultContextStorage::getVar($key);
				if (!is_array($context)) { continue; }
				$context['result_list'] = [];
				$context['result_desc'] = [];
				$context['param_invalid_cache'] = 1;
				ResultContextStorage::setVar($key, $context);
			}
		}
		if (ResultContextStorage::save() === false) {
			throw new ApplicationException(_t('Could not refresh collection permissions for saved results.'));
		}
		Session::setVar('groupCollectionRootMap_acl_revision', $fingerprint);
	}
}
