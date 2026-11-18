<?php
declare(strict_types=1);

/**
 * Small building blocks of the ISP pages: status labels (plain text with a signal light, never a capsule), KPI tiles,
 * filter bars, tabs, the paging of long lists and the letterhead of printed documents.
 */
final class IspUi
{
    /** value => tone (ok / warn / bad / info / idle) */
    private const TONES = [
        'active' => 'ok', 'enabled' => 'ok', 'paid' => 'ok', 'posted' => 'ok', 'confirmed' => 'ok', 'restored' => 'ok', 'completed' => 'ok', 'verified' => 'ok',
        'resolved' => 'ok', 'closed' => 'idle', 'approved' => 'info', 'installed' => 'ok', 'free' => 'ok', 'ok' => 'ok', 'sent' => 'info', 'done' => 'ok', 'in_stock' => 'ok',
        'recovered' => 'ok', 'certified' => 'ok', 'resolved_credit' => 'ok', 'resolved_no_credit' => 'idle', 'kept' => 'ok', 'read' => 'idle',
        'pending' => 'warn', 'draft' => 'idle', 'new' => 'info', 'open' => 'info', 'queued' => 'info', 'requested' => 'info', 'candidate' => 'warn', 'notified' => 'warn',
        'scheduled' => 'info', 'assigned' => 'info', 'accepted' => 'info', 'in_progress' => 'warn', 'on_hold' => 'warn', 'classified' => 'info', 'executing' => 'warn',
        'for_verification' => 'warn', 'site_survey' => 'info', 'for_installation' => 'info', 'for_activation' => 'warn', 'partially_paid' => 'warn', 'partial' => 'warn',
        'provisioning' => 'warn', 'reserved' => 'warn', 'with_technician' => 'info', 'for_recovery' => 'warn', 'investigating' => 'warn', 'pending_approval' => 'warn',
        'suspected' => 'warn', 'in_repair' => 'warn', 'issued' => 'info', 'not_provisioned' => 'idle', 'planned' => 'idle', 'maintenance' => 'warn', 'running' => 'warn',
        'overdue' => 'bad', 'disconnected' => 'bad', 'disabled' => 'bad', 'failed' => 'bad', 'rejected' => 'bad', 'voided' => 'idle', 'void' => 'idle', 'reversed' => 'idle',
        'cancelled' => 'idle', 'terminated' => 'idle', 'suspended' => 'warn', 'disputed' => 'warn', 'adjusted' => 'info', 'defaulted' => 'bad', 'missed' => 'bad',
        'damaged' => 'bad', 'cut' => 'bad', 'faulty' => 'bad', 'missing' => 'bad', 'used' => 'info', 'unknown' => 'idle', 'skipped' => 'idle', 'expired' => 'idle',
        'retired' => 'idle', 'inactive' => 'idle', 'locked' => 'bad', 'manual' => 'warn', 'false_alarm' => 'idle', 'withdrawn' => 'idle', 'consumed' => 'info', 'returned' => 'idle',
        'disposed' => 'idle', 'merged' => 'idle', 'billed' => 'ok', 'critical' => 'bad', 'high' => 'warn', 'urgent' => 'bad', 'normal' => 'info', 'low' => 'idle',
    ];

    /** A status as plain text with its signal light */
    public static function status(?string $value, array $labels = [], ?string $tone = null): string
    {
        $v = (string)$value;
        $label = $labels[$v] ?? ucfirst(str_replace('_', ' ', $v));
        $tone ??= self::TONES[$v] ?? 'idle';
        return '<span class="st st-' . h($tone) . '">' . h($label) . '</span>';
    }

    public static function tone(?string $value): string
    {
        return self::TONES[(string)$value] ?? 'idle';
    }

    /** A KPI tile; with a link the whole tile opens the filtered records */
    public static function kpi(string $label, string $value, string $sub = '', ?string $href = null, string $icon = 'activity', string $tone = 'info'): string
    {
        $inner = '<span class="kpi-ico"><i class="bi bi-' . h($icon) . '"></i></span><span class="kpi-body"><span class="kpi-label">' . h($label) . '</span>'
            . '<span class="kpi-value" data-countup>' . h($value) . '</span>' . ($sub !== '' ? '<span class="kpi-sub">' . h($sub) . '</span>' : '') . '</span>';
        return $href
            ? '<a class="kpi kpi-' . h($tone) . '" href="' . h($href) . '">' . $inner . '<i class="bi bi-chevron-right kpi-go"></i></a>'
            : '<div class="kpi kpi-' . h($tone) . '">' . $inner . '</div>';
    }

