<?php
// dh_attendance_handler.php - Handles insertion of attendance data (Auto Date/Time)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
//     header('Content-Type: application/json');
//     echo json_encode(['success' => false, 'message' => 'Authentication required.']);
//     exit();
// }

// CRITICAL: Ensure no preceding output or errors
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo'); // Use the correct timezone

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'mark_attendance') {
    $response['message'] = 'Invalid request action.';
    goto output;
}

// 1. Collect Input and Auto-Generate Date/Time
$op_code = strtoupper(trim($_POST['op_code'] ?? ''));
$vehicle_no = strtoupper(trim($_POST['vehicle_no'] ?? ''));

// ✅ FIX: Use Server's current date and time
$current_date = date('Y-m-d');
$current_time = date('H:i:s');

// Basic essential validation
if (empty($op_code) || empty($vehicle_no)) {
    $response['message'] = "Op Code and Vehicle No are required.";
    goto output;
}

$conn->begin_transaction();

try {
    
    // 2. Insert Attendance Record
    $insert_sql = "INSERT INTO dh_attendance 
                   (op_code, vehicle_no, date, time) 
                   VALUES (?, ?, ?, ?)";
                 
    $insert_stmt = $conn->prepare($insert_sql);
    if (!$insert_stmt) {
        throw new Exception("Insert Prepare Failed: " . $conn->error);
    }
    
    // Bind parameters: s s s s (op_code, vehicle_no, date, time)
    $insert_stmt->bind_param('ssss', $op_code, $vehicle_no, $current_date, $current_time);

    if (!$insert_stmt->execute()) {
        if ($conn->errno == 1062) {
             // Handle duplicate key if unique constraints exist
             throw new Exception("Attendance record already exists for this Op Code/Vehicle combination today.");
        }
        throw new Exception("Insert Execute Failed: " . $insert_stmt->error);
    }
    $insert_stmt->close();
    
    // 3. Commit Transaction
    $conn->commit();
    
    $response['success'] = true;
    $response['message'] = "Attendance marked successfully for Vehicle {$vehicle_no} on {$current_date} at {$current_time}.";

} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = "ERROR: " . $e->getMessage();
    error_log("DH Attendance Marking Failed: " . $e->getMessage());
}

output:
if (isset($conn)) $conn->close();
echo json_encode($response);