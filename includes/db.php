<?php
// includes/db.php - database connection and last-resort error handler.
// This is where the whole system connects to the MySQL database. When included,
// it provides $conn (a MySQLi connection) used by all pages and process files.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/format.php';   // every page that talks to the database also gets app_url() / SLA settings

// Defaults suit XAMPP on your own PC. On a web host, copy includes/db_config.sample.php to includes/db_config.php,
// fill in the login your host gave you, and leave this file as it is. (The environment variables DB_HOST, DB_USER,
// DB_PASS, DB_NAME and DB_PORT also work.)
$host   = getenv('DB_HOST') ?: "localhost";
$user   = getenv('DB_USER') ?: "root";
$pass   = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
$dbname = getenv('DB_NAME') ?: "laundry_db"; // must match the database you imported laundry_db.sql into
$port   = (int) (getenv('DB_PORT') ?: 3306);
if (is_file(__DIR__ . '/db_config.php')) require __DIR__ . '/db_config.php';   // the host's login, kept out of this file

// Throw mysqli_sql_exception on errors (default in PHP 8.1+, made explicit for older XAMPP builds).
// On an SQL error it throws an exception — kaya failures are never silent.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $dbname, $port);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log('[db] connection failed: ' . $e->getMessage());
    http_response_code(500);
    die("Database connection failed. Check that MySQL is running and that the database login in includes/db.php (or includes/db_config.php) is correct.");
}

// Last line of defence: an uncaught error is logged and shown as a plain message instead of a blank page / stack trace.
// Admins din can see the technical detail.
set_exception_handler(function ($e) {
    error_log('[uncaught] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    $detail = (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['role'] ?? '') === 'Admin')
        ? '<pre style="white-space:pre-wrap;color:#64748b;font-size:12px">' . htmlspecialchars($e->getMessage()) . '</pre>' : '';
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Something went wrong. Please try again.']);
    } else {
        echo '<div style="font-family:system-ui,sans-serif;max-width:480px;margin:15vh auto;text-align:center;color:#334155"><h3>Something went wrong</h3><p>Please go back and try again. If it keeps happening, tell the administrator.</p>' . $detail . '</div>';
    }
});

// Every request re-checks the signed-in account, so changes made on the Users page take effect on the very next click:
// an account set to Inactive (or deleted) is signed out at once, and a changed role or name is picked up right away.
// (The sign-in, sign-out and password-request scripts are skipped: they have no signed-in account to check.)
// Every request re-checks the account. If Inactive or deleted, immediate sign-out;
// and a changed role or name updates the session right away.
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])
    && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['login_process.php', 'logout.php', 'reset_request.php'], true)) {
    try {
        $who_id = (int) $_SESSION['user_id'];
        $who_q  = $conn->prepare("SELECT role, full_name, username, is_active FROM users WHERE user_id = ?");
        $who_q->bind_param("i", $who_id);
        $who_q->execute();
        $who = $who_q->get_result()->fetch_assoc();
        $who_q->close();
        if (!$who || (int) $who['is_active'] !== 1) {
            $_SESSION = [];
            session_destroy();
            if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Your account is no longer active. Please sign in again.', 'redirect' => 'login.php?error=deactivated']);
            } else {
                header("Location: login.php?error=deactivated");
            }
            exit();
        }
        $_SESSION['role']      = $who['role'];
        $_SESSION['full_name'] = $who['full_name'];
        $_SESSION['username']  = $who['username'];
    } catch (Throwable $e) {
        error_log('[db] account re-check failed: ' . $e->getMessage());   // never lock everyone out because of a failed check
    }
}