    /** Tabs as links: [key => label]; the current one is marked */
    public static function tabs(array $tabs, string $current, string $pageKey, array $keep = [], string $param = 'tab'): string
    {
        $out = '<nav class="isp-tabs no-print" role="tablist">';
        foreach ($tabs as $k => $label) {
            $active = (string)$k === $current;
            $out .= '<a role="tab" class="isp-tab' . ($active ? ' is-active' : '') . '" href="' . h(url($pageKey, array_merge($keep, [$param => $k]))) . '"' . ($active ? ' aria-selected="true"' : '') . '>' . $label . '</a>';
        }
        return $out . '</nav>';
    }

    /** The start of a GET filter bar; fields are added by the page, then filterEnd() */
    public static function filterStart(string $pageKey, array $hidden = []): string
    {
        $out = '<form class="isp-filter no-print" method="get" action="' . h(base_url() . '/index.php') . '"><input type="hidden" name="p" value="' . h($pageKey) . '">';
        foreach ($hidden as $k => $v) {
            if ($v !== null && $v !== '') {
                $out .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
            }
        }
        return $out;
    }

    public static function filterEnd(string $resetUrl = ''): string
    {
        return '<div class="isp-filter-actions"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Show</button>'
            . ($resetUrl !== '' ? ' <a class="btn btn-sm btn-outline-secondary" href="' . h($resetUrl) . '">Reset</a>' : '') . '</div></form>';
    }

    public static function fSearch(string $value, string $placeholder = 'Search', string $name = 'q'): string
    {
        return '<div class="isp-f isp-f-grow"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="' . h($name) . '" value="' . h($value) . '" placeholder="' . h($placeholder) . '"></div>';
    }

    public static function fSelect(string $name, string $label, array $opts, $value, string $blank = 'All'): string
    {
        return '<div class="isp-f"><label class="form-label">' . h($label) . '</label><select class="form-select form-select-sm" name="' . h($name) . '">' . options($opts, $value, $blank) . '</select></div>';
    }

    public static function fDate(string $name, string $label, ?string $value): string
    {
        return '<div class="isp-f"><label class="form-label">' . h($label) . '</label><input class="form-control form-control-sm" type="date" name="' . h($name) . '" value="' . h((string)$value) . '"></div>';
    }

    /** [limit, offset, page] of a long list (?pg=) */
    public static function paging(int $per = 50): array
    {
        $page = max(1, (int)g('pg', '1'));
        return [$per, ($page - 1) * $per, $page];
    }

    public static function pager(int $total, int $per, int $page): string
    {
        $pages = (int)ceil($total / max(1, $per));
        if ($pages <= 1) {
            return '<div class="isp-pager small text-muted">' . number_format($total) . ' record' . ($total === 1 ? '' : 's') . '</div>';
        }
        $out = '<div class="isp-pager no-print"><span class="small text-muted">' . number_format($total) . ' records, page ' . $page . ' of ' . $pages . '</span><span>';
        if ($page > 1) {
            $out .= '<a class="btn btn-sm btn-outline-secondary" href="' . h(current_url(['pg' => $page - 1])) . '"><i class="bi bi-chevron-left"></i> Previous</a> ';
        }
        if ($page < $pages) {
            $out .= '<a class="btn btn-sm btn-outline-secondary" href="' . h(current_url(['pg' => $page + 1])) . '">Next <i class="bi bi-chevron-right"></i></a>';
        }
        return $out . '</span></div>';
    }

    public static function empty(string $text, string $icon = 'inbox'): string
    {
        return '<div class="isp-empty"><i class="bi bi-' . h($icon) . '"></i><div>' . h($text) . '</div></div>';
    }

    /** A labelled value of a detail panel */
    public static function dl(array $pairs): string
    {
        $out = '<dl class="isp-dl">';
        foreach ($pairs as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $out .= '<div><dt>' . h($k) . '</dt><dd>' . $v . '</dd></div>';
        }
        return $out . '</dl>';
    }

    /** The letterhead of a printed ISP document */
    public static function letterhead(string $title, string $right = ''): string
    {
        $logo = Logo::path() ? '<img class="doc-logo" src="' . h(Logo::url()) . '" alt="">' : '';
        $tin = Settings::get('company_tin');
        $vat = IspSetup::vatRegistered() ? 'VAT Reg. TIN ' . $tin : ($tin !== '' ? 'Non-VAT Reg. TIN ' . $tin : '');
        return '<header class="doc-head">' . $logo . '<div class="doc-co"><strong>' . h(Settings::get('company_name')) . '</strong><span>' . h(Settings::get('company_address')) . '</span>'
            . '<span>' . h(trim($vat . (Settings::get('company_phone') !== '' ? '   Tel. ' . Settings::get('company_phone') : ''))) . '</span></div>'
            . '<div class="doc-title"><h2>' . h($title) . '</h2>' . $right . '</div></header>';
    }

    /** A yes / no choice */
    public static function yn(): array
    {
        return ['1' => 'Yes', '0' => 'No'];
    }

    /** The address and contacts of a subscriber, hiding them from users without the sensitive-data right */
    public static function sensitive(?string $v): string
    {
        $v = (string)$v;
        if ($v === '' || Access::special('isp_view_sensitive') || Access::special('coop_view_confidential')) {
            return h($v);
        }
        return h(Party::mask($v));
    }

