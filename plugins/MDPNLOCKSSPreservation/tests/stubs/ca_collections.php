<?php
class ca_collections {
	private $id;
	public function __construct($id=0) { $this->id = $id; }
	public function getPrimaryKey() { return $this->id; }
	public function get($field) { return 0; }
	public function checkACLAccessForUser($user) { return 1; }
	public function getLabelForDisplay() { return '<script>synthetic</script>'; }
}
