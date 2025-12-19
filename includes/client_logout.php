<?php
// This script is responsible for destroying the session when called by the client-side JavaScript.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 1. Clear all session data
$_SESSION = array();

// 2. Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy the session on the server
session_destroy();

// 4. Set a message and redirect to the final login page
$_SESSION['error_message'] = "Your session has expired due to inactivity. Please log in again.";
$login_page = "../index.php"; // Adjust the path relative to this script's location

// Redirect to the login page
header("Location: " . $login_page);
exit;
?>