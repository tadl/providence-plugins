<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
require_once __DIR__.'/lib/Config.php';
require_once __DIR__.'/lib/Presentation.php';

class fileHealthPlugin extends BaseApplicationPlugin {
	private $config;
	public function __construct($path) {
		$this->description = _t('Review native media-integrity patrols and their reports.');
		$this->config = \FileHealth\Config::load($path);
		parent::__construct();
	}
	public function checkStatus() {
		return ['description' => $this->getDescription(),'errors' => [],'warnings' => [],'available' => $this->config->enabled()];
	}
	public function hookRenderWidgets($widgets) {
		$request = $this->getRequest();
		if (!$this->config->enabled() || !\FileHealth\Presentation::allowed($request) || !is_array($widgets)
			|| $request->getModulePath() !== 'fileHealth' || $request->getController() !== 'Health') { return $widgets; }
		$widgets['file_health_navigation'] = [
			'domain' => ['module' => 'fileHealth','controller' => 'Health'],
			'handler' => ['module' => 'fileHealth','controller' => 'Health','action' => 'Info','isplugin' => true],
			'parameters' => []
		];
		return $widgets;
	}
	public function hookRenderMenuBar($menu) {
		if (!$this->config->enabled() || !\FileHealth\Presentation::allowed($this->getRequest()) || !is_array($menu)) { return $menu; }
		$item = ['displayName' => _t('File health'),'default' => ['module' => 'fileHealth','controller' => 'Health','action' => 'Index'],
			'requires' => ['action:is_administrator' => 'AND'],'parameters' => [],'is_enabled' => 1,'hideIfNoAccess' => 1];
		if (isset($menu['manage']['navigation']) && is_array($menu['manage']['navigation'])) {
			$menu['manage']['navigation']['file_health'] = $item;
		} else {
			$menu['file_health'] = ['displayName' => _t('File health'),'requires' => ['action:is_administrator' => 'AND'],'navigation' => ['overview' => $item]];
		}
		return $menu;
	}
}
