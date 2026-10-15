<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?error=unauthorized");
    exit();
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ui.php';

// One feed built from the two tables that already record who did what and when. Nothing is written twice.
$types  = ['Status' => 'Order status', 'Payment' => 'Payments'];
$type   = isset($types[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$search = trim($_GET['search'] ?? '');
$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));

$feed = "
    SELECT 'Status' AS kind, h.changed_at AS at, u.full_name AS actor, o.order_number AS order_number,
           CASE WHEN h.from_status IS NULL THEN 'Order created'
                WHEN h.to_status = 'Cancelled' THEN CONCAT('Order cancelled (was ', h.from_status, ')')
                ELSE CONCAT(h.from_status, ' → ', h.to_status) END AS detail
      FROM order_status_history h
      JOIN orders o ON o.order_id = h.order_id
      JOIN users u  ON u.user_id  = h.changed_by
    UNION ALL
    SELECT 'Payment', p.paid_at, u.full_name, o.order_number,
           CONCAT(p.receipt_no, ' · ', p.method, ' ₱', FORMAT(p.amount_due, 2), IF(p.reference_no IS NULL OR p.reference_no = '', '', CONCAT(' · Ref ', p.reference_no)))
      FROM payments p
      JOIN orders o ON o.order_id = p.order_id
      JOIN users u  ON u.user_id  = p.received_by
";

