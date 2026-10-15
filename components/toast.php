<?php
// components/toast.php - shows the flash messages set by the *_process.php files ($_SESSION['success'] / ['error']).
// Shows the "toast" message (green = success, red = error) left by *_process.php.
// Reads and clears the message so it shows once lang.
$toast_ok  = $_SESSION['success'] ?? null;
$toast_err = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);   // remove right away so it does not repeat on the next page
?>
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <?php if ($toast_ok): ?>
        <!-- success: green toast -->
        <div class="toast align-items-center text-white bg-success border-0 shadow" data-delay="4000" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center gap-2">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <div><?php echo htmlspecialchars($toast_ok); ?></div>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($toast_err): ?>
        <!-- error: red toast, stays longer (6000ms) -->
        <div class="toast align-items-center text-white bg-danger border-0 shadow" data-delay="6000" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                    <div><?php echo htmlspecialchars($toast_err); ?></div>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>
</div>
