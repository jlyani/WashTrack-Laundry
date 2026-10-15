<?php
// logout.php
// Ends the session to fully sign out, tapos back to the Login page.
session_start();

// Unset all session variables
// Clear all the session contents (user_id, full_name, role, etc.)
$_SESSION = array();

// Destroy session
// Destroy the session itself on the server (destroy session)
session_destroy();

// Redirect back to login page
// Back to the login page after this (redirect back to login page)
header("Location: login.php");
exit();
?>