<?php
// includes/format.php - small pure helpers: form input, money, order-number display.
// Small helpers with no side-effect: reading form input, formatting money,
// and showing the order number (e.g. LDY-20261009-004 -> #004).

// Read a plain text field from POST (arrays and missing fields come back as '').
// Reads a plain text field from POST; kapag missing or array, it returns ''.
function post_str($key) {
    $v = $_POST[$key] ?? '';
    return is_scalar($v) ? trim((string) $v) : '';
}

function cents_to_str($cents) {
    return number_format($cents / 100, 2, '.', '');
}

// ---------------------------------------------------------------------------------------------
// Order numbers are stored as LDY-YYYYMMDD-NNN and shown in full wherever an order is listed: the running
// number restarts every day, so a bare "#001" would name a different order on every day.
// The order number is LDY-date-progressive (e.g. LDY-20261009-004). It resets to 001 every day,
// so the full number is shown to make the date and the exact order clear.
// ---------------------------------------------------------------------------------------------

/** "LDY-20261009-004" -> "#004" (for short spoken/inline references lang, e.g. "Cancel #004?"). */
function order_short_code($order_number) {
    if (preg_match('/-(\d{1,})$/', (string) $order_number, $m)) return '#' . str_pad($m[1], 3, '0', STR_PAD_LEFT);
    return (string) $order_number;
}

/** The full order number, e.g. LDY-20261009-004. $with_day is kept for old callers: the date is already in the number. Safe to echo. */
function order_label_html($order_number, $with_day = true) {
    $full = htmlspecialchars((string) $order_number, ENT_QUOTES, 'UTF-8');
    return '<span class="ot-code" title="' . $full . '">' . $full . '</span>';
}

/** "2026-10-12 10:31:00" -> "just now" / "5m ago" / "3h ago" / "2d ago" / "Oct 9" (friendly time). */
function time_ago($when) {
    $ts = strtotime((string) $when);
    if (!$ts) return '';
    $d = time() - $ts;
    if ($d < 0) $d = 0;
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return floor($d / 60) . 'm ago';
    if ($d < 86400)  return floor($d / 3600) . 'h ago';
    if ($d < 604800) return floor($d / 86400) . 'd ago';
    return date('M j', $ts);
}

/**
 * LIKE pattern for orders.order_number from what staff type: "004", "#004", "4" (today's style short code),
 * a full "LDY-20261009-004", or part of one. Run the result through real_escape_string / a bound parameter.
 */
function order_search_pattern($search) {
    $s = trim(ltrim(trim((string) $search), '#'));
    $esc = function ($t) { return addcslashes($t, '%_\\'); };
    if (preg_match('/^\d{1,3}$/', $s)) return '%-' . str_pad($s, 3, '0', STR_PAD_LEFT);
    return '%' . $esc($s) . '%';
}
