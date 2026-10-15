<?php
// index.php - when the system's main URL is opened, it just lands dito.
// This page is empty: it just redirects straight to the login page.
header("Location: login.php");
exit();
?>