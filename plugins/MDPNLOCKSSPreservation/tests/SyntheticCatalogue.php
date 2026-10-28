<?php
/** Synthetic catalogue adapter shared by isolated tests. */
class SyntheticCatalogue {
	public $data;
	public $denied = [];
	public $finished = 0;
	private $definitions;
	public function __construct() {
		$this->data = [
			'ca_collections' => [1 => ['collection_id' => 1,'parent_id' => null,'access' => 0],2 => ['collection_id' => 2,'parent_id' => 1,'access' => 0],99 => ['collection_id' => 99,'parent_id' => null,'access' => 0]],
			'ca_objects' => [10 => ['object_id' => 10,'parent_id' => null,'access' => 0],11 => ['object_id' => 11,'parent_id' => 10,'access' => 0],12 => ['object_id' => 12,'parent_id' => null,'deleted' => 1]],
			'ca_objects_x_collections' => [1 => ['relation_id' => 1,'object_id' => 10,'collection_id' => 2,'type_id' => 5],2 => ['relation_id' => 2,'object_id' => 11,'collection_id' => 2,'type_id' => 5],3 => ['relation_id' => 3,'object_id' => 10,'collection_id' => 99,'type_id' => 5]],
			'ca_object_representations' => [20 => ['representation_id' => 20,'access' => 0]],
			'ca_objects_x_object_representations' => [1 => ['relation_id' => 1,'object_id' => 10,'representation_id' => 20],2 => ['relation_id' => 2,'object_id' => 11,'representation_id' => 20]],
			'ca_entities' => [30 => ['entity_id' => 30,'parent_id' => null,'access' => 0]],
			'ca_places' => [40 => ['place_id' => 40,'parent_id' => null,'access' => 0]],
			'ca_objects_x_entities' => [1 => ['relation_id' => 1,'object_id' => 10,'entity_id' => 30]],
			'ca_object_representation_sidecars' => [1 => ['sidecar_id' => 1,'representation_id' => 20]],
			'ca_attribute_value_multifiles' => [1 => ['multifile_id' => 1,'value_id' => 1]],
			'ca_attributes' => [1 => ['attribute_id' => 1,'table_num' => 57,'row_id' => 10]],
			'ca_attribute_values' => [1 => ['value_id' => 1,'attribute_id' => 1,'element_id' => 4,'value_blob' => "\xff\x00"],2 => ['value_id' => 2,'attribute_id' => 1,'element_id' => 5,'reference_table' => 'ca_places','reference_id' => 40]],
			'ca_acl' => [1 => ['acl_id' => 1,'table_num' => 57,'row_id' => 10]],
			'ca_object_labels' => [1 => ['label_id' => 1,'object_id' => 10,'locale_id' => 1,'name' => 'Synthetic object']],
		];
		$this->definitions = [
			'ca_collections' => ['collection_id',13,null], 'ca_objects' => ['object_id',57,'ca_object_labels'],
			'ca_object_representations' => ['representation_id',56,null], 'ca_entities' => ['entity_id',20,null],
			'ca_places' => ['place_id',72,null], 'ca_attribute_value_multifiles' => ['multifile_id',201,null],
			'ca_object_representation_sidecars' => ['sidecar_id',200,null], 'ca_attributes' => ['attribute_id',3,null],
			'ca_attribute_values' => ['value_id',4,null], 'ca_acl' => ['acl_id',1,null], 'ca_object_labels' => ['label_id',58,null],
		];
		foreach (['ca_objects_x_collections' => ['ca_objects','object_id','ca_collections','collection_id'],
			'ca_objects_x_object_representations' => ['ca_objects','object_id','ca_object_representations','representation_id'],
			'ca_objects_x_entities' => ['ca_objects','object_id','ca_entities','entity_id']] as $table => $r) {
			$this->definitions[$table] = ['relation_id',100+count($this->definitions),null,array_combine(['left_table','left_field','right_table','right_field'],$r)];
		}
	}
	public function tables() : array { return array_keys($this->data); }
	public function relationshipTables() : array { return array_values(array_filter($this->tables(),function($table) { return !empty($this->definitions[$table][3]); })); }
	public function info(string $table) : array {
		$d = $this->definitions[$table];
		return ['primary_key' => $d[0],'table_num' => $d[1],'label_table' => $d[2],'relationship' => $d[3] ?? null,'fields' => array_keys(reset($this->data[$table]) ?: []),'foreign_keys' => []];
	}
	public function rows(string $table, ?string $field=null, array $ids=[]) : array {
		$result = array_filter($this->data[$table],function($r) use ($field,$ids) { return $field === null || in_array($r[$field] ?? null,$ids); });
		ksort($result); return $result;
	}
	public function begin() : void {}
	public function finish() : void { $this->finished++; }
	public function hierarchyType() : ?int { return 5; }
	public function profile() : string { return '<profile><profileName>Synthetic</profileName></profile>'; }
	public function references(array $attributes) : array {
		$references = [];
		foreach ($attributes as $attribute) { foreach ($attribute['values'] as $value) { if (isset($value['reference_table'])) { $references[] = ['table' => $value['reference_table'],'id' => $value['reference_id']]; } } }
		return $references;
	}
	public function checkAccess(string $table, array $row) : void { if (in_array($table.':'.$row[$this->info($table)['primary_key']],$this->denied,true)) { throw new RuntimeException('permission denied'); } }
	public function files(string $table, array $row, array $attributes) : array {
		if (!in_array($table,['ca_object_representations','ca_object_representation_sidecars','ca_attribute_value_multifiles'],true)) { return []; }
		$id = $row[$this->info($table)['primary_key']];
		return [['key' => $table.':'.$id,'source_path' => '/synthetic/original','original_filename' => 'synthetic.tif','owner' => ['table' => $table,'id' => $id]]];
	}
}
