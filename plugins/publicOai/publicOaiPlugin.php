<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/lib/Config.php';
require_once __DIR__.'/lib/Catalogue.php';
require_once __DIR__.'/lib/Metadata.php';

/** Supply public descriptive metadata to the native OAI provider and XML exporter. */
class publicOaiPlugin extends BaseApplicationPlugin {
	private $settings;
	private $configurationError;

	public function __construct($plugin_path) {
		$this->description = _t('Exports public objects and public representations as MODS and Dublin Core.');
		try { $this->settings = \TADL\PublicOai\Config::load($plugin_path); }
		catch (\InvalidArgumentException $error) { $this->configurationError = $error->getMessage(); }
		parent::__construct();
	}

	public function checkStatus() {
		return ['description' => $this->getDescription(), 'errors' => $this->configurationError ? [$this->configurationError] : [],
			'warnings' => [], 'available' => !$this->configurationError && !empty($this->settings['enable'])];
	}

	public function hookOAIRecordInfo(&$params) {
		if (empty($this->settings['enable']) || ($params['provider'] ?? null) !== $this->settings['provider_key']
			|| ($params['table'] ?? null) !== 'ca_objects'
			|| ($params['item_acl_policy'] ?? 'native') !== $this->settings['item_acl_policy']
			|| !\TADL\PublicOai\Config::mappingFormat((string)($params['mapping'] ?? ''))) { return $params; }
		$id = (int)($params['record_id'] ?? 0);
		$record = $id > 0 ? $this->catalogue()->record($id) : null;
		$params['info']['handled'] = true;
		$params['info']['eligible'] = $record !== null;
		$params['info']['datestamp'] = $record ? (int)($record['modified'] ?? 0) : 0;
		return $params;
	}

	public function hookExportRecord(&$params) {
		$exporter = $params['exporter_instance'] ?? null;
		$format = $exporter ? \TADL\PublicOai\Config::mappingFormat((string)$exporter->get('exporter_code')) : null;
		if (!$format) { return $params; }
		if (empty($this->settings['enable'])) { throw new \RuntimeException('Public OAI export is unavailable.'); }
		$record = $this->catalogue()->record((int)($params['record_id'] ?? 0));
		if (!$record) { throw new \RuntimeException('Record is not eligible for public OAI publication.'); }
		$params['export'] = \TADL\PublicOai\Metadata::render($record, $format);
		return $params;
	}

	protected function catalogue() {
		return new \TADL\PublicOai\Catalogue($this->settings, $this->getRequest());
	}
}
