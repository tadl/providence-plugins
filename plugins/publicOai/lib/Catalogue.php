<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace TADL\PublicOai;
require_once __DIR__.'/Config.php';

/** Read public descriptive metadata without borrowing the request user's rights. */
final class Catalogue {
	private $config;
	private $anonymous;
	private $cache = [];
	private const RELATIONS = [
		'ca_object_representations' => ['ca_objects_x_object_representations', 'representation_id'],
		'ca_entities' => ['ca_objects_x_entities', 'entity_id'],
		'ca_places' => ['ca_objects_x_places', 'place_id'],
		'ca_collections' => ['ca_objects_x_collections', 'collection_id'],
		'ca_list_items' => ['ca_objects_x_vocabulary_terms', 'item_id']
	];
	private const NAME_ROLES = ['creator', 'publisher', 'contributor', 'interviewer', 'interviewee',
		'producer', 'director', 'photographer', 'author', 'artist', 'illustrator', 'editor'];

	/** $request is deliberately not used as the publication audience. */
	public function __construct(array $config, $request=null) {
		$this->config = Config::normalize($config);
		$this->anonymous = new \ca_users();
	}

	/** Null means this object must not be disseminated in either metadata format. */
	public function record(int $id): ?array {
		if ($id < 1 || !$this->config['enable'] || (defined('__CA_DISABLE_ACL__') && __CA_DISABLE_ACL__)) { return null; }
		if (array_key_exists($id, $this->cache)) { return $this->cache[$id]; }
		$object = $this->model('ca_objects', $id);
		if (!$object || !$this->readable($object) || !$this->bundle('ca_objects', 'ca_object_representations')
			|| !$this->bundle('ca_object_representations', 'media')) { return $this->cache[$id] = null; }
		$modified = $this->modified($object);
		$media = $previews = [];
		foreach ($this->related($object, 'ca_object_representations') as $link) {
			$representation = $this->model('ca_object_representations', (int)$link['related_id']);
			if (!$representation) { continue; }
			// A newly hidden representation still changes the public object's export.
			$modified = max($modified, $this->modified($representation));
			if (!$this->readable($representation) || !($entry = $this->media($representation))) { continue; }
			$entry['context_url'] = $this->landing('objects', $id).'?representation_id='.(int)$representation->getPrimaryKey();
			$media[$entry['url']] = $entry;
			foreach (['thumbnail', 'small'] as $version) {
				$info = $representation->getMediaInfo('media', $version);
				if (!is_array($info) || !empty($info['USE_ICON']) || !empty($info['SKIP']) || !empty($info['QUEUED'])) { continue; }
				$url = $this->mediaUrl($representation->getMediaUrl('media', $version));
				if ($url !== '') { $previews[$url] = $url; break; }
			}
		}
		if (!$media) { return $this->cache[$id] = null; }
		$record = [
			'id' => $id, 'modified' => $modified,
			'titles' => $this->values($object, 'preferred_labels.name'),
			'alternate_titles' => $this->values($object, 'nonpreferred_labels.name'),
			'identifiers' => [], 'names' => [], 'dates' => $this->dates($object),
			'language' => $this->values($object, 'language'),
			'description' => $this->values($object, 'description'),
			'notes' => [], 'type' => '', 'genre' => [], 'subjects' => [],
			'physical' => ['form' => [], 'extent' => [], 'internet_media_type' => array_values(array_unique(array_column($media, 'mimetype')))],
			'rights' => [], 'relations' => [], 'landing_url' => $this->landing('objects', $id),
			'preview_urls' => array_values($previews), 'media' => array_values($media),
			'repository' => $this->config['repository_name']
		];
		foreach (['idno' => 'local', 'omeka_id' => 'omeka'] as $field => $type) {
			foreach ($this->values($object, $field) as $value) { $record['identifiers'][] = ['type' => $type, 'value' => $value]; }
		}
		if ($this->bundle('ca_objects', 'type_id')) {
			$record['genre'] = $this->texts($object->getTypeName());
			$record['type'] = (string)$object->getTypeCode();
		}
		foreach (['description_source' => 'source of description', 'coverageNotes' => 'coverage',
			'inscriptions_marks' => 'inscriptions', 'formatNotes' => 'physical description'] as $field => $type) {
			foreach ($this->values($object, $field) as $value) { $record['notes'][] = ['type' => $type, 'value' => $value]; }
		}
		foreach (['materials_techniques', 'materials_and_techniques', 'gen_format'] as $field) {
			$record['physical']['form'] = array_merge($record['physical']['form'], $this->values($object, $field));
		}
		foreach (['dimensions.dimensions_text', 'duration'] as $field) {
			$record['physical']['extent'] = array_merge($record['physical']['extent'], $this->values($object, $field));
		}
		foreach (['dimensions_height' => 'Height', 'dimensions_width' => 'Width', 'dimensions_depth' => 'Depth',
			'dimensions_diameter' => 'Diameter', 'dimensions_weight' => 'Weight', 'dimensions_type' => 'Dimension type'] as $field => $label) {
			foreach ($this->values($object, 'dimensions.'.$field) as $value) { $record['physical']['extent'][] = $label.': '.$value; }
		}
		foreach (['rights.rightsText', 'rights.copyrightStatement'] as $field) {
			foreach ($this->values($object, $field) as $value) { $record['rights'][] = ['value' => $value, 'type' => 'use and reproduction']; }
		}
		foreach (['subjects_text' => '', 'lcsh_terms' => 'lcsh', 'lctgm' => 'lctgm', 'art_architecture_authority' => 'aat'] as $field => $authority) {
			foreach ($this->values($object, $field) as $value) {
				$record['subjects'][] = ['type' => 'topic', 'value' => $value, 'authority' => $authority];
			}
		}
		$this->relatedMetadata($object, $record);
		foreach ($this->values($object, 'external_link.url_entry') as $url) {
			if (($url = $this->externalUrl($url)) !== '') { $record['relations'][] = ['type' => 'references', 'url' => $url]; }
		}
		return $this->cache[$id] = $record;
	}

