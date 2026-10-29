<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace MDPNPreservation;
require_once __DIR__.'/Service.php';

final class Tool {
	private $config;
	private $root;
	public function __construct(Config $config, string $root) { $this->config = $config; $this->root = $root; }
	public function run($command, $settings) : bool {
		try {
			if (in_array($command,['mdpn_preservation_report','mdpn-preservation-report'],true)) {
				require_once __DIR__.'/Report.php'; require_once __CA_MODELS_DIR__.'/ca_users.php';
				$format = (string)(($settings['format'] ?? '') ?: 'text');
				if (!in_array($format,['text','json'],true)) { throw new Failure('Report format must be text or json.'); }
				$report = (new Report($this->config,new Store($this->config,false,true),$this->root))->run(new \ca_users((int)($settings['user-id'] ?? 0)),!empty($settings['history-only']));
				print Report::render($report,$format);
				return !$report['summary']['errors'] && !$report['warnings'] && (empty($settings['fail-on-attention']) || !$report['summary']['attention_required']);
			}
			if (empty($settings['apply'])) { throw new Failure('This operation requires --apply; no action was taken.'); }
			if ($command === 'mdpn_preservation_init' || $command === 'mdpn-preservation-init') {
				$store = new Store($this->config,true);
				print Store::json(['initialized' => true,'repository_id' => $store->repositoryId()])."\n"; return true;
			}
			$store = new Store($this->config); $service = new Service($this->config,$store,$this->root);
			if (in_array($command,['mdpn_preservation_process_queue','mdpn-preservation-process-queue'],true)) {
				require_once __CA_LIB_DIR__.'/TaskQueue.php';
				$queue = new \TaskQueue();
				// The general --tasks option examines only the first pending task;
				// the handler argument filters the native SQL selection itself.
				if (!$queue->processQueue(Service::QUEUE_HANDLER) || $queue->numErrors()) { throw new Failure('The preservation queue reported an error. Review private diagnostics and collection preservation history.'); }
				print "Preservation queue processed.\n"; return true;
			}
			if (in_array($command,['mdpn_preservation_create','mdpn-preservation-create'],true)) {
				require_once __CA_MODELS_DIR__.'/ca_users.php';
				$user = new \ca_users((int)($settings['user-id'] ?? 0));
				$job = $service->enqueue((int)($settings['collection-id'] ?? 0),(string)(($settings['mode'] ?? '') ?: 'full'),$user,
					array_filter(['contact_name' => $settings['contact-name'] ?? null,'contact_email' => $settings['contact-email'] ?? null,'description' => $settings['description'] ?? null],function($v) { return $v !== null && $v !== ''; }));
				print Store::json(['job_id' => $job['id'],'status' => $job['status']])."\n"; return true;
			}
			$id = (string)($settings['job'] ?? '');
			if (in_array($command,['mdpn_preservation_run','mdpn-preservation-run'],true)) {
				$result = $service->run($id); print Store::json($result)."\n"; return $result['status'] !== 'failed';
			}
			if (in_array($command,['mdpn_preservation_reset','mdpn-preservation-reset'],true)) {
				$job = $store->get($id); $lock = $store->lock((int)$job['collection_id']);
				try {
					if (!in_array($job['status'],['queued','running'],true)) { throw new Failure('Only abandoned queued/running jobs can be reset.'); }
					$store->update($id,['status' => 'failed','error' => 'An operator reset this abandoned job. Create a new deposit, or retry a retained completed package.']);
				} finally { flock($lock,LOCK_UN); fclose($lock); }
				print "Abandoned job marked failed.\n"; return true;
			}
			throw new Failure('Unknown preservation command.');
		} catch (\Throwable $e) {
			fwrite(STDERR,$e instanceof Failure ? $e->getMessage()."\n" : "Preservation command failed. Check private configuration.\n"); return false;
		}
	}
}
