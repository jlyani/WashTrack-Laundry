<?php
// includes/orders.php - order rules: loads, pricing inputs, running numbers, status history, notes, and delete/cancel support.
// This is the "rules" file for orders — how loads are computed, pricing, order number,
// status history, and cancel/delete. This is the main backend brain for orders.
require_once __DIR__ . '/format.php';

// Maximum kilograms per load, by laundry category (Regular 7kg, Heavy 6kg).
// Each category has its own cap.
const LOAD_MAX_KG = ['Light' => 7, 'Heavy' => 6];

// Number of loads for a weight. Whole hundredths avoid floating-point errors (e.g. 14.0 kg / 7).
// Loads = weight / max kg per load (rounded up, never less than 1).
function calc_loads($weight_kg, $load_type) {
    $cap = (int) round(LOAD_MAX_KG[$load_type] * 100);
    $w   = (int) round($weight_kg * 100);
    return max(1, intdiv($w + $cap - 1, $cap));
}

// Next running number for a "PREFIX-YYYYMMDD-NNN" style code. Call this inside a transaction:
// FOR UPDATE serializes concurrent requests, and the UNIQUE index on the column is the final backstop.
// A number is never handed out twice: the day's last number is kept in code_counters, so deleting the newest order
// (or receipt) of the day does not free its number. Without that table (database not updated yet) it falls back to
// "highest number still in the table + 1".
// Builds the next order/receipt number. Runs inside a transaction so two counters
// creating at once never get the same number.
function next_code($conn, $table, $column, $prefix) {
    $day = $conn->query("SELECT DATE_FORMAT(NOW(), '%Y%m%d') AS d")->fetch_assoc()['d'];
    $base = $prefix . '-' . $day . '-';
    $like = $base . '%';
    $stmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX($column, '-', -1) AS UNSIGNED)), 0) AS n FROM $table WHERE $column LIKE ? FOR UPDATE");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();

    $has_counters = $conn->query("SHOW TABLES LIKE 'code_counters'");
    if ($has_counters && $has_counters->num_rows > 0) {
        $first = $n + 1;
        $up = $conn->prepare("INSERT INTO code_counters (prefix, day, last_n) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE last_n = GREATEST(last_n, ?) + 1");
        $up->bind_param("ssii", $prefix, $day, $first, $n);
        $up->execute();
        $up->close();
        $rd = $conn->prepare("SELECT last_n FROM code_counters WHERE prefix = ? AND day = ?");
        $rd->bind_param("ss", $prefix, $day);
        $rd->execute();
        $n = (int) $rd->get_result()->fetch_assoc()['last_n'] - 1;
        $rd->close();
    }
    return $base . str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT);
}

