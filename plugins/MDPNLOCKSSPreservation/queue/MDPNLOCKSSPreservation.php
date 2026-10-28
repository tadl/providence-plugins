<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
require_once __CA_LIB_DIR__.'/Plugins/WLPlug.php';
require_once __CA_LIB_DIR__.'/Plugins/IWLPlugTaskQueueHandler.php';
require_once __DIR__.'/../lib/Service.php';

class WLPlugTaskQueueHandlerMDPNLOCKSSPreservation extends WLPlug implements IWLPlugTaskQueueHandler {
	public $error;
	public function __construct() { $this->error = new ApplicationError(); $this->error->setErrorOutput(0); }
	public function getHandlerName() { return _t('Collection preservation'); }
	public function getParametersForDisplay($record) { return []; }
	public function process($parameters) {
		try {
			$config = \MDPNPreservation\Config::load(dirname(__DIR__));
			$store = new \MDPNPreservation\Store($config); $id = (string)($parameters['job_id'] ?? '');
			$store->get($id); $directory = $store->directory($id,true);
			// process-task-queue defines __CA_DISABLE_ACL__ globally. A fresh PHP
			// process is necessary: PHP constants cannot be safely reset in-process.
			if (PHP_SAPI !== 'cli') { throw new \RuntimeException('Preservation requires the CLI queue worker.'); }
			$old = umask(0077);
			try {
				$process = proc_open([PHP_BINARY,dirname(__DIR__).'/worker/run.php',$id,__CA_BASE_DIR__],
					[0 => ['file','/dev/null','r'],1 => ['file',$directory.'/php-worker-output.txt','w'],2 => ['file',$directory.'/php-worker-error.txt','w']],$pipes);
			} finally { umask($old); }
			if (!is_resource($process)) { throw new \RuntimeException('Cannot start the isolated preservation worker.'); }
			$exit = proc_close($process); $job = $store->get($id);
			if ($exit !== 0 && in_array($job['status'],['queued','running'],true)) {
				try { $lock = $store->lock((int)$job['collection_id']); } catch (\MDPNPreservation\Failure $e) { $lock = null; }
				if ($lock) {
					try { $store->update($id,['status' => 'failed','error' => 'The isolated PHP worker stopped. An administrator should review its private diagnostics.']); }
					finally { flock($lock,LOCK_UN); fclose($lock); }
					$job = $store->get($id);
				}
			}
			$result = ['status' => $job['status']];
			if ($result['status'] !== 'failed') { return $result; }
		} catch (Throwable $e) { /* Private job history carries controlled diagnostics. */ }
		$this->error = new ApplicationError(1,_t('Preservation failed; review the collection preservation history.'),'MDPNLOCKSSPreservation');
		return false;
	}
	public function cancel($task_id,$parameters) {
		try {
			$config = \MDPNPreservation\Config::load(dirname(__DIR__)); $store = new \MDPNPreservation\Store($config);
			$job = $store->get((string)($parameters['job_id'] ?? '')); $lock = $store->lock((int)$job['collection_id']);
			try { if ($job['status'] === 'queued') { $store->update($job['id'],['status' => 'cancelled']); } }
			finally { flock($lock,LOCK_UN); fclose($lock); }
			return true;
		} catch (Throwable $e) { return false; }
	}
}
