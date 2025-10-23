<?php
// update_user_pin.php

header('Content-Type: application/json');
// IMPORTANT: db.php MUST NOT echo or output anything besides the connection object
include('../../includes/db.php');

$response = ['success' => false, 'error' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['emp_id']) && isset($_POST['new_pin'])) {
    
    // Retrieve emp_id from POST data
    $emp_id = trim($_POST['emp_id']); 
    $new_pin = trim($_POST['new_pin']);

    // Basic PIN validation
    if (!is_numeric($new_pin) || strlen($new_pin) != 4) {
        $response['error'] = 'Invalid PIN format. PIN must be exactly 4 digits.';
    } else {
        // SQL to update using emp_id
        $sql = "UPDATE user SET pin = ? WHERE emp_id = ?";
        
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            $response['error'] = 'Database preparation error: ' . $conn->error;
        } else {
            // Bind parameters: 's' for string (PIN), 's' for string (emp_id)
            $stmt->bind_param("ss", $new_pin, $emp_id); 
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $response['success'] = true;
                } else {
                    $response['error'] = 'No records updated. Employee ID might be incorrect or PIN is the same.';
                }
            } else {
                $response['error'] = 'Database execution error: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
} else {
    $response['error'] = 'Invalid request. Missing Employee ID or new PIN.';
}

echo json_encode($response);
$conn->close();

// Omit closing PHP tag