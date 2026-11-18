<?php
declare(strict_types=1);

/**
 * Notifications (SMS / E-mail): the providers (credentials are encrypted and never shown again), a test message,
 * and the delivery log with its retries. Nothing is sent without a configured provider: such messages are logged as
 * skipped with the reason.
 */
$pk = 'ispset.notifications';
const ISPSET_SMS_PROVIDERS = ['none' => 'None (do not send SMS)', 'semaphore' => 'Semaphore (semaphore.co)', 'generic_http' => 'Other HTTP API (JSON, bearer key)'];
const ISPSET_SMTP_SECURE = ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL / TLS (port 465)', 'none' => 'None (port 25, not recommended)'];
const ISPSET_NOTIF_STATUSES = ['queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped (not sent)', 'read' => 'Read'];
$tabs = ['providers' => 'Providers', 'test' => 'Send a test', 'log' => 'Delivery log'];
$tab = isset($tabs[g('tab')]) ? (string)g('tab') : 'providers';

handle_post(function () use ($pk) {
    $action = (string)p('action');
    if ($action === 'providers') {
        Access::require($pk, 'edit');
        $set = [
            'isp_sms_provider' => isset(ISPSET_SMS_PROVIDERS[p('isp_sms_provider')]) ? (string)p('isp_sms_provider') : 'none',
            'isp_sms_api_url' => trim((string)p('isp_sms_api_url')),
            'isp_sms_sender' => mb_substr(trim((string)p('isp_sms_sender')), 0, 11),
            'isp_smtp_host' => trim((string)p('isp_smtp_host')),
            'isp_smtp_port' => (string)max(1, min(65535, (int)p('isp_smtp_port', '587'))),
            'isp_smtp_secure' => isset(ISPSET_SMTP_SECURE[p('isp_smtp_secure')]) ? (string)p('isp_smtp_secure') : 'tls',
            'isp_smtp_user' => trim((string)p('isp_smtp_user')),
            'isp_mail_from' => trim((string)p('isp_mail_from')),
            'isp_mail_from_name' => mb_substr(trim((string)p('isp_mail_from_name')), 0, 80),
        ];
        if ($set['isp_sms_api_url'] !== '' && !preg_match('#^https?://#i', $set['isp_sms_api_url'])) {
            fail('The SMS API URL starts with https://.');
        }
        if ($set['isp_sms_provider'] === 'generic_http' && $set['isp_sms_api_url'] === '') {
            fail('Enter the API URL of the SMS provider.');
        }
        if ($set['isp_mail_from'] !== '' && !filter_var($set['isp_mail_from'], FILTER_VALIDATE_EMAIL)) {
            fail('The sender e-mail address is not valid.');
        }
        if ($set['isp_smtp_host'] !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $set['isp_smtp_host'])) {
            fail('Enter the SMTP server name only (e.g. smtp.gmail.com).');
        }
        // the secrets: written only when a new value is typed; "clear" removes them
        $secrets = [];
        foreach (['isp_sms_api_key_enc' => 'sms_key', 'isp_smtp_pass_enc' => 'smtp_pass'] as $key => $field) {
            if (p($field . '_clear') === '1') {
                $secrets[$key] = '';
            } elseif ((string)($_POST[$field] ?? '') !== '') {
                $secrets[$key] = (string)IspCrypto::encrypt((string)$_POST[$field]);
            }
        }
        $before = [];
        $after = [];
        foreach ($set as $k => $v) {
            if (IspSetup::get($k) !== $v) {
                $before[$k] = IspSetup::get($k);
                $after[$k] = $v;
            }
        }
        DB::tx(function () use ($set, $secrets, $before, $after) {
            foreach ($set + $secrets as $k => $v) {
                Settings::set($k, $v);
            }
            $changed = array_keys($after);
            foreach (array_keys($secrets) as $k) {
                $changed[] = $k . ($secrets[$k] === '' ? ' (cleared)' : ' (new value)');
            }
            if ($changed) {
                // the values of the secrets are never written to the audit trail, only that they changed
                Audit::log('edit', 'ispset', 'settings', null, 'Notification providers', ['before' => $before, 'after' => $after, 'reason' => 'Changed: ' . implode(', ', $changed)]);
            }
        });
        redirect(url($pk), 'Provider settings saved.', 'success');
    }
    if ($action === 'test') {
        Access::require($pk, 'edit');
        $ch = (string)p('channel');
        $to = trim((string)p('to'));
        $text = trim((string)p('message'));
        if ($text === '') {
            fail('Write the test message.');
        }
        if (!IspNotify::configured($ch)) {
            fail('No ' . ($ch === 'sms' ? 'SMS' : 'e-mail') . ' provider is configured: save the provider settings first. Nothing was sent.');
        }
        try {
            if ($ch === 'sms') {
                $num = IspNotify::normalizeMobile($to);
                if (!preg_match('/^63\d{10}$/', $num)) {
                    fail('Enter a Philippine mobile number (09xxxxxxxxx).');
                }
                $ref = IspNotify::sendSms($num, mb_substr($text, 0, 300));
            } elseif ($ch === 'email') {
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    fail('Enter a valid e-mail address.');
                }
                $ref = IspNotify::sendMail($to, 'Test message from ' . Settings::get('company_name'), $text);
            } else {
                fail('Choose SMS or e-mail.');
            }
        } catch (UserError $e) {
            throw $e;
        } catch (Throwable $e) {
            Audit::log('test_send', 'ispset', 'notification', null, $ch . ' ' . $to, ['reason' => 'Failed: ' . mb_substr($e->getMessage(), 0, 200)]);
            fail('The provider refused the message: ' . $e->getMessage());
        }
        Audit::log('test_send', 'ispset', 'notification', null, $ch . ' ' . $to, ['reason' => 'Sent, provider reference ' . $ref]);
        redirect(url($pk, ['tab' => 'test']), 'Test message sent (provider reference ' . $ref . ').', 'success');
    }
    if ($action === 'retry') {
        Access::require($pk, 'edit');
        // one message (its own Retry button) or the ticked ones
        $ids = p('id') ? [(int)p('id')] : array_map('intval', (array)($_POST['ids'] ?? []));
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            fail('Tick the messages to send again.');
        }
        [$in, $ip] = DB::in('n', $ids);
        $n = DB::run("UPDATE isp_notifications SET status = 'queued', attempts = 0, next_try_at = :t, last_error = NULL WHERE id IN $in AND status IN ('failed', 'skipped') AND channel IN ('sms', 'email')",
            ['t' => date('Y-m-d H:i:s')] + $ip)->rowCount();
        Audit::log('retry', 'ispset', 'notification', null, $n . ' message(s)', ['reason' => 'Queued again for delivery']);
        redirect(url($pk, ['tab' => 'log']), $n . ' message' . ($n === 1 ? '' : 's') . ' queued again. They go out with the next delivery run.', 'success');
    }
    if ($action === 'cancel') {
        Access::require($pk, 'delete');
        $id = (int)p('id');
        $n = DB::run("UPDATE isp_notifications SET status = 'skipped', last_error = :e, next_try_at = NULL WHERE id = :id AND status = 'queued'",
            ['e' => mb_substr('Cancelled by ' . (Auth::user()['username'] ?? '') . ': ' . trim((string)p('reason')), 0, 255), 'id' => $id])->rowCount();
        if (!$n) {
            fail('Only a queued message is cancelled.');
        }
        Audit::log('cancel', 'ispset', 'notification', $id, 'Notification ' . $id, ['reason' => trim((string)p('reason'))]);
        redirect(url($pk, ['tab' => 'log']), 'Message cancelled.', 'success');
    }
    if ($action === 'deliver') {
        Access::require($pk, 'edit');
        $n = IspNotify::deliver(200);
        Audit::log('deliver', 'ispset', 'notification', null, $n . ' message(s)', ['reason' => 'Delivery run started by hand']);
        redirect(url($pk, ['tab' => 'log']), $n . ' queued message' . ($n === 1 ? '' : 's') . ' processed.', 'success');
    }
    fail('Unknown action.');
});

