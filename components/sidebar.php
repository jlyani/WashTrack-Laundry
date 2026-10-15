<?php
// components/sidebar.php - navigation. Admin sees everything (including Sales Report); Staff sees Orders, Services and Transactions.
// This is the side menu. Admin sees everything (including Sales Report, Users, Audit Log);
// Staff sees Orders, Services and Transactions lang.
$sb_is_admin = (($_SESSION['role'] ?? '') === 'Admin');
$sb_page     = basename($_SERVER['PHP_SELF']);

// Badges: orders still in the shop, and pending password-reset requests (admin).
// The small count next to the menu — orders still in the shop, and (for Admin) pending reset requests.
$sb_orders_count = 0;
$sb_reset_count  = 0;
if (isset($conn)) {
    $sb_q = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE order_status IN ('Pending', 'In Progress', 'Ready for Pickup')");
    if ($sb_q) $sb_orders_count = (int) $sb_q->fetch_assoc()['cnt'];

    // A missing table (older import) must not break every page.
    if ($sb_is_admin) {
        try {
            $sb_q = $conn->query("SELECT COUNT(*) AS cnt FROM password_reset_requests WHERE status = 'pending'");
            if ($sb_q) $sb_reset_count = (int) $sb_q->fetch_assoc()['cnt'];
        } catch (Throwable $e) { $sb_reset_count = 0; }
    }
}

$sb_nav = [];
// Order: daily work first (orders, money, reports), tapos setup (services, users), and finally the audit trail.
if ($sb_is_admin) $sb_nav[] = ['dashboard.php', 'bi-grid-fill', 'Dashboard', 0];
$sb_nav[] = ['view_order.php', 'bi-list-check', 'Orders', $sb_orders_count];
$sb_nav[] = ['transaction.php', 'bi-clock-history', 'Transactions', 0];
if ($sb_is_admin) $sb_nav[] = ['sales_report.php', 'bi-bar-chart-line', 'Sales Report', 0];
$sb_nav[] = ['services.php', 'bi-tags', 'Services', 0];
if ($sb_is_admin) $sb_nav[] = ['users.php', 'bi-people', 'Users', $sb_reset_count];
if ($sb_is_admin) $sb_nav[] = ['audit_log.php', 'bi-shield-check', 'Audit Log', 0];

$sb_full  = $_SESSION['full_name'] ?? 'User';
$sb_role  = $sb_is_admin ? 'Administrator' : 'Staff';
$sb_words = preg_split('/\s+/', trim($sb_full));
$sb_initials = strtoupper(mb_substr($sb_words[0], 0, 1) . (count($sb_words) > 1 ? mb_substr(end($sb_words), 0, 1) : ''));
?>
<nav id="sidebar" class="p-3 d-flex flex-column justify-content-between text-white">
    <div>
        <div class="sb-top">
            <div class="sb-brand">
                <span class="sb-brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false"><g transform="rotate(-18 16 16)"><path d="M16 16c-3.7-.9-5.8-4.5-4.6-7.8C12.8 4.1 19.2 4.1 20.6 8.2c1.2 3.3-.9 6.9-4.6 7.8Z" fill="currentColor"/><path d="M16 16c3.7.9 5.8 4.5 4.6 7.8-1.4 4.1-7.8 4.1-9.2 0-1.2-3.3.9-6.9 4.6-7.8Z" fill="currentColor" fill-opacity=".7"/></g><path d="M24.6 5.4c.4 2.8 1.4 3.8 4.2 4.2-2.8.4-3.8 1.4-4.2 4.2-.4-2.8-1.4-3.8-4.2-4.2 2.8-.4 3.8-1.4 4.2-4.2Z" fill="currentColor"/><path d="M8.4 20.6c.25 1.9.95 2.6 2.85 2.85-1.9.25-2.6.95-2.85 2.85-.25-1.9-.95-2.6-2.85-2.85 1.9-.25 2.6-.95 2.85-2.85Z" fill="currentColor" fill-opacity=".9"/></svg></span>
                <span class="sb-brand-text">
                    <span class="sb-brand-name">Sparkling Spin</span>
                </span>
            </div>
            <span class="sb-role"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Staff'); ?></span>
        </div>

        <div class="sidebar-heading">Workspace</div>

        <ul class="nav flex-column gap-1">
            <?php foreach ($sb_nav as [$href, $icon, $label, $badge]): ?>
                <li class="nav-item">
                    <a href="<?php echo $href; ?>" class="nav-link <?php echo $sb_page === $href ? 'active' : ''; ?>">
                        <span><i class="bi <?php echo $icon; ?> me-2"></i> <?php echo $label; ?></span>
                        <?php if ((int) $badge > 0): ?>
                            <span class="sidebar-badge"><?php echo (int) $badge; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="sb-profile">
        <div class="sb-profile-row">
            <span class="sb-avatar"><?php echo htmlspecialchars($sb_initials); ?></span>
            <span class="sb-profile-text">
                <span class="sb-profile-name"><?php echo htmlspecialchars($sb_full); ?></span>
                <span class="sb-profile-role"><?php echo $sb_role; ?></span>
            </span>
        </div>
        <a href="logout.php" class="sb-logout"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>
</nav>