<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
require_once __DIR__.'/lib/Config.php';
require_once __DIR__.'/lib/Presentation.php';

class fileHealthPlugin extends BaseApplicationPlugin {
	private $config;
	private $root;
	public function __construct($path) {
		$this->root = $path;
		$this->description = _t('Check representation media integrity in the background and review recorded file health.');
		$this->config = \FileHealth\Config::load($path);
		parent::__construct();
	}
	public function checkStatus() {
		return ['description' => $this->getDescription(),'errors' => [],'warnings' => [],'available' => $this->config->enabled()];
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
	public function hookCLICaUtilsGetCommands($commands) {
		require_once __DIR__.'/lib/Tool.php';
		return array_merge($commands,\FileHealth\Tool::commands());
	}
	public function hookCLICaUtilsGetToolWithSettings($parameters) {
		if (($parameters[0] ?? null) === 'fileHealth') {
			require_once __DIR__.'/lib/Tool.php';
			$parameters['tool'] = new \FileHealth\Tool($this->config,$this->root);
		}
		return $parameters;
	}
}