layout_start('Notifications (SMS / E-mail)');
echo IspUi::tabs($tabs, $tab, $pk);
$canEdit = Access::can($pk, 'edit');

if ($tab === 'providers') {
    $v = fn(string $k) => is_post() ? (string)p($k) : IspSetup::get($k);
    $smsOk = IspNotify::configured('sms');
    $mailOk = IspNotify::configured('email');
    ?>
    <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="providers">
    <div class="row g-3">
      <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">SMS <?= IspUi::status($smsOk ? 'ok' : 'inactive', ['ok' => 'Ready to send', 'inactive' => 'Not configured: SMS are logged, not sent']) ?></h2>
        <div class="row g-2">
          <?= select_field('isp_sms_provider', 'Provider', ISPSET_SMS_PROVIDERS, $v('isp_sms_provider'), ['class' => 'col-12']) ?>
          <?= input_field('isp_sms_api_url', 'API URL (Semaphore: empty for the standard URL)', $v('isp_sms_api_url'), ['class' => 'col-12', 'extra' => 'placeholder="https://api.semaphore.co/api/v4/messages"']) ?>
          <div class="col-md-7"><label class="form-label">API key</label><input class="form-control form-control-sm" type="password" name="sms_key" autocomplete="new-password" placeholder="<?= h(IspCrypto::masked(IspSetup::get('isp_sms_api_key_enc')) ?: 'not set') ?>"<?= $canEdit ? '' : ' disabled' ?>>
            <div class="form-text">Stored encrypted. Leave empty to keep the saved key.</div></div>
          <?= input_field('isp_sms_sender', 'Sender name (max 11)', $v('isp_sms_sender'), ['class' => 'col-md-5', 'extra' => 'maxlength="11"']) ?>
          <?php if (IspSetup::get('isp_sms_api_key_enc') !== ''): ?><?= check_field('sms_key_clear', 'Remove the saved API key', false, 'col-12') ?><?php endif; ?>
        </div>
      </div></div></div>
      <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">E-mail (SMTP) <?= IspUi::status($mailOk ? 'ok' : 'inactive', ['ok' => 'Ready to send', 'inactive' => 'Not configured: e-mails are logged, not sent']) ?></h2>
        <div class="row g-2">
          <?= input_field('isp_smtp_host', 'SMTP server', $v('isp_smtp_host'), ['class' => 'col-md-8', 'extra' => 'placeholder="smtp.example.com"']) ?>
          <?= input_field('isp_smtp_port', 'Port', $v('isp_smtp_port'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-4']) ?>
          <?= select_field('isp_smtp_secure', 'Encryption', ISPSET_SMTP_SECURE, $v('isp_smtp_secure'), ['class' => 'col-md-6']) ?>
          <?= input_field('isp_smtp_user', 'User name', $v('isp_smtp_user'), ['class' => 'col-md-6', 'extra' => 'autocomplete="off"']) ?>
          <div class="col-md-6"><label class="form-label">Password</label><input class="form-control form-control-sm" type="password" name="smtp_pass" autocomplete="new-password" placeholder="<?= h(IspCrypto::masked(IspSetup::get('isp_smtp_pass_enc')) ?: 'not set') ?>"<?= $canEdit ? '' : ' disabled' ?>>
            <div class="form-text">Stored encrypted. Leave empty to keep it.</div></div>
          <?= input_field('isp_mail_from', 'Sender e-mail address', $v('isp_mail_from'), ['type' => 'email', 'class' => 'col-md-6']) ?>
          <?= input_field('isp_mail_from_name', 'Sender name', $v('isp_mail_from_name'), ['class' => 'col-md-6', 'extra' => 'placeholder="' . h(Settings::get('company_name')) . '"']) ?>
          <?php if (IspSetup::get('isp_smtp_pass_enc') !== ''): ?><?= check_field('smtp_pass_clear', 'Remove the saved password', false, 'col-12') ?><?php endif; ?>
        </div>
      </div></div></div>
    </div>
    <p class="small text-muted mt-3">The subscriber's own choice decides the channels (SMS, e-mail) on the subscriber's record; in-app messages always show on the portal. Each message is sent once: the same notice is never queued twice, and failed deliveries are retried up to five times with a growing delay.</p>
    <?php if ($canEdit): ?><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save provider settings</button><?php endif; ?>
    </form>
    <?php
} elseif ($tab === 'test') {
    ?>
    <div class="card isp-card"><div class="card-body">
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="test">
        <?= select_field('channel', 'Channel', ['sms' => 'SMS' . (IspNotify::configured('sms') ? '' : ' (not configured)'), 'email' => 'E-mail' . (IspNotify::configured('email') ? '' : ' (not configured)')], fv('channel', null, 'sms'), ['class' => 'col-md-3']) ?>
        <?= input_field('to', 'Send to (mobile number or e-mail address)', fv('to'), ['class' => 'col-md-5', 'required' => true]) ?>
        <div class="col-12"><label class="form-label">Message</label><textarea class="form-control form-control-sm" name="message" rows="3" maxlength="300" required><?= h((string)fv('message', null, 'This is a test message from ' . Settings::get('company_name') . '.')) ?></textarea></div>
        <div class="col-12"><?php if ($canEdit): ?><button class="btn btn-sm btn-primary"><i class="bi bi-send"></i> Send the test</button><?php endif; ?>
          <span class="small text-muted ms-2">A real message is sent through the provider and may be charged by it.</span></div>
      </form>
    </div></div>
    <?php
} else {
    $q = trim((string)g('q'));
    $status = isset(ISPSET_NOTIF_STATUSES[g('status')]) ? (string)g('status') : '';
    $ch = isset(IspNotify::CHANNELS[g('channel')]) ? (string)g('channel') : '';
    $ev = isset(IspNotify::EVENTS[g('event')]) ? (string)g('event') : '';
    $from = gdate('from', date('Y-m-d', strtotime('-30 days')));
    $to = gdate('to', today());
    $p = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
    $where = ['n.created_at BETWEEN :f AND :t'];
    if ($q !== '') {
        $where[] = '(n.recipient LIKE :q1 OR a.account_no LIKE :q2 OR a.account_name LIKE :q3)';
        $p += ['q1' => "%$q%", 'q2' => "$q%", 'q3' => "%$q%"];
    }
    foreach (['status' => $status, 'channel' => $ch, 'event_code' => $ev] as $col => $val) {
        if ($val !== '') {
            $where[] = "n.$col = :$col";
            $p[$col] = $val;
        }
    }
    $sqlFrom = 'FROM isp_notifications n LEFT JOIN isp_accounts a ON a.id = n.account_id WHERE ' . implode(' AND ', $where);
    [$per, $off, $page] = IspUi::paging(50);
    $total = (int)DB::value("SELECT COUNT(*) $sqlFrom", $p);
    $rows = DB::all("SELECT n.*, a.account_no, a.account_name $sqlFrom ORDER BY n.id DESC LIMIT $per OFFSET $off", $p);
    $counts = DB::pairs('SELECT status, COUNT(*) FROM isp_notifications WHERE created_at >= :d GROUP BY status', ['d' => date('Y-m-d', strtotime('-30 days'))]);
    ?>
    <div class="kpi-grid">
      <?= IspUi::kpi('Queued', number_format((int)($counts['queued'] ?? 0)), 'waiting for the next delivery run', url($pk, ['tab' => 'log', 'status' => 'queued']), 'hourglass-split', 'info') ?>
      <?= IspUi::kpi('Sent (30 days)', number_format((int)($counts['sent'] ?? 0) + (int)($counts['read'] ?? 0)), '', url($pk, ['tab' => 'log', 'status' => 'sent']), 'send-check', 'ok') ?>
      <?= IspUi::kpi('Failed', number_format((int)($counts['failed'] ?? 0)), 'after five attempts', url($pk, ['tab' => 'log', 'status' => 'failed']), 'x-octagon', (int)($counts['failed'] ?? 0) ? 'bad' : 'ok') ?>
      <?= IspUi::kpi('Skipped', number_format((int)($counts['skipped'] ?? 0)), 'no provider configured', url($pk, ['tab' => 'log', 'status' => 'skipped']), 'slash-circle', 'idle') ?>
    </div>
    <?= IspUi::filterStart($pk, ['tab' => 'log']) ?>
      <?= IspUi::fSearch($q, 'Recipient or account') ?>
      <?= IspUi::fSelect('status', 'Status', ISPSET_NOTIF_STATUSES, $status) ?>
      <?= IspUi::fSelect('channel', 'Channel', IspNotify::CHANNELS, $ch) ?>
      <?= IspUi::fSelect('event', 'Event', IspNotify::EVENTS, $ev) ?>
      <?= IspUi::fDate('from', 'From', $from) ?><?= IspUi::fDate('to', 'To', $to) ?>
    <?= IspUi::filterEnd(url($pk, ['tab' => 'log'])) ?>
    <div class="card isp-card"><div class="card-body">
      <?php if ($canEdit): ?>
      <div class="d-flex gap-2 flex-wrap mb-2"><?= IspUi::actionForm('deliver', 'Deliver queued messages now', [], 'btn-outline-primary', 'send') ?></div>
      <?php endif; ?>
      <?php if (!$rows): ?>
        <?= IspUi::empty('No message in this period.', 'chat-dots') ?>
      <?php else: ?>
      <form method="post" id="retryForm"><?= csrf_field() ?><input type="hidden" name="action" value="retry">
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><?= $canEdit ? '<th><input class="form-check-input" type="checkbox" data-check-all="ids" aria-label="Tick all"></th>' : '' ?><th>Queued</th><th>Event</th><th>Channel</th><th>To</th><th>Message</th><th>Status</th><th class="num">Tries</th><?= $canEdit ? '<th></th>' : '' ?></tr></thead><tbody>
        <?php $cancels = []; foreach ($rows as $r): $retryable = in_array($r['status'], ['failed', 'skipped'], true) && $r['channel'] !== 'inapp'; ?>
          <tr><?php if ($canEdit): ?><td><?php if ($retryable): ?><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Tick"><?php endif; ?></td><?php endif; ?>
            <td class="small text-nowrap"><?= h(fdatetime($r['created_at'])) ?></td><td class="small"><?= h(IspNotify::EVENTS[$r['event_code']] ?? $r['event_code']) ?></td>
            <td class="small"><?= h(IspNotify::CHANNELS[$r['channel']] ?? $r['channel']) ?></td>
            <td class="small"><?= IspUi::sensitive((string)$r['recipient']) ?><?= $r['account_no'] ? '<div class="text-muted">' . h($r['account_no'] . ' ' . $r['account_name']) . '</div>' : '' ?></td>
            <td class="small" style="max-width:24rem"><?= $r['subject'] ? '<strong>' . h($r['subject']) . '</strong><br>' : '' ?><?= h(mb_substr($r['body'], 0, 160)) ?><?= mb_strlen($r['body']) > 160 ? '...' : '' ?>
              <?= $r['last_error'] ? '<div class="text-danger">' . h($r['last_error']) . '</div>' : '' ?></td>
            <td><?= IspUi::status($r['status'], ISPSET_NOTIF_STATUSES) ?><?= $r['sent_at'] ? '<div class="small text-muted">' . h(fdatetime($r['sent_at'])) . '</div>' : '' ?></td>
            <td class="num"><?= (int)$r['attempts'] ?></td>
            <?php if ($canEdit): ?><td class="text-nowrap"><?php if ($retryable): ?><button class="btn btn-sm btn-outline-secondary" name="id" value="<?= (int)$r['id'] ?>"><i class="bi bi-arrow-repeat"></i> Retry</button><?php endif; ?>
              <?php if ($r['status'] === 'queued' && Access::can($pk, 'delete')): $cancels[] = (int)$r['id']; ?><button class="btn btn-sm btn-outline-danger" form="cancel<?= (int)$r['id'] ?>"><i class="bi bi-x"></i> Cancel</button><?php endif; ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php if ($canEdit): ?><button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Send the ticked messages again</button><?php endif; ?>
      </form>
      <?php foreach ($cancels as $cid): ?>
        <form method="post" id="cancel<?= $cid ?>" data-confirm="Cancel this message? It will not be sent."><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="reason" value="Cancelled from the delivery log"></form>
      <?php endforeach; ?>
      <?= IspUi::pager($total, $per, $page) ?>
      <?php endif; ?>
    </div></div>
    <?php
}
layout_end();
