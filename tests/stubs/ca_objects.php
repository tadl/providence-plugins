<?php
// Synthetic model: deliberately has no database or write methods.
class ca_objects {
	public $id = 101;
	public $parent = null;
	public $collections = [501];
	public $related_options;

	public function getPrimaryKey() { return $this->id; }
	public function get($field) { return $field === 'parent_id' ? $this->parent : null; }
	public function getProperty($field) { return $field === 'NAME_PLURAL' ? 'objects' : 'object'; }
	public function getRelatedItems($table, $options) {
		if ($table !== 'ca_collections') { throw new RuntimeException('Unexpected table'); }
		$this->related_options = $options;
		return $this->collections;
	}
}
