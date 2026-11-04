<?php
class ca_users {
	public static $deny = false;
	public $fields = ['fname' => 'Example','lname' => 'Operator','email' => 'operator@example.org'];
	private $id;
	public function __construct($id=0) { $this->id = $id; }
	public function getPrimaryKey() { return $this->id; }
	public function get($field) { return $field === 'active' ? 1 : ($this->fields[$field] ?? null); }
	public function getName() { return $this->get('fname').' '.$this->get('lname'); }
	public function canDoAction($action) { return !self::$deny; }
}
