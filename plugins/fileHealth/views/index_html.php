<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
use FileHealth\Presentation as Health;
$summary = $this->getVar('health_summary'); $runs = $this->getVar('health_runs') ?: [];
$error = $this->getVar('health_error'); $request = $this->getVar('health_request');
$download = static function($id) use ($request) { return caNavUrl($request,'fileHealth','Health','Download',['run_id' => $id]); };
?>
<div class="file-health">
	<style>
		.file-health { max-width: 1100px; padding: 0 14px 24px; line-height: 1.5; overflow-wrap: anywhere; }
		.file-health h1 { margin-bottom: 8px; }
		.file-health h2 { margin-top: 24px; }
		.file-health a { text-decoration: underline; }
		.file-health a:focus-visible { outline: 2px solid currentColor; outline-offset: 3px; }
		.file-health .health-notice { padding: 10px 14px; border: 1px solid #b08b37; background: #fff7df; color: #4c390e; }
		.file-health .health-status { padding: 10px 14px; border: 1px solid #ccc; }
		.file-health .health-table { overflow-x: auto; }
		.file-health table { width: 100%; min-width: 700px; border-collapse: collapse; text-align: left; }
		.file-health th, .file-health td { border-bottom: 1px solid #ddd; padding: 8px; vertical-align: top; overflow-wrap: anywhere; }
		.file-health th { white-space: nowrap; }
		.file-health caption { text-align: left; padding: 0 8px 8px; }
	</style>
	<h1 id="file-health-status"><?php print _t('File health'); ?></h1>
	<p><?php print _t('Native media-integrity patrols and their reports. This page reads saved run records; opening it does not check or change files.'); ?></p>
<?php if ($error) { ?>
	<p id="file-health-history" class="health-notice" role="status"><?php print Health::escape($error); ?></p>
<?php } elseif (is_array($summary)) {
	$latest = $summary['latest_run'] ?? null; $completed = $summary['last_completed_run'] ?? null;
	$active = $summary['active_run'] ?? null;
?>
	<p><strong><?php print _t('Configured patrol scope:'); ?></strong> <?php print Health::escape(Health::scope($summary['scope'] ?? null)); ?>.</p>
	<div class="health-status">
		<p><strong><?php print _t('Latest patrol:'); ?></strong>
			<?php print Health::escape($latest ? Health::status($latest['status'] ?? null) : _t('No recorded patrol')); ?>.
			<?php if ($latest && !empty($latest['started_at'])) { print _t('Started:').' '.Health::escape(Health::timestamp($latest['started_at'])).'.'; } ?></p>
		<p><strong><?php print _t('Last completed full patrol:'); ?></strong> <?php print Health::escape(Health::timestamp($summary['last_completed_at'] ?? null)); ?>.
<?php if ($completed) { ?>
			<?php print Health::escape(Health::status($completed['status'] ?? null)); ?>.<br>
			<?php print _t('Recorded scope:'); ?> <?php print Health::escape(Health::scope($completed['scope'] ?? null)); ?>.
<?php } ?>
		</p>
	</div>
<?php if (empty($summary['last_completed_at'])) { ?>
	<p class="health-notice" role="status"><?php print _t('No completed full patrol is recorded. Existing reports alone do not establish completion or scope.'); ?></p>
<?php } ?>
<?php if (!empty($summary['overdue'])) { ?>
	<p class="health-notice" role="status"><?php print _t('A full patrol is overdue or has not completed for the configured scope. Review the scheduled patrol.'); ?></p>
<?php } ?>
<?php if (!empty($summary['worker_stale'])) { ?>
	<p class="health-notice" role="status"><?php print _t('A fresh patrol heartbeat is missing. Review the job status before relying on these results.'); ?></p>
<?php } ?>
<?php if ($active) { ?>
	<p role="status"><strong><?php print _t('Patrol in progress.'); ?></strong>
		<?php print _t('Started:'); ?> <?php print Health::escape(Health::timestamp($active['started_at'] ?? null)); ?>.
		<?php print _t('Last heartbeat:'); ?> <?php print Health::escape(Health::timestamp($active['heartbeat_at'] ?? null)); ?>.<br>
		<?php print _t('Running scope:'); ?> <?php print Health::escape(Health::scope($active['scope'] ?? null)); ?>.</p>
<?php } ?>
<?php if (!empty($summary['untracked_active'])) { ?>
	<p class="health-notice" role="status"><?php print _t('The patrol lock is in use, but no active run record is available. Start/completion details were not recorded.'); ?></p>
<?php } ?>
	<p><?php print _t('Last recorded patrol activity:'); ?> <?php print Health::escape(Health::timestamp($summary['last_worker_at'] ?? null)); ?>.</p>
	<p><?php print _t('“No errors reported” describes the native command’s result. It does not establish that every stored file was read, or confirm LOCKSS preservation, backups, or recovery.'); ?></p>
<?php if (!empty($summary['untracked_reports'])) { ?>
	<p class="health-notice"><?php print Health::integer($summary['untracked_reports']); ?> <?php print _t('untracked native report(s) found. Their completion and scope were not recorded; report dates are file dates, not patrol completion times.'); ?></p>
<?php } ?>
	<h2 id="file-health-history"><?php print _t('Recent patrols and reports'); ?></h2>
<?php if (!$runs) { ?>
	<p><?php print _t('No patrol records or native reports are available.'); ?></p>
<?php } else { ?>
	<div class="health-table" role="region" aria-label="<?php print Health::escape(_t('Recent patrols and reports')); ?>" tabindex="0">
	<table>
		<caption><?php print _t('Most recent 25 entries. All timestamps are UTC. Native reports download as private attachments.'); ?></caption>
		<thead><tr><th scope="col"><?php print _t('Started / report date'); ?></th><th scope="col"><?php print _t('Finished'); ?></th><th scope="col"><?php print _t('Outcome'); ?></th><th scope="col"><?php print _t('Scope'); ?></th><th scope="col"><?php print _t('Native report'); ?></th></tr></thead>
		<tbody>
<?php foreach ($runs as $run) { $untracked = ($run['status'] ?? '') === 'untracked'; ?>
		<tr>
			<td><?php if ($untracked) { print _t('Report date:').' '.Health::escape(Health::timestamp($run['report_at'] ?? null)); } else { print Health::escape(Health::timestamp($run['started_at'] ?? null)); } ?></td>
			<td><?php print Health::escape($untracked ? _t('Not recorded') : (empty($run['finished_at']) ? _t('Not finished') : Health::timestamp($run['finished_at']))); ?></td>
			<td><?php print Health::escape(Health::status($run['status'] ?? null)); ?><?php if ($untracked) { ?><br><?php print _t('Completion was not recorded.'); ?><?php } ?></td>
			<td><?php print Health::escape(Health::scope($run['scope'] ?? null)); ?></td>
			<td><?php if (!empty($run['report_available'])) { ?><a href="<?php print Health::escape($download($run['id'] ?? '')); ?>"><?php print _t('Download report'); ?></a><?php } else { print _t('Not available'); } ?></td>
		</tr>
<?php } ?>
		</tbody>
	</table></div>
<?php } ?>
<?php } ?>
</div>
