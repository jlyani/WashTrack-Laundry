<?php
// includes/order_view.php - how an order row is shown: status colours, weight split, next workflow button, and the
// JSON that the page buttons read (data-order). Shared by view_order.php and dashboard.php.
// This is how an order row looks — status colours, weight split,
// next button (Start/Ready/Release), and the data-order JSON read by JavaScript.
require_once __DIR__ . '/format.php';
require_once __DIR__ . '/orders.php';   // order_is_per_kg() / order_billed_kg() (pricing helpers)
require_once __DIR__ . '/machines.php';
require_once __DIR__ . '/sla.php';       // promise / Overdue helpers: dashboard.php still calls sla_promised_sql() and sla_overdue_sql()

function getStatusExplanation($status) {
    switch (trim($status)) {
        case 'Pending': return 'Received and waiting in line.';
        case 'In Progress': return 'Being washed and dried.';
        case 'Ready for Pickup': return 'Ready. Collect payment and hand over.';
        case 'Released': return 'Picked up and paid.';
        case 'Cancelled': return 'This order was cancelled.';
        default: return 'Status is being updated.';
    }
}

function ot_status_class($status) {
    switch (trim($status)) {
        case 'Pending':          return 'ot-b-pending';
        case 'In Progress':      return 'ot-b-progress';
        case 'Ready for Pickup': return 'ot-b-ready';
        case 'Released':         return 'ot-b-done';
        case 'Cancelled':        return 'ot-b-cancel';
        default:                 return 'ot-b-pending';
    }
}

// Weight per category. Mixed orders keep their breakdown in the note
// ("Mixed: Light 10.00 kg = 2 loads ...; Heavy 7.00 kg = ..."); single-category orders use weight + load_type.
function ot_weight_split($order) {
    $light = 0.0; $heavy = 0.0;
    $notes = (string) ($order['notes'] ?? '');
    if (strpos($notes, 'Mixed:') === 0 && preg_match('/Light ([\d.]+) kg/', $notes, $l) && preg_match('/Heavy ([\d.]+) kg/', $notes, $h)) {
        $light = (float) $l[1];
        $heavy = (float) $h[1];
    } elseif ($order['load_type'] === 'Heavy') {
        $heavy = (float) $order['weight'];
    } else {
        $light = (float) $order['weight'];
    }
    return [$light, $heavy];
}

/** "Mixed" for orders with both categories, otherwise the load type. */
function ot_category_label($order) {
    return strpos((string) ($order['notes'] ?? ''), 'Mixed:') === 0 ? 'Mixed' : $order['load_type'];
}

/** Next workflow button (next action): [action, label, short label]. Empty action = nothing to do. */
function ot_next_action($status) {
    switch (trim($status)) {
        case 'Pending':          return ['start',   'Start Process', 'Start'];
        case 'In Progress':      return ['ready',   'Mark Ready',    'Ready'];
        case 'Ready for Pickup': return ['release', 'Release Order', 'Release'];
    }
    return ['', '', ''];
}

/**
 * The button shown on a row. Same as ot_next_action(), except that in the waiting line only the order at the
 * front (position 1) can be started, so every other Pending order shows no Start button.
 */
// The button shown on a row. In the queue, only the front Pending order (position 1)
// can be started; the rest show no Start button.
function ot_row_action($status, $queue_pos) {
    if (trim($status) === 'Pending' && (int) $queue_pos !== 1) return ['', '', ''];
    return ot_next_action($status);
}

/**
 * A Pending order that cannot be started yet still gets a button in its Actions cell, so every row looks the same:
 * a greyed one that says why. Returns [label, tooltip] or null (the order can be started, or is not Pending).
 * Position 1 waits for a free set of machines; everyone else waits for their turn (first come, first served).
 */
function ot_wait_button($status, $queue_pos, $loads, array $free) {
    if (trim($status) !== 'Pending' || $queue_pos === null || $queue_pos === '') return null;
    $pos = (int) $queue_pos;
    if ($pos === 1) {
        $need = machines_needed($loads);
        if (($free['Washer'] ?? 0) >= $need && ($free['Dryer'] ?? 0) >= $need) return null;
        return ['Start Process', 'Waiting for free machines'];
    }
    return ['Waiting &middot; #' . $pos, 'Orders are started in the order they came in. This one is #' . $pos . ' in line.'];
}

/**
 * Everything the page buttons need for one order, as one JSON attribute (no long onclick argument lists).
 * $order needs: order_id, order_number, customer_name, contact_number, service_id, service_type, weight, load_type, loads,
 * total_amount, payment_status, status, order_date, notes, machines.
 */
// One JSON holding everything the buttons/modals need for one order (data-order attribute).
function ot_order_json($order, $is_admin) {
    [$w_light, $w_heavy] = ot_weight_split($order);
    $status = trim($order['status']);
    [$action, $label, $short] = ot_next_action($status);
    $loads = (float) $order['loads'];
    // Per-kg orders show the billed weight (minimum charge); orders taken before per-kg pricing keep the loads wording.
    $pk = ['weight_kg' => $order['weight'], 'loads' => $order['loads'], 'price_per_load' => $order['price_per_load'] ?? 0, 'total_amount' => $order['total_amount'], 'notes' => $order['notes'] ?? ''];
    $per_kg = isset($order['price_per_load']) && order_is_per_kg($pk);
    $data = [
        'id'        => (int) $order['order_id'],
        'number'    => $order['order_number'],
        'short'     => order_short_code($order['order_number']),
        'customer'  => $order['customer_name'],
        'phone'     => $order['contact_number'] ?: '',
        'service'   => $order['service_type'],
        'weight'    => (float) $order['weight'],
        'loads'     => $loads,
        'category'  => ot_category_label($order),
        'garments'  => (string) ($order['garments'] ?? ''),
        'supplies'  => (string) ($order['supplies'] ?? ''),
        'suppliesUsed' => $status !== 'Pending' && $status !== 'Cancelled',
        'perKg'     => $per_kg,
        'rate'      => (float) ($order['price_per_load'] ?? 0),
        'billed'    => $per_kg ? order_billed_kg($pk) : (float) $order['weight'],
        'notes'     => (string) ($order['notes'] ?? ''),
        'machines'  => (string) ($order['machines'] ?? ''),
        'total'     => (float) $order['total_amount'],
        'paid'      => $order['payment_status'] === 'Paid',
        'status'    => $status,
        'created'   => date('M d, Y - h:i A', strtotime($order['order_date'])),
        'action'    => $action,
        'edit'      => ['service_id' => (int) $order['service_id'], 'light' => $w_light, 'heavy' => $w_heavy],
    ];
    return htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
}