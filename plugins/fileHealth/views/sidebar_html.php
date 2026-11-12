<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
use FileHealth\Presentation as Health;
$request = $this->getVar('health_request');
$overview = caNavUrl($request,'fileHealth','Health','Index');
$dashboard = caNavUrl($request,'','Dashboard','Index');
?>
<nav aria-label="<?php print Health::escape(_t('File health')); ?>">
	<h3><?php print _t('File health'); ?></h3>
	<h2><a class="sf-menu-selected" aria-current="page" href="<?php print Health::escape($overview.'#file-health-status'); ?>"><?php print _t('Patrol status'); ?></a></h2>
	<h2><a href="<?php print Health::escape($overview.'#file-health-history'); ?>"><?php print _t('Check history'); ?></a></h2>
	<h2><a href="<?php print Health::escape($dashboard); ?>"><?php print _t('Dashboard'); ?></a></h2>
</nav>
