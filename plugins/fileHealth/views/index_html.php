<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
use FileHealth\Presentation as Health;
$summary = $this->getVar('health_summary'); $problems = $this->getVar('health_problems') ?: [];
$runs = $this->getVar('health_runs') ?: []; $history = $this->getVar('health_history') ?: [];
$issues = $this->getVar('health_issues') ?: [];
$representation = (int)$this->getVar('health_representation_id'); $offset = (int)$this->getVar('health_offset');
$error = $this->getVar('health_error'); $request = $this->getVar('health_request');
$url = static function(array $parameters=[]) use ($request) { return caNavUrl($request,'fileHealth','Health','Index',$parameters); };
?>
<div class="file-health">
	<style>
		.file-health { max-width: 1100px; padding: 0 14px 24px; line-height: 1.5; }
		.file-health h1 { margin-bottom: 8px; }
		.file-health h2 { margin-top: 24px; }
		.file-health a { text-decoration: underline; }
		.file-health a:focus-visible { outline: 2px solid currentColor; outline-offset: 3px; }
		.file-health .health-notice { padding: 10px 14px; border: 1px solid #b08b37; background: #fff7df; color: #4c390e; }
		.file-health .health-counts { display: flex; flex-wrap: wrap; gap: 8px 22px; margin: 12px 0; }
		.file-health .health-counts div { min-width: 100px; }
		.file-health .health-counts dt { font-weight: normal; }
		.file-health .health-counts dd { margin: 0; font-weight: bold; font-size: 1.25em; }
		.file-health .health-table { overflow-x: auto; }
		.file-health table { width: 100%; min-width: 620px; border-collapse: collapse; text-align: left; }
		.file-health th, .file-health td { border-bottom: 1px solid #ddd; padding: 8px; vertical-align: top; overflow-wrap: anywhere; }
		.file-health th { white-space: nowrap; }
		.file-health caption { text-align: left; padding: 0 8px 8px; }
		.file-health .health-pages { display: flex; flex-wrap: wrap; gap: 16px; margin: 12px 0; }
		.file-health .health-findings { list-style: none; margin: 0; padding: 0; }
		.file-health .health-findings > li { padding: 8px 0; border-bottom: 1px solid #ddd; overflow-wrap: anywhere; }
		.file-health .health-review-note { white-space: pre-wrap; overflow-wrap: anywhere; }
	</style>
	<h1><?php print _t('File health'); ?></h1>
	<p><?php print _t('Recorded integrity checks for representation media. This page reads saved results; opening it does not check or change files.'); ?></p>
	<p><?php print _t('Scope: representation media only. Metadata file/media attributes and uploaded multifiles are excluded. Generated previews and other derivatives are checked only when their versions are configured.'); ?></p>
<?php if ($error) { ?>
	<p class="health-notice" role="status"><?php print Health::escape($error); ?></p>
<?php } elseif (is_array($summary)) { ?>
	<p><?php print _t('Configured versions:'); ?> <?php print Health::escape(implode(', ', $summary['versions'] ?? [])); ?>.
		<?php print _t('Last completed sweep:'); ?> <strong><?php print Health::escape(Health::timestamp($summary['last_completed_at'] ?? null)); ?></strong>.
		<?php print _t('Last worker activity:'); ?> <?php print Health::escape(Health::timestamp($summary['last_worker_at'] ?? null)); ?>.</p>
<?php if (empty($summary['last_completed_at'])) { ?>
	<p class="health-notice" role="status"><?php print _t('No completed inventory is recorded. Existing counts describe discovered targets and do not establish complete repository coverage.'); ?></p>
<?php } ?>
<?php if (!empty($summary['worker_stale'])) { ?>
	<p class="health-notice" role="status"><?php print _t('The worker is overdue or has stopped reporting. Saved results may be out of date; an administrator should review the scheduled worker.'); ?></p>
<?php } ?>
<?php if (!empty($summary['sweep_overdue'])) { ?>
	<p class="health-notice" role="status"><?php print _t('A complete inventory is overdue or has not completed for the configured versions. Worker activity alone does not establish complete coverage.'); ?></p>
<?php } ?>
	<dl class="health-counts">
		<div><dt><?php print _t('Recorded targets'); ?></dt><dd><?php print Health::integer($summary['total_targets'] ?? 0); ?></dd></div>
		<div><dt><?php print _t('Unresolved findings'); ?></dt><dd><?php print Health::integer($summary['unresolved_issues'] ?? 0); ?></dd></div>
		<div><dt><?php print _t('Overdue targets'); ?></dt><dd><?php print Health::integer($summary['overdue'] ?? 0); ?></dd></div>
<?php foreach (($summary['counts'] ?? []) as $status => $count) { ?>
		<div><dt><?php print Health::escape(Health::status($status)); ?></dt><dd><?php print Health::integer($count); ?></dd></div>
<?php } ?>
	</dl>
	<p><?php print _t('Counts describe recorded targets, including earlier media versions. A verified result applies to the bytes read at its check time. It does not confirm a LOCKSS deposit, backup availability, or successful restoration. Files without a trusted checksum remain unverified.'); ?></p>
<?php if (!empty($summary['active_run'])) { $active = $summary['active_run']; ?>
	<p role="status"><strong><?php print _t('Inventory in progress.'); ?></strong>
		<?php print _t('Started:'); ?> <?php print Health::escape(Health::timestamp($active['started_at'] ?? null)); ?>.
		<?php print _t('Checked:'); ?> <?php print Health::integer($active['checked'] ?? 0); ?>.
		<?php print _t('Problems:'); ?> <?php print Health::integer($active['problems'] ?? 0); ?>.
		<?php print _t('Last heartbeat:'); ?> <?php print Health::escape(Health::timestamp($active['heartbeat_at'] ?? null)); ?>.</p>
<?php } ?>
	<h2><?php print _t('Files requiring attention'); ?></h2>
<?php if (!$problems) { ?>
	<p><?php print _t('No recorded problems on this page. Review inventory coverage and worker activity above before interpreting this result.'); ?></p>
<?php } else { ?>
	<div class="health-table" role="region" aria-label="<?php print Health::escape(_t('Files requiring attention')); ?>" tabindex="0">
	<table>
		<caption><?php print _t('Unverified targets and unresolved findings. Up to 50 results per page.'); ?></caption>
		<thead><tr><th scope="col"><?php print _t('Representation'); ?></th><th scope="col"><?php print _t('Version'); ?></th><th scope="col"><?php print _t('Finding'); ?></th><th scope="col"><?php print _t('Last check'); ?></th></tr></thead>
		<tbody>
<?php foreach ($problems as $problem) { $id = max(0,(int)($problem['representation_id'] ?? 0)); ?>
		<tr><td>
			<?php if ($id) { ?><a href="<?php print Health::escape(caEditorUrl($request,'ca_object_representations',$id)); ?>"><?php print Health::escape(($problem['idno'] ?? '') ?: '#'.$id); ?></a><?php } ?>
			<?php print Health::escape($problem['label'] ?? ''); ?>
			<?php if ($id) { ?><br><a href="<?php print Health::escape($url(['representation_id' => $id])); ?>"><?php print _t('Check history'); ?></a><?php } ?>
		</td><td><?php print Health::escape($problem['version'] ?? ''); ?></td><td>
			<strong><?php print _t('Finding'); ?> #<?php print max(0,(int)($problem['issue_id'] ?? 0)); ?></strong><br>
			<?php print Health::escape(Health::status($problem['status'] ?? '')); ?><br><?php print Health::escape(Health::reason($problem['reason'] ?? '')); ?>
			<?php if (!(int)($problem['is_current'] ?? 1)) { ?><br><strong><?php print _t('Earlier file generation.'); ?></strong> <?php print _t('The current file has a separate identity; this finding remains open for review.'); ?><?php } ?>
		</td><td><?php print Health::escape(Health::timestamp($problem['checked_at'] ?? null)); ?></td></tr>
<?php } ?>
		</tbody>
	</table></div>
<?php } ?>
<?php if ($offset || count($problems) === 50) { ?>
	<nav class="health-pages" aria-label="<?php print Health::escape(_t('Problem pages')); ?>">
		<?php if ($offset) { ?><a href="<?php print Health::escape($url(['offset' => max(0,$offset-50)])); ?>"><?php print _t('Previous problems'); ?></a><?php } ?>
		<?php if (count($problems) === 50) { ?><a href="<?php print Health::escape($url(['offset' => $offset+50])); ?>"><?php print _t('More problems'); ?></a><?php } ?>
	</nav>
<?php } ?>
<?php if ($representation) { ?>
	<h2><?php print _t('Check history for representation'); ?> #<?php print $representation; ?></h2>
	<p><a href="<?php print Health::escape($url()); ?>"><?php print _t('Back to file health'); ?></a></p>
<?php if (!$history) { ?>
	<p><?php print _t('No recorded checks for this representation.'); ?></p>
<?php } else { ?>
	<div class="health-table" role="region" aria-label="<?php print Health::escape(_t('Representation check history')); ?>" tabindex="0"><table>
		<caption><?php print _t('Most recent 50 recorded checks, including earlier file versions.'); ?></caption>
		<thead><tr><th scope="col"><?php print _t('Checked at'); ?></th><th scope="col"><?php print _t('Version'); ?></th><th scope="col"><?php print _t('Recorded MD5'); ?></th><th scope="col"><?php print _t('Result'); ?></th></tr></thead><tbody>
<?php foreach ($history as $check) { ?>
		<tr><td><?php print Health::escape(Health::timestamp($check['checked_at'] ?? null)); ?></td><td><?php print Health::escape($check['version'] ?? ''); ?></td><td><code><?php print Health::escape(Health::checksum($check['expected_md5'] ?? null)); ?></code></td><td><?php print Health::escape(Health::status($check['status'] ?? '')); ?><br><?php print Health::escape(Health::reason($check['reason'] ?? '')); ?></td></tr>
<?php } ?>
	</tbody></table></div>
<?php } ?>
	<h2><?php print _t('Finding and review history'); ?></h2>
<?php if (!$issues) { ?>
	<p><?php print _t('No recorded findings for this representation.'); ?></p>
<?php } else { ?>
	<p><?php print _t('Most recent 50 findings. Reviews preserve the earlier checks and their recorded file identities.'); ?></p>
	<ul class="health-findings">
<?php foreach ($issues as $issue) { ?>
		<li>
			<h3><?php print _t('Finding'); ?> #<?php print max(0,(int)($issue['issue_id'] ?? 0)); ?>: <?php print Health::escape(Health::status($issue['status'] ?? '')); ?></h3>
			<p><?php print _t('Version:'); ?> <?php print Health::escape($issue['version'] ?? ''); ?>.
				<?php print _t('Recorded MD5:'); ?> <code><?php print Health::escape(Health::checksum($issue['expected_md5'] ?? null)); ?></code>.<br>
				<?php print _t('Last finding check:'); ?> <?php print Health::escape(Health::timestamp($issue['checked_at'] ?? null)); ?>.
				<?php if (!(int)($issue['is_current'] ?? 1)) { ?><strong><?php print _t('Earlier file generation.'); ?></strong><?php } ?></p>
			<p><?php print Health::escape(Health::reason($issue['reason'] ?? '')); ?></p>
<?php if (!empty($issue['resolved_at'])) { ?>
			<p><strong><?php print _t('Resolved:'); ?></strong> <?php print Health::escape(Health::timestamp($issue['resolved_at'])); ?><br>
				<span class="health-review-note"><?php print Health::escape($issue['resolution'] ?? ''); ?></span></p>
<?php } else { ?>
			<p><strong><?php print _t('Open finding'); ?></strong></p>
<?php } ?>
<?php if (!empty($issue['reviews'])) { ?>
			<ul>
<?php foreach ($issue['reviews'] as $review) { ?>
				<li><?php print _t('Administrator review by backend user'); ?> #<?php print max(0,(int)($review['user_id'] ?? 0)); ?>,
					<?php print Health::escape(Health::timestamp($review['reviewed_at'] ?? null)); ?>
					<p class="health-review-note"><?php print Health::escape($review['note'] ?? ''); ?></p></li>
<?php } ?>
			</ul>
<?php } ?>
		</li>
<?php } ?>
	</ul>
<?php } ?>
<?php } ?>
	<h2><?php print _t('Recent inventories'); ?></h2>
<?php if (!$runs) { ?>
	<p><?php print _t('No inventory runs recorded.'); ?></p>
<?php } else { ?>
	<div class="health-table" role="region" aria-label="<?php print Health::escape(_t('Recent inventories')); ?>" tabindex="0"><table>
		<caption><?php print _t('Most recent 10 inventory runs. All timestamps are UTC.'); ?></caption>
		<thead><tr><th scope="col"><?php print _t('Started'); ?></th><th scope="col"><?php print _t('Finished'); ?></th><th scope="col"><?php print _t('Status'); ?></th><th scope="col"><?php print _t('Checked'); ?></th><th scope="col"><?php print _t('Problems'); ?></th></tr></thead><tbody>
<?php foreach ($runs as $run) { ?>
		<tr><td><?php print Health::escape(Health::timestamp($run['started_at'] ?? null)); ?></td><td><?php print Health::escape(empty($run['finished_at']) ? _t('Not finished') : Health::timestamp($run['finished_at'])); ?></td><td><?php print Health::escape(Health::status($run['status'] ?? '')); ?></td><td><?php print Health::integer($run['checked'] ?? 0); ?></td><td><?php print Health::integer($run['problems'] ?? 0); ?></td></tr>
<?php } ?>
	</tbody></table></div>
<?php } ?>
<?php } ?>
</div>
