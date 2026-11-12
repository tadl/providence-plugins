<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace FileHealth;

/** Read-only native representation inventory. No application settings are loaded here. */
class Catalogue {
	private $db;
	public function __construct($db=null) { $this->db = $db ?: new \Db(); }
	private function query(string $sql, array $params=[]) {
		$result = $this->db->query($sql,$params);
		if (!$result || $this->db->numErrors()) { throw new \RuntimeException('The file inventory query failed.'); }
		return $result;
	}
	public function maxId() : int {
		$q = $this->query('SELECT MAX(representation_id) AS max_id FROM ca_object_representations WHERE deleted=0');
		return $q->nextRow() ? (int)$q->get('max_id') : 0;
	}
	public function batch(int $afterId, int $limit, ?int $maxId=null) : array {
		if ($afterId < 0 || $limit < 1 || $limit > 1000 || ($maxId !== null && $maxId < 0)) { throw new \InvalidArgumentException('Invalid file inventory batch.'); }
		$sql = 'SELECT representation_id FROM ca_object_representations WHERE deleted=0 AND representation_id>?';
		$params = [$afterId];
		if ($maxId !== null) { $sql .= ' AND representation_id<=?'; $params[] = $maxId; }
		// The validated integer is required because native LIMIT placeholders vary by adapter.
		$q = $this->query($sql.' ORDER BY representation_id LIMIT '.$limit,$params);
		$ids = [];
		while ($q->nextRow()) { $ids[] = (int)$q->get('representation_id'); }
		return $ids;
	}
	public function describe(int $id, string $version) : array {
		if ($id < 1 || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$version)) { throw new \InvalidArgumentException('Invalid representation or media version.'); }
		$base = ['representation_id' => $id,'version' => $version,'label' => '', 'idno' => '',
			'descriptor' => [],'expected_md5' => null,'path' => null,'supported' => false,
			'reason' => 'representation_unavailable','multifile_count' => 0];
		$q = $this->query('SELECT representation_id FROM ca_object_representations WHERE representation_id=? AND deleted=0',[$id]);
		if (!$q->nextRow()) { return $base; }
		if (!class_exists('ca_object_representations')) { require_once __CA_MODELS_DIR__.'/ca_object_representations.php'; }
		$model = new \ca_object_representations();
		if (!$model->load($id) || (int)$model->getPrimaryKey() !== $id) { return $base; }
		$base['label'] = self::plain((string)$model->getLabelForDisplay());
		$base['idno'] = self::plain((string)$model->get('idno'));
		$media = $model->getMediaInfo('media');
		$entry = is_array($media) && isset($media[$version]) && is_array($media[$version]) ? $media[$version] : [];
		$base['descriptor'] = ['entry' => $entry,'input' => is_array($media) ? ($media['INPUT'] ?? null) : null,
			'original_filename' => is_array($media) ? ($media['ORIGINAL_FILENAME'] ?? null) : null];
		$multifiles = $this->query('SELECT COUNT(*) AS file_count FROM ca_object_representation_multifiles WHERE representation_id=?',[$id]);
		if ($multifiles->nextRow()) { $base['multifile_count'] = (int)$multifiles->get('file_count'); }
		if (!$entry) { $base['reason'] = 'version_unavailable'; return $base; }
		foreach (['SKIP' => 'version_substituted','USE_ICON' => 'icon_only','EXTERNAL_URL' => 'externally_hosted','QUEUED' => 'processing_pending'] as $key => $reason) {
			if (!empty($entry[$key])) { $base['reason'] = $reason; return $base; }
		}
		if ((int)($entry['PAGES'] ?? 1) > 1) { $base['reason'] = 'multipage_version_unsupported'; return $base; }
		$base['expected_md5'] = $model->getMediaInfo('media',$version,'MD5');
		$base['path'] = $model->getMediaPath('media',$version);
		if (!is_string($base['expected_md5']) || !preg_match('/^[a-f0-9]{32}$/D',$base['expected_md5'])) { $base['reason'] = 'baseline_unavailable'; return $base; }
		if (!is_string($base['path']) || $base['path'] === '' || $base['path'][0] !== '/' || strpos($base['path'],"\0") !== false) { $base['reason'] = 'local_path_unavailable'; return $base; }
		$base['supported'] = true; $base['reason'] = '';
		return $base;
	}
	private static function plain(string $value) : string {
		return trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',html_entity_decode(strip_tags($value),ENT_QUOTES | ENT_HTML5,'UTF-8')) ?? '');
	}
}
