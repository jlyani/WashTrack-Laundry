<?php
// services_process.php - add / update / delete ng services (Admin only).
// Handles add/update/delete of services (packages). Admin lang can do this.
session_start();
require_once __DIR__ . '/includes/db.php';

// Modal forms post with fetch (X-Requested-With), kaya errors can be shown inside the modal instead of redirecting.
$is_ajax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');

function finish_request($redirect = 'services.php') {
    global $is_ajax;
    if ($is_ajax) {
        header('Content-Type: application/json');
        if (!empty($_SESSION['error'])) {
            $msg = $_SESSION['error'];
            unset($_SESSION['error']);
            echo json_encode(['ok' => false, 'message' => $msg]);
        } else {
            unset($_SESSION['success']);   // the page shows its own toast pagkatapos mag-reload
            echo json_encode(['ok' => true, 'redirect' => $redirect]);
        }
        exit();
    }
    header("Location: " . $redirect);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Your session has expired. Please log in again.']);
        exit();
    }
    header("Location: login.php?error=unauthorized");
    exit();
}

// Services are managed by administrators only (services.php only shows these actions to Admin).
// Admin only can manage services — blocked dito too, not just on the page.
if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['error'] = "Only administrators can manage services.";
    finish_request();
}

// Don't let an old, unshown error leak into the reply dito.
if ($is_ajax) unset($_SESSION['error']);

const SERVICE_TYPES = ['Standard', 'Package'];
const LAST_SERVICE_MSG = "At least one service must stay active, or no order can be taken. Add or activate another service first.";

// Validates ang name + price; returns an error message or null.
// Mga column limits: services.name VARCHAR(100) UNIQUE, services.price_per_load DECIMAL(10,2).
function validate_service(mysqli $conn, string $name, float $price, int $exclude_id = 0): ?string {
    if ($name === '' || $price <= 0) return "Please fill in all required fields properly.";
    if ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 100) return "Service name must be 100 characters or less.";
    if ($price > 9999.99) return "Price per load cannot be more than ₱9,999.99.";

    $chk = $conn->prepare("SELECT service_id FROM services WHERE LOWER(name) = LOWER(?) AND service_id <> ? LIMIT 1");
    $chk->bind_param("si", $name, $exclude_id);
    $chk->execute();
    $exists = $chk->get_result()->num_rows > 0;
    $chk->close();
    return $exists ? "A service with that name already exists." : null;
}

/**
 * True when $service_id is active and no other service is active, i.e. switching it off (or deleting it) would leave
 * the shop with nothing to sell. Call ito inside a transaction: the rows are locked so two admins cannot both switch off
 * "the other" last service at the same moment.
 */
// Ensures the shop keeps at least one active service - if this is the only one left,
// it cannot be switched off or deleted, kung hindi the shop can no longer take orders.
function is_last_active_service(mysqli $conn, int $service_id): bool {
    $res = $conn->query("SELECT service_id FROM services WHERE is_active = 1 FOR UPDATE");
    $ids = [];
    while ($row = $res->fetch_assoc()) $ids[] = (int) $row['service_id'];
    return in_array($service_id, $ids, true) && count($ids) === 1;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {

        // 1. ADD NEW SERVICE (bagong service)
        if (isset($_POST['add_service'])) {
            $name          = trim($_POST['service_name'] ?? '');
            $price         = round((float)($_POST['price_per_load'] ?? 0), 2);
            $service_type  = $_POST['service_type'] ?? '';
            $includes_fold = isset($_POST['includes_fold']) ? 1 : 0;

            $err = validate_service($conn, $name, $price);
            if (!$err && !in_array($service_type, SERVICE_TYPES, true)) {
                $err = "Please choose a service type.";
            }
            if ($err) {
                $_SESSION['error'] = $err;
            } else {
                $stmt = $conn->prepare("INSERT INTO services (name, service_type, includes_fold, price_per_load, is_active) VALUES (?, ?, ?, ?, 1)");
                $stmt->bind_param("ssid", $name, $service_type, $includes_fold, $price);
                $stmt->execute();
                $stmt->close();
                $_SESSION['success'] = "Service added.";
            }
            finish_request();
        }

        // 2. UPDATE SERVICE (name, price, active status). Type / fold flag are fixed once created.
        // Changing the price never alters existing orders: they keep their own price snapshot.
        // Changing the price does not affect the mga existing orders - they keep their own saved price snapshot.
        if (isset($_POST['update_service'])) {
            $service_id = (int)($_POST['service_id'] ?? 0);
            $name       = trim($_POST['service_name'] ?? '');
            $price      = round((float)($_POST['price_per_load'] ?? 0), 2);
            $is_active  = (($_POST['is_active'] ?? '1') === '0') ? 0 : 1;

            $err = ($service_id > 0) ? validate_service($conn, $name, $price, $service_id) : "Invalid service selected.";
            if ($err) {
                $_SESSION['error'] = $err;
                finish_request();
            }

            $conn->begin_transaction();
            try {
                // The last active service cannot be switched off.
                if ($is_active === 0 && is_last_active_service($conn, $service_id)) {
                    $conn->rollback();
                    $_SESSION['error'] = LAST_SERVICE_MSG;
                    finish_request();
                }
                $stmt = $conn->prepare("UPDATE services SET name = ?, price_per_load = ?, is_active = ? WHERE service_id = ?");
                $stmt->bind_param("sdii", $name, $price, $is_active, $service_id);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                $_SESSION['success'] = "Service updated.";
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            finish_request();
        }

        // 3. DELETE SERVICE (POST only). Services used by orders can be deactivated lang.
        if (isset($_POST['delete_service'])) {
            $service_id = (int)($_POST['service_id'] ?? 0);

            if ($service_id <= 0) {
                $_SESSION['error'] = "Invalid service selected.";
                finish_request();
            }

            $conn->begin_transaction();
            try {
                // The last active service cannot be removed.
                if (is_last_active_service($conn, $service_id)) {
                    $conn->rollback();
                    $_SESSION['error'] = LAST_SERVICE_MSG;
                    finish_request();
                }

                $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM orders WHERE service_id = ?");
                $chk->bind_param("i", $service_id);
                $chk->execute();
                $used = (int)($chk->get_result()->fetch_assoc()['cnt'] ?? 0);
                $chk->close();

                if ($used > 0) {
                    $conn->rollback();
                    $_SESSION['error'] = "This service is used by orders. Set it to Inactive instead.";
                    finish_request();
                }

                // Package ingredient rows reference the service, kaya delete them first.
                $del_c = $conn->prepare("DELETE FROM service_consumables WHERE service_id = ?");
                $del_c->bind_param("i", $service_id);
                $del_c->execute();
                $del_c->close();

                $del_s = $conn->prepare("DELETE FROM services WHERE service_id = ?");
                $del_s->bind_param("i", $service_id);
                $del_s->execute();
                $del_s->close();

                $conn->commit();
                $_SESSION['success'] = "Service deleted.";
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('[services_process] delete failed: ' . $e->getMessage());
                $_SESSION['error'] = "Could not delete. Set it to Inactive instead.";
            }
            finish_request();
        }

    } catch (Throwable $e) {
        error_log('[services_process] ' . $e->getMessage());
        $_SESSION['error'] = "Could not save the service. Try again.";
        finish_request();
    }
}

finish_request();