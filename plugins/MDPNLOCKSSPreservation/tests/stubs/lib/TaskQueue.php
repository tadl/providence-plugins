<?php
class TaskQueue {
	public static $jobs = [];
	public function addTask($handler,$parameters,$options) { self::$jobs[] = $parameters; return count(self::$jobs); }
}
