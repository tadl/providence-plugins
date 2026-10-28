<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace MDPNPreservation;
require_once __DIR__.'/Config.php';

/** Thin adapter: all SQL identifiers come from the trusted native datamodel. */
final class ProvidenceCatalogue {
	private $db;
	private $transaction;
	private $user;
	private $info = [];
	private $attributeTypes = [];
	public function __construct($user) { $this->user = $user; }
	public function begin() : void {
		$this->attributeTypes = [];
		require_once __CA_LIB_DIR__.'/Db/Transaction.php';
		$this->db = new \Db(null, ['uniqueConnection' => true]);
		$this->db->dieOnError(false);
		$this->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
		$this->query('SET TRANSACTION READ ONLY');
		$this->transaction = new \Transaction($this->db);
		if ($this->db->numErrors()) { throw new Failure('Cannot start the catalogue snapshot transaction.'); }
	}
	public function finish() : void { if ($this->transaction) { $this->transaction->rollback(); $this->transaction = null; } }
	public function tables() : array { return \Datamodel::getTableNames(); }
	public function relationshipTables() : array {
		$tables = [];
		foreach ($this->tables() as $table) {
			// Native linking tables use this convention. Avoid constructing every
			// unrelated system/export/import model merely to inspect the graph.
			if (strpos($table,'_x_') === false) { continue; }
			$model = \Datamodel::getInstanceByTableName($table,true);
			if ($model instanceof \BaseRelationshipModel) { $tables[] = $table; }
		}
		return $tables;
	}
	public function info(string $table) : array {
		if (isset($this->info[$table])) { return $this->info[$table]; }
		if (!in_array($table,$this->tables(),true) || !preg_match('/^ca_[a-z0-9_]+$/D',$table)) { throw new Failure('Unknown catalogue table.'); }
		$model = \Datamodel::getInstanceByTableName($table,true);
		if (!$model) { throw new Failure('A required native catalogue model is unavailable.'); }
		$relationship = $model instanceof \BaseRelationshipModel;
		return $this->info[$table] = ['primary_key' => $model->primaryKey(), 'table_num' => $model->tableNum(),
			'fields' => array_keys($model->getFieldsArray()), 'label_table' => method_exists($model,'getLabelTableName') ? $model->getLabelTableName() : null,
			'foreign_keys' => \Datamodel::getManyToOneRelations($table) ?: [],
			'relationship' => $relationship ? ['left_table' => $model->getLeftTableName(), 'left_field' => $model->getLeftTableFieldName(),
				'right_table' => $model->getRightTableName(), 'right_field' => $model->getRightTableFieldName()] : null];
	}
	private function query(string $sql, array $params=[]) {
		$result = $this->db->query($sql,$params);
		if (!$result || $this->db->numErrors()) { throw new Failure('A catalogue preservation query failed.'); }
		return $result;
	}
	public function rows(string $table, ?string $field=null, array $ids=[]) : array {
		$info = $this->info($table);
		if ($field !== null && !in_array($field,$info['fields'],true)) { throw new Failure('Unknown catalogue field.'); }
		if ($field !== null && !$ids) { return []; }
		$rows = [];
		foreach ($field === null ? [null] : array_chunk(array_values(array_unique(array_map('intval',$ids))),500) as $batch) {
			$q = $field === null ? $this->query('SELECT * FROM '.$table) : $this->query('SELECT * FROM '.$table.' WHERE '.$field.' IN (?)',[$batch]);
			while ($q->nextRow()) { $r = $q->getRow(); $rows[(string)$r[$info['primary_key']]] = $r; }
		}
		ksort($rows,SORT_NATURAL); return $rows;
	}
	public function hierarchyType() : ?int {
		$config = \Configuration::load(); $value = $config->get('ca_objects_x_collections_hierarchy_relationship_type');
		if (!$value) {
			if ($config->get('ca_objects_x_collections_hierarchy_enabled')) { throw new Failure('Configure the collection hierarchy relationship type before preservation.'); }
			return null;
		}
		require_once __CA_MODELS_DIR__.'/ca_relationship_types.php';
		$types = new \ca_relationship_types();
		$id = $types->getRelationshipTypeID('ca_objects_x_collections',$value);
		if (!$id) { throw new Failure('The configured collection hierarchy relationship type is invalid.'); }
		return (int)$id;
	}
	public function checkAccess(string $table, array $row) : void {
		$model = $this->model($table,$row);
		if (method_exists($model,'checkACLAccessForUser') && $model->checkACLAccessForUser($this->user) < __CA_ACL_READONLY_ACCESS__) { throw new Failure('A member record is outside your native catalogue permissions. Preservation stopped without omitting it.'); }
	}
	private function model(string $table, array $row) {
		$model = \Datamodel::getInstanceByTableName($table);
		$model->setTransaction($this->transaction);
		if (!$model->load($row[$this->info($table)['primary_key']])) { throw new Failure('A catalogue record could not be loaded.'); }
		return $model;
	}
	public function files(string $table, array $row, array $attributes) : array {
		$files = []; $info = $this->info($table); $id = (int)$row[$info['primary_key']];
		$model = $this->model($table,$row);
		foreach ($model->getFieldsArray() as $field => $settings) {
			$type = $settings['FIELD_TYPE'] ?? null;
			if (!in_array($type,[FT_MEDIA,FT_FILE],true) || empty($row[$field])) { continue; }
			$media = $type === FT_MEDIA ? $model->getMediaInfo($field) : null;
			if ($media && (empty($media['original']) || !empty($media['original']['SKIP']) || !empty($media['original']['USE_ICON']) || !empty($media['original']['EXTERNAL_URL']))) {
				throw new Failure('An original is not stored locally. A generated replacement cannot be preserved as the original.');
			}
			$path = $type === FT_MEDIA ? $model->getMediaPath($field,'original') : $model->getFilePath($field);
			if (!$path && $type === FT_MEDIA) { $path = $model->getOriginalMediaPath($field); }
			$name = $type === FT_MEDIA ? $model->getMediaInfo($field,'ORIGINAL_FILENAME') : $model->getFileInfo($field,'ORIGINAL_FILENAME');
			$files[] = $this->file($table.':'.$id.':'.$field, $path, $name ?: ($row['original_filename'] ?? basename((string)$path)),
				['table' => $table,'id' => $id,'field' => $field], $media['INPUT']['MD5'] ?? ($table === 'ca_object_representations' ? ($row['md5'] ?? null) : null));
		}
		if (in_array($table,['ca_object_representations','ca_object_representation_multifiles','ca_attribute_value_multifiles','ca_object_representation_captions','ca_object_representation_sidecars'],true) && !$files) { throw new Failure('A representation or attached file has no readable original. Preservation stopped without omitting it.'); }
		require_once __CA_LIB_DIR__.'/Attributes/Attribute.php';
		foreach ($attributes as $attribute) {
			foreach ($attribute['values'] as $value) {
				$datatype = $this->attributeType((int)$value['element_id']);
				if ($datatype === 34 && !empty($value['value_longtext1'])) { throw new Failure('An external-media attribute has no local original. Stage it locally before preservation.'); }
				if (!in_array($datatype,[15,16],true) || empty($value['value_blob'])) { continue; }
				$v = \CA\Attributes\Attribute::getValueInstance($datatype,$value);
				$media = $datatype === 16 ? (new \MediaInfoCoder($value['value_blob']))->getMedia() : null;
				if ($datatype === 16 && (!$media || empty($media['original']) || !empty($media['original']['SKIP']) || !empty($media['original']['USE_ICON']) || !empty($media['original']['EXTERNAL_URL']))) {
					throw new Failure('A media attribute has no locally stored original.');
				}
				$path = $v->getDisplayValue(['return' => 'path','version' => 'original']);
				$files[] = $this->file('attribute:'.$value['value_id'], $path, $value['value_longtext2'] ?: basename((string)$path),
					['table' => $table,'id' => $id,'attribute_id' => (int)$attribute['row']['attribute_id'],'value_id' => (int)$value['value_id'],'element_id' => (int)$value['element_id']], $media['INPUT']['MD5'] ?? null);
			}
		}
		return $files;
	}
	private function attributeType(int $id) : int {
		if (!$this->attributeTypes) { foreach ($this->rows('ca_metadata_elements') as $row) { $this->attributeTypes[(int)$row['element_id']] = (int)$row['datatype']; } }
		if (!isset($this->attributeTypes[$id])) { throw new Failure('An attribute definition is missing.'); }
		return $this->attributeTypes[$id];
	}
	public function references(array $attributes) : array {
		require_once __CA_LIB_DIR__.'/Attributes/Attribute.php';
		require_once __CA_LIB_DIR__.'/Attributes/Values/AuthorityAttributeValue.php';
		$references = [];
		foreach ($attributes as $attribute) {
			foreach ($attribute['values'] as $value) {
				$type = $this->attributeType((int)$value['element_id']);
				if ($type >= 21 && $type <= 30 && !empty($value['value_integer1'])) {
					$model = \AuthorityAttributeValue::elementTypeToInstance($type);
					if ($model) { $references[] = ['table' => $model->tableName(),'id' => (int)$value['value_integer1']]; }
				}
			}
		}
		return $references;
	}
	private function file(string $key, $path, string $name, array $owner, ?string $md5=null) : array {
		clearstatcache();
		$resolved = $path ? realpath($path) : false;
		if (!$resolved || !is_file($resolved) || !is_readable($resolved)) { throw new Failure('An original is missing, remote-only, or unreadable. Preservation stopped without omitting it.'); }
		return ['key' => $key,'source_path' => $resolved,'original_filename' => $name,'owner' => $owner,'expected_md5' => $md5 ?: null,
			'source_size' => filesize($resolved),'source_mtime' => filemtime($resolved)];
	}
	public function profile() : string {
		require_once __CA_LIB_DIR__.'/ConfigurationExporter.php';
		// Build an explicit allowlist. The standard export may include user logins
		// (and randomly generated replacement passwords), depending on site settings.
		$exporter = new \ConfigurationExporter(null,false); $dom = $exporter->getDOM();
		$root = $dom->createElement('profile'); $dom->appendChild($root);
		$root->setAttribute('xmlns:xsi','http://www.w3.org/2001/XMLSchema-instance');
		$root->setAttribute('xsi:noNamespaceSchemaLocation','profile.xsd'); $root->setAttribute('useForConfiguration','1');
		$root->appendChild($dom->createElement('profileName','Preservation recovery profile'));
		$root->appendChild($dom->createElement('profileDescription','Catalogue configuration at preservation capture'));
		foreach (['getLocalesAsDOM','getListsAsDOM','getElementsAsDOM','getUIsAsDOM','getRelationshipTypesAsDOM','getRolesAsDOM','getGroupsAsDOM','getSearchFormsAsDOM','getMetadataDictionaryAsDOM'] as $method) {
			if ($node = $exporter->$method()) { $root->appendChild($node); }
		}
		$xml = $dom->saveXML();
		if (!is_string($xml) || !strlen($xml)) { throw new Failure('The installation profile could not be exported.'); }
		return $xml;
	}
}

