<?php
// view_order.php - Order Tracking: the working list of active orders (tabs by status, search, at pager).
// The main page for Staff — list of active orders with tabs (Pending, In Progress,
// Ready), search, and pager din. Action buttons (Start, Ready, Release) call orders_process.php.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?error=unauthorized");
    exit();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/machines.php';
require_once __DIR__ . '/includes/order_view.php';
require_once __DIR__ . '/includes/sms.php';   // "Customer texted" line sa Ready orders
$free_now     = free_machine_counts($conn);   // for the "can start" hint on the order at the front ng line

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin');

// Packages for the New Order / Edit Order forms.
$services = [];
$srv_res = $conn->query("SELECT service_id, name AS service_name, service_type, includes_fold, price_per_load FROM services WHERE is_active = 1 ORDER BY price_per_load ASC, name ASC");
if ($srv_res) {
    while ($srv_row = $srv_res->fetch_assoc()) { $services[] = $srv_row; }
}

// Tab at search filter. Released/Cancelled orders won't show up na sa active list.
$allowed_statuses = ['Pending', 'In Progress', 'Ready for Pickup'];
$status_filter = in_array($_GET['status'] ?? '', $allowed_statuses, true) ? $_GET['status'] : '';
$search_query  = trim($_GET['search'] ?? '');

$where_clauses = ["o.order_status NOT IN ('Released', 'Cancelled')"];
if ($status_filter !== '') $where_clauses[0] = "o.order_status = '" . $conn->real_escape_string($status_filter) . "'";
if ($search_query !== '') {
    $esc_search = $conn->real_escape_string($search_query);
    $esc_order  = $conn->real_escape_string(order_search_pattern($search_query));
    $where_clauses[] = "(o.order_number LIKE '{$esc_order}' OR c.full_name LIKE '%{$esc_search}%')";
}
$where_sql = " WHERE " . implode(" AND ", $where_clauses);
// Every view follows the real work order: the waiting line muna (oldest customer first, the order Start must follow),
// tapos orders being washed, then orders waiting for pickup. Inside each stage the oldest order is on top.
// The list follows the same real flow: waiting line muna (oldest Pending), tapos
// the washing stage, then pickup. Inside each stage, oldest ang nasa top.
$order_sql = "FIELD(o.order_status, 'Pending', 'In Progress', 'Ready for Pickup'), o.created_at ASC, o.order_id ASC";

$items_per_page = 7;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $items_per_page;

