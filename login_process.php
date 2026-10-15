<?php
// login_process.php - handles the sign-in form: brute-force guard, password check, tapos sets the session.
// This handles login. It checks the username/password, with a guard against
// repeated wrong passwords, then sets the session (user_id, role, pangalan).
session_start();
require_once __DIR__ . '/includes/db.php';

// Brute-force guard: after LOGIN_MAX_FAILS wrong passwords for the same username from the same computer within
// LOGIN_WINDOW_MIN minutes, that username is paused from this computer for the rest of the window. The pause lifts by
// itself, kaya nobody is locked out for good (and an Admin can still sign in from another computer).
const LOGIN_MAX_FAILS  = 5;
const LOGIN_WINDOW_MIN = 15;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login_btn'])) {

    // Server-side sanitization & validation ng inputs
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        header("Location: login.php?error=empty");
        exit();
    }

    $ip  = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $key = mb_strtolower(mb_substr($username, 0, 50));

    try {
        // Created on first use, kaya an existing database needs no re-import.
        $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
            attempt_id   INT AUTO_INCREMENT PRIMARY KEY,
            username     VARCHAR(50) NOT NULL,
            ip_address   VARCHAR(45) NOT NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_attempt (username, ip_address, attempted_at)
        )");
        $conn->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");

        // Paused? Count recent failures and find when the oldest one in the window expires.
        // Check the pause state — count recent failed attempts and when it expires.
        $w = LOGIN_WINDOW_MIN;
        $q = $conn->prepare("SELECT COUNT(*) c, MIN(attempted_at) first_at,
                                    GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, NOW(), MIN(attempted_at) + INTERVAL $w MINUTE) / 60)) mins
                             FROM login_attempts
                             WHERE username = ? AND ip_address = ? AND attempted_at > (NOW() - INTERVAL $w MINUTE)");
        $q->bind_param("ss", $key, $ip);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if ((int) $row['c'] >= LOGIN_MAX_FAILS) {
            header("Location: login.php?error=locked&mins=" . (int) $row['mins']);
            exit();
        }

        // Prepared Statement to prevent SQL Injection. The account is looked up whether or not it is active, so the
        // password can be checked first; only then is an inactive account reported as such.
        $stmt = $conn->prepare("SELECT user_id, username, password_hash, role, full_name, is_active FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $stored = (string)$user['password_hash'];
            $ok = password_verify($password, $stored);

            // Legacy/dummy rows whose stored value is not a real hash (does not start with "$"):
            // allow a plain comparison once, then upgrade the row to a proper hash.
            if (!$ok && $stored !== '' && $stored[0] !== '$' && hash_equals($stored, $password)) {
                $ok = true;
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                $up = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $up->bind_param("si", $new_hash, $user['user_id']);
                $up->execute();
                $up->close();
            }

            if ($ok) {
                // Right password but switched off: say so (safe to reveal, the caller proved they know the password).
                if ((int) $user['is_active'] !== 1) {
                    header("Location: login.php?error=inactive");
                    exit();
                }

                $cl = $conn->prepare("DELETE FROM login_attempts WHERE username = ? AND ip_address = ?");
                $cl->bind_param("ss", $key, $ip);
                $cl->execute();
                $cl->close();

                session_regenerate_id(true);   // new session ID to prevent session fixation

                // Set Session Variables
                // The user is stored in the session so they don't log in again on every page.
                $_SESSION['user_id']   = $user['user_id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role']      = $user['role']; // 'Admin' or 'Staff' role

                // Redirect based on role
                header("Location: " . ($_SESSION['role'] === 'Admin' ? "dashboard.php" : "view_order.php"));
                exit();
            }
        }

        // Wrong username or password: count it (unknown usernames too, para the response never reveals which exist).
        // Wrong username/password - it is logged (even if the username does not exist) so it
        // cannot be guessed by an attacker which are real. Extra hint on the Login page for tries remaining.
        $f = $conn->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)");
        $f->bind_param("ss", $key, $ip);
        $f->execute();
        $f->close();

        if ((int) $row['c'] + 1 >= LOGIN_MAX_FAILS) {
            header("Location: login.php?error=locked&mins=" . LOGIN_WINDOW_MIN);
            exit();
        }
    } catch (Throwable $e) {
        error_log('[login_process] ' . $e->getMessage());
    }

    // If credentials are wrong (tell them how many tries remain before the pause)
    $left = isset($row['c']) ? max(1, LOGIN_MAX_FAILS - (int) $row['c'] - 1) : null;
    header("Location: login.php?error=invalid" . ($left !== null ? "&left=" . $left : ""));
    exit();

} else {
    header("Location: login.php");
    exit();
}