<?php
// includes/inventory.php - stock follows orders through the inventory_movements ledger.
// Tracks how many supplies (detergent, fabric conditioner) each order consumes,
// recorded in inventory_movements so there is a trace.

// Keeps stock in step with what an order should consume: (package consumables per load) x (loads).
// Works from the inventory_movements ledger, so it is safe to call again after the order changes:
//   - new order            -> writes "Order Usage" movements (negative) and lowers stock
//   - loads/package edited -> writes only the difference (an "Adjustment" when stock goes back)
//   - $loads = 0           -> returns everything this order used (cancel / delete)
// Stock is allowed to go below zero rather than blocking the counter; a negative number means a restock was not recorded.
// Compares what the order should use against what it used, and records only the difference
// (safe to call again even if the order changed). When $loads = 0, it returns everything used
// (for cancel/delete). Stock is allowed to go below zero instead of blocking the counter.
function sync_order_stock($conn, $order_id, $service_id, $loads, $user_id) {
    $desired = [];
    if ($loads > 0) {
        $stmt = $conn->prepare("SELECT item_id, qty FROM service_consumables WHERE service_id = ?");
        $stmt->bind_param("i", $service_id);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $desired[(int) $r['item_id']] = round((float) $r['qty'] * $loads, 2);
        $stmt->close();
    }

    $used = [];
    $stmt = $conn->prepare("SELECT item_id, -SUM(change_qty) AS used FROM inventory_movements WHERE order_id = ? GROUP BY item_id");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $used[(int) $r['item_id']] = round((float) $r['used'], 2);
    $stmt->close();

    $changed = 0;
    foreach (array_unique(array_merge(array_keys($desired), array_keys($used))) as $item) {
        $delta = round(($desired[$item] ?? 0) - ($used[$item] ?? 0), 2);   // > 0: needs more stock, < 0: give back
        if (abs($delta) < 0.005) continue;
        $change = number_format(-$delta, 2, '.', '');
        $reason = $delta > 0 ? 'Order Usage' : 'Adjustment';
        $ins = $conn->prepare("INSERT INTO inventory_movements (item_id, change_qty, reason, order_id, created_by) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param("issii", $item, $change, $reason, $order_id, $user_id);
        $ins->execute();
        $ins->close();
        $up = $conn->prepare("UPDATE inventory_items SET stock_qty = stock_qty + ? WHERE item_id = ?");
        $up->bind_param("si", $change, $item);
        $up->execute();
        $up->close();
        $changed++;
    }
    return $changed;
}
