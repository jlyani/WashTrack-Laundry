<?php
// orders_process.php - the single endpoint for every order action: create, edit, start, mark ready,
// release (collect payment), cancel and delete.
// This is the main backend file para sa orders. Every action (create, edit, start, ready, release/payment,
// cancel, delete) goes through it. Every critical step runs in a transaction, with a row lock
// (FOR UPDATE) so two staff don't collide.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

// Ends the request. Row actions (assets/js/orders-ui.js) send via fetch and expect JSON;
// normal form posts get the usual redirect. Messages travel in $_SESSION['success'] / ['error'].
// This ends the request dito. For AJAX (fetch) the reply is JSON; kung a normal form post, redirect.
// Messages pass through $_SESSION['success'] / ['error'].
function finish() {
    global $is_ajax;
    if ($is_ajax) {
        header('Content-Type: application/json');
        if (!empty($_SESSION['error'])) {
            $msg = $_SESSION['error'];
            unset($_SESSION['error']);
            echo json_encode(['ok' => false, 'message' => $msg]);
        } else {
            $msg = $_SESSION['success'] ?? '';
            unset($_SESSION['success']);   // the page shows it itself after the reply (no double toast)
            echo json_encode(['ok' => true, 'message' => $msg, 'redirect' => 'view_order.php']);
        }
        exit();
    }
    header("Location: view_order.php");
    exit();
}

// Security check
// Dapat logged in before any action.
if (!isset($_SESSION['user_id'])) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Your session has expired. Please log in again.']);
        exit();
    }
    header("Location: login.php?error=unauthorized");
    exit();
}

// Don't let an old, unshown error leak into the reply dito.
if ($is_ajax) unset($_SESSION['error']);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/machines.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/sms.php';

// Make database errors throw, para a failed statement can never be committed by accident.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin');
$user_id  = (int) $_SESSION['user_id'];


// Reply para sa Start Process action. AJAX gets JSON (type: success | warning), normal posts get the usual flash + redirect.
function start_reply($type, $message, $reload = true) {
    global $is_ajax;
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $type === 'success', 'type' => $type, 'message' => $message, 'reload' => $reload]);
        exit();
    }
    if ($type === 'success') { $_SESSION['success'] = $message; } else { $_SESSION['error'] = $message; }
    finish();
}

