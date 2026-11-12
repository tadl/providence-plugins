<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace FileHealth;
require_once __DIR__.'/Scheduler.php';

/** All media reads occur in the explicitly scheduled CLI, never a widget request. */
final class Tool {
	private $config;
	private $root;
	public function __construct(Config $config, string $root) { $this->config=$config; $this->root=$root; }
	public static function commands() : array {
		$user = ['user-id|u=s' => _t('Active backend administrator ID; native permissions are checked.')];
		$apply = ['apply' => _t('Write File Health history. No catalogue or media changes are made.')];
		return ['fileHealth' => [
			'file_health_init' => ['Command' => 'file-health-init','Options' => $user+$apply,'ShortHelp' => _t('Initialize private File Health history.'),'Help' => _t('Initialize private SQLite storage outside the application and source checkout. Requires --apply and --user-id.')],
			'file_health_check' => ['Command' => 'file-health-check','Options' => $user+$apply+['restart' => _t('Supersede any active sweep and begin a new full sweep now.')],'ShortHelp' => _t('Advance the scheduled native fixity sweep.'),'Help' => _t('Check a bounded batch using native check-media-fixity and save results. Resumes interrupted sweeps; starts a new sweep when due. Requires --apply and --user-id. Schedule this command separately with cron or a timer. Use --restart manually after repairs; never include it in the recurring schedule.')],
			'file_health_status' => ['Command' => 'file-health-status','Options' => $user,'ShortHelp' => _t('Read saved File Health status as JSON.'),'Help' => _t('Read saved status without scanning files, initializing storage or updating the scheduler heartbeat.')],
			'file_health_verify_candidate' => ['Command' => 'file-health-verify-candidate','Options' => $user+['issue-id=s' => _t('Saved issue with the original expected checksum.'),'file=s' => _t('Absolute local path to a backup or replacement candidate.')],
				'ShortHelp' => _t('Verify replacement bytes against a saved issue checksum.'),'Help' => _t('Read a candidate file and compare it with the upload checksum frozen in an issue. Does not upload, restore, change baselines, or resolve issues.')],
			'file_health_review' => ['Command' => 'file-health-review','Options' => $user+$apply+['issue-id=s' => _t('Open issue to review.'),'note=s' => _t('Required explanation of an intentional replacement or reviewed repair.')],
				'ShortHelp' => _t('Record administrator review of a replaced file.'),'Help' => _t('Close an old issue only after a recent successful native check of its current file and an explicit review note. Retains all original checks and expected hashes.')]
		]];
	}
	public static function authorize($user) : void {
		if (!$user || !$user->getPrimaryKey() || !$user->get('active') || !$user->canDoAction('is_administrator')) { throw new Failure('An active backend administrator is required.'); }
		if (defined('__CA_DISABLE_ACL__') && __CA_DISABLE_ACL__) { throw new Failure('Run File Health directly, with native ACL checks enabled.'); }
		$GLOBALS['AUTH_CURRENT_USER_ID'] = (int)$user->getPrimaryKey();
	}
	private static function positive($value) : int {
		if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D',$value) || !($id = filter_var($value,FILTER_VALIDATE_INT))) { throw new Failure('A positive integer ID is required.'); }
		return $id;
	}
	public function run($command, $settings) : bool {
		try {
			if (!$this->config->enabled()) { throw new Failure('File Health is disabled.'); }
			$userId = self::positive($settings['user-id'] ?? null);
			require_once __CA_MODELS_DIR__.'/ca_users.php';
			self::authorize(new \ca_users($userId));
			$command = str_replace('-','_',$command);
			if ($command === 'file_health_status') {
				$store = new Store($this->config,false,true);
				$this->output(['summary' => $store->summary(),'problems' => $store->problems(),'runs' => $store->recentRuns()]); return true;
			}
			if ($command === 'file_health_verify_candidate') {
				$store = new Store($this->config,false,true);
				$result = self::verifyCandidate($store->issue(self::positive($settings['issue-id'] ?? null)),(string)($settings['file'] ?? ''));
				$this->output($result); return $result['verified'];
			}
			if (empty($settings['apply'])) { throw new Failure('This operation requires --apply; no action was taken.'); }
			if ($command === 'file_health_init') { new Store($this->config,true); $this->output(['initialized' => true]); return true; }
			$store = new Store($this->config);
			if ($command === 'file_health_review') {
				$store->reviewIssue(self::positive($settings['issue-id'] ?? null),$userId,(string)($settings['note'] ?? ''));
				$this->output(['reviewed' => true]); return true;
			}
			if ($command !== 'file_health_check') { throw new Failure('Unknown File Health command.'); }
			require_once __DIR__.'/NativeChecker.php';
			$catalogue = new Catalogue();
			$result = (new Scheduler($this->config,$store,$catalogue,new NativeChecker($catalogue)))->run($userId,!empty($settings['restart']));
			$this->output($result);
			return $result['summary']['unresolved_issues'] === 0;
		} catch (\Throwable $e) {
			// PDO/native failures may contain private server paths or record values.
			\CLIUtils::addError($e instanceof Failure ? $e->getMessage() : _t('File Health could not complete. Check private storage, database access and installed runtime compatibility.'));
			return false;
		}
	}
	private function output(array $value) : void { print json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n"; }
	public static function verifyCandidate(array $issue, string $path) : array {
		$expected = $issue['expected_md5'] ?? '';
		if (!is_string($expected) || !preg_match('/^[a-f0-9]{32}$/Di',$expected)) { throw new Failure('This issue has no usable recorded upload checksum.'); }
		if ($path === '' || $path[0] !== '/' || strpos($path,"\0") !== false) { throw new Failure('An absolute local candidate path is required.'); }
		clearstatcache(true,$path); $pathBefore = @stat($path);
		if (!$pathBefore || ($pathBefore['mode'] & 0170000) !== 0100000) { throw new Failure('The candidate must be a regular local file.'); }
		$handle = @fopen($path,'rb');
		if (!$handle) { throw new Failure('The candidate cannot be read.'); }
		try {
			$before = fstat($handle);
			if (!$before || ($before['mode'] & 0170000) !== 0100000) { throw new Failure('The candidate must be a regular local file.'); }
			foreach (['dev','ino','size','mtime','ctime'] as $field) { if ($before[$field] !== $pathBefore[$field]) { throw new Failure('The candidate changed before verification.'); } }
			$hash = hash_init('md5');
			$bytes = hash_update_stream($hash,$handle); $actual = hash_final($hash); $after = fstat($handle);
			foreach (['dev','ino','size','mtime','ctime'] as $field) { if ($before[$field] !== $after[$field]) { throw new Failure('The candidate changed during verification.'); } }
			clearstatcache(true,$path); $pathAfter = @stat($path);
			if (!$pathAfter) { throw new Failure('The candidate changed during verification.'); }
			foreach (['dev','ino','size','mtime','ctime'] as $field) { if ($after[$field] !== $pathAfter[$field]) { throw new Failure('The candidate path changed during verification.'); } }
			if ($bytes !== $before['size']) { throw new Failure('The candidate could not be read completely.'); }
			return ['issue_id' => (int)$issue['issue_id'],'representation_id' => (int)$issue['representation_id'],'version' => $issue['version'],
				'verified' => hash_equals(strtolower($expected),$actual),'expected_md5' => strtolower($expected),'actual_md5' => $actual,'bytes' => $bytes];
		} finally { fclose($handle); }
	}
}
