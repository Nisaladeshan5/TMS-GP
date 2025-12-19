<?php
session_start();
require_once 'db.php'; // Ensure your database connection file is correctly included.

// 1. Check if the user is logged in. If not, redirect them to the login page.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// 2. Check if the request method is POST.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // 3. Retrieve the employee number from the session and the new passwords from the form.
    $empNo = $_SESSION['empNo'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    // 4. Server-side validation: Check if passwords match and meet basic length requirements.
    if ($newPassword !== $confirmPassword) {
        $_SESSION['error_message'] = "Passwords do not match.";
        header("Location: resetpass.php");
        exit;
    }

    if (strlen($newPassword) < 8) { // Example: enforce a minimum length of 8 characters
        $_SESSION['error_message'] = "Password must be at least 8 characters long.";
        header("Location: resetpass.php");
        exit;
    }

    // 5. Securely hash the new password.
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    // 6. Update the user's password and reset the 'is_first_login' flag in the database.
    // Assuming your 'user' table has 'password' and 'is_first_login' columns.
    $sql = "UPDATE admin SET password = ?, is_first_login = 0 WHERE emp_id = ?";
    $stmt = $conn->prepare($sql);

    // Check if the statement was prepared successfully
    if ($stmt === false) {
        // Handle error, e.g., log the error and display a generic message
        $_SESSION['error_message'] = "Database error. Please try again later.";
        header("Location: resetpass.php");
        exit;
    }

    // Bind parameters and execute the statement
    $stmt->bind_param("ss", $hashedPassword, $empNo);
    
    if ($stmt->execute()) {
        // 7. If the update is successful, clear the session error message and redirect to the main page.
        unset($_SESSION['error_message']);
        header("Location: ../index.php");
        exit;
    } else {
        // 8. Handle execution error.
        $_SESSION['error_message'] = "Error updating password. Please try again.";
        header("Location: resetpass.php");
        exit;
    }
    
    $stmt->close();
    $conn->close();
} else {
    // 9. If the request is not a POST request, redirect to the login page.
    header("Location: login.php");
    exit;
}
?>