// ---------------------------------------------------------
// 1. CREATE ORDER (Staff & Admin)
// Create a new order. No payment dito — it is only collected at release.
// The whole bag is weighed once, and the price is rate per kg x billed kg (final ito).
// Intake only: no payment is taken here. The bag is weighed once. The price is rate per kg x billed kg (the real weight,
// or the minimum charge when the bag is lighter), rounded to a whole peso, and it is FINAL: this is the amount printed
// and charged kapag pickup. Loads are worked out from the weight and the garment types ticked (Heavy = 6 kg per load, Regular = 7 kg),
// but only to size the machine set and the supplies - never the price.
// New orders always start Pending + Unpaid, at the back of the waiting line, and hold no machines until started.
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    $customer_name  = post_str('customer_name');
    $contact_raw    = post_str('contact_number');
    $contact_number = (string) normalize_phone($contact_raw);   // 09171234567 form; '' kapag empty
    $service_id     = (int) post_str('service_id');
    $weight_kg      = read_weight();
    $garments       = read_garments($conn);   // ticked garment types; null = none ticked (required kapag types exist)

    if ($customer_name === '' || $contact_raw === '' || $service_id <= 0 || $weight_kg === null) {
        $_SESSION['error'] = "Fill in all required fields. Weight: more than 0, up to " . ORDER_MAX_KG . " kg.";
    } elseif ($garments === null) {
        $_SESSION['error'] = "Tick at least one garment type.";
    } elseif (mb_strlen($customer_name) > 100 || mb_strlen($contact_raw) > 20) {
        $_SESSION['error'] = "Name or contact number is too long.";
    } elseif (normalize_phone($contact_raw) === null) {
        $_SESSION['error'] = "Enter an 11-digit mobile number (09xxxxxxxxx).";
    } else {
        // The whole intake runs in one transaction. If another counter creates an order at the same moment and the
        // database reports a duplicate order number or a deadlock, the transaction is rolled back and retried.
        // If the database reports a duplicate order number or a deadlock, rollback muna
        // and retry, up to 3 times.
        $max_attempts = 3;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                $conn->begin_transaction();

                // Customer: a person is the same name + same phone. Family members who share one phone stay separate
                // customers (each with their own name on the order); the same name on a different phone is a different person.
                // Ito ang rule: same name + same phone = one customer. A different phone is a different
                // person even with the same name.
                $customer_id = find_or_create_customer($conn, $customer_name, $contact_number);

                // The current rate per kg ng chosen active service (saved sa order as a snapshot).
                $stmt = $conn->prepare("SELECT price_per_load FROM services WHERE service_id = ? AND is_active = 1");
                $stmt->bind_param("i", $service_id);
                $stmt->execute();
                $srv = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$srv) {
                    throw new Exception("Selected package is not available.");
                }

                // price_per_load holds the RATE PER KG ng service (the column name is older than per-kg pricing).
                $rate_cents  = (int) round(((float) $srv['price_per_load']) * 100);
                $priced      = price_order($rate_cents, $weight_kg);
                $total_cents = $priced['total_cents'];
                // Machine planning only: any Heavy garment ticked -> the bag is planned at 6 kg per load, otherwise 7 kg;
                // Regular and Heavy together are washed separately, kaya at least 2 loads.
                $load_type   = $garments['heavy'] ? 'Heavy' : 'Light';
                $loads       = order_loads($weight_kg, $garments);   // Kapag Regular + Heavy mixed = at least 2 loads

                $order_number = next_code($conn, 'orders', 'order_number', 'LDY');

                $weight_s  = number_format($weight_kg, 2, '.', '');
                $loads_s   = (string) $loads;
                $price_s   = cents_to_str($rate_cents);
                $total_s   = cents_to_str($total_cents);
                $min_note  = $priced['min_applied'] ? sprintf('Minimum charge: billed %.2f kg', $priced['billed_kg']) : '';
                $notes     = build_notes($min_note);

                $stmt_ord = $conn->prepare(
                    "INSERT INTO orders (order_number, customer_id, service_id, weight_kg, load_type, loads, price_per_load, total_amount, order_status, payment_status, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Unpaid', ?, ?)"
                );
                $stmt_ord->bind_param("siissssssi", $order_number, $customer_id, $service_id, $weight_s, $load_type, $loads_s, $price_s, $total_s, $notes, $user_id);
                $stmt_ord->execute();
                $new_order_id = (int) $stmt_ord->insert_id;
                $stmt_ord->close();

                log_status($conn, $new_order_id, null, 'Pending', $user_id);
                save_order_garments($conn, $new_order_id, $garments['ids']);
                // Supplies are NOT taken here: they leave stock when the order is started (see 'start' below).
                // Supplies stock is deducted only at Start Process.

                $conn->commit();
                sms_notify_status($conn, $new_order_id, $user_id, 'received');   // after the commit: a failed text never blocks the order
                // The SMS is sent dito after the commit, so a slow or failed text never blocks the order.

                // For AJAX: return order_id so the client can refresh the list
                // For a normal POST lang: set flash for the modal (legacy)
                if ($is_ajax) {
                    // No $_SESSION['success'] dito: the page shows this message itself after it reloads (a second one is a double toast).
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok' => true,
                        'message' => "Order {$order_number} created.",
                        'order_id' => $new_order_id,
                        'order_number' => $order_number
                    ]);
                    exit();
                } else {
                    $_SESSION['new_order_flash'] = ['order_id' => $new_order_id, 'order_number' => $order_number];
                }
                break;
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                if ($attempt < $max_attempts && in_array((int) $e->getCode(), [1062, 1213, 1205], true)) {
                    usleep(random_int(50000, 150000));   // brief pause, tapos try again
                    continue;
                }
                $_SESSION['error'] = "Could not create order: " . db_error_message($e, 'create_order');
                break;
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = "Could not create order: " . $e->getMessage();
                break;
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['error'] = "Could not create order: " . db_error_message($e, 'create_order');
                break;
            }
        }
    }
    finish();
}

