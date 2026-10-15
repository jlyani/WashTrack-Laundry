<?php
// dashboard.php - shift overview (admin). Create-order lead card, three
// pipeline cards, a completed-orders bar chart, and the live orders on a
// white panel as simple rows (5 most urgent).
// Ang main page ng Admin. Shows the day's summary: Create Order card, counts
// of Pending/In Progress/Ready, completed chart, and the 5 most urgent active orders.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SERVER-SIDE GUARD (check bago load)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?error=unauthorized");
    exit();
}
// The dashboard is for administrators lang; staff work from Order Tracking.
// Dashboard is for Admin only; Staff use view_order.php.
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: view_order.php");
    exit();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/machines.php';
require_once __DIR__ . '/includes/order_view.php';   // also loads sla.php
require_once __DIR__ . '/includes/ui.php';
$machines_sql = machine_labels_sql($conn);
$is_admin = true;

// Packages ng New Order modal
$services = [];
$srv_res = $conn->query("SELECT service_id, name AS service_name, service_type, includes_fold, price_per_load FROM services WHERE is_active = 1 ORDER BY price_per_load ASC, name ASC");
if ($srv_res) { while ($srv_row = $srv_res->fetch_assoc()) { $services[] = $srv_row; } }

// Counts (mga bilang)
// Count of orders per stage (Pending / In Progress / Ready) para sa pipeline cards.
$one = function ($sql, $field = 'total') use ($conn) {
    $r = $conn->query($sql);
    return ($r && ($row = $r->fetch_assoc())) ? (float) ($row[$field] ?? 0) : 0;
};
$pending_count  = (int) $one("SELECT COUNT(*) AS total FROM orders WHERE order_status = 'Pending'");
$progress_count = (int) $one("SELECT COUNT(*) AS total FROM orders WHERE order_status = 'In Progress'");
$ready_count    = (int) $one("SELECT COUNT(*) AS total FROM orders WHERE order_status = 'Ready for Pickup'");

