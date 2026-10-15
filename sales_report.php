<?php
// sales_report.php - sales report (admin only), opened galing sa dashboard's Completed orders panel and the sidebar.
// Same two views as the dashboard: Weekly (this week, with the last 8 weeks as bars) and Annual (this year by month).
// Sales = payments recorded at release (payments.amount_due), kaya cancelled and unpaid orders never count.
// "Sales" are the payments recorded at release. So cancelled and
// unpaid orders are excluded. Each payment sa period can be exported to CSV (Excel).
//   ?range=week|year (default week)        ?range=week&export=csv   one row per payment in the period (opens in Excel)
// Print uses the browser's print dialog (Save as PDF works too); the print layout hides the sidebar and buttons.
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?error=unauthorized");
    exit();
}
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: view_order.php");
    exit();
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ui.php';

$range = (($_GET['range'] ?? '') === 'year') ? 'year' : 'week';
$today = date('Y-m-d');
$from  = $range === 'year' ? date('Y-01-01') : date('Y-m-d', strtotime('monday this week'));
$from_dt = $from . ' 00:00:00';
$to_dt   = $today . ' 23:59:59';

// ---------- CSV export: one row per payment sa period ----------
// Kapag export=csv, one row per payment is downloaded as CSV (opens in Excel).
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $conn->prepare(
        "SELECT p.receipt_no, p.paid_at, o.order_number, c.full_name AS customer, c.phone, s.name AS service, o.weight_kg,
                p.method, p.reference_no, p.amount_due, u.full_name AS cashier
         FROM payments p
         JOIN orders o    ON o.order_id = p.order_id
         JOIN customers c ON c.customer_id = o.customer_id
         JOIN services s  ON s.service_id = o.service_id
         JOIN users u     ON u.user_id = p.received_by
         WHERE p.paid_at BETWEEN ? AND ?
         ORDER BY p.paid_at, p.payment_id");
    $stmt->bind_param("ss", $from_dt, $to_dt);
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_report_' . $from . '_to_' . $today . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 marker so Excel reads names correctly
    // Ang text starting with = + - @ is treated as a formula by Excel: a leading quote keeps it text.
    $safe = function ($v) { $v = (string) $v; return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v; };
    fputcsv($out, ['Receipt No', 'Paid At', 'Order No', 'Customer', 'Contact', 'Service', 'Weight (kg)', 'Method', 'GCash Reference', 'Amount (PHP)', 'Received By']);
    $sum = 0.0; $n = 0;
    while ($r = $res->fetch_assoc()) {
        $phone = (string) ($r['phone'] ?? '');
        fputcsv($out, [
            $r['receipt_no'], $r['paid_at'], $r['order_number'], $safe($r['customer']),
            $phone !== '' ? '="' . preg_replace('/[^0-9+]/', '', $phone) . '"' : '',   // keeps the leading 0 sa Excel
            $safe($r['service']), number_format((float) $r['weight_kg'], 2, '.', ''), $r['method'], $safe($r['reference_no']),
            number_format((float) $r['amount_due'], 2, '.', ''), $safe($r['cashier']),
        ]);
        $sum += (float) $r['amount_due']; $n++;
    }
    fputcsv($out, []);
    fputcsv($out, ['TOTAL', '', $n . ' payments', '', '', '', '', '', '', number_format($sum, 2, '.', ''), '']);
    fclose($out);
    exit();
}

