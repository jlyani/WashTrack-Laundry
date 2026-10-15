<?php
// services.php - the service menu (admin + staff). Dashboard patterns:
// filter cards sa top, a pricing report beside the water panel, and each
// service as a feed row na spells out what the package includes.
// Pricing: every rate on this page is a price PER KILO (stored in services.price_per_load). Ang order total is
// rate x billed kg, where a bag under the minimum charge is billed at the minimum (MIN_CHARGE_KG, includes/config.php).
// Each rate dito is PER KILO (stored in services.price_per_load). Total = rate x billed kg;
// kapag under the minimum charge, the minimum (MIN_CHARGE_KG) is billed.
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?error=unauthorized");
    exit();
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/orders.php';   // also loads config.php (MIN_CHARGE_KG)

$is_admin = (($_SESSION['role'] ?? '') === 'Admin');
$search_query = trim($_GET['search'] ?? '');
$status_filter = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : 'all';
$per_page = 8;
$page = max(1, (int) ($_GET['page'] ?? 1));

// The search box scopes everything: card counts, report, and the list.
$where = '';
$params = [];
$types = '';
if ($search_query !== '') {
    $where = " WHERE name LIKE ?";
    $params[] = '%' . $search_query . '%';
    $types .= 's';
}

// Card counts are global (the whole menu): never scoped by the search or the
// status filter, kaya each tab always reports the true size of each bucket. The
// search box only filters the list below, never changes these numbers.
// Named $tab_counts: components/sidebar.php destructures its nav loop into
// $counts, and the include chain would clobber ang same-named variable dito.
$tab_counts = ['all' => 0, 'active' => 0, 'inactive' => 0];
$db_error = false;
try {
    $cstmt = $conn->prepare("SELECT is_active, COUNT(*) AS n FROM services GROUP BY is_active");
    $cstmt->execute();
    $cres = $cstmt->get_result();
    while ($c = $cres->fetch_assoc()) {
        $tab_counts[(int) $c['is_active'] === 1 ? 'active' : 'inactive'] = (int) $c['n'];
    }
    $cstmt->close();
    $tab_counts['all'] = $tab_counts['active'] + $tab_counts['inactive'];
} catch (Throwable $e) {
    error_log('[services] counts failed: ' . $e->getMessage());
    $db_error = true;
}

// The list itself: search + status filter, paginated.
$list_where = $where;
$list_params = $params;
$list_types = $types;
if ($status_filter !== 'all') {
    $list_where .= ($list_where === '' ? ' WHERE ' : ' AND ') . 'is_active = ?';
    $list_params[] = $status_filter === 'active' ? 1 : 0;
    $list_types .= 'i';
}

