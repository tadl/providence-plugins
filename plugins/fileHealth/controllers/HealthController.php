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
		$summary = null; $runs = []; $error = null;
		try {
			$store = $this->store(); $summary = $store->summary();
			$runs = $store->recentRuns(25);
		} catch (Throwable $failure) {
			$summary = null; $runs = [];
			$error = _t('Patrol results are unavailable. Ask an administrator to check the private report directory and patrol configuration.');
		}
		$this->view->setVar('health_summary',$summary); $this->view->setVar('health_runs',$runs);
		$this->view->setVar('health_error',$error); $this->view->setVar('health_request',$this->request);
		$this->render('index_html.php');
	}
	public function Download() {
		$this->authorize();
		if (!$this->healthConfig->enabled()) { throw new ApplicationException(_t('File health is disabled.')); }
		$id = (string)$this->request->getParameter('run_id',pString);
		try {
			if (!preg_match('/^(?:[a-f0-9]{32}|legacy-[a-f0-9]{64})$/D',$id)) { throw new RuntimeException('Invalid report identifier.'); }
			$store = $this->store(); $path = $store->reportPath($id); $name = $store->reportDownloadName($id);
			if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',$name)) { throw new RuntimeException('Invalid download name.'); }
		} catch (Throwable $failure) { throw new ApplicationException(_t('The requested native patrol report is unavailable.')); }
		$this->view->setVar('archive_path',$path); $this->view->setVar('archive_name',$name);
		// Providence's native binary view streams an attachment with private/no-store
		// headers. Process logs are never a downloadable report type.
		$this->view->render('bundles/download_file_binary.php');
	}
}
