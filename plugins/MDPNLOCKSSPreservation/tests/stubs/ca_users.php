<?php
class ca_users {
	public static $deny = false;
	private $id;
	public function __construct($id=0) { $this->id = $id; }
	public function getPrimaryKey() { return $this->id; }
	public function get($field) { return $field === 'active' ? 1 : null; }
	public function canDoAction($action) { return !self::$deny; }
}