$offset = 0; $total_rows = 0; $services = []; $consumables = []; $total_pages = 1;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM services" . $list_where);
    if ($list_types !== '') $stmt->bind_param($list_types, ...$list_params);
    $stmt->execute();
    $total_rows = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $total_pages = max(1, (int) ceil($total_rows / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $stmt = $conn->prepare("SELECT service_id, name, service_type, includes_fold, price_per_load, is_active FROM services" . $list_where . " ORDER BY service_id ASC LIMIT ? OFFSET ?");
    $stmt->bind_param($list_types . 'ii', ...array_merge($list_params, [$per_page, $offset]));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $services[] = $row;
    $stmt->close();
} catch (Throwable $e) {
    error_log('[services] load failed: ' . $e->getMessage());
    $db_error = true;
}

// What each package includes (its consumables), nakikita under the name.
// Loaded separately para a missing table can never blank out the whole list.
if ($services) {
    try {
        $svc_ids = array_map(function ($s) { return (int) $s['service_id']; }, $services);
        $ph = implode(',', array_fill(0, count($svc_ids), '?'));
        $cstmt = $conn->prepare("SELECT sc.service_id, ii.name, sc.qty FROM service_consumables sc JOIN inventory_items ii ON ii.item_id = sc.item_id WHERE sc.service_id IN ($ph) ORDER BY ii.name ASC");
        $cstmt->bind_param(str_repeat('i', count($svc_ids)), ...$svc_ids);
        $cstmt->execute();
        $cres = $cstmt->get_result();
        while ($c = $cres->fetch_assoc()) $consumables[(int) $c['service_id']][] = $c;
        $cstmt->close();
    } catch (Throwable $e) {
        error_log('[services] consumables load failed: ' . $e->getMessage());
    }
}

/** Quantity na shown as a tidy number: 1 -> "1", 1.50 -> "1.5". */
function svc_qty_label($q) {
    $s = rtrim(rtrim(number_format((float) $q, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

function svc_url(array $o = []) {
    $q = array_merge(['search' => $_GET['search'] ?? '', 'status' => 'all', 'page' => 1], $o);
    $q = array_filter($q, function ($v, $k) {
        return $v !== '' && !($k === 'page' && (int) $v === 1) && !($k === 'status' && $v === 'all');
    }, ARRAY_FILTER_USE_BOTH);
    return 'services.php' . ($q ? '?' . http_build_query($q) : '');
}

$showing = $total_rows === 0 ? 'No services' : 'Showing ' . ($offset + 1) . '–' . ($offset + count($services)) . ' of ' . $total_rows . ' services';
$min_kg_label = rtrim(rtrim(number_format(MIN_CHARGE_KG, 2, '.', ''), '0'), '.');
$break_even   = break_even_rate_per_kg();   // lowest rate that still earns the margin on the worst bag (includes/config.php: COST_PER_LOAD, PROFIT_MARGIN_PCT)

$page_scripts = $is_admin ? ['assets/js/services.js'] : [];
include __DIR__ . '/components/header.php';
?>
<div class="container-fluid ot-page" <?php if ($is_admin): ?>x-data="serviceAdmin()"<?php endif; ?>>

    <?php if ($db_error): ?><div class="alert alert-danger">Could not load services. Check the database connection.</div><?php endif; ?>

    <div class="ot-toolbar">
        <div class="ot-toolbar-group">
            <nav class="ot-tabs" aria-label="Service status">
                <?php foreach (['all' => 'All services', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label): ?>
                <a class="ot-tab <?php echo $status_filter === $key ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(svc_url(['status' => $key])); ?>"><?php echo $label; ?> <span class="ot-tab-count"><?php echo $tab_counts[$key]; ?></span></a>
                <?php endforeach; ?>
            </nav>
            <a href="services.php" class="ot-clear <?php echo ($search_query !== '' || $status_filter !== 'all') ? '' : 'is-off'; ?>" <?php echo ($search_query !== '' || $status_filter !== 'all') ? '' : 'aria-hidden="true" tabindex="-1"'; ?>><i class="bi bi-x-lg"></i> Clear</a>
        </div>
        <?php if ($is_admin): ?>
        <button type="button" class="ot-add-btn" @click="openAdd()"><i class="bi bi-plus-lg"></i> Add Service</button>
        <?php endif; ?>
    </div>

    <div class="ot-searchbar">
        <form method="get" class="ot-search">
            <?php if ($status_filter !== 'all'): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
            <i class="bi bi-search"></i>
            <input type="text" name="search" placeholder="Search services..." value="<?php echo htmlspecialchars($search_query); ?>" aria-label="Search services">
        </form>
    </div>

    <div class="ot-hint">
        <i class="bi bi-info-circle"></i>
        <span>Priced per kilo<?php if (MIN_CHARGE_KG > 0): ?>, lighter bags are billed as <?php echo $min_kg_label; ?> kg<?php endif; ?>. Supplies are included.</span>
    </div>

    <div class="ot-table-card">
        <div class="table-responsive">
            <table class="ot-table">
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Price per kg</th>
                    <th class="d-none d-sm-table-cell">Includes</th>
                    <th class="is-center">Status</th>
                    <?php if ($is_admin): ?><th class="is-center ot-col-action">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$services): ?>
                    <tr><td colspan="<?php echo $is_admin ? 5 : 4; ?>" class="ot-empty"><i class="bi bi-basket"></i><?php echo $search_query !== '' ? 'No services match &ldquo;' . htmlspecialchars($search_query) . '&rdquo;.' : 'No services on the menu yet.'; ?><span class="ot-sub"><?php echo $search_query !== '' ? 'Try another name, or clear the search.' : 'Add the first service rate to start taking orders.'; ?></span></td></tr>
                <?php else: foreach ($services as $s):
                    $active = (int) $s['is_active'] === 1;
                                        $json = htmlspecialchars(json_encode([
                        'id' => (int) $s['service_id'], 'name' => $s['name'], 'type' => $s['service_type'],
                        'fold' => (int) $s['includes_fold'] === 1, 'price' => number_format((float) $s['price_per_load'], 2, '.', ''),
                        'active' => $active ? '1' : '0',
                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');

                    // What this service includes: consumables sa one line, folding said underneath.
                    $parts = [];
                    foreach ($consumables[(int) $s['service_id']] ?? [] as $c) {
                        $parts[] = svc_qty_label($c['qty']) . '&times; ' . htmlspecialchars($c['name']);
                    }
                    $includes = ($parts ? implode(', ', $parts) . ' <span class="text-muted">per load</span>' : 'No consumables')
                              . '<span class="ot-sub">' . ((int) $s['includes_fold'] === 1 ? 'Folding included' : 'No folding') . '</span>';
                ?>
                    <tr data-service="<?php echo $json; ?>">
                        <td>
                            <div class="ot-cust-name"><?php echo htmlspecialchars($s['name']); ?></div>
                        </td>
                        <td><span class="ot-amount">₱<?php echo number_format((float) $s['price_per_load'], 2); ?></span><?php if ($break_even !== null && (float) $s['price_per_load'] < $break_even): ?><span class="ot-sub text-danger" title="Below the break-even rate of ₱<?php echo number_format($break_even, 2); ?>/kg"><i class="bi bi-exclamation-triangle"></i> Below break-even</span><?php endif; ?></td>
                        <td class="d-none d-sm-table-cell"><?php echo $includes; ?></td>
                        <td class="is-center"><span class="ot-badge is-compact <?php echo $active ? 'ot-b-done' : 'ot-b-cancel'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span></td>
                        <?php if ($is_admin): ?>
                        <td class="is-center">
                            <div class="ot-actions">
                                <button type="button" class="ot-icon-btn" title="Edit service" aria-label="Edit <?php echo htmlspecialchars($s['name']); ?>" @click="openEdit($el.closest('[data-service]'))"><i class="bi bi-pencil-square"></i></button>
                                <button type="button" class="ot-icon-btn" title="Delete service" aria-label="Delete <?php echo htmlspecialchars($s['name']); ?>" @click="remove($el.closest('[data-service]'))"><i class="bi bi-trash"></i></button>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            </table>
        </div>
        <div class="ot-foot">
            <span><?php echo htmlspecialchars($showing); ?></span>
            <?php echo ui_pager($page, $total_pages, function ($p) use ($status_filter) { return svc_url(['status' => $status_filter, 'page' => $p]); }); ?>
        </div>
    </div>

<?php if ($is_admin): ?>
<div class="modal fade" id="serviceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered wt-modal-md">
        <div class="modal-content wt-modal">
            <form @submit.prevent="submit" novalidate>
                <div class="modal-header border-0 pb-2">
                    <div class="d-flex align-items-center gap-3 w-100 pe-3">
                        <div class="avatar-box"><i class="bi bi-tags fs-5"></i></div>
                        <div class="min-w-0">
                            <h5 class="modal-title fw-bold mb-0" x-text="f.id ? 'Edit service' : 'Add new service'"></h5>
                            <div class="small text-muted" x-text="f.id ? 'Old orders keep the price they were taken at.' : 'Appears in the New Order form once active.'"></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger py-2 small" x-show="error" x-text="error" x-cloak></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small" for="sName">Service name <span class="text-danger">*</span></label>
                        <input type="text" id="sName" class="form-control" maxlength="100" placeholder="e.g. Wash and Dry" x-model="f.name" :class="{ 'is-invalid': tried && !f.name.trim() }">
                        <div class="invalid-feedback">Please enter a service name.</div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small" for="sPrice">Price per kg (₱) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" max="9999.99" id="sPrice" class="form-control" placeholder="0.00" x-model="f.price" :class="{ 'is-invalid': tried && !(parseFloat(f.price) > 0) }">
                            <div class="invalid-feedback">Enter a price above 0.</div>
                        </div>
                    </div>
                    <div class="form-check mb-3" x-show="!f.id" x-cloak>
                        <input class="form-check-input" type="checkbox" id="sFold" x-model="f.fold">
                        <label class="form-check-label small" for="sFold">Includes folding</label>
                    </div>
                    <div x-show="f.id" x-cloak>
                        <label class="form-label fw-semibold small" for="sActive">Status</label>
                        <select id="sActive" class="form-select" x-model="f.active">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                        <div class="small text-muted mt-2">Folding is fixed after creation.</div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 gap-2">
                    <button type="button" class="btn btn-light border flex-fill" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-fill fw-semibold" :disabled="busy"><i class="bi bi-check-lg me-1"></i><span x-text="f.id ? 'Save changes' : 'Add service'"></span></button>
                </div>
            </form>
        </div>
    </div>
</div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/components/footer.php';