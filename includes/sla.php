<?php
// includes/sla.php - turnaround promise (promised_at) and the computed "Overdue" flag.
// This computes when the order should be done ("Ready by"/promised_at) and whether it is Overdue.
// "Overdue" is not a status — a flag lang computed when the time passed and it is still Pending/In Progress.
//
// promised_at = order time + SLA_ROUND_MINUTES x (rounds + waiting rounds)
//   rounds         = ceil(loads / MACHINES_PER_ORDER)   (an order runs at most MACHINES_PER_ORDER loads at a time)
//   waiting rounds = 0 when the order can start at once; otherwise 1 plus one more for every full "wave" of orders
//                    already waiting in the line (see sla_wait_rounds()). A flat estimate, fixed when the claim slip prints.
//
// Overdue is NOT an order status. It is a flag worked out on the fly: an order that is still Pending or In Progress
// after its promised time. That keeps the real stage of the order (waiting in line? washing?) visible, and keeps the
// waiting line and every status query exactly as they were.
//
// All time comparisons happen in SQL (NOW()), so they use the same clock as orders.created_at.
// Works before AND after sla_migration.sql: with no promised_at column the same rule is applied to created_at.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/machines.php';

/**
 * Extra rounds to promise at intake because the order cannot start at once: 0 when nobody is waiting and a full
 * set of machines is free, otherwise 1 + one per full wave of orders already in the line (a wave = the orders the
 * shop can run at the same time). Call BEFORE the new order is inserted, so it is not counted as waiting for itself.
 */
// Extra rounds to promise because it cannot start at once (many orders still queued or
// no free machine). 0 when it can start at once.
function sla_wait_rounds(mysqli $conn, $loads): int {
    if (!machine_tables_exist($conn)) return 0;
    try {
        $ahead = (int) $conn->query("SELECT COUNT(*) AS n FROM orders WHERE order_status = 'Pending'")->fetch_assoc()['n'];
        $free  = free_machine_counts($conn);
        $need  = machines_needed($loads);
        if ($ahead === 0 && $free['Washer'] >= $need && $free['Dryer'] >= $need) return 0;

        $active = active_machine_counts($conn);
        $sets   = max(1, intdiv(min($active['Washer'], $active['Dryer']), MACHINES_PER_ORDER));
        return 1 + intdiv($ahead, $sets);
    } catch (Throwable $e) {
        error_log('[sla] could not work out the waiting time: ' . $e->getMessage());
        return 0;
    }
}

/** Does orders.promised_at exist yet? (cached result) */
function sla_column_exists(mysqli $conn): bool {
    static $has = null;
    if ($has === null) {
        try { $has = $conn->query("SHOW COLUMNS FROM orders LIKE 'promised_at'")->num_rows > 0; }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** SQL expression for an order's promised time (the saved value, else computed from created_at + loads). */
// SQL expression for an order's promised time: use saved promised_at if present, else compute from created_at + loads.
function sla_promised_sql(mysqli $conn, string $a = 'o'): string {
    $calc = "DATE_ADD({$a}.created_at, INTERVAL (" . SLA_ROUND_MINUTES . " * GREATEST(CEIL(CEIL({$a}.loads) / " . MACHINES_PER_ORDER . "), 1)) MINUTE)";
    return sla_column_exists($conn) ? "COALESCE({$a}.promised_at, {$calc})" : $calc;
}

/** SQL condition: still waiting or washing, and past the promised time. */
// Condition for calling an order Overdue: still Pending or In Progress, but past the promised time.
function sla_overdue_sql(mysqli $conn, string $a = 'o'): string {
    return "({$a}.order_status IN ('Pending', 'In Progress') AND " . sla_promised_sql($conn, $a) . " < NOW())";
}