// Status history row (feeds Order Tracking).
// Logs every status change — these show up in Order Tracking / Audit Log.
function log_status($conn, $order_id, $from, $to, $user_id) {
    $stmt = $conn->prepare("INSERT INTO order_status_history (order_id, from_status, to_status, changed_by) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("issi", $order_id, $from, $to, $user_id);
    $stmt->execute();
    $stmt->close();
}

// The one weight typed on the intake form ("weight_kg"): the whole bag, weighed once.
// Returns kg rounded to 2 decimals, or null when missing, not a number, zero, or above ORDER_MAX_KG.
// Contact number as typed -> the form we store. Philippine mobile numbers only: 09171234567, 0917 123 4567, 0917-123-4567,
// +63 917 123 4567, 639171234567 and 9171234567 all become 09171234567 (09 + 9 digits).
// Returns null for anything else (too short, too long, landline, letters only), so a mistyped number is never saved.
// Normalizes the contact number. Only 09 + 9 digits (11 digits) are accepted; otherwise null.
function normalize_phone($raw) {
    // A Philippine mobile number is exactly 11 digits: 09 + 9 digits. Spaces and dashes are ignored;
    // nothing else is guessed or converted, so a number that is one digit short is rejected, not "fixed".
    $s = preg_replace('/[\s-]+/', '', (string) $raw);
    return preg_match('/^09\d{9}$/', $s) ? $s : null;
}

// Reads the single weight (whole bag) from the form; null if invalid, zero, or over the limit.
function read_weight() {
    $raw = post_str('weight_kg');
    if ($raw === '' || !is_numeric($raw)) return null;
    $kg = round((float) $raw, 2);
    if ($kg <= 0 || $kg > ORDER_MAX_KG) return null;
    return $kg;
}

// ---------- Garment types (form checkboxes) ----------
// The garment types (checkboxes) are only for the number of loads — not for the price.
// Each type has a category: Regular (7kg/load) or Heavy (6kg/load).
// Each type belongs to a category: Regular (7 kg per load) or Heavy (6 kg per load). They only decide the number of
// loads (machines, promise time, supplies) - never the price. Stored in garment_types; the ticked ones per order in
// order_garments. If the tables are missing (garment_migration not run) the form simply has no garment step.
function garment_tables_exist($conn) {
    static $ok = null;
    if ($ok === null) {
        try {
            $n = (int) $conn->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('garment_types', 'order_garments')")->fetch_assoc()['n'];
            $ok = ($n === 2);
        } catch (Throwable $e) {
            error_log('[orders] garment table check failed: ' . $e->getMessage());
            $ok = false;
        }
    }
    return $ok;
}

// Active garment types for the form, Regular muna.
function load_garment_types($conn) {
    if (!garment_tables_exist($conn)) return [];
    $r = $conn->query("SELECT garment_id, name, category FROM garment_types WHERE is_active = 1 ORDER BY (category = 'Heavy'), sort_order, name");
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

// The garment types ticked on the form: ['ids' => [...], 'heavy' => bool], or null when the form needs at least one.
// Without garment tables (or no active type) the step does not exist: returns an empty Regular selection.
function read_garments($conn) {
    $types = load_garment_types($conn);
    if (!$types) return ['ids' => [], 'heavy' => false, 'mixed' => false];
    $by_id = [];
    foreach ($types as $t) $by_id[(int) $t['garment_id']] = $t['category'];
    $ids = []; $heavy = false; $regular = false;
    foreach ((array) ($_POST['garments'] ?? []) as $raw) {
        $id = (int) $raw;
        if (isset($by_id[$id]) && !in_array($id, $ids, true)) {
            $ids[] = $id;
            if ($by_id[$id] === 'Heavy') $heavy = true; else $regular = true;
        }
    }
    // mixed = Regular and Heavy items in the same bag: they cannot share a load, so the order is at least 2 loads.
    return $ids ? ['ids' => $ids, 'heavy' => $heavy, 'mixed' => $heavy && $regular] : null;
}

// Loads for a bag: 7 kg per load (Regular), 6 kg when a Heavy type is ticked, and never fewer than 2 when Regular
// and Heavy are mixed (they are washed separately). Machines, promise time and supplies follow this; the price does not.
// Loads for one bag. When Regular and Heavy are mixed, they cannot share the same
// load, so the minimum is 2 loads.
function order_loads($weight_kg, array $garments) {
    $loads = calc_loads($weight_kg, $garments['heavy'] ? 'Heavy' : 'Light');
    return !empty($garments['mixed']) ? max(2, $loads) : $loads;
}

// Lowest rate per kg that still earns the margin on the worst bag: a mixed bag of the minimum weight, which takes 2 loads.
// rate >= 2 x cost per load x (1 + margin) / minimum kg. Null when there is no minimum charge to base it on.
function break_even_rate_per_kg() {
    if (MIN_CHARGE_KG <= 0) return null;
    return round(2 * COST_PER_LOAD * (1 + PROFIT_MARGIN_PCT / 100) / MIN_CHARGE_KG, 2);
}

function save_order_garments($conn, $order_id, array $ids) {
    if (!$ids || !garment_tables_exist($conn)) return;
    $stmt = $conn->prepare("INSERT INTO order_garments (order_id, garment_id) VALUES (?, ?)");
    foreach ($ids as $gid) {
        $stmt->bind_param("ii", $order_id, $gid);
        $stmt->execute();
    }
    $stmt->close();
}

// Garment names per order for the order details: [order_id => "Jeans, T-shirts & polos"].
function order_garment_names($conn, array $order_ids) {
    $out = [];
    if (!$order_ids || !garment_tables_exist($conn)) return $out;
    $ids = implode(',', array_map('intval', $order_ids));
    $r = $conn->query("SELECT og.order_id, g.name FROM order_garments og JOIN garment_types g ON g.garment_id = og.garment_id WHERE og.order_id IN ($ids) ORDER BY (g.category = 'Heavy'), g.sort_order, g.name");
    while ($r && ($row = $r->fetch_assoc())) $out[(int) $row['order_id']][] = $row['name'];
    return array_map(fn($names) => implode(', ', $names), $out);
}

// Supplies one order uses: its service's dose per load x its loads ("2 Detergent, 2 Fabric Conditioner").
function supplies_text(array $doses, $loads) {
    $parts = [];
    foreach ($doses as $d) {
        $q = round((float) $d['qty'] * (float) $loads, 2);
        $parts[] = ($q == (int) $q ? (int) $q : $q) . ' ' . $d['name'];
    }
    return implode(', ', $parts);
}

// Price of an order: rate per kg x billed kg, rounded to a whole peso (half up).
//   billed kg = the real weight, or MIN_CHARGE_KG when the bag is lighter (the weight itself is never changed).
// Works in cents and hundredths of a kg, so there is no floating-point drift. Loads are NOT part of the price.
// Price = rate per kg x billed kg, rounded to the nearest peso. When the bag is lighter than MIN_CHARGE_KG,
// the minimum weight is billed (the actual weight is never changed).
function price_order($rate_cents, $kg) {
    $w_h      = (int) round($kg * 100);
    $min_h    = (int) round(MIN_CHARGE_KG * 100);
    $billed_h = max($w_h, $min_h);
    $pesos    = intdiv(((int) $rate_cents) * $billed_h + 5000, 10000);
    return [
        'billed_kg'   => $billed_h / 100,
        'min_applied' => $min_h > $w_h,
        'total_cents' => $pesos * 100,
    ];
}

// Billed kg of a saved order: the minimum-charge note if present, otherwise the real weight.
function order_billed_kg(array $o) {
    if (preg_match('/Minimum charge: billed ([\d.]+) kg/', (string) ($o['notes'] ?? ''), $m)) return (float) $m[1];
    return (float) ($o['weight_kg'] ?? $o['weight'] ?? 0);
}

// True when the order was priced per kg. Orders saved before the per-kg model have total = price x loads
// (price_per_load was a price per load), so they are told apart by that identity and keep their old layout.
// Tells whether the order is priced per kg. Old orders (charged per load) are
// told apart by the identity total = price x loads, and keep their old layout.
function order_is_per_kg(array $o) {
    return abs((float) $o['total_amount'] - (float) $o['price_per_load'] * (float) $o['loads']) > 0.005;
}

// Joins note pieces (mixed-load breakdown, machine shortfall) into the value for orders.notes (VARCHAR 255).
// Joins the note pieces (e.g. mixed-load breakdown) for orders.notes.
function build_notes(...$parts) {
    $text = implode(' | ', array_filter($parts, fn($p) => $p !== ''));
    return $text === '' ? null : mb_substr($text, 0, 255);
}

// Rows that reference an order must go before the order itself.
// Before deleting the order, delete the referencing rows first (payments, history, etc.)
// so the foreign key is not violated.
function delete_order_rows($conn, $order_id) {
    $tables = ['payments', 'order_status_history', 'inventory_movements'];
    if (machine_tables_exist($conn)) $tables[] = 'order_machines';
    if (garment_tables_exist($conn)) $tables[] = 'order_garments';
    foreach ($tables as $table) {
        $stmt = $conn->prepare("DELETE FROM $table WHERE order_id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $conn->prepare("DELETE FROM orders WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $stmt->close();
}

// True when orders.order_status accepts 'Cancelled' (cancelled_migration.sql has been applied).
function cancelled_status_supported($conn) {
    static $ok = null;
    if ($ok === null) {
        $r = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'order_status'");
        $row = $r ? $r->fetch_assoc() : null;
        $ok = $row && stripos((string) $row['COLUMN_TYPE'], "'Cancelled'") !== false;
    }
    return $ok;
}

function db_error_message($e, $context = '') {
    error_log('orders_process.php' . ($context !== '' ? " [$context]" : '') . ': ' . $e->getMessage()
        . ' (errno ' . (int) $e->getCode() . ') at ' . basename($e->getFile()) . ':' . $e->getLine());
    $msg = "A database error occurred. Please try again or contact an administrator.";
    if (!empty($GLOBALS['is_admin'])) {
        $msg .= " [Admin detail: " . $e->getMessage() . "]";
    }
    return $msg;
}


/** customer_id of the customer with exactly this name (case and spacing ignored) and phone, or null. */
function find_customer(mysqli $conn, string $name, ?string $phone): ?int
{
    $stmt = $conn->prepare("SELECT customer_id FROM customers WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?)) AND phone <=> ? ORDER BY customer_id LIMIT 1");
    $stmt->bind_param("ss", $name, $phone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row['customer_id'] : null;
}

// If a customer with the same name + phone exists, reuse it; otherwise register a new one.
/** Reuses the customer with this name + phone, or registers a new one. */
function find_or_create_customer(mysqli $conn, string $name, ?string $phone): int
{
    $id = find_customer($conn, $name, $phone);
    if ($id !== null) return $id;
    $ins = $conn->prepare("INSERT INTO customers (full_name, phone) VALUES (?, ?)");
    $ins->bind_param("ss", $name, $phone);
    $ins->execute();
    $id = (int) $ins->insert_id;
    $ins->close();
    return $id;
}