// ---------------------------------------------------------
// 2. EDIT ORDER (Admin only, Pending orders only)
// Admin lang, and only Pending orders can be edited. The weight and price are final - they cannot
// be changed; only the contact details (name/phone) can be corrected.
// The customer has already been told the weight and the amount, so those are locked:
// only the customer's contact details can be corrected. A wrong weight or package means cancelling the order and
// taking a new one. Nothing here touches machines, stock or the price, and the order keeps its
// place sa waiting line (the line is ordered by created_at).
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_order'])) {
    if (!$is_admin) {
        $_SESSION['error'] = "Only administrators can edit orders.";
        finish();
    }

    $order_id       = (int) post_str('order_id');
    $customer_name  = post_str('customer_name');
    $contact_raw    = post_str('contact_number');
    $contact_number = (string) normalize_phone($contact_raw);

    if ($order_id <= 0 || $customer_name === '' || $contact_raw === '') {
        $_SESSION['error'] = "Fill in all required fields.";
    } elseif (mb_strlen($customer_name) > 100 || mb_strlen($contact_raw) > 20) {
        $_SESSION['error'] = "Name or contact number is too long.";
    } elseif (normalize_phone($contact_raw) === null) {
        $_SESSION['error'] = "Enter an 11-digit mobile number (09xxxxxxxxx).";
    } else {
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("SELECT order_number, order_status, payment_status, customer_id FROM orders WHERE order_id = ? FOR UPDATE");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $order_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$order_info) {
                throw new Exception("Order not found.");
            }
            if ($order_info['payment_status'] !== 'Unpaid' || $order_info['order_status'] !== 'Pending') {
                throw new Exception("Only Pending orders can be edited.");
            }

            // Fix the customer on THIS order lang. If other orders share the customer record, they keep their own details.
            $cid = (int) $order_info['customer_id'];
            $same = find_customer($conn, $customer_name, $contact_number);
            if ($same !== null) {
                $new_cid = $same;                                   // naka-file with exactly these details
            } else {
                $cnt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND order_id <> ?");
                $cnt->bind_param("ii", $cid, $order_id);
                $cnt->execute();
                $others = (int) $cnt->get_result()->fetch_row()[0];
                $cnt->close();
                if ($others > 0) {
                    $new_cid = find_or_create_customer($conn, $customer_name, $contact_number);
                } else {
                    $up_c = $conn->prepare("UPDATE customers SET full_name = ?, phone = ? WHERE customer_id = ?");
                    $up_c->bind_param("ssi", $customer_name, $contact_number, $cid);
                    $up_c->execute();
                    $up_c->close();
                    $new_cid = $cid;
                }
            }
            if ($new_cid !== $cid) {
                $re = $conn->prepare("UPDATE orders SET customer_id = ? WHERE order_id = ?");
                $re->bind_param("ii", $new_cid, $order_id);
                $re->execute();
                $re->close();
            }

            $conn->commit();
            $_SESSION['success'] = "Order " . order_short_code($order_info['order_number']) . " updated.";
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Could not update order: " . db_error_message($e, 'edit_order');
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Could not update order: " . $e->getMessage();
        }
    }
    finish();
}

// ---------------------------------------------------------
// 3. ADD PAYMENT: not supported. The schema records one full payment per order, taken at release.
// One full payment per order lang, collected at release.
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $_SESSION['error'] = "Payment is collected when the order is released.";
    finish();
}