// ---------- Figures (mga numero) ----------
$rows = function ($sql, $types = '', ...$args) use ($conn) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $r;
};
$error = false;
$total = 0.0; $count = 0; $prev_total = 0.0;
$by_service = $by_method = $series = $chart = [];
try {
    $s = $rows("SELECT COUNT(*) AS n, COALESCE(SUM(amount_due), 0) AS total FROM payments WHERE paid_at BETWEEN ? AND ?", "ss", $from_dt, $to_dt)[0];
    $total = (float) $s['total']; $count = (int) $s['n'];

    $by_service = $rows(
        "SELECT s.name, COUNT(*) AS n, SUM(o.weight_kg) AS kg, SUM(p.amount_due) AS total
         FROM payments p JOIN orders o ON o.order_id = p.order_id JOIN services s ON s.service_id = o.service_id
         WHERE p.paid_at BETWEEN ? AND ? GROUP BY s.service_id, s.name ORDER BY total DESC", "ss", $from_dt, $to_dt);
    $by_method = $rows(
        "SELECT method, COUNT(*) AS n, SUM(amount_due) AS total FROM payments
         WHERE paid_at BETWEEN ? AND ? GROUP BY method ORDER BY total DESC", "ss", $from_dt, $to_dt);

    if ($range === 'week') {
        // the last 8 weeks as bars, this week huli (same keys as the dashboard)
        for ($i = 7; $i >= 0; $i--) {
            $mon = strtotime("monday this week -$i week");
            $chart[date('oW', $mon)] = ['label' => date('M j', $mon), 'n' => 0.0];
        }
        $wk_start = date('Y-m-d', strtotime('monday this week -7 week'));
        foreach ($rows("SELECT YEARWEEK(paid_at, 1) AS k, SUM(amount_due) AS total FROM payments WHERE paid_at >= ? GROUP BY k", "s", $wk_start) as $r) {
            if (isset($chart[$r['k']])) $chart[$r['k']]['n'] = (float) $r['total'];
        }
        $cur_key = date('oW');
        // fair comparison: same days ng last week, not a full week
        $prev_from = date('Y-m-d', strtotime('monday this week -1 week'));
        $prev_total = (float) $rows("SELECT COALESCE(SUM(amount_due), 0) AS t FROM payments WHERE paid_at >= ? AND paid_at < NOW() - INTERVAL 7 DAY", "s", $prev_from)[0]['t'];
        $delta_label = 'vs same days last week';
        $period_word = 'this week';
        // this week per day, Monday hanggang today
        $by_day = [];
        foreach ($rows("SELECT DATE(paid_at) AS d, COUNT(*) AS n, SUM(amount_due) AS total FROM payments WHERE paid_at BETWEEN ? AND ? GROUP BY d", "ss", $from_dt, $to_dt) as $r) $by_day[$r['d']] = $r;
        for ($d = strtotime($from); $d <= strtotime($today); $d += 86400) {
            $k = date('Y-m-d', $d);
            if (isset($by_day[$k])) $series[] = ['label' => date('l, M j', $d), 'n' => (int) $by_day[$k]['n'], 'total' => (float) $by_day[$k]['total']];
        }
        $series_head = 'Day';
    } else {
        $cur_m = (int) date('n');
        $mm = [];
        for ($m = 1; $m <= $cur_m; $m++) $mm[$m] = ['label' => date('M', mktime(0, 0, 0, $m, 1)), 'n' => 0.0, 'orders' => 0];
        foreach ($rows("SELECT MONTH(paid_at) AS m, COUNT(*) AS n, SUM(amount_due) AS total FROM payments WHERE YEAR(paid_at) = YEAR(CURDATE()) GROUP BY m") as $r) {
            if (isset($mm[(int) $r['m']])) { $mm[(int) $r['m']]['n'] = (float) $r['total']; $mm[(int) $r['m']]['orders'] = (int) $r['n']; }
        }
        $chart = $mm;
        $cur_key = $cur_m;
        $prev_total = (float) $rows("SELECT COALESCE(SUM(amount_due), 0) AS t FROM payments WHERE YEAR(paid_at) = YEAR(CURDATE()) - 1 AND DAYOFYEAR(paid_at) <= DAYOFYEAR(CURDATE())")[0]['t'];
        $delta_label = 'vs same period last year';
        $period_word = 'in ' . date('Y');
        foreach ($mm as $m => $v) if ($v['orders'] > 0) $series[] = ['label' => date('F Y', mktime(0, 0, 0, $m, 1)), 'n' => $v['orders'], 'total' => $v['n']];
        $series_head = 'Month';
    }
} catch (Throwable $e) {
    error_log('[sales_report] ' . $e->getMessage());
    $error = true;
    $cur_key = ''; $delta_label = ''; $period_word = ''; $series_head = '';
}

$avg = $count ? $total / $count : 0;
$delta_html = '';
if ($prev_total > 0) {
    $p = (int) round(($total - $prev_total) / $prev_total * 100);
    $cls = $p > 0 ? 'is-up' : ($p < 0 ? 'is-down' : 'is-flat');
    $ico = $p > 0 ? 'bi-arrow-up-right' : ($p < 0 ? 'bi-arrow-down-right' : 'bi-dash');
    $delta_html = '<span class="wd-delta ' . $cls . '"><i class="bi ' . $ico . '"></i>' . abs($p) . '%</span> ' . $delta_label;
}

// Bars: ang dashboard's chart, drawn with pesos. Short labels above the bars (1.4k), exact amounts on hover.
$short = function ($n) { return $n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k' : (string) (int) round($n); };
$render_bars = function (array $items, $current_key) use ($short) {
    $its = array_values($items); $keys = array_keys($items);
    $vals = array_column($its, 'n'); $cnt = count($its);
    $W = 560; $H = 196; $px = 8; $pt = 30; $pb = 30;
    $max = max(1, max($vals ?: [1]));
    $plotH = $H - $pt - $pb; $plotW = $W - 2 * $px; $bar_w = 34;
    $gap = max(8, min(28, $cnt > 1 ? ($plotW - $cnt * $bar_w) / ($cnt - 1) : 0));
    $group_w = $cnt * $bar_w + $gap * ($cnt - 1);
    $offset = ($plotW - $group_w) / 2 + $px;
    $base = $pt + $plotH;
    $bars = $vals_txt = $labs = '';
    $best = $its ? $its[0] : ['n' => 0, 'label' => ''];
    foreach ($its as $i => $it) {
        if ($it['n'] > $best['n']) $best = $it;
        $d = $i * 55;
        $x = $offset + $i * ($bar_w + $gap);
        $h = max(3, round($plotH * ($it['n'] / $max), 1));
        $y = $base - $h;
        $cur = ($keys[$i] == $current_key);
        $fill = $cur ? 'url(#srBarB)' : 'url(#srBarA)';
        $bars .= '<rect class="wd-bar' . ($cur ? ' is-current' : '') . '" style="animation-delay:' . $d . 'ms" x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . $bar_w . '" height="' . round($h + 6, 1) . '" rx="6" fill="' . $fill . '"><title>' . htmlspecialchars($it['label'] . ': ' . ui_money($it['n'])) . '</title></rect>';
        $vals_txt .= '<text class="wd-bar-val' . ($cur ? ' is-current' : '') . '" style="animation-delay:' . ($d + 340) . 'ms" x="' . round($x + $bar_w / 2, 1) . '" y="' . round($y - 8, 1) . '" text-anchor="middle">' . $short($it['n']) . '</text>';
        $labs .= '<text class="wd-bar-lab" style="animation-delay:' . ($d + 400) . 'ms" x="' . round($x + $bar_w / 2, 1) . '" y="' . ($base + 18) . '" text-anchor="middle">' . htmlspecialchars($it['label']) . '</text>';
    }
    $avg = $cnt ? array_sum($vals) / $cnt : 0;
    return '<svg class="wd-bars" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Sales trend"><defs>'
         . '<linearGradient id="srBarA" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#dbeafe"/><stop offset="0.07" stop-color="#93c5fd"/><stop offset="1" stop-color="#3b82f6"/></linearGradient>'
         . '<linearGradient id="srBarB" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#93c5fd"/><stop offset="0.07" stop-color="#3b82f6"/><stop offset="1" stop-color="#1e3a8a"/></linearGradient>'
         . '<linearGradient id="srBarL" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#cdd6e6" stop-opacity="0"/><stop offset="0.1" stop-color="#c3cddd"/><stop offset="0.9" stop-color="#c3cddd"/><stop offset="1" stop-color="#cdd6e6" stop-opacity="0"/></linearGradient>'
         . '</defs>' . $bars
         . '<rect class="wd-bar-mask" x="0" y="' . $base . '" width="' . $W . '" height="8"/>'
         . '<line class="wd-bars-base" stroke="url(#srBarL)" stroke-width="1.25" x1="0" x2="' . $W . '" y1="' . $base . '" y2="' . $base . '"/>'
         . $vals_txt . $labs . '</svg>'
         . '<p class="wd-facts"><span>Average <b>' . ui_money($avg) . '</b></span><span>Best <b>' . ui_money($best['n']) . '</b> <i>' . htmlspecialchars($best['label']) . '</i></span></p>';
};
$method_badge = function ($m) { return '<span class="ot-badge is-compact ' . ($m === 'Cash' ? 'ot-b-cash' : 'ot-b-gcash') . '">' . htmlspecialchars($m) . '</span>'; };
$period_label = $range === 'year' ? date('Y') : date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($today));
$csv_url = 'sales_report.php?range=' . $range . '&export=csv';
$gen = date('M j, Y g:i A') . ' by ' . ($_SESSION['full_name'] ?? 'Admin');

include __DIR__ . '/components/header.php';
?>
<style>
    .sr-print-head { display: none; }
    .sr-btn { height: 38px; border-radius: 11px; border: 1px solid #e3e8f2; font-weight: 600; font-size: .8125rem; display: inline-flex; align-items: center; gap: 7px; padding: 0 14px; white-space: nowrap; text-decoration: none; cursor: pointer; transition: transform .15s ease, box-shadow .15s ease, filter .15s ease, background .15s ease; }
    .sr-btn-light { background: #fff; color: #33415a; }
    .sr-btn-light:hover { background: #f6f9ff; color: #1d4ed8; border-color: rgba(37,99,235,.45); }
    .sr-btn-primary { border: 0; color: #fff; background: linear-gradient(180deg, #3b82f6, #1d4ed8); box-shadow: 0 8px 18px -10px rgba(37,99,235,.8), inset 0 1px 0 rgba(255,255,255,.22); }
    .sr-btn-primary:hover { color: #fff; transform: translateY(-1px); filter: brightness(1.05); }
    .sr-tpanel { padding: 0; overflow: hidden; }
    .sr-tpanel .wd-panel-head { padding: 20px 22px 14px; margin: 0; }
    .sr-tpanel .ot-table thead th:first-child, .sr-tpanel .ot-table tbody td:first-child { padding-left: 22px; }
    .sr-tpanel .ot-table tbody tr:last-child td { border-bottom: 0; }
    .sr-bar { display: block; height: 6px; border-radius: 99px; background: #e2ecfe; min-width: 80px; }
    .sr-bar i { display: block; height: 100%; border-radius: 99px; background: linear-gradient(90deg, #3b82f6, #1d4ed8); }
    .sr-pct { font-size: .75rem; color: #5b6b80; font-weight: 600; }
    .sr-none { padding: 34px 22px 38px; text-align: center; color: #5b6b80; font-size: .875rem; }
    .sr-methods { display: flex; flex-direction: column; gap: 14px; flex: 1 1 auto; }
    .sr-method { flex: 1 1 0; display: flex; flex-direction: column; justify-content: center; gap: 7px; padding: 16px 18px; border: 1px solid #edf1f8; border-radius: 14px; background: #fbfcfe; }
    .sr-method-amt { font-family: "Space Grotesk", "Inter", system-ui, sans-serif; font-size: 1.7rem; font-weight: 600; letter-spacing: -.03em; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .sr-row2 { align-items: stretch; }
    .wd-page .wd-main { margin-top: 0; }
    .wd-page .sr-row2 { margin-top: 22px; }
    @media print {
        @page { size: A4 portrait; margin: 14mm; }
        body { margin-left: 0 !important; background: #fff !important; }
        #sidebar, .app-header, .no-print, .toast-container { display: none !important; }
        #content { padding: 0 !important; }
        #content::before { display: none !important; }
        .wd-page { padding: 0 !important; }
        .sr-print-head { display: block; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 2px solid #0b1220; }
        .sr-print-head h1 { font-size: 20px; margin: 0; }
        .sr-print-head p { margin: 2px 0 0; font-size: 12px; color: #333; }
        .wd-panel { box-shadow: none !important; break-inside: avoid; }
        .wd-main { grid-template-columns: minmax(0, 1fr) !important; }
        .ot-table tbody tr, .wd-bar, .wd-bar-val, .wd-bar-lab { animation: none !important; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>

<div class="container-fluid wd-page">

    <div class="sr-print-head">
        <h1>Sparkling Spin &mdash; Sales Report</h1>
        <p><?php echo $range === 'year' ? 'Annual' : 'Weekly'; ?> &middot; <?php echo htmlspecialchars($period_label); ?></p>
        <p>Generated <?php echo htmlspecialchars($gen); ?></p>
    </div>

    <?php if ($error): ?>
        <div class="ot-hint"><i class="bi bi-exclamation-triangle"></i><span>Could not load the report. Please try again.</span></div>
    <?php endif; ?>

    <!-- The trend: same panel as Completed orders sa dashboard, in pesos. -->
    <div class="wd-main">
    <section class="wd-panel wd-done" aria-label="Sales">
        <div class="wd-panel-head">
            <h2 class="wd-h2"><span class="wd-h2-ico" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>Sales</h2>
            <div class="d-flex align-items-center flex-wrap gap-3">
                <div class="wd-tabs no-print" role="tablist" aria-label="Report period">
                    <button type="button" role="tab" class="<?php echo $range === 'week' ? 'is-active' : ''; ?>" aria-selected="<?php echo $range === 'week' ? 'true' : 'false'; ?>" onclick="location.href='sales_report.php?range=week'">Weekly</button>
                    <button type="button" role="tab" class="<?php echo $range === 'year' ? 'is-active' : ''; ?>" aria-selected="<?php echo $range === 'year' ? 'true' : 'false'; ?>" onclick="location.href='sales_report.php?range=year'">Annual</button>
                </div>
                <div class="d-flex gap-2 no-print">
                    <button type="button" class="sr-btn sr-btn-light" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                    <a class="sr-btn sr-btn-primary" href="<?php echo htmlspecialchars($csv_url); ?>"><i class="bi bi-download"></i> Export CSV</a>
                </div>
            </div>
        </div>
        <div class="wd-pane is-revealed">
            <div class="wd-fig"><span class="wd-fig-num"><?php echo ui_money($total); ?></span><span class="wd-fig-label"><?php echo $period_word; ?> &middot; <?php echo $count; ?> order<?php echo $count === 1 ? '' : 's'; ?> paid<?php if ($count): ?> &middot; <?php echo ui_money($avg); ?> average<?php endif; ?></span></div>
            <p class="wd-fig-cmp"><?php echo $delta_html; ?></p>
            <?php echo $render_bars($chart, $cur_key); ?>
        </div>
    </section>

        <section class="wd-panel" aria-label="Sales by payment method">
            <div class="wd-panel-head"><h2 class="wd-h2"><span class="wd-h2-ico" aria-hidden="true"><i class="bi bi-wallet2"></i></span>By payment method</h2></div>
            <?php if (!$by_method): ?>
                <div class="sr-none">No sales <?php echo $period_word; ?> yet.</div>
            <?php else: ?>
            <div class="sr-methods">
                <?php foreach ($by_method as $m): $mshare = $total > 0 ? (float) $m['total'] / $total * 100 : 0; ?>
                    <div class="sr-method">
                        <div class="d-flex justify-content-between align-items-center"><?php echo $method_badge($m['method']); ?><span class="sr-pct"><?php echo round($mshare); ?>%</span></div>
                        <div class="sr-method-amt"><?php echo ui_money($m['total']); ?></div>
                        <div class="sr-pct"><?php echo (int) $m['n']; ?> order<?php echo (int) $m['n'] === 1 ? '' : 's'; ?></div>
                        <span class="sr-bar"><i style="width:<?php echo round($mshare); ?>%"></i></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="wd-main sr-row2">
        <section class="wd-panel sr-tpanel" aria-label="Sales by service">
            <div class="wd-panel-head"><h2 class="wd-h2"><span class="wd-h2-ico" aria-hidden="true"><i class="bi bi-tags"></i></span>By service</h2></div>
            <?php if (!$by_service): ?>
                <div class="sr-none">No sales <?php echo $period_word; ?> yet.</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="ot-table">
                <thead><tr><th>Service</th><th>Orders</th><th>Weight</th><th>Sales</th></tr></thead>
                <tbody>
                <?php foreach ($by_service as $sv): ?>
                    <tr>
                        <td class="ot-cust-name"><?php echo htmlspecialchars($sv['name']); ?></td>
                        <td><?php echo (int) $sv['n']; ?></td>
                        <td><?php echo number_format((float) $sv['kg'], 1); ?> kg</td>
                        <td class="ot-amount"><?php echo ui_money($sv['total']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </section>

        <section class="wd-panel sr-tpanel" aria-label="Sales by <?php echo strtolower($series_head); ?>">
        <div class="wd-panel-head"><h2 class="wd-h2"><span class="wd-h2-ico" aria-hidden="true"><i class="bi bi-calendar3"></i></span>By <?php echo strtolower($series_head); ?></h2></div>
        <?php if (!$series): ?>
            <div class="sr-none">No sales <?php echo $period_word; ?> yet.</div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="ot-table">
            <thead><tr><th><?php echo $series_head; ?></th><th>Orders</th><th>Sales</th></tr></thead>
            <tbody>
            <?php foreach ($series as $r): ?>
                <tr>
                    <td class="ot-cust-name"><?php echo htmlspecialchars($r['label']); ?></td>
                    <td><?php echo (int) $r['n']; ?></td>
                    <td class="ot-amount"><?php echo ui_money($r['total']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
        </section>

    </div>

</div>

<?php include __DIR__ . '/components/footer.php'; ?>    