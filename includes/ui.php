<?php
// includes/ui.php - tiny rendering helpers shared by pages (pagination, money).
// Small rendering helpers for display — formatting money and the "pager" (Showing x of y).

// Includes the peso sign and 2 decimal places.
function ui_money($n) { return '₱' . number_format((float) $n, 2); }

/** "Showing x of y" pager with the same look as Order Tracking. $url = function (int $page): string */
// Builds the pager/pagination links (Previous, 1, 2, 3, Next) with the same look as Order Tracking.
function ui_pager($page, $total_pages, callable $url) {
    $start = max(1, $page - 2);
    $end   = min($total_pages, $page + 2);
    $h  = '<div class="ot-pager">';
    $h .= '<a class="ot-page-btn ' . ($page <= 1 ? 'is-disabled' : '') . '" href="' . htmlspecialchars($url($page - 1)) . '" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>';
    if ($start > 1) {
        $h .= '<a class="ot-page-btn" href="' . htmlspecialchars($url(1)) . '">1</a>';
        if ($start > 2) $h .= '<span class="text-muted px-1">…</span>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $h .= '<a class="ot-page-btn ' . ($i === $page ? 'is-current' : '') . '" href="' . htmlspecialchars($url($i)) . '">' . $i . '</a>';
    }
    if ($end < $total_pages) {
        if ($end < $total_pages - 1) $h .= '<span class="text-muted px-1">…</span>';
        $h .= '<a class="ot-page-btn" href="' . htmlspecialchars($url($total_pages)) . '">' . $total_pages . '</a>';
    }
    $h .= '<a class="ot-page-btn ' . ($page >= $total_pages ? 'is-disabled' : '') . '" href="' . htmlspecialchars($url($page + 1)) . '" aria-label="Next"><i class="bi bi-chevron-right"></i></a>';
    return $h . '</div>';
}
