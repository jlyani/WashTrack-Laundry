<?php
// get_payment_history.php - payment record(s) of one order, as table rows for the order details modal.
// Returns the payment record of one order as HTML table rows — this is loaded by the
// order details modal kapag it is opened.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['order_id'])) {
    // Must be logged in and have an order_id; if not, stop right away.
    exit('Unauthorized');
}

$order_id = intval($_GET['order_id']);

// Fetch all payments for this order (newest muna).
$stmt = $conn->prepare("
    SELECT payment_id, receipt_no, method, amount_due, amount_tendered, change_given, reference_no, paid_at
    FROM payments
    WHERE order_id = ?
    ORDER BY paid_at DESC
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // No payment yet - this is normal naman, payment is collected on release.
    echo '<tr><td colspan="3" class="text-center text-muted">No payment yet. Payment is collected when the order is released.</td></tr>';
} else {
    while ($row = $result->fetch_assoc()) {
        $date    = date("M d, Y h:i A", strtotime($row['paid_at']));
        $method  = htmlspecialchars($row['method']);
        $receipt = htmlspecialchars($row['receipt_no']);
        $amount  = number_format($row['amount_due'], 2);
        $extra   = '';
        // Extra details: ref number kung GCash, or change for Cash.
        if ($row['method'] === 'GCash' && $row['reference_no']) {
            $extra = ' &middot; Ref ' . htmlspecialchars($row['reference_no']);
        } elseif ($row['method'] === 'Cash' && (float) $row['change_given'] > 0) {
            $extra = ' &middot; Change ₱' . number_format($row['change_given'], 2);
        }
        echo "<tr>
                <td>{$date}</td>
                <td><span class='badge bg-info-subtle text-info-emphasis'>{$method}</span><div class='text-muted' style='font-size:.72rem;'>{$receipt}{$extra}</div></td>
                <td class='fw-bold text-end'>₱{$amount}</td>
              </tr>";
    }
}
$stmt->close();
