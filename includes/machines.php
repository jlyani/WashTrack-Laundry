<?php
// includes/machines.php - washer/dryer availability and the strict FIFO waiting line.
// This checks which washer/dryer is free and enforces the queue (FIFO) —
// the older Pending order must go first. A Pending order holds no machine;
// it only takes a machine when Start Process runs, and returns it on Mark Ready or Cancel.
//
// How the queue works:
//   - Pending is the waiting line, oldest first. A Pending order holds NO machines.
//   - Start Process is allowed only for the OLDEST Pending order, and only when a full set of machines is free:
//     machines_needed() washers + the same number of dryers (never more than MACHINES_PER_ORDER of each; loads
//     beyond that run as extra rounds on the same machines). All or nothing: the order gets its whole set or none.
//   - The set is held (order_machines.released_at IS NULL) while the order is In Progress and given back at
//     Mark Ready (or Cancel). The next order in line can then be started.
require_once __DIR__ . '/config.php';

if (!function_exists('machine_tables_exist')) {
    /**
     * True when the machine tables (machines, order_machines) exist. Lets every page keep working, without
     * machine reservation, on a database where machines_migration.sql has not been applied.
     */
    function machine_tables_exist(mysqli $conn): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $n = (int) $conn->query(
                "SELECT COUNT(*) AS n FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('machines', 'order_machines')"
            )->fetch_assoc()['n'];
            $ok = ($n === 2);
        } catch (Throwable $e) {
            error_log('[machines] table check failed: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) error_log('[machines] machines/order_machines tables are missing - run machines_migration.sql. Machine reservation is skipped.');
        return $ok;
    }
}

if (!function_exists('load_free_machines')) {
    /**
     * Free, active machines grouped by type: ['Washer' => [[machine_id, label], ...], 'Dryer' => [...]].
     * A machine is "busy" while an In Progress order holds an open reservation (order_machines.released_at IS NULL).
     * Returns empty lists (and logs) when the machine tables are missing, so the intake form still opens.
     */
    function load_free_machines(mysqli $conn): array {
        $free = ['Washer' => [], 'Dryer' => []];
        if (!machine_tables_exist($conn)) return $free;
        try {
            $res = $conn->query(
                "SELECT m.machine_id, m.machine_type, m.label
                 FROM machines m
                 WHERE m.is_active = 1
                   AND NOT EXISTS (SELECT 1 FROM order_machines om WHERE om.machine_id = m.machine_id AND om.released_at IS NULL)
                 ORDER BY m.machine_type, m.label"
            );
            while ($row = $res->fetch_assoc()) {
                $free[$row['machine_type']][] = ['machine_id' => (int) $row['machine_id'], 'label' => $row['label']];
            }
        } catch (Throwable $e) {
            error_log('[machines] could not load free machines: ' . $e->getMessage());
        }
        return $free;
    }
}

if (!function_exists('machine_labels_sql')) {
    /** SQL expression (alias o = orders) listing the machines an order holds now, or NULL if the tables are missing. */
    function machine_labels_sql(mysqli $conn): string {
        if (!machine_tables_exist($conn)) return 'NULL';
        return "(SELECT GROUP_CONCAT(m.label ORDER BY m.machine_type DESC, m.label SEPARATOR ', ')
                 FROM order_machines om JOIN machines m ON m.machine_id = om.machine_id
                 WHERE om.order_id = o.order_id AND om.released_at IS NULL)";
    }
}

/** Washers (and the same number of dryers) an order needs to run: one per load, never more than MACHINES_PER_ORDER. */
// How many washers (and the same number of dryers) an order needs — one per load,
// never more than MACHINES_PER_ORDER.
function machines_needed($loads): int {
    return max(1, min((int) ceil((float) $loads), MACHINES_PER_ORDER));
}

/** Free machine counts: ['Washer' => n, 'Dryer' => n]. */
function free_machine_counts(mysqli $conn): array {
    $free = load_free_machines($conn);
    return ['Washer' => count($free['Washer']), 'Dryer' => count($free['Dryer'])];
}