// Overdue orders (mga late)
$overdue_orders = [];
try {
    $ov_res = $conn->query("SELECT o.order_id, o.order_number, c.full_name
                            FROM orders o JOIN customers c ON c.customer_id = o.customer_id
                            WHERE " . sla_overdue_sql($conn) . " ORDER BY o.created_at ASC, o.order_id ASC");
    if ($ov_res) { while ($ov = $ov_res->fetch_assoc()) $overdue_orders[] = $ov; }
} catch (Throwable $e) { error_log('[dashboard] overdue: ' . $e->getMessage()); }

// Active orders, overdue muna
// The 5 active orders (overdue muna) shown in the "Active orders" panel on the right.
$recent_orders = [];
$orders_res = $conn->query(
    "SELECT o.order_id, o.order_number, c.full_name AS customer_name, c.phone AS contact_number, o.service_id, s.name AS service_type,
            o.weight_kg AS weight, o.load_type, o.loads, o.total_amount, o.payment_status, o.order_status AS status, o.created_at AS order_date, o.notes,
            {$machines_sql} AS machines, " . sla_promised_sql($conn) . " AS promised_at, " . sla_overdue_sql($conn) . " AS is_overdue
     FROM orders o JOIN customers c ON o.customer_id = c.customer_id JOIN services s ON o.service_id = s.service_id
     WHERE o.order_status NOT IN ('Released', 'Cancelled')
     ORDER BY is_overdue DESC, o.created_at DESC, o.order_id DESC LIMIT 5"
);
if ($orders_res) { while ($ord = $orders_res->fetch_assoc()) { $recent_orders[] = $ord; } }

// Completed report: Released orders only (counts lang, no money), last 8 weeks and this year by month
$weeks = [];
for ($i = 7; $i >= 0; $i--) {
    $mon = strtotime("monday this week -$i week");
    $weeks[date('oW', $mon)] = ['label' => date('M j', $mon), 'n' => 0];
}
$wk_start = date('Y-m-d', strtotime('monday this week -7 week'));
$q = $conn->query("SELECT YEARWEEK(released_at, 1) AS yw, COUNT(*) AS n
                   FROM orders WHERE order_status = 'Released' AND released_at >= '{$wk_start}' GROUP BY yw");
while ($q && ($r = $q->fetch_assoc())) {
    if (isset($weeks[$r['yw']])) { $weeks[$r['yw']]['n'] = (int) $r['n']; }
}
$months = [];
for ($m = 1; $m <= 12; $m++) { $months[$m] = ['label' => date('M', mktime(0, 0, 0, $m, 1)), 'n' => 0]; }
$q = $conn->query("SELECT MONTH(released_at) AS m, COUNT(*) AS n
                   FROM orders WHERE order_status = 'Released' AND YEAR(released_at) = YEAR(CURDATE()) GROUP BY m");
while ($q && ($r = $q->fetch_assoc())) {
    // keep the ['label','n'] shape — replacing the whole entry would drop the
    // label, kaya array_column($months, 'n') would skip this whole month.
    $m = (int) $r['m'];
    if (isset($months[$m])) { $months[$m]['n'] = (int) $r['n']; }
}
$this_week = end($weeks);
$year_n = array_sum(array_column($months, 'n'));
$cur_month = (int) date('n');

// Trend vs the same point in the previous period (fair comparison, hindi partial week vs a full one)
$wk_prev_from = date('Y-m-d', strtotime('monday this week -1 week'));
$prev_week_n = (int) $one("SELECT COUNT(*) AS total FROM orders WHERE order_status = 'Released' AND released_at >= '{$wk_prev_from}' AND released_at < NOW() - INTERVAL 7 DAY");
$prev_year_n = (int) $one("SELECT COUNT(*) AS total FROM orders WHERE order_status = 'Released' AND YEAR(released_at) = YEAR(CURDATE()) - 1 AND DAYOFYEAR(released_at) <= DAYOFYEAR(CURDATE())");
$render_delta = function ($now, $prev, $label) {
    if ($prev <= 0) return '';
    $p = (int) round(($now - $prev) / $prev * 100);
    $cls = $p > 0 ? 'is-up' : ($p < 0 ? 'is-down' : 'is-flat');
    $ico = $p > 0 ? 'bi-arrow-up-right' : ($p < 0 ? 'bi-arrow-down-right' : 'bi-dash');
    return '<span class="wd-delta ' . $cls . '"><i class="bi ' . $ico . '"></i>' . abs($p) . '%</span> ' . $label;
};

// Bar chart: equal-width bars for any period. Fixed ang bar width, kaya
// weekly (8 bars) and annual (up to 12) read at the same thickness; only
// the gaps flex. Each column is filled like water — pale at the surface,
// deepening toward the baseline — and the current period runs deeper than
// the rest. Per-bar delays are inline so the columns rise in sequence.
$render_bars = function (array $items, $current_key, $avg_label, $best_label) {
    static $uid = 0;
    $uid++;
    $gid_a = 'wdBarA' . $uid;   // standing water (mga past periods)
    $gid_b = 'wdBarB' . $uid;   // this period (kasalukuyan)
    $gid_l = 'wdBarL' . $uid;   // baseline, fading out sa both edges
    $its = array_values($items);
    $keys = array_keys($items);
    $vals = array_column($its, 'n');
    $cnt = count($its);
    $W = 560;
    $H = 196;
    $px = 8;
    $pt = 30;
    $pb = 30;
    $max = max(1, max($vals));
    $plotH = $H - $pt - $pb;
    $plotW = $W - 2 * $px;
    $bar_w = 34;
    $gap = max(8, min(28, $cnt > 1 ? ($plotW - $cnt * $bar_w) / ($cnt - 1) : 0));
    $group_w = $cnt * $bar_w + $gap * ($cnt - 1);
    $offset = ($plotW - $group_w) / 2 + $px;
    $base = $pt + $plotH;
    $bars = '';
    $vals_txt = '';
    $labs = '';
    $best = $its[0];
    foreach ($its as $i => $it) {
        if ($it['n'] > $best['n']) $best = $it;
        $d = $i * 55;
        $x = $offset + $i * ($bar_w + $gap);
        $h = max(3, round($plotH * ($it['n'] / $max), 1));
        $y = $base - $h;
        $cur = ($keys[$i] == $current_key);
        $fill = $cur ? 'url(#' . $gid_b . ')' : 'url(#' . $gid_a . ')';
        // extend 6px below the baseline so only the top corners are rounded
        $bars .= '<rect class="wd-bar' . ($cur ? ' is-current' : '') . '" style="animation-delay:' . $d . 'ms" x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . $bar_w . '" height="' . round($h + 6, 1) . '" rx="6" fill="' . $fill . '"><title>' . htmlspecialchars($it['label'] . ': ' . $it['n'] . ' completed') . '</title></rect>';
        $vals_txt .= '<text class="wd-bar-val' . ($cur ? ' is-current' : '') . '" style="animation-delay:' . ($d + 340) . 'ms" x="' . round($x + $bar_w / 2, 1) . '" y="' . round($y - 8, 1) . '" text-anchor="middle">' . (int) $it['n'] . '</text>';
        $labs .= '<text class="wd-bar-lab" style="animation-delay:' . ($d + 400) . 'ms" x="' . round($x + $bar_w / 2, 1) . '" y="' . ($base + 18) . '" text-anchor="middle">' . htmlspecialchars($it['label']) . '</text>';
    }
    $avg = $cnt ? round(array_sum($vals) / $cnt, 1) : 0;
    return '<svg class="wd-bars" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Completed orders trend">'
         . '<defs>'
         . '<linearGradient id="' . $gid_a . '" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="#dbeafe"/><stop offset="0.07" stop-color="#93c5fd"/><stop offset="1" stop-color="#3b82f6"/>'
         . '</linearGradient>'
         . '<linearGradient id="' . $gid_b . '" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="#93c5fd"/><stop offset="0.07" stop-color="#3b82f6"/><stop offset="1" stop-color="#1e3a8a"/>'
         . '</linearGradient>'
         . '<linearGradient id="' . $gid_l . '" x1="0" y1="0" x2="1" y2="0">'
         . '<stop offset="0" stop-color="#cdd6e6" stop-opacity="0"/><stop offset="0.1" stop-color="#c3cddd" stop-opacity="1"/>'
         . '<stop offset="0.9" stop-color="#c3cddd" stop-opacity="1"/><stop offset="1" stop-color="#cdd6e6" stop-opacity="0"/>'
         . '</linearGradient>'
         . '</defs>'
         . $bars
         . '<rect class="wd-bar-mask" x="0" y="' . $base . '" width="' . $W . '" height="8"/>'
         . '<line class="wd-bars-base" stroke="url(#' . $gid_l . ')" stroke-width="1.25" x1="0" x2="' . $W . '" y1="' . $base . '" y2="' . $base . '"/>'
         . $vals_txt . $labs . '</svg>'
         . '<p class="wd-facts"><span>' . $avg_label . ' <b>' . $avg . '</b></span><span>' . $best_label . ' <b>' . $best['n'] . '</b> <i>' . htmlspecialchars($best['label']) . '</i></span></p>';
};

$ov_n = count($overdue_orders);
$stages = [
    ['Pending',          'Pending',          $pending_count,  'pending',  'Not started yet',   'bi-hourglass'],
    ['In Progress',      'In progress',      $progress_count, 'progress', 'Washing or drying', 'bi-arrow-clockwise'],
    ['Ready for Pickup', 'Ready for pickup', $ready_count,    'ready',    'Awaiting pickup',   'bi-bag-check'],
];
$status_key = function ($s) {
    $s = strtolower(trim($s));
    return strpos($s, 'ready') !== false ? 'ready' : (strpos($s, 'progress') !== false ? 'progress' : 'pending');
};
// Monogram ng customer avatar on each order card.
$initials = function ($name) {
    $parts = preg_split('/\s+/', trim((string) $name));
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return strtoupper($first . $last);
};
// Age of the order for the feed: "just now", "12m ago", "3h ago", "2d ago", tapos a date.
$ago = function ($dt) {
    $ts = strtotime((string) $dt);
    if (!$ts) { return ''; }
    $s = time() - $ts;
    if ($s < 60)    { return 'just now'; }
    if ($s < 3600)  { return floor($s / 60) . 'm ago'; }
    if ($s < 86400) { return floor($s / 3600) . 'h ago'; }
    if ($s < 604800){ return floor($s / 86400) . 'd ago'; }
    return date('M j', $ts);
};

$page_scripts = ['assets/js/orders-ui.js'];
include __DIR__ . '/components/header.php';
?>

<style>
/* Active orders: the blue panel, calm lang — no ripple grain, light sweeps, glowing avatars, row shine or inner scrolling. */
.wd-page .wd-active { background: linear-gradient(160deg, #123a94 0%, #1d4ed8 100%); }
.wd-page .wd-active::before, .wd-page .wd-active::after, .wd-page .wd-order::after { display: none; }
.wd-page .wd-stack { max-height: none; overflow: visible; }
.wd-page .wd-order { animation: none; padding: 12px 8px; border-top-color: rgba(255, 255, 255, 0.14); }
.wd-page .wd-order:first-child { border-top: 0; }
.wd-page .wd-order:hover, .wd-page .wd-order.is-pending:hover, .wd-page .wd-order.is-progress:hover, .wd-page .wd-order.is-ready:hover { background: rgba(255, 255, 255, 0.1); box-shadow: none; border-radius: 12px; }
.wd-page .wd-order .wd-avatar, .wd-page .wd-order.is-pending .wd-avatar, .wd-page .wd-order.is-progress .wd-avatar, .wd-page .wd-order.is-ready .wd-avatar, .wd-page .wd-order .wd-avatar.is-overdue { width: 36px; height: 36px; border-radius: 10px; background: rgba(255, 255, 255, 0.16); color: #fff; box-shadow: none; }
.wd-page .wd-order:hover .wd-avatar { transform: none; box-shadow: none; }
/* View all / Sales report hover: a soft pill behind the label (no lift, underline or arrow) */
.wd-page .wd-link { transition: background-color 0.15s ease, color 0.15s ease; }
.wd-page .wd-link::after { display: none; }
.wd-page .wd-link:hover { transform: none; text-decoration: none; background: #eaf1ff; color: var(--wd-brand-2); }
.wd-page .wd-active .wd-link:hover { background: rgba(255, 255, 255, 0.16); color: #fff; }
</style>

<div class="container-fluid wd-page">

    <!-- Row one: Create Order card muna, then the live pipeline. -->
    <section class="wd-cards" aria-label="Order pipeline">
        <button type="button" class="wd-card wd-card-new" data-bs-toggle="modal" data-bs-target="#addOrderModal">
            <span class="wd-card-label">Create order</span>
            <span class="wd-new-ico" aria-hidden="true"><i class="bi bi-plus-lg"></i></span>
            <span class="wd-card-meta">Log a new pickup</span>
        </button>
        <?php foreach ($stages as $s): ?>
        <button type="button" class="wd-card is-<?php echo $s[3]; ?>" data-filter="<?php echo htmlspecialchars($s[0]); ?>" aria-pressed="false">
            <span class="wd-card-label"><i class="bi <?php echo $s[5]; ?>" aria-hidden="true"></i><?php echo $s[1]; ?></span>
            <span class="wd-card-num<?php echo (int) $s[2] === 0 ? ' is-zero' : ''; ?>" data-count="<?php echo (int) $s[2]; ?>"><?php echo (int) $s[2]; ?></span>
            <span class="wd-card-meta"><?php echo $s[4]; ?></span>
        </button>
        <?php endforeach; ?>
    </section>

    <!-- Row two: completed trend at live orders, side by side. -->
    <div class="wd-main">

        <!-- Completed orders: period toggle at equal-width bar chart. -->
        <section class="wd-panel wd-done" aria-label="Completed orders">
            <div class="wd-panel-head">
                <h2 class="wd-h2"><span class="wd-h2-ico" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span>Completed orders</h2>
                <div class="wd-tabs" role="tablist" aria-label="Report period">
                    <span class="wd-tabs-thumb" aria-hidden="true"></span>
                    <button type="button" role="tab" id="tab-week" aria-selected="true" aria-controls="pane-week" class="is-active" data-pane="pane-week">Weekly</button>
                    <button type="button" role="tab" id="tab-year" aria-selected="false" aria-controls="pane-year" data-pane="pane-year">Annual</button>
                </div>
            </div>
            <div class="wd-pane" id="pane-week" role="tabpanel" aria-labelledby="tab-week">
                <div class="wd-fig"><span class="wd-fig-num" data-count="<?php echo $this_week['n']; ?>"><?php echo $this_week['n']; ?></span><span class="wd-fig-label">this week</span></div>
                <p class="wd-fig-cmp"><?php echo $render_delta($this_week['n'], $prev_week_n, 'vs same days last week'); ?></p>
                <?php echo $render_bars($weeks, date('oW'), 'Average', 'Best'); ?>
                <a href="sales_report.php?range=week" class="wd-link">Sales report</a>
            </div>
            <div class="wd-pane" id="pane-year" role="tabpanel" aria-labelledby="tab-year" hidden>
                <div class="wd-fig"><span class="wd-fig-num" data-count="<?php echo $year_n; ?>"><?php echo $year_n; ?></span><span class="wd-fig-label">in <?php echo date('Y'); ?></span></div>
                <p class="wd-fig-cmp"><?php echo $render_delta($year_n, $prev_year_n, 'vs same period last year'); ?></p>
                <?php echo $render_bars(array_slice($months, 0, $cur_month, true), $cur_month, 'Average', 'Best'); ?>
                <a href="sales_report.php?range=year" class="wd-link">Sales report</a>
            </div>
        </section>

        <!-- Active orders: flat rows direkta sa water panel, with one View all. -->
        <section class="wd-panel wd-active" aria-label="Active orders">
            <div class="wd-panel-head">
                <h2 class="wd-h2"><span class="wd-h2-ico" aria-hidden="true"><i class="bi bi-lightning-charge-fill"></i></span>Active orders</h2>
                <div class="wd-head-actions">
                    <a href="view_order.php" class="wd-link" id="wd-all-link">View all</a>
                </div>
            </div>
            <?php if ($recent_orders): ?>
            <div class="wd-stack">
                <?php foreach ($recent_orders as $ri => $ord):
                    $st = trim($ord['status']);
                    $sk = $status_key($st);
                    $is_overdue = !empty($ord['is_overdue']);
                ?>
                    <article class="wd-order is-<?php echo $sk; ?>" style="animation-delay:<?php echo (int) min($ri * 55, 660); ?>ms" data-order="<?php echo ot_order_json($ord, $is_admin); ?>" data-status="<?php echo htmlspecialchars($st); ?>" data-overdue="<?php echo $is_overdue ? '1' : '0'; ?>" role="button" tabindex="0" title="View order <?php echo htmlspecialchars($ord['order_number']); ?>" aria-label="View order <?php echo htmlspecialchars($ord['order_number']) . ' for ' . htmlspecialchars($ord['customer_name']); ?>">
                        <span class="wd-avatar<?php echo $is_overdue ? ' is-overdue' : ''; ?>" aria-hidden="true"><?php echo htmlspecialchars($initials($ord['customer_name'])); ?></span>
                        <div class="wd-order-main min-w-0">
                            <div class="wd-order-r1">
                                <span class="wd-who-name text-truncate"><?php echo htmlspecialchars($ord['customer_name']); ?></span>
                                <span class="wd-amount"><?php echo ui_money($ord['total_amount']); ?></span>
                            </div>
                            <div class="wd-order-r2">
                                <span class="wd-sub text-truncate"><?php echo htmlspecialchars($ord['service_type']); ?></span>
                                <span class="wd-when"><i class="bi bi-clock" aria-hidden="true"></i><?php echo htmlspecialchars($ago($ord['order_date'])); ?></span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="wd-none" id="wd-none" hidden><i class="bi bi-funnel" aria-hidden="true"></i><span>No orders match this stage.</span></div>
            <?php else: ?>
            <div class="wd-empty">
                <span class="wd-empty-ico" aria-hidden="true"><i class="bi bi-droplet"></i></span>
                <strong>No active orders</strong>
                <span>New orders appear here as soon as they are logged.</span>
                <button type="button" class="wd-cta" data-bs-toggle="modal" data-bs-target="#addOrderModal"><i class="bi bi-plus-lg" aria-hidden="true"></i> New order</button>
            </div>
            <?php endif; ?>
        </section>

    </div>
</div>

<script>
(function () {
    var root = document.querySelector('.wd-page');
    if (!root) return;
    var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    /* --- one orchestrated moment: figures roll as the columns rise --- */
    function roll(el) {
        var target = parseInt(el.getAttribute('data-count'), 10);
        if (isNaN(target)) return;
        if (reduce || target === 0) { el.textContent = target; return; }
        var t0 = performance.now(), dur = 640;
        function step(now) {
            var p = Math.min(1, (now - t0) / dur);
            el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3)));
            if (p < 1) { requestAnimationFrame(step); } else { el.textContent = target; }
        }
        requestAnimationFrame(step);
    }
    function reveal(pane) {
        if (!pane) return;
        pane.classList.remove('is-revealed');
        void pane.offsetWidth; // restart the rise (paandarin muli)
        pane.classList.add('is-revealed');
        [].forEach.call(pane.querySelectorAll('[data-count]'), roll);
    }

    /* --- report tabs: a white pill slides under the active label --- */
    var tabs = [].slice.call(root.querySelectorAll('.wd-tabs button'));
    var tabsBox = root.querySelector('.wd-tabs');
    var thumb = tabsBox && tabsBox.querySelector('.wd-tabs-thumb');
    function placeThumb() {
        if (!thumb) return;
        var on = tabsBox.querySelector('button.is-active');
        if (!on) return;
        thumb.style.width = on.offsetWidth + 'px';
        thumb.style.transform = 'translateX(' + on.offsetLeft + 'px)';
        tabsBox.classList.add('has-thumb');
    }
    if (thumb) {
        thumb.style.transition = 'none'; // seat it muna before the first paint
        placeThumb();
        void thumb.offsetWidth;
        thumb.style.transition = '';
        window.addEventListener('resize', placeThumb);
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(placeThumb); }
    }
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) {
                var on = t === tab;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                var pane = document.getElementById(t.dataset.pane);
                if (pane) pane.hidden = !on;
            });
            placeThumb();
            reveal(document.getElementById(tab.dataset.pane)); // the chart refills
        });
    });

    // The pipeline cards (at the overdue shortcut) filter the active-orders stack.
    var rows = [].slice.call(root.querySelectorAll('.wd-order[data-status]'));
    var nodes = [].slice.call(root.querySelectorAll('[data-filter]'));
    var link = root.querySelector('#wd-all-link'), none = root.querySelector('#wd-none');
    var cur = '';
    var say = { // the empty note names the stage it was asked about
        'Pending': 'No pending orders right now.',
        'In Progress': 'Nothing is being washed or dried right now.',
        'Ready for Pickup': 'No orders are waiting for pickup right now.',
        'overdue': 'Nothing overdue \u2014 every order is on time.'
    };
    function apply(f) {
        cur = f;
        var n = 0;
        rows.forEach(function (r) {
            var show = !f || (f === 'overdue' ? r.dataset.overdue === '1' : r.dataset.status === f);
            r.hidden = !show;
            if (show) n++;
        });
        if (!reduce) { // the remaining rows step in again, softly
            rows.forEach(function (r) {
                if (r.hidden) return;
                r.style.animation = 'none';
                void r.offsetWidth;
                r.style.animation = '';
            });
        }
        nodes.forEach(function (b) {
            var on = b.dataset.filter === f;
            b.classList.toggle('is-on', on);
            if (b.classList.contains('wd-card') || b.classList.contains('wd-overdue')) {
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            }
        });
        if (link) link.href = 'view_order.php' + (f ? '?status=' + encodeURIComponent(f === 'overdue' ? 'Overdue' : f) : '');
        if (none) {
            var msg = none.querySelector('span');
            if (msg) msg.textContent = say[f] || 'No orders match this stage.';
            none.hidden = n > 0;
        }
    }
    nodes.forEach(function (b) { b.addEventListener('click', function () { apply(cur === b.dataset.filter ? '' : b.dataset.filter); }); });

    // An active-order card is one big button: it opens that order's
    // read-only summary (the same modal the eye button uses elsewhere).
    function openOrder(row) {
        var o;
        try { o = JSON.parse(row.dataset.order); } catch (err) { return; }
        var body = document.getElementById('orderDetailsBody');
        if (window.Alpine && body) { Alpine.$data(body).open(o); }
    }
    rows.forEach(function (r) {
        r.addEventListener('click', function () { openOrder(r); });
        r.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openOrder(r); }
        });
    });

    /* --- boot: the report, the pipeline, tapos light across the water --- */
    reveal(document.getElementById('pane-week'));
    [].forEach.call(root.querySelectorAll('.wd-card-num[data-count]'), roll);
    var feed = root.querySelector('.wd-active');
    if (feed) feed.classList.add('is-revealed');
})();
</script>

<?php
include __DIR__ . '/components/create_order_modal.php';
include __DIR__ . '/components/order_details_modal.php';
include __DIR__ . '/components/release_modal.php';
include __DIR__ . '/components/footer.php';