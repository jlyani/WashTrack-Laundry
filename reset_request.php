<?php
// reset_request.php — the sign-in page's "Forgot password?" endpoint.
// There is no email in this system, kaya a request is recorded for the admin to
// action from the Users page. Always answers with a redirect back to login.php
// (?reset=...) so the page can explain what happened.
// When the user clicks "Forgot password?", the request is recorded here. There is no email in the system,
// so the Admin sets a new password from the Users page. It always returns to login.php.
require_once __DIR__ . '/includes/db.php';

// If the request is not POST (e.g. directly visited in the browser), just go back to login.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit();
}

$username = trim($_POST['username'] ?? '');

// Empty or too-long username -> report "no user".
if ($username === '' || mb_strlen($username) > 50) {
    header("Location: login.php?reset=nouser");
    exit();
}

try {
    // Only an account that can actually sign in may request a reset.
    // Only an active account (is_active = 1) pwede mag request.
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$found) {
        header("Location: login.php?reset=nouser");
        exit();
    }

    $user_id = (int) $found['user_id'];

    // One pending request per account: a second tap must not pile up rows.
    // Just one pending request per account - rows must not pile up on repeats.
    $stmt = $conn->prepare("SELECT request_id FROM password_reset_requests WHERE user_id = ? AND status = 'pending' LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $already = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($already) {
        header("Location: login.php?reset=pending");
        exit();
    }

    // The request is recorded dito; the Admin will act on it later on the Users page.
    $stmt = $conn->prepare("INSERT INTO password_reset_requests (user_id) VALUES (?)");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();

    header("Location: login.php?reset=sent");
    exit();

} catch (Throwable $e) {
    // If the DB fails, log it and warn the user (details are not revealed).
    error_log('[reset_request] ' . $e->getMessage());
    header("Location: login.php?reset=error");
    exit();
}
