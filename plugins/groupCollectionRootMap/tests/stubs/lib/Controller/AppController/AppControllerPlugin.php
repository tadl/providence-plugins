<?php
class AppControllerPlugin {
	public $request;
	public function __construct() {}
	public function getRequest() { return $this->request; }
}
