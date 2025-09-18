<?php
// Sesi sudah dimulai oleh front controller (index.php)
require_once 'includes/bootstrap.php';

// Log the logout activity before destroying the session
if (isset($_SESSION['username'])) {
    log_activity($_SESSION['username'], 'User Logout');
}

// Unset all of the session variables
$_SESSION = array();

// Destroy the session.
session_destroy();

// Redirect to login page via the router
header("location: " . base_url('/login'));
exit;