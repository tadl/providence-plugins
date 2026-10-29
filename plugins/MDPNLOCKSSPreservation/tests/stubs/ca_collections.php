<?php
class ca_collections {
	private $id;
	public function __construct($id=0) { $this->id = empty($GLOBALS['AUTH_CURRENT_USER_ID']) ? 0 : $id; }
	public function getPrimaryKey() { return $this->id; }
	public function tableName() { return 'ca_collections'; }
	public function get($field) { return 0; }
	public function checkACLAccessForUser($user) { return 1; }
	public function getLabelForDisplay() { return '<script>synthetic</script>'; }
}