	private function model(string $table, int $id) {
		if ($id < 1) { return null; }
		$model = \Datamodel::getInstanceByTableName($table, false);
		if (!$model) { throw new \RuntimeException('A required public catalogue model is unavailable.'); }
		// Loading is a candidate read, not authorization. Avoid the signed-in
		// editor's row filter; readable() applies the explicit publication audience.
		$guard = method_exists($model, 'disableACL');
		if ($guard) { $model->disableACL(true); }
		try { $loaded = $model->load($id); }
		finally { if ($guard) { $model->disableACL(false); } }
		return $loaded ? $model : null;
	}

	private function readable($model): bool {
		if (!$model->hasField('access') || !$model->hasField('deleted')
			|| (int)$model->get('access') !== 1 || (int)$model->get('deleted') !== 0) { return false; }
		$table = $model->tableName();
		if (($this->config['item_acl_policy'] ?? 'native') === 'native'
			&& (int)\ca_acl::accessForRow($this->anonymous, $model->tableNum(), (int)$model->getPrimaryKey()) < 1) { return false; }
		$app = $model->getAppConfig();
		if ($app->get('perform_type_access_checking') && method_exists($model, 'getTypeID')
			&& $this->anonymous->getTypeAccessLevel($table, $model->getTypeID()) < 1) { return false; }
		if (\caSourceAccessControlIsEnabled($model) && method_exists($model, 'getSourceFieldName')
			&& ($field = $model->getSourceFieldName())
			&& $this->anonymous->getSourceAccessLevel($table, $model->get($field)) < 1) { return false; }
		return true;
	}

	private function bundle(string $table, string $field): bool {
		$parts = explode('.', $field);
		return $this->anonymous->getBundleAccessLevel($table, $parts[0]) >= 1;
	}

	/** All paths are fixed source-code allowlists; unknown optional schema fields are omitted. */
	private function values($model, string $field): array {
		$table = $model->tableName(); $root = explode('.', $field)[0];
		$labels = in_array($root, ['preferred_labels', 'nonpreferred_labels'], true);
		if (!$this->bundle($table, $field) || (!$labels && !$model->hasField($root) && !$model->hasElement($root))) { return []; }
		return $this->texts($model->get($table.'.'.$field, ['returnAsArray' => true,
			'convertCodesToDisplayText' => true, 'dontReturnDefault' => true, 'text' => true,
			'highlighting' => false, 'convertLineBreaks' => false]));
	}

