<?php
// PHP session configuration (optional, but good practice)
// Set session cookie lifetime to 9 hours (32400 seconds)
 

// Start the session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Define the 9-hour session lifetime
$session_lifetime = 32400; // 9 hours in seconds

// 1. Check if the user is NOT logged in. Redirect immediately.
// This is the primary protection check.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // If not logged in, redirect to login page.
    $_SESSION['error_message'] = "Please log in to continue.";
    // !!! Adjust the redirect path if needed (e.g., ../includes/login.php)
    header("Location: /TMS/includes/login.php"); 
    exit;
}


// 2. Check for Session Expiration (Auto-Logout)
// Check if the 'expire' timestamp is set and if the current time is greater than the expire time.
if (isset($_SESSION['expire']) && $_SESSION['expire'] < time()) {
    
    // Session has expired (more than 9 hours since the last activity/login)
    
    // Clear all session variables
    $_SESSION = array(); 
    
    // Destroy the session on the server
    session_destroy();
    
    // Set a message for the user
    $_SESSION['error_message'] = "Your session has expired (9 hours inactivity). Please log in again.";
    
    // Redirect to the login page
    // !!! Adjust the redirect path if needed
    header("Location: /TMS/index.php"); 
    exit;
}


// 3. Update Expiry Time (Activity-Based Timeout)
// If the user is active, reset the 9-hour counter.
// This means the user gets 9 hours of *inactivity* before they are logged out.
$_SESSION['expire'] = time() + $session_lifetime;

?>