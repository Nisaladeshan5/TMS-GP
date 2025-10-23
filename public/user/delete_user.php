<?php
// delete_user.php
// Handles the AJAX request to delete a user record from the 'user' table.

// 1. Check your db.php: Ensure this file outputs NOTHING (no whitespace, no errors).
include('../../includes/db.php');

// Set the response header to JSON. This should be done early.
header('Content-Type: application/json');

// Function to handle connection close and exit
function respondAndExit($data, $httpCode = 200, $conn = null) {
    http_response_code($httpCode);
    if ($conn) {
        $conn->close();
    }
    echo json_encode($data);
    exit;
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondAndExit(['success' => false, 'message' => 'Method not allowed.'], 405, $conn);
}

// Validate the Employee ID input
if (!isset($_POST['emp_id']) || empty(trim($_POST['emp_id']))) {
    respondAndExit(['success' => false, 'message' => 'Employee ID is required for deletion.'], 400, $conn);
}

$emp_id = trim($_POST['emp_id']);

try {
    // Prepare the DELETE statement
    $stmt = $conn->prepare("DELETE FROM user WHERE emp_id = ?");

    if ($stmt === false) {
        throw new Exception("Database prepare error: " . htmlspecialchars($conn->error));
    }
    
    // Bind the Employee ID parameter as a string ('s')
    $stmt->bind_param("s", $emp_id);

    // Execute the statement
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // Success: User deleted
            respondAndExit(['success' => true, 'message' => "User ID {$emp_id} successfully deleted."], 200, $conn);
        } else {
            // Failure: No rows affected (User ID not found)
            respondAndExit(['success' => false, 'message' => "User ID {$emp_id} not found."], 404, $conn);
        }
    } else {
        // Database execution error
        respondAndExit(['success' => false, 'message' => 'Database execution failed: ' . htmlspecialchars($stmt->error)], 500, $conn);
    }
    
    // NOTE: respondAndExit handles $stmt->close() implicitly via $conn->close() if it's there.
    $stmt->close();
    
} catch (Exception $e) {
    // General server error
    respondAndExit(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500, $conn);
}

// Final connection close if it wasn't closed in respondAndExit and the script somehow reached here
if (isset($conn)) {
    $conn->close();
}
?>