/** Active machine counts (busy or not): ['Washer' => n, 'Dryer' => n]. */
function active_machine_counts(mysqli $conn): array {
    $out = ['Washer' => 0, 'Dryer' => 0];
    if (!machine_tables_exist($conn)) return $out;
    try {
        $res = $conn->query("SELECT machine_type, COUNT(*) AS n FROM machines WHERE is_active = 1 GROUP BY machine_type");
        while ($row = $res->fetch_assoc()) $out[$row['machine_type']] = (int) $row['n'];
    } catch (Throwable $e) {
        error_log('[machines] could not count machines: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Gives an order its full machine set, all or nothing. Call this inside a transaction, after the order row is locked
 * and the FIFO check has passed. Locks every active machine of each type (always washers first) so two staff
 * clicking at once can never get the same machine.
 *
 * Returns ['ok' => bool, 'free' => ['Washer' => n, 'Dryer' => n]]; 'free' is what was available when it failed.
 * Without the machine tables there is nothing to reserve, so it reports ok.
 */
// All-or-nothing reservation of the full machine set. Rows are locked (FOR UPDATE)
// so two staff clicking at once never get the same machine.
function reserve_machine_set($conn, $order_id, $need) {
    $result = ['ok' => false, 'free' => ['Washer' => 0, 'Dryer' => 0]];
    if (!machine_tables_exist($conn)) { $result['ok'] = true; return $result; }

    // A Pending order holds nothing. Drop any stale open rows (left over from the old "reserve at intake" model)
    // so they can neither block this order nor collide with the primary key (order_id + machine_id).
    $del = $conn->prepare("DELETE FROM order_machines WHERE order_id = ? AND released_at IS NULL");
    $del->bind_param("i", $order_id);
    $del->execute();
    $del->close();

    $pick = [];
    foreach (['Washer', 'Dryer'] as $type) {
        $stmt = $conn->prepare("SELECT machine_id FROM machines WHERE machine_type = ? AND is_active = 1 ORDER BY machine_id FOR UPDATE");
        $stmt->bind_param("s", $type);
        $stmt->execute();
        $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'machine_id'));
        $stmt->close();

        $busy = array_map('intval', array_column(
            $conn->query("SELECT machine_id FROM order_machines WHERE released_at IS NULL")->fetch_all(MYSQLI_ASSOC), 'machine_id'
        ));
        $free = array_values(array_diff($ids, $busy));
        $result['free'][$type] = count($free);
        $pick[$type] = array_slice($free, 0, $need);
    }
    if (count($pick['Washer']) < $need || count($pick['Dryer']) < $need) return $result;

    $ins = $conn->prepare("INSERT INTO order_machines (order_id, machine_id) VALUES (?, ?)");
    foreach ($pick as $ids) {
        foreach ($ids as $machine_id) {
            $ins->bind_param("ii", $order_id, $machine_id);
            $ins->execute();
        }
    }
    $ins->close();
    $result['ok'] = true;
    return $result;
}

/** Gives back every machine an order holds (Mark Ready, Cancel). History is kept: rows are closed, not deleted. Returns how many were freed. */
// Returns every machine the order holds. Rows are not deleted — released_at is just set,
// so there is history of who used it.
function free_order_machines($conn, $order_id) {
    if (!machine_tables_exist($conn)) return 0;
    $stmt = $conn->prepare("UPDATE order_machines SET released_at = NOW() WHERE order_id = ? AND released_at IS NULL");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return max(0, (int) $n);
}

/** SQL expression (alias o = orders): place in the waiting line (1 = next) for a Pending order, NULL for any other status. */
// SQL that tells the queue position of a Pending order (1 = next to start).
function queue_position_sql(string $a = 'o'): string {
    return "(CASE WHEN {$a}.order_status = 'Pending' THEN
                (SELECT COUNT(*) FROM orders q WHERE q.order_status = 'Pending'
                    AND (q.created_at < {$a}.created_at OR (q.created_at = {$a}.created_at AND q.order_id <= {$a}.order_id)))
             ELSE NULL END)";
}
