<?php
class ca_collections {
	public static $exists = true;
	public static $deleted = false;
	public static $access = 1;
	private $id;

	public function __construct($id) { $this->id = $id; }
	public function getPrimaryKey() { return self::$exists ? $this->id : null; }
	public function get($field) { return $field === 'deleted' && self::$deleted; }
	public function checkACLAccessForUser($user) { return self::$access; }
}
