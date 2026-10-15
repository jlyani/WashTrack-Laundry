<?php
// transaction.php - the Transactions Log: one row per payment already collected (ang financial ledger).
// List of all payments already collected. Bawat row is one payment (kita), with a filter na
// Today / Last 7 Days / All History at search. Unpaid orders will never show up dito.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?error=unauthorized");
    exit();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ui.php';

// Filter at search
$date_filter  = (isset($_GET['date_filter']) && in_array($_GET['date_filter'], ['today', 'weekly'], true)) ? $_GET['date_filter'] : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// THE LEDGER: one row per payment. The query starts FROM payments (ang immutable financial ledger); orders, customers,
// services and users are joined only para ilarawan each payment. Nothing here depends on an order's operational status,
// and no active (unpaid) order can ever appear on this page.
// The query starts sa payments (ang kita ledger); the other tables are just for clarity.
// So no unpaid order can appear here — paid ones lang.
$where_clauses = ['1 = 1'];
if ($date_filter === 'weekly') {
    $where_clauses[] = "p.paid_at >= NOW() - INTERVAL 7 DAY";
} elseif ($date_filter === 'today') {
    $where_clauses[] = "p.paid_at >= CURDATE()";   // for end-of-shift reconciliation
}
if ($search_query !== '') {
    $esc_search = $conn->real_escape_string($search_query);
    $esc_order  = $conn->real_escape_string(order_search_pattern($search_query));
    $where_clauses[] = "(o.order_number LIKE '{$esc_order}' OR c.full_name LIKE '%{$esc_search}%' OR p.receipt_no LIKE '%{$esc_search}%')";
}
$where_sql = " WHERE " . implode(" AND ", $where_clauses);

$ledger_from = "
    FROM payments p
    JOIN orders o    ON o.order_id    = p.order_id
    JOIN customers c ON c.customer_id = o.customer_id
    JOIN services s  ON s.service_id  = o.service_id
    JOIN users u     ON u.user_id     = p.received_by
";

$items_per_page = 7;
$page   = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $items_per_page;

