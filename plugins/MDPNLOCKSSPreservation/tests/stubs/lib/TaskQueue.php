<?php
class TaskQueue {
	public static $jobs = [];
	public static $addedHandlers = [];
	public static $processedHandlers = [];
	public static $queueSucceeds = true;
	public function addTask($handler,$parameters,$options) {
		if (strlen($handler) > 20) { throw new RuntimeException('Native handler column length exceeded.'); }
		self::$addedHandlers[] = $handler; self::$jobs[] = $parameters; return count(self::$jobs);
	}
	public function processQueue($handler='') { self::$processedHandlers[] = $handler; return self::$queueSucceeds; }
	public function numErrors() { return self::$queueSucceeds ? 0 : 1; }
}