/** Collect full native rows and relationship attributes, never display exports. */
final class Collector {
	private $catalogue;
	private $records = [];
	private $relationships = [];
	private $files = [];
	private $attributeReferences = [];
	public function __construct($catalogue) { $this->catalogue = $catalogue; }
	public function capture(int $root, string $repository) : array {
		$this->records = $this->relationships = $this->files = [];
		$this->attributeReferences = [];
		$c = $this->catalogue; $c->begin();
		try {
			$collections = $this->descendants('ca_collections',[$root]);
			if (!isset($collections[(string)$root])) { throw new Failure('Collection is missing or deleted.'); }
			foreach ($collections as $row) { $this->record('ca_collections',$row); }
			$memberships = $c->rows('ca_objects_x_collections','collection_id',array_keys($collections));
			$type = $c->hierarchyType(); $objects = [];
			foreach ($memberships as $row) { if ($type === null || (int)$row['type_id'] === $type) { $objects[] = $row['object_id']; } }
			if (count($c->rows('ca_objects','object_id',$objects)) !== count(array_unique($objects))) { throw new Failure('A collection membership references a missing object.'); }
			$objects = $this->descendants('ca_objects',$objects);
			foreach ($objects as $row) { $this->record('ca_objects',$row); }
			$links = $c->rows('ca_objects_x_object_representations','object_id',array_keys($objects));
			$rep_ids = array_column($links,'representation_id');
			$representations = $c->rows('ca_object_representations','representation_id',$rep_ids);
			if (count(array_unique($rep_ids)) !== count($representations)) { throw new Failure('An object references a missing representation.'); }
			foreach ($representations as $row) {
				if (!empty($row['deleted'])) { throw new Failure('An object references a deleted representation.'); }
				$this->record('ca_object_representations',$row);
			}
			$scope = ['ca_collections' => array_keys($collections),'ca_objects' => array_keys($objects),'ca_object_representations' => array_keys($representations)];
			$authorities = ['ca_entities','ca_places','ca_occurrences','ca_storage_locations','ca_object_lots','ca_loans','ca_movements','ca_tours','ca_tour_stops'];
			$external = [];
			do {
				$this->resolveAttributeReferences($authorities,$external);
				$scope['ca_object_representations'] = array_keys($this->records['ca_object_representations'] ?? []);
				$representation_count = count($scope['ca_object_representations']);
				foreach ($c->relationshipTables() as $table) {
					$info = $c->info($table); $r = $info['relationship'];
					if (!$r) { continue; }
					$rows = [];
					foreach (['left','right'] as $side) {
						$target = $r[$side.'_table'];
						if (isset($scope[$target])) { $rows += $c->rows($table,$r[$side.'_field'],$scope[$target]); }
					}
					foreach ($rows as $id => $row) {
						if (isset($this->relationships[$table][(string)$id])) { continue; }
						$this->relationships[$table][(string)$id] = $this->recordData($table,$row);
						foreach (['left','right'] as $side) {
							$target = $r[$side.'_table']; $target_id = (int)$row[$r[$side.'_field']];
							if (isset($this->records[$target][(string)$target_id])) { continue; }
							if (in_array($target,$authorities,true)) {
								foreach ($this->ancestors($target,$target_id) as $related) { $this->record($target,$related); }
							} else { $external[$target.':'.$target_id] = ['table' => $target,'id' => $target_id]; }
						}
					}
				}
				$this->resolveAttributeReferences($authorities,$external);
			} while (count($this->records['ca_object_representations'] ?? []) !== $representation_count);
			// Keep native IDs as well as the installable profile: ID-only values in
			// raw attributes must remain resolvable after restoration to new IDs.
			$schema = ['tables' => [],'definitions' => [],'installation_profile_xml' => $c->profile(),
				'providence_version' => defined('__CollectiveAccess_Version__') ? __CollectiveAccess_Version__ : 'unknown',
				'providence_schema_revision' => defined('__CollectiveAccess_Schema_Rev__') ? __CollectiveAccess_Schema_Rev__ : null];
			foreach (['ca_locales','ca_lists','ca_list_items','ca_list_labels','ca_list_item_labels','ca_metadata_elements','ca_metadata_element_labels','ca_metadata_type_restrictions','ca_relationship_types','ca_relationship_type_labels'] as $table) {
				if (in_array($table,$c->tables(),true)) { $schema['definitions'][$table] = $c->rows($table); }
			}
			$tables = array_unique(array_merge(array_keys($this->records),array_keys($this->relationships),array_keys($schema['definitions']),['ca_attributes','ca_attribute_values','ca_acl']));
			foreach ($tables as $table) {
				$schema['tables'][$table] = $c->info($table);
				if ($label = $schema['tables'][$table]['label_table']) { $schema['tables'][$label] = $c->info($label); }
			}
			foreach ($scope as $table => $ids) {
				foreach ($ids as $id) {
					$row = $this->records[$table][(string)$id]['row'];
					if (!empty($row['parent_id']) && !isset($this->records[$table][(string)$row['parent_id']])) { $external[$table.':'.$row['parent_id']] = ['table' => $table,'id' => (int)$row['parent_id']]; }
				}
			}
			ksort($this->records); ksort($this->relationships); ksort($external);
			foreach ($this->records as &$rows) { ksort($rows,SORT_NATURAL); } unset($rows);
			foreach ($this->relationships as &$rows) { ksort($rows,SORT_NATURAL); } unset($rows);
			return self::portable(['repository_id' => $repository,'collection_id' => $root,'captured_at' => gmdate('c'),
				'records' => $this->records,'relationships' => $this->relationships,'schema' => $schema,
				'external_references' => array_values($external),'files' => array_values($this->files)]);
		} finally { $c->finish(); }
	}
	private function descendants(string $table, array $ids) : array {
		$c = $this->catalogue; $result = []; $frontier = $ids;
		while ($frontier) {
			$rows = $c->rows($table,$c->info($table)['primary_key'],$frontier); $next = [];
			foreach ($rows as $id => $row) {
				if (!empty($row['deleted']) || isset($result[(string)$id])) { continue; }
				$result[(string)$id] = $row; $next[] = (int)$id;
			}
			$children = $next ? $c->rows($table,'parent_id',$next) : [];
			$frontier = [];
			foreach ($children as $id => $row) { if (empty($row['deleted']) && !isset($result[(string)$id])) { $frontier[] = (int)$id; } }
		}
		$done = [];
		foreach (array_keys($result) as $id) {
			$path = [];
			while (isset($result[(string)$id]) && !isset($done[(string)$id])) {
				if (isset($path[(string)$id])) { throw new Failure('A hierarchy cycle prevents preservation.'); }
				$path[(string)$id] = true; $id = (int)($result[(string)$id]['parent_id'] ?? 0);
			}
			$done += $path;
		}
		ksort($result,SORT_NATURAL); return $result;
	}
	private function ancestors(string $table, int $id) : array {
		$result = [];
		while ($id > 0) {
			if (isset($result[(string)$id])) { throw new Failure('An authority hierarchy contains a cycle.'); }
			$rows = $this->catalogue->rows($table,$this->catalogue->info($table)['primary_key'],[$id]);
			if (!isset($rows[(string)$id]) || !empty($rows[(string)$id]['deleted'])) { throw new Failure('A related authority is missing or deleted.'); }
			$row = $rows[(string)$id]; $result[(string)$id] = $row; $id = (int)($row['parent_id'] ?? 0);
		}
		return $result;
	}
	private function resolveAttributeReferences(array $authorities, array &$external) : void {
		while ($this->attributeReferences) {
			$key = array_key_first($this->attributeReferences); $ref = $this->attributeReferences[$key]; unset($this->attributeReferences[$key]);
			if (isset($this->records[$ref['table']][(string)$ref['id']])) { unset($external[$key]); continue; }
			if (in_array($ref['table'],$authorities,true) || $ref['table'] === 'ca_object_representations') {
				foreach ($this->ancestors($ref['table'],$ref['id']) as $row) { $this->record($ref['table'],$row); }
				unset($external[$key]);
			} else { $external[$key] = $ref; }
		}
	}
	private function record(string $table, array $row) : void {
		$id = (string)$row[$this->catalogue->info($table)['primary_key']];
		if (isset($this->records[$table][$id])) { return; }
		$this->catalogue->checkAccess($table,$row);
		$record = $this->recordData($table,$row);
		$this->records[$table][$id] = $record;
		if ($table === 'ca_object_representations') {
			foreach (['ca_object_representation_multifiles','ca_object_representation_captions','ca_object_representation_sidecars'] as $attached) {
				if (in_array($attached,$this->catalogue->tables(),true)) { foreach ($this->catalogue->rows($attached,'representation_id',[(int)$id]) as $file) { $this->record($attached,$file); } }
			}
		}
	}
	private function recordData(string $table, array $row) : array {
		$c = $this->catalogue; $info = $c->info($table); $id = (int)$row[$info['primary_key']];
		$attributes = [];
		foreach ($c->rows('ca_attributes','row_id',[$id]) as $attribute_id => $attribute) {
			if ((int)$attribute['table_num'] === (int)$info['table_num']) { $attributes[] = ['row' => $attribute,'values' => array_values($c->rows('ca_attribute_values','attribute_id',[$attribute_id]))]; }
		}
		if (method_exists($c,'references')) { foreach ($c->references($attributes) as $ref) { $this->attributeReferences[$ref['table'].':'.$ref['id']] = $ref; } }
		if (in_array('ca_attribute_value_multifiles',$c->tables(),true)) {
			foreach ($attributes as $attribute) {
				foreach ($c->rows('ca_attribute_value_multifiles','value_id',array_column($attribute['values'],'value_id')) as $file) { $this->record('ca_attribute_value_multifiles',$file); }
			}
		}
		$acl = [];
		foreach ($c->rows('ca_acl','row_id',[$id]) as $a) { if ((int)$a['table_num'] === (int)$info['table_num']) { $acl[] = $a; } }
		foreach ($c->files($table,$row,$attributes) as $file) {
			if (isset($this->files[$file['key']]) && $this->files[$file['key']] !== $file) { throw new Failure('Conflicting original file identities.'); }
			$this->files[$file['key']] = $file;
		}
		return ['row' => $row,'labels' => $info['label_table'] ? array_values($c->rows($info['label_table'],$info['primary_key'],[$id])) : [],'attributes' => $attributes,'acl' => $acl];
	}
	/** Preserve bytes losslessly without letting invalid UTF-8 break JSON. */
	public static function portable($value) {
		if (is_string($value) && !preg_match('//u',$value)) { return ['$base64' => base64_encode($value)]; }
		if (is_array($value)) { foreach ($value as $key => $v) { $value[$key] = self::portable($v); } }
		return $value;
	}
}
