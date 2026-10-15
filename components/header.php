<?php
// components/header.php - opens the page: <head>, sidebar, content wrapper and the top bar.
// Every signed-in page includes this first and components/footer.php last.
// This opens every page — <head>, sidebar, the content wrapper, and the top bar.
// Every signed-in page starts dito and ends in footer.php.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Based on the current page file, this sets the title and subtitle in the header.
$current_page   = basename($_SERVER['PHP_SELF']);
$page_title     = "Sparkling Spin - Dashboard";
$header_heading = "Dashboard";
$show_page_desc = true; // set to false to hide the subtitle under the page title

switch ($current_page) {
    case 'view_order.php':
        $page_title = "Sparkling Spin - Order Tracking";
        $header_heading = "Order Tracking";
        $page_desc = "Monitor laundry stages and update progress in real-time.";
        break;

    case 'users.php':
        $page_title = "Sparkling Spin - User Management";
        $header_heading = "User Management";
        $page_desc = "Manage accounts, roles and permissions.";
        break;

    case 'services.php':
        $page_title = "Sparkling Spin - Services and Pricing";
        $header_heading = "Services and Pricing";
        $page_desc = "Service packages, pricing and stock usage.";
        break;

    case 'transaction.php':
        $page_title = "Sparkling Spin - Transactions";
        $header_heading = "Transactions Log";
        $page_desc = "Payments recorded at pickup.";
        break;

    case 'sales_report.php':
        $page_title = "Sparkling Spin - Sales Report";
        $header_heading = "Sales Report";
        $page_desc = "Payments collected at pickup, by period.";
        break;

    case 'audit_log.php':
        $page_title = "Sparkling Spin - Audit Log";
        $header_heading = "Audit Log";
        $page_desc = "Who changed what, and when.";
        break;

    default:
        $page_desc = "Today's shop activity at a glance.";
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?php echo (int) (@filemtime(__DIR__ . '/../assets/css/app.css') ?: 1); ?>" rel="stylesheet">
</head>
<body>

<div id="wrapper">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div id="content">
    <div class="app-header">
        <div class="d-flex align-items-center gap-3">
            <button type="button" id="sidebarCollapse" class="app-header-btn" aria-label="Toggle sidebar">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <h1 class="app-header-title"><?php echo htmlspecialchars($header_heading); ?></h1>
                <?php if ($show_page_desc && !empty($page_desc)): ?>
                    <small class="app-header-desc d-none d-sm-block"><?php echo $page_desc; ?></small>
                <?php endif; ?>
            </div>
        </div>
        <span class="app-header-date d-none d-md-inline-flex" id="headerClock"><i class="bi bi-broadcast"></i><span class="header-clock-text"><?php echo date('d M Y, l'); ?></span></span>
    </div>
    <script>
    /* live clock in the header chip — the text updates every second, no motion */
    (function () {
        var el = document.querySelector('#headerClock .header-clock-text');
        if (!el) return;
        var M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var D = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        (function tick() {
            var d = new Date();
            el.textContent = d.getDate() + ' ' + M[d.getMonth()] + ' ' + d.getFullYear() + ', ' +
                D[d.getDay()] + ' \u00b7 ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
            setTimeout(tick, 1000 - (Date.now() % 1000));
        })();
    })();
    </script>
