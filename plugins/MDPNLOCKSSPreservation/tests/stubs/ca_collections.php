<?php
class ca_collections {
	public static $descriptionFields = [];
	public static $parents = [2 => 1];
	public static $notes = [];
	private $id;
	public function __construct($id=0) { $this->id = empty($GLOBALS['AUTH_CURRENT_USER_ID']) ? 0 : $id; }
	public function getPrimaryKey() { return $this->id; }
	public function tableName() { return 'ca_collections'; }
	public function get($field,$options=[]) { return $field === 'parent_id' ? (self::$parents[$this->id] ?? 0) : (self::$descriptionFields[$field] ?? 0); }
	public function hasElement($field) { return $field === 'internal_notes' || isset(self::$descriptionFields['ca_collections.'.$field]); }
	public function getAttributesByElement($element) {
		$text = self::$notes[$this->id] ?? '';
		return [new class($element,$text) {
			private $element; private $text;
			public function __construct($element,$text) { $this->element=$element; $this->text=$text; }
			public function getValues() { return [new class($this->element,$this->text) {
				private $element; private $text;
				public function __construct($element,$text) { $this->element=$element; $this->text=$text; }
				public function getElementCode() { return $this->element; }
				public function getDisplayValue() { return $this->text; }
			}]; }
		}];
	}
	public function checkACLAccessForUser($user) { return 1; }
	public function getLabelForDisplay() { return '<script>synthetic</script>'; }
}
