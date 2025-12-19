<?php
// Start the session to ensure session variables can be accessed and destroyed
session_start();

// Unset all session variables associated with the current session
$_SESSION = array();

// Destroy the session cookie and session data on the server
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Redirect the user back to the login page
header("Location: /TMS/index.php");
exit;
?>