$total_result = $conn->query("SELECT COUNT(*) AS total {$ledger_from} {$where_sql}");
$total_rows   = $total_result ? intval($total_result->fetch_assoc()['total']) : 0;
$total_pages  = max(1, ceil($total_rows / $items_per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $items_per_page; }

$transactions = [];
try {
    $result = $conn->query("
        SELECT p.payment_id, p.receipt_no, p.method AS payment_method, p.reference_no, p.amount_due AS paid_amount,
               p.amount_tendered, p.change_given, p.paid_at, u.full_name AS cashier_name,
               o.order_id, o.order_number, o.total_amount, o.loads, o.price_per_load, o.weight_kg AS weight,
               o.released_at AS release_date, c.full_name AS customer_name, c.phone AS contact_number, s.name AS service_type
        {$ledger_from} {$where_sql}
        ORDER BY p.paid_at DESC, p.payment_id DESC
        LIMIT {$items_per_page} OFFSET {$offset}
    ");
    while ($row = $result->fetch_assoc()) { $transactions[] = $row; }
} catch (Throwable $e) {
    error_log('[transaction] ' . $e->getMessage());
    $_SESSION['error'] = 'Could not load the transaction ledger. Please try again.';
}

function tx_url(array $over = []) {
    $params = array_filter(array_merge(['date_filter' => $_GET['date_filter'] ?? '', 'search' => $_GET['search'] ?? '', 'page' => ''], $over), function ($v) { return $v !== '' && $v !== null && $v !== 1; });
    return 'transaction.php' . ($params ? '?' . http_build_query($params) : '');
}

// Everything the detail modal shows travels kasama ng row (no extra request).
function tx_json($tx) {
    return htmlspecialchars(json_encode([
        'order_id'  => (int) $tx['order_id'],
        'receipt'   => $tx['receipt_no'],
        'order_no'  => $tx['order_number'],
        'customer'  => $tx['customer_name'],
        'phone'     => $tx['contact_number'] ?: 'N/A',
        'service'   => $tx['service_type'],
        'weight'    => (float) $tx['weight'],
        'loads'     => (float) $tx['loads'],
        'per_kg'    => abs((float) $tx['total_amount'] - (float) $tx['price_per_load'] * (float) $tx['loads']) > 0.005,   // priced per kg (newer orders) or per load (mga older ones)
        'price'     => (float) $tx['price_per_load'],
        'total'     => (float) $tx['total_amount'],
        'paid'      => (float) $tx['paid_amount'],
        'method'    => $tx['payment_method'],
        'ref'       => (string) ($tx['reference_no'] ?? ''),
        'tendered'  => (float) $tx['amount_tendered'],
        'change'    => (float) $tx['change_given'],
        'cashier'   => $tx['cashier_name'],
        'paid_at'   => date('M j, Y g:i A', strtotime($tx['paid_at'])),
        'completed' => date('M j, Y g:i A', strtotime(!empty($tx['release_date']) ? $tx['release_date'] : $tx['paid_at'])),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
}
function tx_method_badge($m) { return '<span class="ot-badge is-compact ' . ($m === 'Cash' ? 'ot-b-cash' : 'ot-b-gcash') . '">' . htmlspecialchars($m) . '</span>'; }

$shown_count  = count($transactions);
$showing_text = 'Showing ' . $shown_count . ($total_rows > $shown_count ? ' of ' . $total_rows : '') . ' ' . ($shown_count === 1 ? 'transaction' : 'transactions');
$has_filter = ($date_filter !== '' || $search_query !== '');

// Search-aware empty state (mirrors view_order.php): name what emptied the list.
if ($search_query !== '') {
    $empty_msg  = 'No transactions match &ldquo;' . htmlspecialchars($search_query) . '&rdquo;.';
    $empty_hint = 'Try another customer, order or receipt number, or clear the search.';
} elseif ($date_filter === 'today') {
    $empty_msg  = 'No transactions recorded today.';
    $empty_hint = 'Payments taken today will appear here.';
} elseif ($date_filter === 'weekly') {
    $empty_msg  = 'No transactions in the last 7 days.';
    $empty_hint = 'Payments from this week will appear here.';
} else {
    $empty_msg  = 'No transaction records found.';
    $empty_hint = 'Payments will appear here once orders are released.';
}

$page_scripts = ['assets/js/transaction.js'];
include __DIR__ . '/components/header.php';
?>
<div class="container-fluid ot-page">

    <div class="ot-toolbar">
        <div class="ot-toolbar-group">
            <nav class="ot-tabs" aria-label="Transaction filter">
                <a class="ot-tab <?php echo $date_filter === 'today' ? 'is-active' : ''; ?>" href="<?php echo tx_url(['date_filter' => 'today']); ?>">Today</a>
                <a class="ot-tab <?php echo $date_filter === 'weekly' ? 'is-active' : ''; ?>" href="<?php echo tx_url(['date_filter' => 'weekly']); ?>">Last 7 Days</a>
                <a class="ot-tab <?php echo $date_filter === '' ? 'is-active' : ''; ?>" href="<?php echo tx_url(['date_filter' => '']); ?>">All History</a>
            </nav>
            <a href="transaction.php" class="ot-clear <?php echo $has_filter ? '' : 'is-off'; ?>" <?php echo $has_filter ? '' : 'aria-hidden="true" tabindex="-1"'; ?>><i class="bi bi-x-lg"></i> Clear</a>
        </div>
    </div>

    <div class="ot-searchbar">
        <form action="transaction.php" method="GET" class="ot-search">
            <?php if ($date_filter !== ''): ?><input type="hidden" name="date_filter" value="<?php echo htmlspecialchars($date_filter); ?>"><?php endif; ?>
            <i class="bi bi-search"></i>
            <input type="text" name="search" placeholder="Search customer, order or receipt no..." value="<?php echo htmlspecialchars($search_query); ?>" aria-label="Search transactions">
        </form>
    </div>

    <!-- DESKTOP TABLE (for bigger screens) -->
    <div class="ot-table-card d-none d-xl-block mb-3">
        <div class="table-responsive">
            <table class="ot-table">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Customer</th>
                        <th class="d-none d-xxl-table-cell">Cashier</th>
                        <th>Method</th>
                        <th>Total Paid</th>
                        <th class="d-none d-xxl-table-cell">Tendered</th>
                        <th class="d-none d-xxl-table-cell">Change</th>
                        <th>Paid at</th>
                        <th class="is-center ot-col-action">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$transactions): ?>
                        <tr><td colspan="9" class="ot-empty"><i class="bi bi-receipt"></i><?php echo $empty_msg; ?><span class="ot-sub"><?php echo $empty_hint; ?></span></td></tr>
                    <?php else: foreach ($transactions as $t): $ts = strtotime($t['paid_at']); ?>
                        <tr data-tx="<?php echo tx_json($t); ?>">
                            <td class="ot-order-id"><?php echo htmlspecialchars($t['receipt_no']); ?></td>
                            <td>
                                <div class="ot-cust-name"><?php echo htmlspecialchars($t['customer_name']); ?></div>
                            </td>
                            <td class="d-none d-xxl-table-cell text-nowrap"><?php echo htmlspecialchars($t['cashier_name']); ?></td>
                            <td><?php echo tx_method_badge($t['payment_method']); ?></td>
                            <td class="text-nowrap"><span class="ot-amount"><?php echo ui_money($t['paid_amount']); ?></span></td>
                            <td class="d-none d-xxl-table-cell text-nowrap"><?php echo ui_money($t['amount_tendered']); ?></td>
                            <td class="d-none d-xxl-table-cell text-nowrap"><?php echo ui_money($t['change_given']); ?></td>
                            <td class="text-nowrap"><?php echo date('M j, Y', $ts); ?></td>
                            <td class="is-center">
                                <div class="ot-actions">
                                    <button type="button" class="ot-icon-btn" data-tx-do="view" title="View transaction details" aria-label="View transaction details"><i class="bi bi-eye"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="ot-foot">
            <span><?php echo $showing_text; ?></span>
            <?php echo ui_pager($page, $total_pages, function ($p) { return tx_url(['page' => $p]); }); ?>
        </div>
    </div>

    <!-- MOBILE / TABLET CARDS (card layout) -->
    <div class="d-block d-xl-none mb-3">
        <?php if (!$transactions): ?>
            <div class="ot-mcard ot-empty-card p-4 text-muted"><?php echo $empty_msg; ?><span class="ot-sub"><?php echo $empty_hint; ?></span></div>
        <?php else: foreach ($transactions as $t): ?>
            <div class="ot-mcard mb-3" data-tx="<?php echo tx_json($t); ?>">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="ot-code"><?php echo htmlspecialchars($t['receipt_no']); ?></span>
                    <?php echo tx_method_badge($t['payment_method']); ?>
                </div>
                <div class="ot-cust-name" style="font-size:.95rem;"><?php echo htmlspecialchars($t['customer_name']); ?></div>
                <div class="text-muted small mb-2"><?php echo date('M j, Y g:i A', strtotime($t['paid_at'])); ?> &middot; <?php echo htmlspecialchars($t['cashier_name']); ?></div>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                    <span class="ot-amount fs-5"><?php echo ui_money($t['paid_amount']); ?></span>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-light border rounded-pill px-3" data-tx-do="view"><i class="bi bi-eye"></i> Details</button>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <div class="ot-foot ot-foot-mobile d-xl-none">
        <span><?php echo $showing_text; ?></span>
        <?php echo ui_pager($page, $total_pages, function ($p) { return tx_url(['page' => $p]); }); ?>
    </div>
</div>

<!-- TRANSACTION DETAIL: read-only view ng isang ledger entry (same layout as the order details popup) -->
<div class="modal fade" id="transactionDetailModal" tabindex="-1" aria-labelledby="txdTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered wt-modal-sm">
    <div class="modal-content wt-modal" id="txDetailBody" x-data="txDetail()">
      <div class="modal-header border-0 pb-2">
        <div class="d-flex align-items-center gap-3 w-100 pe-3">
          <div class="avatar-box"><i class="bi bi-receipt fs-5"></i></div>
          <div class="min-w-0">
            <h5 class="modal-title fw-bold mb-0" id="txdTitle">Official Receipt</h5>
            <div class="text-muted small"><span class="font-monospace" x-text="d.receipt"></span> &middot; <span x-text="d.paid_at"></span></div>
          </div>
        </div>
        <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body pt-1">
        <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
          <span class="ot-badge is-compact ot-b-done">Paid</span>
        </div>

        <dl class="wt-kv mb-3">
          <dt>Customer</dt><dd class="text-break" x-text="d.customer"></dd>
          <dt>Contact</dt><dd x-text="d.phone"></dd>
          <dt>Order</dt><dd class="font-monospace" x-text="d.order_no"></dd>
          <dt>Package</dt><dd x-text="d.service"></dd>
          <dt>Weight</dt>
          <dd><span x-text="Number(d.weight).toFixed(2)"></span> kg &middot;
            <template x-if="d.per_kg"><span><span x-text="money(d.price)"></span>/kg &middot; <span x-text="d.loads"></span> <span x-text="d.loads == 1 ? 'load' : 'loads'"></span></span></template>
            <template x-if="!d.per_kg"><span><span x-text="d.loads"></span> <span x-text="d.loads == 1 ? 'load' : 'loads'"></span> &times; <span x-text="money(d.price)"></span></span></template>
          </dd>
          <dt>Completed</dt><dd x-text="d.completed"></dd>
        </dl>

        <div class="wt-box d-flex justify-content-between align-items-center mb-3">
          <div>
            <div class="small text-muted fw-semibold">Total paid</div>
            <div class="wt-total fs-4" x-text="money(d.paid)"></div>
          </div>
          <span class="ot-badge is-compact" :class="d.method === 'Cash' ? 'ot-b-cash' : 'ot-b-gcash'" x-text="d.method"></span>
        </div>

        <dl class="wt-kv mb-0">
          <dt x-show="d.ref" x-cloak>GCash ref.</dt><dd x-show="d.ref" x-cloak class="font-monospace" x-text="d.ref"></dd>
          <dt>Tendered</dt><dd x-text="money(d.tendered)"></dd>
          <dt>Change</dt><dd x-text="money(d.change)"></dd>
          <dt>Received by</dt><dd x-text="d.cashier"></dd>
        </dl>
      </div>

      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-primary w-100" @click="wtPrintReceipt(d.order_id)"><i class="bi bi-printer me-1"></i> Print Receipt</button>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/components/footer.php'; ?>