<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
require_once __DIR__.'/lib/Config.php';

class MDPNLOCKSSPreservationPlugin extends BaseApplicationPlugin {
	private $config;
	public function __construct($path) {
		$this->description = _t('Preserve complete collection hierarchies in recoverable BagIt deposits for MDPN and other LOCKSS staging services.');
		$this->config = \MDPNPreservation\Config::load($path);
		parent::__construct();
	}
	public function checkStatus() { return ['description' => $this->getDescription(),'errors' => [],'warnings' => [],'available' => $this->config->enabled()]; }
	public static function getRoleActionList() {
		return [
			'can_preserve_collections' => ['label' => _t('Can preserve complete collections'),
				'description' => _t('Export all metadata and original files, including non-public values, for accessible collection hierarchies. Native member record ACLs must also permit reading.')],
			'can_confirm_preservation' => ['label' => _t('Can record LOCKSS preservation confirmation'),
				'description' => _t('Record manual confirmation that a submitted deposit has been preserved by LOCKSS nodes.')]
		];
	}
	public function hookAppendToEditorInspector($params) {
		if (!$this->config->enabled()) { return $params; }
		$item = $params['t_item'] ?? null; $request = $this->getRequest();
		if (!$item || $item->tableName() !== 'ca_collections' || !$item->getPrimaryKey() || !$request || !$request->user->canDoAction('can_preserve_collections')) { return $params; }
		$url = caNavUrl($request,'MDPNLOCKSSPreservation','Preservation','Index',['collection_id' => (int)$item->getPrimaryKey()]);
		$params['caEditorInspectorAppend'] = ($params['caEditorInspectorAppend'] ?? '').'<h2 class="mdpn-preservation-inspector"><a href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">'.htmlspecialchars(_t('Preservation'),ENT_QUOTES,'UTF-8').'</a></h2>';
		return $params;
	}
	public function hookRegisterTaskQueuePluginDirectories($params) {
		$params['handler_plugin_directories'][] = __DIR__.'/queue'; return $params;
	}
	public function hookCLICaUtilsGetCommands($commands) {
		$commands['MDPNLOCKSSPreservation'] = [
			'mdpn_preservation_process_queue' => ['Command' => 'mdpn-preservation-process-queue','Options' => ['apply' => _t('Process pending preservation tasks.')],
				'Help' => _t('Process only MDPNLOCKSSPreservation tasks using the native queue handler filter. Requires --apply. Each isolated worker rechecks the original requester permissions. No recurring or unrelated tasks run.'),
				'ShortHelp' => _t('Process the collection preservation queue.')],
			'mdpn_preservation_report' => ['Command' => 'mdpn-preservation-report','Options' => ['user-id|u=s' => _t('Backend administrator ID with preservation permission.'),
				'format|f=s' => _t('text (default) or json; use --quiet for JSON.'),'history-only' => _t('Report recorded history without claiming content is current.'),
				'fail-on-attention' => _t('Exit nonzero when any collection needs attention.')],
				'Help' => _t('Read-only status for all non-deleted collections at the configured destination. Checks fresh metadata and SHA-256 by default. Never queues, packages, uploads or changes confirmations.'),
				'ShortHelp' => _t('Report collection preservation status and outdated deposits.')],
			'mdpn_preservation_init' => ['Command' => 'mdpn-preservation-init','Options' => ['apply' => _t('Initialize private preservation storage.')],
				'Help' => _t('Initialize the private SQLite ledger in an existing 0700 directory outside the web root. Requires --apply.'),'ShortHelp' => _t('Initialize preservation storage.')],
			'mdpn_preservation_create' => ['Command' => 'mdpn-preservation-create','Options' => ['apply' => _t('Queue the requested preservation operation.'),
				'collection-id|c=s' => _t('Collection database ID.'),'user-id|u=s' => _t('Requesting backend user ID; permissions are checked.'),
				'mode|m=s' => _t('scan, full or supplemental.'),'contact-name|n=s' => _t('Contact name.'),'contact-email|e=s' => _t('Contact email.'),'description|d=s' => _t('Deposit description.')],
				'Help' => _t('Queue a complete collection scan or deposit. Requires --apply and an authorized --user-id.'),'ShortHelp' => _t('Queue collection preservation.')],
			'mdpn_preservation_run' => ['Command' => 'mdpn-preservation-run','Options' => ['apply' => _t('Run a queued job.'),'job|j=s' => _t('Preservation job identifier.')],
				'Help' => _t('Run one queued preservation job in the foreground. The worker rechecks the original requester permissions.'),'ShortHelp' => _t('Run a queued preservation job.')],
			'mdpn_preservation_reset' => ['Command' => 'mdpn-preservation-reset','Options' => ['apply' => _t('Mark an abandoned job failed.'),'job|j=s' => _t('Preservation job identifier.')],
				'Help' => _t('Mark an abandoned queued/running job failed only when no worker holds its collection lock. Review worker state first.'),'ShortHelp' => _t('Release an abandoned preservation job.')]
		];
		return $commands;
	}
	public function hookCLICaUtilsGetToolWithSettings($params) {
		if (($params[0] ?? null) === 'MDPNLOCKSSPreservation') {
			require_once __DIR__.'/lib/Tool.php';
			$params['tool'] = new \MDPNPreservation\Tool($this->config,__DIR__);
		}
		return $params;
	}
}
