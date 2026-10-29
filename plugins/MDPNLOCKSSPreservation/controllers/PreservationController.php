<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
require_once __DIR__.'/../lib/Service.php';

class PreservationController extends ActionController {
	private $preservation_config;
	private $preservation_root;
	public function __construct(&$request,&$response,$paths=null) {
		$this->preservation_root = dirname(__DIR__);
		$paths = is_array($paths) ? $paths : []; $paths[] = $this->preservation_root.'/views';
		parent::__construct($request,$response,$paths);
		$this->preservation_config = \MDPNPreservation\Config::load($this->preservation_root);
		if (!$this->preservation_config->enabled()) { throw new ApplicationException(_t('Collection preservation is disabled.')); }
	}
	private function collection() : int {
		$id = (int)$this->request->getParameter('collection_id',pInteger);
		\MDPNPreservation\Service::authorize($this->request->user,$id); return $id;
	}
	private function service() : \MDPNPreservation\Service {
		return new \MDPNPreservation\Service($this->preservation_config,new \MDPNPreservation\Store($this->preservation_config),$this->preservation_root);
	}
	private function post() : void {
		if (strtoupper($this->request->getRequestMethod()) !== 'POST' || !caValidateCSRFToken($this->request)) { throw new ApplicationException(_t('A valid form submission is required.')); }
	}
	public function Info($parameters=null) : string {
		$this->collection();
		require_once __CA_LIB_DIR__.'/AppNavigation.php';
		// Reuse the configured editor screens and inspector without changing the
		// real plugin request, its form destinations or its authorization context.
		$editorRequest = clone $this->request;
		$editorRequest->setIsApplicationPlugin(false);
		$editorRequest->setModulePath('editor/collections');
		$editorRequest->setController('CollectionEditor');
		$editorRequest->setAction('Edit'); $editorRequest->setActionExtra('');
		$editorRequest->setControllerUrl(caNavUrl($editorRequest,'editor/collections','CollectionEditor',''));
		$navigation = new AppNavigation($editorRequest,$this->response);
		// Resolve the default screen before the inspector builds previous/next URLs.
		$menu = $navigation->getHTMLSideNav('mdpnCollectionSidebar',['hideDisabled' => false]);
		$inspector = $navigation->getHTMLWidgets();
		// Preservation is selected here; no underlying editor screen is current.
		return $inspector.str_replace(['class="sf-menu-selected"',"class='sf-menu-selected'"],'',$menu);
	}
	public function Index() {
		$id = $this->collection(); $error = null; $history = []; $baseline = null;
		try {
			$store = new \MDPNPreservation\Store($this->preservation_config);
			$history = $store->history($id); $baseline = $store->baseline($id,$this->preservation_config->target());
		} catch (Throwable $e) { $error = $e instanceof \MDPNPreservation\Failure ? $e->getMessage() : _t('Preservation storage is unavailable. Ask an administrator to check configuration.'); }
		$collection = new ca_collections($id);
		$this->view->setVar('collection_id',$id); $this->view->setVar('collection_label',$collection->getLabelForDisplay());
		$this->view->setVar('default_description',preg_replace('/[\r\n\x00-\x1f\x7f]+/',' ',(string)$collection->getLabelForDisplay()));
		$this->view->setVar('history',$history); $this->view->setVar('baseline',$baseline); $this->view->setVar('preservation_error',$error);
		$this->view->setVar('preservation_config',$this->preservation_config);
		$this->render('index_html.php');
	}
	public function Submit() {
		$this->post(); $id = $this->collection();
		$mode = (string)$this->request->getParameter('mode',pString);
		$input = [];
		foreach (['source_organization','contact_name','contact_email','description'] as $field) { $input[$field] = (string)$this->request->getParameter($field,pString); }
		$this->service()->enqueue($id,$mode,$this->request->user,$input); $this->back($id);
	}
	public function Retry() {
		$this->post(); $id = $this->collection(); $job = $this->job($id);
		$this->service()->retry($job['id'],$this->request->user); $this->back($id);
	}
	public function Confirm() {
		$this->post(); $id = $this->collection(); $job = $this->job($id);
		$this->service()->confirm($job['id'],$this->request->user,(string)$this->request->getParameter('reference',pString)); $this->back($id);
	}
	public function Download() {
		$id = $this->collection(); $job = $this->job($id);
		$part_number = (int)$this->request->getParameter('part',pInteger);
		$part = $job['result']['parts'][$part_number] ?? null;
		if (!$part || basename($part['filename']) !== $part['filename'] || !in_array($job['status'],['built','uploaded','confirmed','failed'],true)) { throw new ApplicationException(_t('The requested archive is unavailable.')); }
		$store = new \MDPNPreservation\Store($this->preservation_config); $path = $store->directory($job['id']).'/'.$part['filename'];
		if (!is_file($path) || is_link($path)) { throw new ApplicationException(_t('The requested archive is unavailable.')); }
		$this->view->setVar('archive_path',$path); $this->view->setVar('archive_name',$part['filename']);
		$this->view->render('bundles/download_file_binary.php');
	}
	public function Roadmap() {
		$id = $this->collection(); $job = $this->job($id);
		if (empty($job['result']['recovery_chain'])) { throw new ApplicationException(_t('This job has no completed recovery roadmap.')); }
		$this->response->setContentType('application/json');
		$this->view->setVar('roadmap',['format' => 'mdpn-ca-preservation-1','deposit_id' => $job['id'],'collection_id' => $id,
			'status' => $job['status'],'chain' => $job['result']['recovery_chain']]);
		$this->render('roadmap_json.php');
	}
	private function job(int $collection) : array {
		$store = new \MDPNPreservation\Store($this->preservation_config);
		$job = $store->get((string)$this->request->getParameter('job_id',pString));
		if ((int)$job['collection_id'] !== $collection) { throw new ApplicationException(_t('The preservation job belongs to another collection.')); }
		return $job;
	}
	private function back(int $id) : void { $this->response->setRedirect(caNavUrl($this->request,'MDPNLOCKSSPreservation','Preservation','Index',['collection_id' => $id])); }
}
