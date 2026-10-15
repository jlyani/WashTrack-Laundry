<?php
// users_process.php - add / update / delete ng users, and resolve password-reset requests (Admin only).
// Handles the add/update/delete of users and resolves password-reset requests.
// Admin only is allowed (blocked dito too, not just on the page).
session_start();
require_once __DIR__ . '/includes/db.php';

// Ensures user management is Admin lang.
// Modal forms post with fetch (X-Requested-With), kaya errors can be shown inside the modal instead of redirecting.
$is_ajax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');

function finish_request($redirect = 'users.php') {
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

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Your session has expired or you are not allowed to do this. Please log in again.']);
        exit();
    }
    header("Location: login.php?error=unauthorized");
    exit();
}

// Don't let an old, unshown error leak into the reply dito.
if ($is_ajax) unset($_SESSION['error']);

try {

    // 1. ADD NEW USER (bagong user)
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'add') {
        $full_name = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = $_POST['role'] ?? '';

        if (!in_array($role, ['Admin', 'Staff'], true)) $role = '';

        if ($full_name !== '' && $username !== '' && $password !== '' && $role !== '') {
            if (mb_strlen($full_name) > 100 || mb_strlen($username) > 50) {
                $_SESSION['error'] = "Name (max 100) or username (max 50) is too long.";
                finish_request();
            }

            // Check kung the username already exists.
            $checkStmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            $checkStmt->bind_param("s", $username);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if ($exists) {
                $_SESSION['error'] = "Username is already taken.";
            } else {
                // Hash the password for security (column: users.password_hash).
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $full_name, $username, $hashed_password, $role);
                $stmt->execute();
                $stmt->close();
                $_SESSION['success'] = "User added.";
            }
        } else {
            $_SESSION['error'] = "Fill in all required fields.";
        }

        finish_request();
    }

    // 2. UPDATE USER (edit a user)
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'update') {
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = $_POST['role'] ?? '';
        $is_active = (isset($_POST['is_active']) && $_POST['is_active'] === '0') ? 0 : 1;

        // An admin cannot demote or deactivate their own account
        // An admin can never demote or deactivate their own account.
        if ($user_id === (int)$_SESSION['user_id']) {
            $role      = 'Admin';
            $is_active = 1;
        }

        if (!in_array($role, ['Admin', 'Staff'], true)) {
            $role = '';
        }

        if ($user_id > 0 && $full_name !== '' && $username !== '' && $role !== '') {
            if (mb_strlen($full_name) > 100 || mb_strlen($username) > 50) {
                $_SESSION['error'] = "Name (max 100) or username (max 50) is too long.";
                finish_request();
            }

            // Check kung another user already uses the same username.
            $checkStmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id <> ?");
            $checkStmt->bind_param("si", $username, $user_id);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            // There must always be at least one active Admin left, or nobody could manage the system.
            // Keep at least one active Admin at all times, or no one can manage the system.
            $cur = $conn->prepare("SELECT role, is_active FROM users WHERE user_id = ?");
            $cur->bind_param("i", $user_id);
            $cur->execute();
            $was = $cur->get_result()->fetch_assoc();
            $cur->close();
            $loses_admin = $was && $was['role'] === 'Admin' && (int) $was['is_active'] === 1 && ($role !== 'Admin' || $is_active === 0);
            if ($loses_admin) {
                $cnt = $conn->prepare("SELECT COUNT(*) AS n FROM users WHERE role = 'Admin' AND is_active = 1 AND user_id <> ?");
                $cnt->bind_param("i", $user_id);
                $cnt->execute();
                $others = (int) $cnt->get_result()->fetch_assoc()['n'];
                $cnt->close();
                if ($others < 1) {
                    $_SESSION['error'] = "Keep at least one active Admin.";
                    finish_request();
                }
            }

            if ($exists) {
                $_SESSION['error'] = "Username is already taken.";
            } else {
                if ($password !== '') {
                    // Update with a new password.
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, password_hash = ?, role = ?, is_active = ? WHERE user_id = ?");
                    $stmt->bind_param("ssssii", $full_name, $username, $hashed_password, $role, $is_active, $user_id);
                } else {
                    // Update without changing the old password.
                    $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, role = ?, is_active = ? WHERE user_id = ?");
                    $stmt->bind_param("sssii", $full_name, $username, $role, $is_active, $user_id);
                }
                $stmt->execute();
                $stmt->close();
                $_SESSION['success'] = "User updated.";
            }
        } else {
            $_SESSION['error'] = "Fill in all required fields.";
        }

        finish_request();
    }

    // 3. DELETE USER (POST only, para a stray link or prefetch can never delete an account).
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'delete') {
        $user_id = (int)($_POST['user_id'] ?? 0);

        // An admin cannot delete their own active account.
        if ($user_id === (int)$_SESSION['user_id']) {
            $_SESSION['error'] = "You can't delete your own account.";
        } elseif ($user_id > 0) {
            // Admin accounts are never deleted (checked dito too, not just on the page, so a crafted request can't get around it).
            $who = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
            $who->bind_param("i", $user_id);
            $who->execute();
            $target = $who->get_result()->fetch_assoc();
            $who->close();

            if ($target && $target['role'] === 'Admin') {
                $_SESSION['error'] = "Admins can't be deleted. Set to Inactive instead.";
                finish_request();
            }
            try {
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['success'] = "User deleted.";
            } catch (mysqli_sql_exception $e) {
                // Foreign keys ito: orders.created_by, payments.received_by, order_status_history.changed_by, inventory_movements.created_by
                $_SESSION['error'] = "This user has records. Set to Inactive instead.";
            }
        }

        finish_request();
    }

    // 4. PASSWORD RESET REQUEST — set a new password and close the request.
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'reset_resolve') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $password   = $_POST['password'] ?? '';
        $admin_id   = (int) $_SESSION['user_id'];

        if ($request_id <= 0) {
            $_SESSION['error'] = "That reset request is no longer valid.";
            finish_request();
        }
        if (strlen($password) < 6) {
            $_SESSION['error'] = "Use at least 6 characters.";
            finish_request();
        }

        $stmt = $conn->prepare("SELECT user_id FROM password_reset_requests WHERE request_id = ? AND status = 'pending' LIMIT 1");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req) {
            $_SESSION['error'] = "Request already handled.";
            finish_request();
        }

        $target = (int) $req['user_id'];
        $hash   = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $stmt->bind_param("si", $hash, $target);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE password_reset_requests SET status = 'resolved', handled_at = NOW(), handled_by = ? WHERE request_id = ?");
        $stmt->bind_param("ii", $admin_id, $request_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success'] = "Password updated.";
        finish_request();
    }

    // 5. DISMISS A RESET REQUEST (the account itself is left untouched dito).
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'reset_dismiss') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $admin_id   = (int) $_SESSION['user_id'];

        if ($request_id > 0) {
            $stmt = $conn->prepare("UPDATE password_reset_requests SET status = 'dismissed', handled_at = NOW(), handled_by = ? WHERE request_id = ? AND status = 'pending'");
            $stmt->bind_param("ii", $admin_id, $request_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['success'] = "Reset request dismissed.";
        }

        finish_request();
    }

} catch (Throwable $e) {
    error_log('[users_process] ' . $e->getMessage());
    $_SESSION['error'] = "Something went wrong. Try again.";
    finish_request();
}

finish_request();