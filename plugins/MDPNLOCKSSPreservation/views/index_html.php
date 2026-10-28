<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
$escape = static function($value) { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); };
$id = (int)$this->getVar('collection_id'); $history = $this->getVar('history'); $config = $this->getVar('preservation_config');
$baseline = $this->getVar('baseline'); $error = $this->getVar('preservation_error'); $active = false;
foreach ($history as $job) { if (in_array($job['status'],['queued','running'],true)) { $active = true; } }
$form = function($action,$name) use ($id) {
	return caFormTag($this->request,$action,$name,'MDPNLOCKSSPreservation/Preservation','post','multipart/form-data','_top',['disableUnsavedChangesWarning' => true]).'<input type="hidden" name="collection_id" value="'.$id.'">';
};
?>
<div class="mdpn-preservation" style="max-width:1100px;padding:1em">
<h1><?= $escape(_t('Preservation: %1',$this->getVar('collection_label'))) ?></h1>
<p><?= caNavLink($this->request,_t('Return to collection'),'','editor/collections','CollectionEditor','Edit',['collection_id' => $id]) ?> · <?= caNavLink($this->request,_t('Refresh status'),'','MDPNLOCKSSPreservation','Preservation','Index',['collection_id' => $id]) ?></p>
<?php if ($error): ?><p role="alert"><?= $escape($error) ?></p><?php endif; ?>
<p><?= $escape(_t('Includes the complete collection hierarchy, all metadata and original files, including non-public content. Upload to staging and confirmation of LOCKSS preservation are recorded separately.')) ?></p>
<?php if ($config->get('transport','local') === 'local'): ?>
<p><strong><?= $escape(_t('Local testing: packages are created on this server. No files are sent to a preservation service.')) ?></strong></p>
<?php endif; ?>
<?php if ($baseline): ?>
<p><?= $escape(_t('Latest complete deposit for this destination: revision %1 (%2), %3.',$baseline['revision'],$baseline['status'],$baseline['updated_at'])) ?></p>
<?php else: ?><p><?= $escape(_t('No complete deposit for this destination. Begin with a full deposit.')) ?></p><?php endif; ?>
<?php foreach ($history as $scan): if ($scan['status'] !== 'scanned' || $scan['target'] !== $config->target()) { continue; } ?>
<?php if ($baseline && $scan['result']['compared_to_deposit_id'] !== $baseline['id']): ?>
<p><?= $escape(_t('The latest scan predates the current deposit. Run a fresh scan to compare current content.')) ?></p>
<?php else: ?>
<p><?= $escape(_t('Latest scan: %1. %2 change entries. Scans describe the captured state; run another scan after editing.',$scan['result']['captured_at'],$scan['result']['change_count'])) ?></p>
<?php endif; ?>
<?php break; endforeach; ?>
<?php if (!$error && !$active): ?>
<h2><?= $escape(_t('Create a deposit')) ?></h2>
<?= $form('Submit','mdpnCreateDeposit') ?>
<?php foreach (['source_organization' => _t('Source organization'),'contact_name' => _t('Contact name'),'contact_email' => _t('Contact email')] as $field => $label): ?>
<p><label for="mdpn-<?= $escape($field) ?>"><?= $escape($label) ?> *</label><br><input id="mdpn-<?= $escape($field) ?>" name="<?= $escape($field) ?>" type="<?= $field === 'contact_email' ? 'email' : 'text' ?>" required maxlength="500" value="<?= $escape($config->get($field,'')) ?>" style="width:100%;max-width:38em"></p>
<?php endforeach; ?>
<p><label for="mdpn-description"><?= $escape(_t('Deposit description (optional)')) ?></label><br><input id="mdpn-description" name="description" type="text" maxlength="2000" value="<?= $escape($this->getVar('default_description')) ?>" style="width:100%;max-width:38em"></p>
<p><label for="mdpn-mode"><?= $escape(_t('Deposit type')) ?></label><br><select id="mdpn-mode" name="mode"><?php if ($baseline): ?><option value="supplemental"><?= $escape(_t('Supplement the latest complete deposit')) ?></option><?php endif; ?><option value="full"><?= $escape(_t('Create new full deposit')) ?></option></select></p>
<p><button type="submit"><?= $escape($config->get('transport','local') === 'local' ? _t('Create local preservation package') : _t('Create and send preservation deposit')) ?></button></p>
</form>
<?= $form('Submit','mdpnScanCollection') ?><input type="hidden" name="mode" value="scan"><p><button type="submit"><?= $escape(_t('Scan for changes without creating bags')) ?></button></p></form>
<?php elseif ($active): ?><p role="status"><?= $escape(_t('A job is queued or running. Refresh this page to follow its progress; processing continues when you close the browser.')) ?></p><?php endif; ?>
<h2><?= $escape(_t('History')) ?></h2>
<?php if (!$history): ?><p><?= $escape(_t('No preservation jobs yet.')) ?></p><?php endif; ?>
<?php foreach ($history as $job): ?>
<article style="border-top:1px solid #aaa;padding:1em 0;overflow-wrap:anywhere">
<h3><?= $escape($job['mode'] === 'scan' ? _t('Collection scan') : _t('Revision %1: %2',$job['revision'],$job['mode'])) ?></h3>
<p><?= $escape($job['status'].' · '.$job['created_at']) ?><br><small><?= $escape($job['id']) ?></small></p>
<?php if ($job['error']): ?><p role="alert"><?= $escape($job['error']) ?></p><?php endif; ?>
<?php if ($job['result']): ?>
<p><?= $escape(_t('%1 original files; %2 change entries.',$job['result']['files'],$job['result']['change_count'])) ?></p>
<?php if (!empty($job['result']['recovery_chain'])): ?>
<p><?= $escape(_t('Recovery requires one full deposit and %1 supplements.',count($job['result']['recovery_chain'])-1)) ?> <?= caNavLink($this->request,_t('View recovery roadmap'),'','MDPNLOCKSSPreservation','Preservation','Roadmap',['collection_id' => $id,'job_id' => $job['id']]) ?></p>
<?php endif; ?>
<?php foreach ($job['result']['parts'] as $n => $part): ?>
<p><?= caNavLink($this->request,$escape($part['filename']),'','MDPNLOCKSSPreservation','Preservation','Download',['collection_id' => $id,'job_id' => $job['id'],'part' => $n]) ?><br><small>SHA-256: <?= $escape($part['sha256']) ?></small></p>
<?php endforeach; ?>
<details><summary><?= $escape(_t('Changes (first 200; complete list is in each bag)')) ?></summary><ul><?php foreach ($job['result']['changes'] as $change): ?><li><?= $escape($change['category'].' · '.$change['key'].' · '.$change['operation']) ?></li><?php endforeach; ?></ul></details>
<?php endif; ?>
<?php if ($job['status'] === 'failed' && !empty($job['result']['parts']) && !$active): ?>
<?= $form('Retry','mdpnRetry'.$job['id']) ?><input type="hidden" name="job_id" value="<?= $escape($job['id']) ?>"><p><button type="submit"><?= $escape(_t('Retry transfer of retained package')) ?></button> <?= $escape(_t('(Available only if packaging completed.)')) ?></p></form>
<?php endif; ?>
<?php if ($job['status'] === 'uploaded' && $this->request->user->canDoAction('can_confirm_preservation')): ?>
<?= $form('Confirm','mdpnConfirm'.$job['id']) ?><input type="hidden" name="job_id" value="<?= $escape($job['id']) ?>"><p><label for="mdpn-reference-<?= $escape($job['id']) ?>"><?= $escape(_t('Confirmation reference: verified LOCKSS crawl / node evidence')) ?></label><br><input type="text" id="mdpn-reference-<?= $escape($job['id']) ?>" name="reference" required maxlength="2000"><button type="submit"><?= $escape(_t('Record preservation confirmation')) ?></button></p></form>
<?php endif; ?>
<?php if ($job['confirmation']): ?><p><?= $escape(_t('Preservation confirmation recorded %1: %2',$job['confirmation']['recorded_at'],$job['confirmation']['reference'])) ?></p><?php endif; ?>
</article>
<?php endforeach; ?>
</div>
