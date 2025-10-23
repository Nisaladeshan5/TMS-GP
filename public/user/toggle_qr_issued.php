<?php
// toggle_qr_issued.php

// Include your database connection file
include('../../includes/db.php'); 

// Set the response header to JSON
header('Content-Type: application/json');

// Check if the request is a POST request and has the required data
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['emp_id']) || !isset($_POST['issued_status'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or missing data.']);
    exit;
}

// Sanitize and validate inputs
$emp_id = trim($_POST['emp_id']);
$issued_status = (int)$_POST['issued_status']; // Should be 0 or 1

// Basic validation
if (empty($emp_id) || ($issued_status !== 0 && $issued_status !== 1)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Employee ID or status value.']);
    exit;
}

// Get the database connection (assuming $conn is defined in db.php)
global $conn;

try {
    // Prepare the update statement
    $sql = "UPDATE user SET issued = ? WHERE emp_id = ?";
    
    // Use prepared statements to prevent SQL injection
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Database statement preparation failed: " . $conn->error);
    }

    // Bind parameters: 'is' means integer for status and string for emp_id
    $stmt->bind_param("is", $issued_status, $emp_id);

    // Execute the statement
    if ($stmt->execute()) {
        // Check if any row was affected
        if ($stmt->affected_rows > 0) {
            $message = $issued_status == 1 ? "QR status for {$emp_id} set to Issued." : "QR status for {$emp_id} set to Not Issued.";
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            // User not found or status was already the requested value
            echo json_encode(['success' => false, 'message' => "No user found with ID {$emp_id} or status is already set."]);
        }
    } else {
        throw new Exception("Database execution failed: " . $stmt->error);
    }
    
    // Close the statement
    $stmt->close();

} catch (Exception $e) {
    // Log the error (optional) and return a generic error message
    // error_log("Error in toggle_qr_issued: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred during the update.']);
}

// NOTE: It is assumed that your 'user' table has an 'issued' column of type TINYINT(1) or similar.
?>