<?php
class ca_collections {
	public static $descriptionFields = [];
	private $id;
	public function __construct($id=0) { $this->id = empty($GLOBALS['AUTH_CURRENT_USER_ID']) ? 0 : $id; }
	public function getPrimaryKey() { return $this->id; }
	public function tableName() { return 'ca_collections'; }
	public function get($field,$options=[]) { return self::$descriptionFields[$field] ?? 0; }
	public function hasElement($field) { return isset(self::$descriptionFields['ca_collections.'.$field]); }
	public function checkACLAccessForUser($user) { return 1; }
	public function getLabelForDisplay() { return '<script>synthetic</script>'; }
}
