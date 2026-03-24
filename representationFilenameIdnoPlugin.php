<?php
/* ----------------------------------------------------------------------
 * representationFilenameIdnoPlugin.php
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__ . '/ca_object_representations.php');

class representationFilenameIdnoPlugin extends BaseApplicationPlugin {
	/** @var Configuration */
	private $base_config;
	/** @var Configuration|null */
	private $local_config = null;

	/** @var array<int, bool> */
	private static $update_guard = [];

	public function __construct($plugin_path) {
		$this->description = _t('Sets object representation identifiers and blank preferred labels from uploaded media filenames.');
		$this->base_config = Configuration::load($plugin_path . '/conf/plugin.conf');

		$local_path = $plugin_path . '/conf/plugin.local.conf';
		if (file_exists($local_path)) {
			$this->local_config = Configuration::load($local_path);
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

	public function hookBeforeInsertItem(&$params) {
		$this->applyFilenameIdnoBeforeInsert($params);
		return $params;
	}

	public function hookBeforeSaveItem(&$params) {
		$this->applyFilenameIdnoBeforeSave($params);
		return $params;
	}

	public function hookInsertItem(&$params) {
		$this->applyFilenameLabelAfterInsert($params);
		return $params;
	}

	public function hookUpdateItem(&$params) {
		$this->applyFilenameIdnoAfterUpdate($params);
		return $params;
	}

	private function applyFilenameIdnoBeforeInsert(array &$params) : void {
		if (!$this->isEnabled()) { return; }
		$t_rep = $this->getRepresentationInstance($params);
		if (!$t_rep) { return; }
		if ($this->isGuarded($t_rep)) { return; }

		$current_idno = trim((string)$t_rep->get('idno'));
		if ($this->preserveExistingIdnoOnInsert() && strlen($current_idno)) { return; }

		$filename = trim((string)$t_rep->get('original_filename'));
		if (!strlen($filename)) { return; }

		$t_rep->set('idno', $this->buildUniqueIdnoFromFilename($filename, null));
	}

	private function applyFilenameIdnoBeforeSave(array &$params) : void {
		if (!$this->isEnabled()) { return; }
		if (!(bool)caGetOption('is_insert', $params, false)) { return; }

		$t_rep = $this->getRepresentationInstance($params);
		if (!$t_rep) { return; }
		if ($this->isGuarded($t_rep)) { return; }

		$current_idno = trim((string)$t_rep->get('idno'));
		if ($this->preserveExistingIdnoOnInsert() && strlen($current_idno)) { return; }

		$filename = trim((string)$t_rep->get('original_filename'));
		if (!strlen($filename)) { return; }

		$t_rep->set('idno', $this->buildUniqueIdnoFromFilename($filename, $t_rep->getPrimaryKey() ? (int)$t_rep->getPrimaryKey() : null));
	}

	private function applyFilenameIdnoAfterUpdate(array &$params) : void {
		if (!$this->isEnabled()) { return; }
		if (!$this->syncOnMediaReplace() && !$this->updateBlankPreferredLabelOnMediaReplace()) { return; }

		$t_rep = $this->getRepresentationInstance($params);
		if (!$t_rep) { return; }
		if ($this->isGuarded($t_rep)) { return; }

		$representation_id = (int)$t_rep->getPrimaryKey();
		if ($representation_id <= 0) { return; }

		$filename = trim((string)$t_rep->get('original_filename'));
		if (!strlen($filename)) { return; }

		$original_filename = trim((string)$t_rep->getOriginalValue('original_filename'));
		if ($original_filename === $filename) { return; }

		$should_update_idno = $this->syncOnMediaReplace();
		if ($should_update_idno && $this->preserveManualIdnoOnUpdate()) {
			$original_idno = trim((string)$t_rep->getOriginalValue('idno'));
			$current_idno = trim((string)$t_rep->get('idno'));
			if ($original_idno !== $current_idno) {
				$should_update_idno = false;
			}
		}

		$needs_update = false;
		$new_idno = trim((string)$t_rep->get('idno'));
		if ($should_update_idno) {
			$new_idno = $this->buildUniqueIdnoFromFilename($filename, $representation_id);
			if (strlen($new_idno) && ($new_idno !== trim((string)$t_rep->get('idno')))) {
				$needs_update = true;
			}
		}

		$should_update_label = $this->updateBlankPreferredLabelOnMediaReplace() && $this->labelNeedsFilenameFallback($t_rep);
		if (!$needs_update && !$should_update_label) { return; }

		$label = $this->filenameToLabel($filename);
		self::$update_guard[$representation_id] = true;
		try {
			if ($needs_update) {
				$t_rep->set('idno', $new_idno);
				$t_rep->update();
			}
			if ($should_update_label && strlen($label)) {
				$this->applyPreferredLabel($t_rep, $label);
			}
		} finally {
			unset(self::$update_guard[$representation_id]);
		}
	}

	private function applyFilenameLabelAfterInsert(array &$params) : void {
		if (!$this->isEnabled()) { return; }
		if (!$this->setPreferredLabelFromFilenameWhenBlank()) { return; }

		$t_rep = $this->getRepresentationInstance($params);
		if (!$t_rep) { return; }
		if ($this->isGuarded($t_rep)) { return; }
		if (!$this->labelNeedsFilenameFallback($t_rep)) { return; }

		$filename = trim((string)$t_rep->get('original_filename'));
		$label = $this->filenameToLabel($filename);
		if (!strlen($label)) { return; }

		$this->applyPreferredLabel($t_rep, $label);
	}

	private function getRepresentationInstance(array $params) : ?ca_object_representations {
		if (caGetOption('table_name', $params, null) !== 'ca_object_representations') { return null; }
		$t_rep = caGetOption('instance', $params, null);
		return ($t_rep instanceof ca_object_representations) ? $t_rep : null;
	}

	private function buildUniqueIdnoFromFilename(string $filename, ?int $representation_id) : string {
		$base = $this->filenameToBaseIdno($filename);
		if (!strlen($base)) { $base = 'representation'; }
		if (!$this->ensureUnique()) { return $base; }

		$candidate = $base;
		$suffix_separator = (string)$this->getConfig('suffix_separator');
		if (!strlen($suffix_separator)) { $suffix_separator = '-'; }

		$i = 2;
		while ($this->idnoExistsOnOtherRepresentation($candidate, $representation_id)) {
			$candidate = $base . $suffix_separator . $i;
			$i++;
		}
		return $candidate;
	}

	private function filenameToBaseIdno(string $filename) : string {
		$filename = pathinfo($filename, PATHINFO_BASENAME);
		if ($this->stripExtension()) {
			$filename = pathinfo($filename, PATHINFO_FILENAME);
		}

		$filename = trim($filename);
		$filename = str_replace(["\\", "/"], '_', $filename);

		$whitespace_replacement = (string)$this->getConfig('replace_whitespace_with');
		$filename = preg_replace('!\s+!u', strlen($whitespace_replacement) ? $whitespace_replacement : ' ', $filename);
		$filename = preg_replace('![[:cntrl:]]+!u', '', $filename);
		$filename = trim($filename, " \t\n\r\0\x0B-_.");

		if ((bool)$this->getConfig('lowercase')) {
			$filename = mb_strtolower($filename);
		}
		return $filename;
	}

	private function filenameToLabel(string $filename) : string {
		return $this->filenameToBaseIdno($filename);
	}

	private function idnoExistsOnOtherRepresentation(string $idno, ?int $representation_id) : bool {
		$ids = ca_object_representations::find(
			['idno' => $idno],
			['returnAs' => 'ids', 'purifyWithFallback' => true]
		);
		if (!is_array($ids)) { return false; }

		foreach($ids as $id) {
			$id = (int)$id;
			if (($id > 0) && ($id !== (int)$representation_id)) {
				return true;
			}
		}
		return false;
	}

	private function isGuarded(ca_object_representations $t_rep) : bool {
		$representation_id = (int)$t_rep->getPrimaryKey();
		if ($representation_id <= 0) { return false; }
		return (bool)(self::$update_guard[$representation_id] ?? false);
	}

	private function labelNeedsFilenameFallback(ca_object_representations $t_rep) : bool {
		$label = trim(strip_tags((string)$t_rep->getLabelForDisplay(true)));
		if (!strlen($label)) { return true; }
		return ($label === '['.caGetBlankLabelText('ca_object_representations').']');
	}

	private function applyPreferredLabel(ca_object_representations $t_rep, string $label) : void {
		$label = trim($label);
		if (!strlen($label)) { return; }

		$locale_id = (int)$t_rep->get('locale_id');
		if ($locale_id <= 0) {
			$locale_id = (int)ca_locales::getDefaultCataloguingLocaleID();
		}

		if ($t_rep->getPreferredLabelCount() > 0) {
			$t_rep->replaceLabel(['name' => $label], $locale_id, null, true, ['queueIndexing' => true]);
		} else {
			$t_rep->addLabel(['name' => $label], $locale_id, null, true, ['queueIndexing' => true]);
		}
	}

	private function isEnabled() : bool {
		return (bool)$this->getConfig('enable');
	}

	private function stripExtension() : bool {
		return (bool)$this->getConfig('strip_extension');
	}

	private function preserveExistingIdnoOnInsert() : bool {
		return (bool)$this->getConfig('preserve_existing_idno_on_insert');
	}

	private function syncOnMediaReplace() : bool {
		return (bool)$this->getConfig('sync_on_media_replace');
	}

	private function preserveManualIdnoOnUpdate() : bool {
		return (bool)$this->getConfig('preserve_manual_idno_on_update');
	}

	private function setPreferredLabelFromFilenameWhenBlank() : bool {
		return (bool)$this->getConfig('set_preferred_label_from_filename_when_blank');
	}

	private function updateBlankPreferredLabelOnMediaReplace() : bool {
		return (bool)$this->getConfig('update_blank_preferred_label_on_media_replace');
	}

	private function ensureUnique() : bool {
		return (bool)$this->getConfig('ensure_unique');
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
}
