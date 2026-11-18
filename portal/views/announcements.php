<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$accounts = portal_accounts();
$rows = portal_announcements($accounts, 40);
$outages = [];
foreach ($accounts as $a) {
    foreach (portal_outages((int)$a['id']) as $o) {
        $outages[(int)$o['id']] = $o;
    }
}
portal_start('Announcements');
?>
<?php foreach ($outages as $o): ?>
  <div class="pp-alert pp-alert-warn pp-reveal"><i class="bi bi-cone-striped"></i><div><strong><?= (int)$o['is_planned'] === 1 ? 'Scheduled maintenance' : 'Service interruption' ?> affecting you: <?= h($o['title']) ?></strong>
    Since <?= h(fdatetime($o['started_at'])) ?>. <?= h(IspOutages::STATUSES[$o['status']] ?? '') ?>.</div></div>
<?php endforeach; ?>
<div class="pp-card pp-reveal">
<?php if (!$rows): ?><?= portal_empty('No announcements right now.', 'megaphone') ?><?php endif; ?>
<?php foreach ($rows as $n): ?>
  <article class="pp-ann">
    <h2><?= h($n['title']) ?></h2>
    <div class="pp-row-sub"><?= h(fdatetime($n['starts_at'])) ?><?= $n['ends_at'] ? ' until ' . h(fdatetime($n['ends_at'])) : '' ?></div>
    <p><?= nl2br(h($n['body'])) ?></p>
  </article>
<?php endforeach; ?>
</div>
<?php
portal_end();