	private function texts($value): array {
		$result = [];
		$walk = function($part) use (&$walk, &$result) {
			if (is_array($part)) { foreach ($part as $child) { $walk($child); } return; }
			if (!is_string($part) && !is_int($part) && !is_float($part)) { return; }
			$text = trim(html_entity_decode(strip_tags((string)$part), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if ($text !== '') { $result[$text] = $text; }
		};
		$walk($value); return array_values($result);
	}

	private function dates($object): array {
		if (!$object->hasElement('date') || !$this->bundle('ca_objects', 'date')) { return []; }
		$raw = $object->get('ca_objects.date', ['returnWithStructure' => true, 'convertCodesToDisplayText' => true,
			'dontReturnDefault' => true, 'text' => true]);
		$dates = [];
		$walk = function($row) use (&$walk, &$dates) {
			if (!is_array($row)) { return; }
			if (array_key_exists('dates_value', $row)) {
				$type = $this->texts($row['dc_dates_types'] ?? '');
				foreach ($this->texts($row['dates_value']) as $value) { $dates[] = ['value' => $value, 'type' => $type[0] ?? '']; }
				return;
			}
			foreach ($row as $child) { $walk($child); }
		};
		$walk($raw);
		if (!$dates) { foreach ($this->values($object, 'date.dates_value') as $value) { $dates[] = ['value' => $value, 'type' => '']; } }
		return $dates;
	}

	private function modified($model): int {
		$change = $model->getLastChangeTimestamp();
		return max(0, (int)($change['timestamp'] ?? 0));
	}

	/** Fixed link-table queries avoid current-user filtering in getRelatedItems(). */
	private function related($object, string $table): array {
		[$link, $key] = self::RELATIONS[$table];
		$select = $table === 'ca_object_representations' ? "SELECT {$key} related_id, '' role FROM {$link} WHERE object_id = ? ORDER BY is_primary DESC, `rank`, relation_id"
			: "SELECT l.{$key} related_id, t.type_code role FROM {$link} l LEFT JOIN ca_relationship_types t ON t.type_id = l.type_id WHERE l.object_id = ? ORDER BY l.`rank`, l.relation_id";
		return $this->rows($object, $select, [(int)$object->getPrimaryKey()]);
	}

	private function rows($object, string $sql, array $params): array {
		$db = $object->getDb(); $result = $db->query($sql, $params);
		if (!$result || $db->numErrors()) { throw new \RuntimeException('Unable to read public catalogue relationships.'); }
		$rows = [];
		while ($result->nextRow()) { $rows[] = $result->getRow(); }
		return $rows;
	}

	private function relatedMetadata($object, array &$record): void {
		foreach (['ca_entities', 'ca_places', 'ca_collections', 'ca_list_items'] as $table) {
			foreach ($this->related($object, $table) as $link) {
				$item = $this->model($table, (int)$link['related_id']);
				if (!$item) { continue; }
				$record['modified'] = max($record['modified'], $this->modified($item));
				if (!$this->bundle('ca_objects', $table) || !$this->readable($item) || !$this->bundle($table, 'preferred_labels')) { continue; }
				$labelField = $table === 'ca_entities' ? 'preferred_labels.displayname' : ($table === 'ca_list_items' ? 'preferred_labels.name_plural' : 'preferred_labels.name');
				$labels = $this->values($item, $labelField);
				if (!$labels && $table === 'ca_list_items') { $labels = $this->values($item, 'preferred_labels.name_singular'); }
				if ($table === 'ca_entities') {
					$role = (string)($link['role'] ?? '');
					if (!in_array($role, self::NAME_ROLES, true)) { continue; }
					foreach ($labels as $name) { $record['names'][] = ['name' => $name, 'type' => (string)$item->getTypeCode(), 'roles' => [$role]]; }
				} elseif ($table === 'ca_places') {
					foreach ($labels as $value) { $record['subjects'][] = ['type' => 'geographic', 'value' => $value]; }
				} elseif ($table === 'ca_list_items') {
					$list = $this->model('ca_lists', (int)$item->get('list_id'));
					if (!$list || $list->get('list_code') !== 'subject_terms') { continue; }
					foreach ($labels as $value) { $record['subjects'][] = ['type' => 'topic', 'value' => $value]; }
				} else {
					$type = ($link['role'] ?? '') === 'part_of' ? 'host' : ((string)($link['role'] ?? '') ?: 'related');
					foreach ($labels as $title) { $record['relations'][] = ['type' => $type, 'title' => $title,
						'url' => $this->landing('collections', (int)$item->getPrimaryKey()), 'identifier' => $this->values($item, 'idno')[0] ?? '']; }
				}
			}
		}
		if (!$this->bundle('ca_objects', 'ca_objects')) { return; }
		$links = $this->rows($object, 'SELECT CASE WHEN l.object_left_id = ? THEN l.object_right_id ELSE l.object_left_id END related_id, t.type_code role, l.object_left_id FROM ca_objects_x_objects l LEFT JOIN ca_relationship_types t ON t.type_id = l.type_id WHERE l.object_left_id = ? OR l.object_right_id = ? ORDER BY l.`rank`, l.relation_id', array_fill(0, 3, (int)$object->getPrimaryKey()));
		foreach ($links as $link) {
			$item = $this->model('ca_objects', (int)$link['related_id']);
			if (!$item) { continue; }
			$record['modified'] = max($record['modified'], $this->modified($item));
			if (!$this->readable($item)) { continue; }
			$type = trim((string)($link['role'] ?? '')) ?: 'related';
			if ((int)$link['object_left_id'] !== (int)$object->getPrimaryKey()) { $type .= ' (reverse)'; }
			foreach ($this->values($item, 'preferred_labels.name') as $title) {
				$record['relations'][] = ['type' => $type, 'title' => $title, 'url' => $this->landing('objects', (int)$item->getPrimaryKey())];
			}
		}
	}

	private function media($representation): ?array {
		$info = $representation->getMediaInfo('media');
		if (!is_array($info) || !$info) { return null; }
		$original = $info['original'] ?? null;
		if (is_array($original) && empty($original['SKIP']) && empty($original['USE_ICON']) && empty($original['QUEUED'])
			&& (!empty($original['FILENAME']) || !empty($original['EXTERNAL_URL']))) {
			$url = $this->mediaUrl($representation->getMediaUrl('media', 'original'), empty($info['IS_EMBEDDED']));
		} elseif (!empty($info['IS_EMBEDDED'])) {
			$url = $this->mediaUrl($info['INPUT']['FETCHED_FROM'] ?? '', false);
		} else { return null; }
		if ($url === '') { return null; }
		$mime = trim((string)($info['INPUT']['MIMETYPE'] ?? $original['MIMETYPE'] ?? ''));
		if (!preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~iD', $mime)) { $mime = ''; }
		return ['url' => $url, 'mimetype' => $mime, 'title' => $this->values($representation, 'preferred_labels.name')[0] ?? '',
			'caption' => join("\n\n", $this->values($representation, 'media_caption'))];
	}

	private function landing(string $kind, int $id): string {
		return $this->config['public_base_url'].'/Detail/'.$kind.'/'.$id;
	}

	private function mediaUrl($url, bool $rewrite=true): string {
		if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return ''; }
		if (substr($url, 0, 1) === '/' && substr($url, 0, 2) !== '//') { $url = $this->config['media_base_url'].$url; }
		$parts = parse_url($url);
		if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
			|| isset($parts['port']) || isset($parts['fragment'])
			|| !Config::safeMediaPath($parts['path'] ?? '')) { return ''; }
		$source = $this->config['media_source_url_prefix'] ?? '';
		if ($rewrite && $source !== '') {
			$prefix = parse_url($source); $path = $parts['path'] ?? ''; $sourcePath = $prefix['path'];
			if (strtolower($parts['host'] ?? '') === strtolower($prefix['host'])
				&& ($path === $sourcePath || strpos($path, $sourcePath.'/') === 0)) {
				if (isset($parts['query'])) { return ''; }
				$url = $this->config['media_public_url_prefix'].substr($path, strlen($sourcePath));
				$parts = parse_url($url);
			}
		}
		if (!in_array(strtolower($parts['host'] ?? ''), $this->config['allowed_media_hosts'], true)) { return ''; }
		return $url;
	}

	private function externalUrl(string $url): string {
		$parts = parse_url($url);
		$host = strtolower($parts['host'] ?? '');
		if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !$host
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
			|| preg_match('/[\x00-\x20\x7f\\\\]/', $url)
			|| !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || strpos($host, '.') === false
			|| filter_var($host, FILTER_VALIDATE_IP) || substr($host, -6) === '.local' || substr($host, -10) === '.localhost') { return ''; }
		return $url;
	}
}