$total_result = $conn->query("SELECT COUNT(*) AS total FROM orders o JOIN customers c ON o.customer_id = c.customer_id JOIN services s ON o.service_id = s.service_id {$where_sql}");
$total_rows = $total_result ? intval($total_result->fetch_assoc()['total']) : 0;
$total_pages = max(1, ceil($total_rows / $items_per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $items_per_page; }

$orders = [];
$query = "
    SELECT o.order_id, o.order_number, c.customer_id, c.full_name AS customer_name, c.phone AS contact_number, o.service_id, s.name AS service_type,
           o.weight_kg AS weight, o.load_type, o.loads, o.price_per_load, o.total_amount, o.payment_status, o.order_status AS status, o.created_at AS order_date, o.notes,
           " . sms_status_sql($conn) . " AS sms_status, " . queue_position_sql() . " AS queue_pos
    FROM orders o JOIN customers c ON o.customer_id = c.customer_id JOIN services s ON o.service_id = s.service_id
    {$where_sql} ORDER BY {$order_sql} LIMIT {$items_per_page} OFFSET {$offset}
";
$result = $conn->query($query);
if ($result) { while ($row = $result->fetch_assoc()) { $orders[] = $row; } }

// Garment types ticked per order, and the supplies each order uses (dose per load x loads): shown sa order details.
$garment_names = order_garment_names($conn, array_map(fn($o) => (int) $o['order_id'], $orders));
$doses = [];
try {
    $dq = $conn->query("SELECT sc.service_id, i.name, sc.qty FROM service_consumables sc JOIN inventory_items i ON i.item_id = sc.item_id ORDER BY i.item_id");
    while ($dq && ($dr = $dq->fetch_assoc())) $doses[(int) $dr['service_id']][] = $dr;
} catch (Throwable $e) { error_log('[view_order] supplies: ' . $e->getMessage()); }
foreach ($orders as &$ord_row) {
    $ord_row['garments'] = $garment_names[(int) $ord_row['order_id']] ?? '';
    $ord_row['supplies'] = supplies_text($doses[(int) $ord_row['service_id']] ?? [], $ord_row['loads']);
}
unset($ord_row);

// Tab counts (bilang per status)
$tab_counts = ['Pending' => 0, 'In Progress' => 0, 'Ready for Pickup' => 0];
$tab_q = $conn->query("SELECT order_status AS s, COUNT(*) AS c FROM orders WHERE order_status IN ('Pending','In Progress','Ready for Pickup') GROUP BY order_status");
if ($tab_q) { while ($tr = $tab_q->fetch_assoc()) { $tab_counts[$tr['s']] = intval($tr['c']); } }
$tab_total = array_sum($tab_counts);

// Builds the link for a tab/page while keeping the search.
function ot_url($status = null, $p = 1) {
    $params = [];
    $st = $status ?? ($_GET['status'] ?? '');
    if ($st !== '')              $params['status'] = $st;
    if (!empty($_GET['search'])) $params['search'] = $_GET['search'];
    if ($p > 1)                  $params['page'] = $p;
    return 'view_order.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

// Shows the pager (Previous / 1 2 3 / Next).
function ot_render_pager() {
    global $page, $total_pages;
    $start = max(1, $page - 2);
    $end   = min($total_pages, $page + 2);
    echo '<div class="ot-pager">';
    echo '<a class="ot-page-btn ' . ($page <= 1 ? 'is-disabled' : '') . '" href="' . ot_url(null, $page - 1) . '" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>';
    if ($start > 1) {
        echo '<a class="ot-page-btn" href="' . ot_url(null, 1) . '">1</a>';
        if ($start > 2) echo '<span class="text-muted px-1">…</span>';
    }
    for ($i = $start; $i <= $end; $i++) {
        echo '<a class="ot-page-btn ' . ($i === $page ? 'is-current' : '') . '" href="' . ot_url(null, $i) . '">' . $i . '</a>';
    }
    if ($end < $total_pages) {
        if ($end < $total_pages - 1) echo '<span class="text-muted px-1">…</span>';
        echo '<a class="ot-page-btn" href="' . ot_url(null, $total_pages) . '">' . $total_pages . '</a>';
    }
    echo '<a class="ot-page-btn ' . ($page >= $total_pages ? 'is-disabled' : '') . '" href="' . ot_url(null, $page + 1) . '" aria-label="Next"><i class="bi bi-chevron-right"></i></a>';
    echo '</div>';
}

$shown_count  = count($orders);
$showing_text = 'Showing ' . $shown_count . ($total_rows > $shown_count ? ' of ' . $total_rows : '') . ' ' . (empty($status_filter) ? 'active ' : '') . ($shown_count === 1 ? 'order' : 'orders');
$has_filter   = ($status_filter !== '' || $search_query !== '');

// Search-aware empty state: name what emptied the list instead of the generic
// "no orders" line (which reads like the whole system is empty, kaya misleading).
if ($search_query !== '') {
    $empty_msg  = 'No orders match &ldquo;' . htmlspecialchars($search_query) . '&rdquo;.';
    $empty_hint = 'Try another name or order number, or clear the search.';
} elseif ($status_filter !== '') {
    $empty_msg  = 'No ' . htmlspecialchars($status_filter) . ' orders right now.';
    $empty_hint = 'New orders will show up here as they come in.';
} else {
    $empty_msg  = 'No laundry orders found.';
    $empty_hint = 'New orders will show up here.';
}

$page_scripts = ['assets/js/orders-ui.js'];

include __DIR__ . '/components/header.php';
?>
<div class="container-fluid ot-page">

    <div class="ot-toolbar">
        <div class="ot-toolbar-group">
            <nav class="ot-tabs" aria-label="Order status">
                <a class="ot-tab <?php echo $status_filter === '' ? 'is-active' : ''; ?>" href="<?php echo ot_url(''); ?>">Active <span class="ot-tab-count"><?php echo $tab_total; ?></span></a>
                <?php foreach ($allowed_statuses as $tab_status): ?>
                    <?php if ($tab_counts[$tab_status] > 0 || $status_filter === $tab_status): ?>
                        <a class="ot-tab <?php echo $status_filter === $tab_status ? 'is-active' : ''; ?>" href="<?php echo ot_url($tab_status); ?>"><?php echo $tab_status; ?> <span class="ot-tab-count"><?php echo $tab_counts[$tab_status]; ?></span></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <a href="view_order.php" class="ot-clear <?php echo $has_filter ? '' : 'is-off'; ?>" <?php echo $has_filter ? '' : 'aria-hidden="true" tabindex="-1"'; ?>><i class="bi bi-x-lg"></i> Clear</a>
        </div>

        <button type="button" class="ot-add-btn" data-bs-toggle="modal" data-bs-target="#addOrderModal"><i class="bi bi-plus-lg"></i> Add New Order</button>
    </div>

    <div class="ot-searchbar">
        <form action="view_order.php" method="GET" class="ot-search">
            <?php if ($status_filter !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
            <i class="bi bi-search"></i>
            <input type="text" name="search" placeholder="Search customer or order no..." value="<?php echo htmlspecialchars($search_query); ?>" aria-label="Search orders">
        </form>
    </div>

    <!-- DESKTOP TABLE VIEW (for bigger screens) -->
    <div class="ot-table-card d-none d-xl-block mb-3">
        <div class="table-responsive">
            <table class="ot-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th class="d-none d-sm-table-cell">Service</th>
                    <th>Weight</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th class="is-center ot-col-action">Actions</th>
                </tr>
            </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="6" class="ot-empty"><i class="bi bi-basket3"></i><?php echo $empty_msg; ?><span class="ot-sub"><?php echo $empty_hint; ?></span></td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order):
                            $current_status = trim($order['status']);
                            $next = ot_row_action($current_status, $order['queue_pos'] ?? null);
                            $loads_n = (float) $order['loads'];
                            $status_badge = ot_status_class($current_status);
                            $order_json = ot_order_json($order, $is_admin);
                        ?>
                        <tr data-order="<?php echo $order_json; ?>">
                            <td>
                                <div class="ot-cust-name"><?php echo order_label_html($order['order_number'], false); ?></div>
                            </td>
                            <td class="d-none d-sm-table-cell">
                                <span class="ot-badge is-compact is-fixed ot-b-pending">
                                    <?php echo htmlspecialchars($order['service_type']); ?>
                                </span>
                            </td>
                            <td class="text-nowrap"><?php echo number_format($order['weight'], 1); ?> kg &middot; <?php echo $loads_n; ?> load<?php echo $loads_n == 1 ? '' : 's'; ?></td>
                            <td class="text-nowrap"><span class="ot-amount">₱<?php echo number_format($order['total_amount'], 2); ?></span></td>
                            <td>
                                <span class="ot-badge is-compact is-fixed <?php echo $status_badge; ?>" style="width:140px"><?php echo htmlspecialchars($current_status); ?></span>
                            </td>
                            <td class="is-center">
                                <div class="ot-actions">
                                    <button type="button" class="ot-icon-btn" data-ot="view" title="View order details" aria-label="View order details"><i class="bi bi-eye"></i></button>
                                    <span class="ot-act-slot">
                                        <?php $wait = ot_wait_button($current_status, $order['queue_pos'] ?? null, $order['loads'], $free_now); ?>
                                        <?php if ($wait !== null): ?>
                                            <button type="button" class="ot-btn" disabled title="<?php echo htmlspecialchars($wait[1]); ?>" style="background:#e2e8f0;color:#64748b;box-shadow:none;cursor:not-allowed;opacity:1"><?php echo $wait[0]; ?></button>
                                        <?php elseif ($next[0] !== ''): ?>
                                            <button type="button" class="ot-btn" data-ot="<?php echo $next[0]; ?>"><?php echo $next[1]; ?></button>
                                        <?php endif; ?>
                                    </span>
                                    <div class="ot-kebab-slot">
                                        <?php if ($is_admin && $current_status === 'Pending'): ?>
                                        <div class="dropdown">
                                            <button class="ot-icon-btn" type="button" data-bs-toggle="dropdown" aria-label="More actions"><i class="bi bi-three-dots-vertical"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-1">
                                                <li><button type="button" class="dropdown-item d-flex align-items-center gap-2 py-2 px-3 text-primary" data-ot="edit"><i class="bi bi-pencil-square"></i> Edit Order</button></li>
                                                <li><button type="button" class="dropdown-item d-flex align-items-center gap-2 py-2 px-3 text-danger fw-medium" data-ot="cancel"><i class="bi bi-x-circle"></i> Cancel</button></li>
                                            </ul>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="ot-foot">
            <span><?php echo $showing_text; ?></span>
            <?php ot_render_pager(); ?>
        </div>
    </div>

    <!-- MOBILE / TABLET CARD VIEW -->
    <div class="d-block d-xl-none mb-3">
        <?php if (empty($orders)): ?>
            <div class="ot-mcard ot-empty-card p-4 text-muted"><?php echo $empty_msg; ?><span class="ot-sub"><?php echo $empty_hint; ?></span></div>
        <?php else: ?>
            <?php foreach ($orders as $order):
                $current_status = trim($order['status']);
                $next = ot_row_action($current_status, $order['queue_pos'] ?? null);
                $loads_n = (float) $order['loads'];
                $wait = ot_wait_button($current_status, $order['queue_pos'] ?? null, $order['loads'], $free_now);
            ?>
                <div class="ot-mcard mb-3" data-order="<?php echo ot_order_json($order, $is_admin); ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold fs-6"><?php echo order_label_html($order['order_number'], false); ?></span>
                        <span class="ot-badge is-compact <?php echo ot_status_class($current_status); ?>"><?php echo htmlspecialchars($current_status); ?></span>
                    </div>
                    <?php if ($current_status === 'Ready for Pickup'): ?>
                        <div class="small mb-2"><?php echo ot_sms_html($current_status, $order['sms_status'] ?? null); ?></div>
                    <?php endif; ?>

                    <div class="fw-bold mb-1" style="font-size:.95rem;"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                    <div class="text-muted small mb-2">
                        <?php echo htmlspecialchars($order['service_type']); ?>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 pt-1">
                        <span class="text-secondary small fw-medium"><?php echo number_format($order['weight'], 1); ?> kg &middot; <?php echo $loads_n; ?> load<?php echo $loads_n == 1 ? '' : 's'; ?></span>
                        <span class="ot-amount">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center pt-2 border-top gap-2">
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-light border rounded-pill px-3" data-ot="view"><i class="bi bi-eye"></i> Details</button>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <span class="ot-act-slot is-sm">
                                <?php if ($wait !== null): ?>
                                    <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3 fw-medium" disabled title="<?php echo htmlspecialchars($wait[1]); ?>"><?php echo $wait[0]; ?></button>
                                <?php elseif ($next[0] !== ''): ?>
                                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 fw-medium" data-ot="<?php echo $next[0]; ?>"><?php echo $next[2]; ?></button>
                                <?php endif; ?>
                            </span>
                            <?php if ($is_admin): ?>
                                <span class="ot-kebab-slot">
                                    <?php if ($current_status === 'Pending'): ?>
                                    <div class="dropdown d-inline">
                                        <button class="btn btn-sm btn-light border rounded-pill px-2" type="button" data-bs-toggle="dropdown" aria-label="More actions"><i class="bi bi-three-dots-vertical"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 p-1">
                                            <li><button type="button" class="dropdown-item d-flex align-items-center gap-2 py-2 px-3 text-primary" data-ot="edit"><i class="bi bi-pencil-square"></i> Edit Order</button></li>
                                            <li><button type="button" class="dropdown-item d-flex align-items-center gap-2 py-2 px-3 text-danger fw-medium" data-ot="cancel"><i class="bi bi-x-circle"></i> Cancel</button></li>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="ot-foot ot-foot-mobile d-xl-none">
        <span><?php echo $showing_text; ?></span>
        <?php ot_render_pager(); ?>
    </div>
</div>

<?php
include __DIR__ . '/components/create_order_modal.php';
include __DIR__ . '/components/edit_order_modal.php';
include __DIR__ . '/components/order_details_modal.php';
include __DIR__ . '/components/release_modal.php';
include __DIR__ . '/components/footer.php';