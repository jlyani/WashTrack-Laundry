<?php
// components/footer.php - closes #content / #wrapper, prints flash toasts and loads the scripts.
// This closes the page — closes the wrapper, includes the toast, and loads the
// JS files (Bootstrap, SweetAlert2, app.js). $page_scripts adds page-specific
// JS (e.g. orders-ui.js).
include __DIR__ . '/toast.php';

// Cache-bust app/page scripts with their mtime (same idea as app.css in the header):
// a stale page script running against newer markup is a silent, empty page.
// Adds "?v=<mtime>" to the JS link so the browser does not use the old cached
// script when the file changes.
$asset_src = function ($rel) {
    $abs = __DIR__ . '/../' . $rel;
    return htmlspecialchars($rel) . '?v=' . (int) (@filemtime($abs) ?: 1);
};
?>
    </div><!-- /#content (end) -->
</div><!-- /#wrapper (end) -->

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="<?php echo $asset_src('assets/js/app.js'); ?>"></script>
<?php foreach (($page_scripts ?? []) as $ps): ?><script src="<?php echo $asset_src($ps); ?>"></script>
<?php endforeach; ?>
<script src="assets/vendor/alpine/alpine.min.js" defer></script>
</body>
</html>