$where = []; $params = []; $bind = '';
if ($type !== '') { $where[] = 'kind = ?'; $params[] = $type; $bind .= 's'; }
if ($search !== '') {
    $where[] = '(actor LIKE ? OR order_number LIKE ? OR detail LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, order_search_pattern($search), $like);
    $bind .= 'sss';
}
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$rows = []; $total = 0; $db_error = false; $total_pages = 1; $offset = 0;
try {
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM ($feed) f" . $where_sql);
    if ($bind !== '') $st->bind_param($bind, ...$params);
    $st->execute();
    $total = (int) $st->get_result()->fetch_assoc()['c'];
    $st->close();
    $total_pages = max(1, (int) ceil($total / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $st = $conn->prepare("SELECT * FROM ($feed) f" . $where_sql . " ORDER BY at DESC LIMIT ? OFFSET ?");
    $st->bind_param($bind . 'ii', ...array_merge($params, [$per_page, $offset]));
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $st->close();
} catch (Throwable $e) {
    error_log('[audit_log] ' . $e->getMessage());
    $db_error = true;
}

// Tab counts are global (the whole feed): never scoped by the search or the
// tab, so each tab always reports the true size of its bucket. The search box
// only filters the rows below, it never changes these numbers.
$tab_counts = ['' => 0, 'Status' => 0, 'Payment' => 0];
try {
    $st = $conn->prepare("SELECT kind, COUNT(*) AS c FROM ($feed) f GROUP BY kind");
    $st->execute();
    $res = $st->get_result();
    while ($rr = $res->fetch_assoc()) $tab_counts[$rr['kind']] = (int) $rr['c'];
    $st->close();
    $tab_counts[''] = $tab_counts['Status'] + $tab_counts['Payment'];
} catch (Throwable $e) {
    error_log('[audit_log counts] ' . $e->getMessage());
}

function audit_url(array $o = []) {
    $q = array_merge(['type' => $_GET['type'] ?? '', 'search' => $_GET['search'] ?? '', 'page' => 1], $o);
    $q = array_filter($q, function ($v, $k) { return $v !== '' && !($k === 'page' && (int) $v === 1); }, ARRAY_FILTER_USE_BOTH);
    return 'audit_log.php' . ($q ? '?' . http_build_query($q) : '');
}
$showing = $total === 0 ? 'No activity' : 'Showing ' . ($offset + 1) . '–' . ($offset + count($rows)) . ' of ' . $total . ' entries';
$has_filter = ($type !== '' || $search !== '');

include __DIR__ . '/components/header.php';
?>
<style>
.al-card{padding:14px 16px;border-bottom:1px solid var(--ot-line-soft)}
.al-card:last-child{border-bottom:0}
.al-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px}
.al-detail{font-size:.875rem;color:var(--ot-ink);word-break:break-word}
.al-pay strong{font-family:var(--ot-display);font-weight:700}
.al-sub{display:flex;flex-direction:column;gap:2px;margin-top:3px;font-size:.75rem;color:var(--ot-mute);word-break:break-all}
.al-meta{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:8px;font-size:.75rem;color:var(--ot-mute)}
.al-meta i{color:#93a4ba}
.al-cards .ot-empty{padding:48px 16px}
@media (max-width:575.98px){.ot-foot{flex-direction:column;align-items:center}}
</style>
<div class="container-fluid ot-page">

    <?php if ($db_error): ?><div class="alert alert-danger">Could not load the audit log.</div><?php endif; ?>

    <div class="ot-toolbar">
        <div class="ot-toolbar-group">
            <nav class="ot-tabs" aria-label="Activity type">
                <a class="ot-tab <?php echo $type === '' ? 'is-active' : ''; ?>" href="<?php echo audit_url(['type' => '', 'page' => 1]); ?>">All activity <span class="ot-tab-count"><?php echo $tab_counts['']; ?></span></a>
                <?php foreach ($types as $key => $label): ?>
                    <a class="ot-tab <?php echo $type === $key ? 'is-active' : ''; ?>" href="<?php echo audit_url(['type' => $key, 'page' => 1]); ?>"><?php echo $label; ?> <span class="ot-tab-count"><?php echo $tab_counts[$key]; ?></span></a>
                <?php endforeach; ?>
            </nav>
            <a href="audit_log.php" class="ot-clear <?php echo $has_filter ? '' : 'is-off'; ?>" <?php echo $has_filter ? '' : 'aria-hidden="true" tabindex="-1"'; ?>><i class="bi bi-x-lg"></i> Clear</a>
        </div>
    </div>

    <div class="ot-searchbar">
        <form method="get" class="ot-search">
            <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>"><?php endif; ?>
            <i class="bi bi-search"></i>
            <input type="text" name="search" placeholder="Search person, order or detail..." value="<?php echo htmlspecialchars($search); ?>" aria-label="Search audit log">
        </form>
    </div>

    <div class="glass-card ot-table-card">
        <div class="table-responsive d-none d-md-block">
            <table class="ot-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>User</th>
                        <th>Type</th>
                        <th>Order</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5" class="ot-empty"><i class="bi bi-shield-check"></i>No activity found.<span class="ot-sub">Try another tab or a different search.</span></td></tr>
                    <?php else: foreach ($rows as $r): $ts = strtotime($r['at']); ?>
                        <tr>
                            <td class="text-nowrap" title="<?php echo htmlspecialchars(date('l, F j, Y \a\t g:i:s A', $ts)); ?>"><?php echo date('M j, Y', $ts); ?><span class="ot-sub"><?php echo date('g:i A', $ts); ?></span></td>
                            <td class="text-nowrap"><?php echo htmlspecialchars($r['actor']); ?></td>
                            <td class="text-nowrap"><?php echo htmlspecialchars($types[$r['kind']]); ?></td>
                            <?php // The audit log is a record that outlives the day, so it shows the full order number (LDY-YYYYMMDD-NNN): a short #001 would be ambiguous across days. ?>
                            <td class="text-nowrap"><?php echo $r['order_number'] ? '<span class="ot-code">' . htmlspecialchars($r['order_number']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo htmlspecialchars($r['detail']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <!-- MOBILE: one card per entry, no table, no horizontal scroll -->
        <div class="d-md-none al-cards">
            <?php if (!$rows): ?>
                <div class="ot-empty"><i class="bi bi-shield-check"></i>No activity found.<span class="ot-sub">Try another tab or a different search.</span></div>
            <?php else: foreach ($rows as $r): $ts = strtotime($r['at']); ?>
                <div class="al-card">
                    <div class="al-top">
                        <?php echo $r['order_number'] ? '<span class="ot-code">' . htmlspecialchars($r['order_number']) . '</span>' : '<span class="text-muted">—</span>'; ?>
                        <span class="ot-badge is-compact <?php echo $r['kind'] === 'Payment' ? 'ot-b-done' : 'ot-b-progress'; ?>"><?php echo htmlspecialchars($types[$r['kind']]); ?></span>
                    </div>
                    <?php if ($r['kind'] === 'Payment'): $pp = explode(' · ', $r['detail']); ?>
                        <div class="al-detail al-pay"><strong><?php echo htmlspecialchars($pp[1] ?? $r['detail']); ?></strong></div>
                        <div class="al-sub"><?php echo htmlspecialchars($pp[0]); ?><?php if (!empty($pp[2])): ?><span><?php echo htmlspecialchars($pp[2]); ?></span><?php endif; ?></div>
                    <?php else: ?>
                        <div class="al-detail"><?php echo htmlspecialchars($r['detail']); ?></div>
                    <?php endif; ?>
                    <div class="al-meta"><span><i class="bi bi-person"></i> <?php echo htmlspecialchars($r['actor']); ?></span><span><?php echo date('M j, Y · g:i A', $ts); ?></span></div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <div class="ot-foot">
            <span><?php echo htmlspecialchars($showing); ?></span>
            <?php echo ui_pager($page, $total_pages, function ($p) { return audit_url(['page' => $p]); }); ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/components/footer.php'; ?>