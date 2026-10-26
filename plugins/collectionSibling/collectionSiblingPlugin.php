<?php
/**
 * Add collection-based sibling creation through Providence's bundle HTML hook.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_collections.php');

class collectionSiblingPlugin extends BaseApplicationPlugin {
	private $config;
	private $local_config = null;

	public function __construct($plugin_path) {
		$this->description = _t('Adds sibling creation for objects directly in a collection.');
		$this->config = Configuration::load($plugin_path.'/conf/plugin.conf');
		if (file_exists($plugin_path.'/conf/plugin.local.conf')) {
			$this->local_config = Configuration::load($plugin_path.'/conf/plugin.local.conf');
		}
		parent::__construct();
	}

	public function checkStatus() {
		return [
			'description' => $this->getDescription(),
			'errors' => [],
			'warnings' => [],
			'available' => $this->isEnabled()
		];
	}

	public function hookBundleFormHTML(&$params) {
		if (!$this->isEnabled()) { return $params; }
		$subject = $params['subject'] ?? null;
		$options = $params['options'] ?? [];
		if (!($subject instanceof ca_objects) || !$subject->getPrimaryKey()
			|| $subject->get('parent_id') || !empty($options['batch'])) {
			return $params;
		}
		$bundles = $params['bundles'] ?? [];
		if (!is_array($bundles)) { return $params; }
		$prefixes = [];
		foreach ($bundles as $code => $html) {
			if (!is_string($html) || strpos($html, 'data-collection-sibling') !== false) { continue; }
			// Match only the native Add row. Do not reserialize or replace bundle HTML.
			if (preg_match('/\bid=([\'"])([A-Za-z0-9_]+)HierarchyBrowseAdd\1/', $html, $match)) {
				$prefixes[$code] = $match[2];
			}
		}
		// Other editor screens need no collection lookup or ACL query.
		if (!$prefixes) { return $params; }

		$request = $options['request'] ?? $this->getRequest();
		$user = $request ? $request->user : null;
		if (!$user || !$user->canDoAction('can_create_ca_objects')
			|| $user->getBundleAccessLevel('ca_objects', 'hierarchy_location') < __CA_BUNDLE_ACCESS_EDIT__) {
			return $params;
		}
		$config = $request->getAppConfig();
		if (!$config->get('ca_objects_x_collections_hierarchy_enabled')
			|| $config->get('ca_objects_enforce_strict_type_hierarchy')) {
			return $params;
		}

		// Use the same relationship type as ObjectEditorController::postSave().
		// An unconfigured type would open a form that cannot attach the collection.
		$relationship_type = $config->get('ca_objects_x_collections_hierarchy_relationship_type');
		if (!is_scalar($relationship_type) || !strlen(trim((string)$relationship_type))) {
			return $params;
		}
		$collection_ids = $subject->getRelatedItems('ca_collections', [
			'idsOnly' => true,
			'restrictToRelationshipTypes' => [$relationship_type]
		]);
		if (!is_array($collection_ids)) { return $params; }
		$collection_ids = array_values(array_unique(array_filter(array_map('intval', $collection_ids), function($id) {
			return $id > 0;
		})));
		// Never guess which collection should receive a new record.
		if (count($collection_ids) !== 1) { return $params; }
		$collection = new ca_collections($collection_ids[0]);
		if (!$collection->getPrimaryKey() || $collection->get('deleted')
			|| $collection->checkACLAccessForUser($user) < __CA_ACL_READONLY_ACCESS__) {
			return $params;
		}

		$script = file_get_contents(__DIR__.'/assets/collection-sibling.js');
		foreach ($prefixes as $code => $prefix) {
			$data = [
				'prefix' => $prefix,
				'editorUrl' => caEditorUrl($request, 'ca_objects', 0),
				'collectionId' => $collection_ids[0],
				'label' => _t('next to (sibling)'),
				'createLabel' => _t('Create a new object')
			];
			$json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
			$bundles[$code] .= "\n<script data-collection-sibling=\"1\">\n({$script})({$json});\n</script>";
		}
		$params['bundles'] = $bundles;
		return $params;
	}

	private function isEnabled() : bool {
		$local = $this->local_config ? $this->local_config->getScalar('enable') : false;
		return (bool)(($local !== false) ? $local : $this->config->get('enable'));
	}
}