    /** "3 days ago", "in 2 hours" */
    public static function ago(?string $dt): string
    {
        if (!$dt) {
            return '';
        }
        $s = time() - strtotime($dt);
        $future = $s < 0;
        $s = abs($s);
        $t = $s < 3600 ? max(1, intdiv($s, 60)) . ' min' : ($s < 86400 ? intdiv($s, 3600) . ' h' : intdiv($s, 86400) . ' day' . (intdiv($s, 86400) === 1 ? '' : 's'));
        return $future ? 'in ' . $t : $t . ' ago';
    }

    /** A confirm-and-post action button (a small form) */
    public static function actionForm(string $action, string $label, array $fields = [], string $class = 'btn-outline-primary', string $icon = '', string $confirm = ''): string
    {
        $out = '<form method="post" class="d-inline"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="action" value="' . h($action) . '">';
        foreach ($fields as $k => $v) {
            $out .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
        }
        return $out . '<button class="btn btn-sm ' . h($class) . '">' . ($icon ? '<i class="bi bi-' . h($icon) . '"></i> ' : '') . h($label) . '</button></form>';
    }

    /**
     * A subscriber account picker: a hidden id and a search box (assets/js/isp.js lists the matches from api.isp as
     * one types). Without JavaScript the account number typed in the box is still understood by postedAccount().
     */
    public static function accountField(string $name = 'account_id', ?int $value = null, string $label = 'Subscriber account', string $class = 'col-md-6', bool $required = true): string
    {
        $text = '';
        if ($value && ($a = IspAccounts::get($value))) {
            $text = $a['account_no'] . ' - ' . $a['account_name'];
        }
        $id = 'acf_' . preg_replace('/\W/', '', $name);
        return '<div class="' . h($class) . ' isp-acct-field"><label class="form-label" for="' . $id . '">' . h($label) . '</label>'
            . '<input type="hidden" name="' . h($name) . '" value="' . h((string)($value ?: '')) . '">'
            . '<input type="text" class="form-control form-control-sm" id="' . $id . '" name="' . h($name) . '_text" value="' . h($text) . '" autocomplete="off"'
            . ' data-isp-account="' . h($name) . '" data-url="' . h(url('api.isp', ['what' => 'accounts'])) . '" placeholder="Account no., name, mobile or PPPoE user"' . ($required ? ' required' : '') . '>'
            . '<div class="isp-acct-menu" hidden></div></div>';
    }

    /** The account chosen with accountField(): its id, or the account number / exact name typed in the box */
    public static function postedAccount(string $name = 'account_id'): ?int
    {
        $id = (int)($_POST[$name] ?? $_GET[$name] ?? 0);
        if ($id > 0 && IspAccounts::get($id)) {
            return $id;
        }
        $t = trim((string)($_POST[$name . '_text'] ?? $_GET[$name . '_text'] ?? ''));
        if ($t === '') {
            return null;
        }
        $no = trim(explode(' - ', $t, 2)[0]);
        $a = IspAccounts::byNo($no);
        if ($a) {
            return (int)$a['id'];
        }
        $ids = DB::column('SELECT id FROM isp_accounts WHERE account_name = :n LIMIT 2', ['n' => $t]);
        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    /** Accounts matching a search (number, name, mobile, PPPoE user, customer no.) for the picker */
    public static function searchAccounts(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $p = ['q1' => $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%', 'q4' => $q . '%', 'q5' => $q . '%'];
        $b = IspSetup::branchSql('a.branch_id', $p);
        return DB::all("SELECT a.id, a.account_no, a.account_name, a.service_status, a.install_address, p.name AS plan, c.mobile,
                (SELECT COALESCE(SUM(i.balance), 0) FROM isp_invoices i WHERE i.account_id = a.id AND i.status IN ('posted','partially_paid','overdue','disputed')) AS balance
            FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id LEFT JOIN isp_plans p ON p.id = a.plan_id
            WHERE (a.account_no LIKE :q1 OR a.account_name LIKE :q2 OR c.name LIKE :q3 OR c.mobile LIKE :q4 OR a.pppoe_username LIKE :q5)$b
            ORDER BY a.account_no LIMIT " . (int)$limit, $p);
    }

    /** The link of an account to its page (plain text without access) */
    public static function acct($id, ?string $no, ?string $name = null): string
    {
        $t = trim((string)$no . ($name !== null && $name !== '' ? ' ' . $name : ''));
        if (!$id || !Access::page('isp.account')) {
            return h($t);
        }
        return '<a href="' . h(url('isp.account', ['id' => (int)$id])) . '">' . h((string)$no) . '</a>' . ($name !== null && $name !== '' ? ' <span class="text-muted">' . h($name) . '</span>' : '');
    }
}
