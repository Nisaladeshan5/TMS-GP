<?php
session_start();
// Ensure the path to your database connection file is correct
require_once 'db.php'; 

// Define the 9-hour session lifetime (32400 seconds)
$session_lifetime = 32400;

// Check for POST request.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Safely retrieve and sanitize user input
    $empNo = htmlspecialchars(trim($_POST['empNo'] ?? ''));
    $password = $_POST['password'] ?? '';

    // SQL query to get the hashed password, first-login flag, and role.
    $sql = "SELECT password, is_first_login, role, user_id FROM admin WHERE emp_id = ?";
    $stmt = $conn->prepare($sql);
    
    // Check for statement preparation failure
    if ($stmt === false) {
        $_SESSION['error_message'] = "System error. Could not process login.";
        // Log the actual error for debugging
        error_log("MySQLi prepare failed: " . $conn->error); 
        header("Location: login.php");
        exit;
    }
    
    $stmt->bind_param("s", $empNo);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close(); // Close the statement immediately

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Secure password verification
        if (password_verify($password, $row['password'])) {
            // --- LOGIN SUCCESS ---
            
            // ⚠️ SECURITY IMPROVEMENT: Regenerate session ID to prevent Session Fixation
            session_regenerate_id(true); 

            // Set minimal essential session variables
            $_SESSION['loggedin'] = true;
            $_SESSION['empNo'] = $empNo;
            $_SESSION['user_role'] = $row['role'];
            $_SESSION['user_id'] = $row['user_id'];
            
            // ⏰ TIMEOUT IMPLEMENTATION: Set the session expiration time (9 hours from now)
            $_SESSION['expire'] = time() + $session_lifetime; 
            
            // Check if this is the user's first login.
            if ($row['is_first_login'] == 1) {
                // If it's the first login, redirect to reset password page.
                header("Location: resetpass.php");
                exit;
            } else {
                // Regular login, redirect based on user role.
                
                $user_role = $row['role'];
                
                // Set default redirect page
                $redirect_page = "../index.php"; 
                
                header("Location: " . $redirect_page);
                exit;
            }
        } else {
            // Failed password check.
            $_SESSION['error_message'] = "Invalid Employee ID or Password.";
            header("Location: login.php");
            exit;
        }
    } else {
        // User not found in the database.
        $_SESSION['error_message'] = "Invalid Employee ID or Password.";
        header("Location: login.php");
        exit;
    }
} else {
    // If not a POST request, redirect back to login page.
    $_SESSION['error_message'] = "Please log in to continue.";
    header("Location: login.php");
    exit;
}
?>