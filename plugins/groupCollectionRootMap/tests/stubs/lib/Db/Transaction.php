<?php
class Transaction {
	private $db;
	public function __construct($db) { $this->db = $db; Db::$pdo->beginTransaction(); }
	public function getDb() { return $this->db; }
	public function commit() { Db::$pdo->commit(); }
	public function rollback() { if (Db::$pdo->inTransaction()) { Db::$pdo->rollBack(); } }
}
