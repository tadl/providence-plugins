<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
require_once dirname(__DIR__).'/lib/Config.php';
require_once dirname(__DIR__).'/lib/Store.php';
require_once dirname(__DIR__).'/lib/Presentation.php';

class HealthController extends ActionController {
	private $healthConfig;
	public function __construct(&$request,&$response,$paths=null) {
		$paths = is_array($paths) ? $paths : []; $paths[] = dirname(__DIR__).'/views';
		parent::__construct($request,$response,$paths);
		$this->response->addHeader('Cache-Control','no-store');
		$this->authorize();
		try { $this->healthConfig = $this->configuration(); }
		catch (Throwable $error) { throw new ApplicationException(_t('File health is unavailable. Ask an administrator to review the private configuration.')); }
		if (!$this->healthConfig->enabled()) { throw new ApplicationException(_t('File health is disabled.')); }
	}
	protected function configuration() { return \FileHealth\Config::load(dirname(__DIR__)); }
	protected function store() { return new \FileHealth\Store($this->healthConfig,false,true); }
	private function authorize() : void {
		if (!\FileHealth\Presentation::allowed($this->request)) { throw new ApplicationException(_t('File health is available only to active administrators.')); }
	}
	public function Index() {
		$this->authorize();
		if (!$this->healthConfig->enabled()) { throw new ApplicationException(_t('File health is disabled.')); }
		$offset = max(0,min(1000000,(int)$this->request->getParameter('offset',pInteger)));
		$representation = max(0,(int)$this->request->getParameter('representation_id',pInteger));
		$summary = null; $problems = []; $runs = []; $history = []; $issues = []; $error = null;
		try {
			$store = $this->store(); $summary = $store->summary();
			$problems = $store->problems(50,$offset); $runs = $store->recentRuns(10);
			if ($representation) { $history = $store->history($representation,50); $issues = $store->issueHistory($representation,50); }
		} catch (Throwable $failure) {
			$summary = null; $problems = []; $runs = []; $history = []; $issues = [];
			$error = _t('File health results are unavailable. Ask an administrator to check the private health storage and worker configuration.');
		}
		$this->view->setVar('health_summary',$summary); $this->view->setVar('health_problems',$problems);
		$this->view->setVar('health_runs',$runs); $this->view->setVar('health_history',$history);
		$this->view->setVar('health_issues',$issues);
		$this->view->setVar('health_representation_id',$representation); $this->view->setVar('health_offset',$offset);
		$this->view->setVar('health_error',$error); $this->view->setVar('health_request',$this->request);
		$this->render('index_html.php');
	}
}