// ---------------------------------------------------------
// 4. ORDER STATUS & WORKFLOW (Start, Mark Ready, Release, Cancel, Delete)
// Order workflow: Pending -> In Progress -> Ready for Pickup -> Released (payment at release).
// Pending -> Cancelled is pwede too (Admin only, before it is started).
// Pending -> In Progress -> Ready for Pickup -> Released (payment collected at release)
// Start takes machines (oldest Pending order only); ibinabalik naman ng Mark Ready.
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['action_type']) && !isset($_POST['edit_order']) && !isset($_POST['add_payment'])) {
    $order_id    = (int) post_str('order_id');
    $action_type = post_str('action_type');

    try {
        $conn->begin_transaction();

        // Lock the order row so two staff members can't act on it at the same time.
        // The FOR UPDATE lock dito keeps two staff from acting on the same order at once.
        $stmt = $conn->prepare("SELECT order_number, order_status, payment_status, total_amount, loads, service_id, created_at FROM orders WHERE order_id = ? FOR UPDATE");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $ord = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ord) {
            throw new Exception("Order not found.");
        }

        $order_number = $ord['order_number'];
        $current      = $ord['order_status'];

        if ($action_type === 'delete') {
            // Admin only: permanently removes the order and every row that references it.
            // Admin lang. This permanently deletes the order and every row that references it.
            // It cannot be deleted once paid or Released - the payment ledger is permanent.
            if (!$is_admin) {
                throw new Exception("Only administrators can delete orders.");
            }
            // The payment ledger is permanent: an order that has been paid (or released) can never be deleted.
            // Once paid or Released, ito cannot be deleted - the payment ledger is permanent.
            if ($ord['payment_status'] === 'Paid' || $current === 'Released') {
                throw new Exception("This order has a payment on record and cannot be deleted.");
            }
            sync_order_stock($conn, $order_id, 0, 0, $user_id);   // return its stock first: delete_order_rows() removes ang ledger rows
            delete_order_rows($conn, $order_id);                  // also removes its machine rows, kaya any machines it held are free again
            $conn->commit();
            $_SESSION['success'] = "Order " . order_short_code($order_number) . " deleted.";
            finish();
        }

        if ($action_type === 'cancel') {
            // Soft delete (Admin only, Pending orders only): the order is kept, marked Cancelled and leaves the waiting line.
            // "Soft delete" ito - the order is kept, marked Cancelled and removed from the queue.
            // Admin lang and Pending only; once started (In Progress) it can no longer be cancelled.
            // A Pending order holds no machines and has used no supplies (a legacy one charged at intake gets them back).
            // Once an order has started, the wash is under way and supplies are used, so it can no longer be cancelled.
            if (!$is_admin) {
                throw new Exception("Unauthorized: Only administrators can cancel orders.");
            }
            if ($current !== 'Pending' || $ord['payment_status'] !== 'Unpaid') {
                throw new Exception("Only Pending orders can be cancelled. Once an order has started it can no longer be cancelled.");
            }
            if (!cancelled_status_supported($conn)) {
                throw new Exception("Cancelling needs a one-time database update: run cancelled_migration.sql in phpMyAdmin, then try again.");
            }

            $up = $conn->prepare("UPDATE orders SET order_status = 'Cancelled' WHERE order_id = ? AND order_status = ? AND payment_status = 'Unpaid'");
            $up->bind_param("is", $order_id, $current);
            $up->execute();
            if ($up->affected_rows !== 1) {
                $up->close();
                if ($ord['payment_status'] !== 'Unpaid') {
                    throw new Exception("Paid orders cannot be cancelled.");
                }
                throw new Exception("Someone else just changed this order. Refresh and try again.");
            }
            $up->close();
            log_status($conn, $order_id, $current, 'Cancelled', $user_id);

            // Only an In Progress order holds machines; free them agad (history of who used what is kept).
            $freed = free_order_machines($conn, $order_id);

            $returned = ($current === 'Pending') ? sync_order_stock($conn, $order_id, 0, 0, $user_id) : 0;

            $conn->commit();
            sms_notify_status($conn, $order_id, $user_id, 'cancelled');
            $_SESSION['success'] = "Order " . order_short_code($order_number) . " cancelled.";
            finish();
        }

        if ($action_type === 'start') {
            if ($current !== 'Pending') {
                throw new Exception("Only Pending orders can be started.");
            }

            $short = order_short_code($order_number);
            $pl = function ($n, $word) { return $n . ' ' . $word . ($n === 1 ? '' : 's'); };

            // 1. Strict FIFO: only the oldest Pending order can be started. The order row is locked, and an older
            //    order that is still Pending when we look means this one has to wait its turn.
            // Strict FIFO queue — the oldest Pending order lang can be started.
            // If an earlier Pending order exists, this one has to wait muna.
            $q = $conn->prepare(
                "SELECT order_number FROM orders
                 WHERE order_status = 'Pending' AND order_id <> ? AND (created_at < ? OR (created_at = ? AND order_id < ?))
                 ORDER BY created_at ASC, order_id ASC"
            );
            $q->bind_param("issi", $order_id, $ord['created_at'], $ord['created_at'], $order_id);
            $q->execute();
            $older = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();
            if ($older) {
                $conn->rollback();
                $first = order_short_code($older[0]['order_number']);
                start_reply('warning', "{$first} is ahead in line. Start it first.", false);
            }

            // 2. Machines: the full set (up to MACHINES_PER_ORDER washers + the same number of dryers), or nothing.
            // The full machine set (X washers + X dryers) is required — kapag short, it cannot start muna.
            if (machine_tables_exist($conn)) {
                $need = machines_needed($ord['loads']);
                $set  = reserve_machine_set($conn, $order_id, $need);
                if (!$set['ok']) {
                    $conn->rollback();
                    start_reply('warning',
                        "Not enough machines. Needs " . $pl($need, 'washer') . " + " . $pl($need, 'dryer') .
                        "; free: " . $set['free']['Washer'] . " + " . $set['free']['Dryer'] . ".", false);
                }
            }

            // 3. Start na.
            $up = $conn->prepare("UPDATE orders SET order_status = 'In Progress' WHERE order_id = ? AND order_status = 'Pending'");
            $up->bind_param("i", $order_id);
            $up->execute();
            if ($up->affected_rows !== 1) {
                $up->close();
                throw new Exception("Someone else just changed this order. Refresh and try again.");
            }
            $up->close();
            log_status($conn, $order_id, $current, 'In Progress', $user_id);

            // Supplies: the service's dose per load x the order's loads leave stock now that the wash really starts.
            // (An order taken before this change was already charged at intake: only the difference, if any, is written.)
            // Once the order starts, supplies stock is deducted dito (dose per load x loads).
            sync_order_stock($conn, $order_id, (int) $ord['service_id'], (float) $ord['loads'], $user_id);
            $conn->commit();
            sms_notify_status($conn, $order_id, $user_id, 'started');
            start_reply('success', "Order {$short} started.");
        } elseif ($action_type === 'mark_ready_or_dispatch') {
            if ($current !== 'In Progress') {
                throw new Exception("Only orders In Progress can be marked ready.");
            }
            $new_status = 'Ready for Pickup';
        } elseif ($action_type === 'finalize') {
            // Release gate: collect the full payment, mark Paid + Released, issue the receipt.
            // "Release" ito: collect the full payment, mark Paid + Released, and issue a receipt.
            // Ready for Pickup lang can be released.
            if ($current !== 'Ready for Pickup') {
                throw new Exception("Only orders that are Ready for Pickup can be released.");
            }
            if ($ord['payment_status'] !== 'Unpaid' || $current === 'Released') {
                throw new Exception("This order is already paid and cannot be modified.");
            }

            $method = post_str('payment_method');
            if (!in_array($method, ['Cash', 'GCash'], true)) {
                throw new Exception("Please choose a payment method.");
            }

            $due_cents = (int) round(((float) $ord['total_amount']) * 100);

            // Cash: amount handed over (defaults to the exact amount). GCash is always the exact amount.
            // Cash: the amount tendered may exceed the total, so there can be change. GCash: exact amount lang.
            $tendered_cents = $due_cents;
            if ($method === 'Cash' && post_str('amount_tendered') !== '') {
                $raw_t = post_str('amount_tendered');
                if ($raw_t === '' || !is_numeric($raw_t) || (float) $raw_t < 0) {
                    throw new Exception("Please enter a valid cash amount received.");
                }
                $tendered_cents = (int) round(((float) $raw_t) * 100);
            }
            if ($tendered_cents < $due_cents) {
                throw new Exception("The amount received is less than the amount due.");
            }
            $change_cents = $tendered_cents - $due_cents;

            $reference_no = null;
            if ($method === 'GCash' && post_str('reference_no') !== '') {
                $reference_no = post_str('reference_no');
                if (mb_strlen($reference_no) > 50) {
                    throw new Exception("The GCash reference number is too long.");
                }
            }

            $receipt_no = next_code($conn, 'payments', 'receipt_no', 'OR');
            $due_s      = cents_to_str($due_cents);
            $tendered_s = cents_to_str($tendered_cents);
            $change_s   = cents_to_str($change_cents);

            try {
                $ins_p = $conn->prepare(
                    "INSERT INTO payments (order_id, receipt_no, method, amount_due, amount_tendered, change_given, reference_no, received_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $ins_p->bind_param("issssssi", $order_id, $receipt_no, $method, $due_s, $tendered_s, $change_s, $reference_no, $user_id);
                $ins_p->execute();
                $ins_p->close();
            } catch (mysqli_sql_exception $e) {
                if ((int) $e->getCode() === 1062) {
                    // Order already paid na (duplicate key on payments.order_id)
                    throw new Exception("This order is already paid and cannot be modified.");
                }
                throw $e;
            }

            // One statement sets Paid and Released together (the table requires Released orders to be Paid),
            // at only if the order is still Unpaid / Ready for Pickup.
            $up = $conn->prepare("UPDATE orders SET order_status = 'Released', payment_status = 'Paid', released_at = NOW() WHERE order_id = ? AND payment_status = 'Unpaid' AND order_status = 'Ready for Pickup'");
            $up->bind_param("i", $order_id);
            $up->execute();
            if ($up->affected_rows !== 1) {
                $up->close();
                throw new Exception("This order is already paid and cannot be modified.");
            }
            $up->close();

            log_status($conn, $order_id, $current, 'Released', $user_id);

            // Machines were returned na at Mark Ready; this only closes any row that is somehow still open.
            free_order_machines($conn, $order_id);

            $conn->commit();
            sms_notify_status($conn, $order_id, $user_id, 'released');
            $_SESSION['success'] = "Order " . order_short_code($order_number) . " released.";
            finish();
        } else {
            throw new Exception("Unknown action.");
        }

        // Plain status change (mark ready)
        // Simple status change lang (Mark Ready).
        $up = $conn->prepare("UPDATE orders SET order_status = ? WHERE order_id = ?");
        $up->bind_param("si", $new_status, $order_id);
        $up->execute();
        $up->close();

        log_status($conn, $order_id, $current, $new_status, $user_id);

        // The laundry is out of the machines: give them back so the next order in line can be started.
        // Return the machines na so the next order in line can be started.
        $freed_note = '';
        if ($new_status === 'Ready for Pickup') {
            $freed = free_order_machines($conn, $order_id);
            // (the freed machines are no longer announced: keeps the toast short lang)
        }

        $conn->commit();

        // Tell the customer. Done after the commit, so a slow or failed text can never undo or block Mark Ready.
        // The text is sent dito after the commit, so a slow or failed SMS never blocks Mark Ready.
        $sms_note = '';
        if ($new_status === 'Ready for Pickup') {
            $sms = sms_notify_ready($conn, $order_id, $user_id);
            $sms_note = $sms['note'] !== '' ? ' ' . $sms['note'] : '';
        }
        $_SESSION['success'] = "Order " . order_short_code($order_number) . ($new_status === 'Ready for Pickup' ? " is ready for pickup." : " is now {$new_status}.") . $sms_note;
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        $_SESSION['error'] = db_error_message($e);
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
    }

    finish();
}

header("Location: view_order.php");
exit();