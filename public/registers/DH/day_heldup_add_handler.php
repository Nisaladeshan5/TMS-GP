<?php
// day_heldup_add_handler.php - Handles POST submission for new Day Heldup trips

// Set up session and necessary security checks
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}

// CRITICAL: Ensure no preceding output or errors
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

$response = ['success' => false, 'message' => ''];
$conn->begin_transaction();

try {
    // 1. Collect and Validate Base Data
    $op_code = strtoupper(trim($_POST['op_code'] ?? ''));
    $vehicle_no = strtoupper(trim($_POST['vehicle_no'] ?? ''));
    $date = trim($_POST['date'] ?? '');
    $out_time = trim($_POST['out_time'] ?? '');
    $in_time = trim($_POST['in_time'] ?? '');
    $distance = trim($_POST['distance'] ?? null);
    $user_id_raw = trim($_POST['user_id'] ?? 0); 
    $reason_data_json = $_POST['reason_data_json'] ?? '[]'; 
    
    // Cast user_id to int
    $user_id = (int)$user_id_raw;

    // Handle Distance
    $distance_float = !empty($distance) && is_numeric($distance) ? (float)$distance : 0.00;
    
    // Parse Reason Data
    $reason_data = json_decode($reason_data_json, true);
    
    // Basic essential validation
    if (empty($op_code) || empty($vehicle_no) || empty($date) || empty($out_time) || empty($in_time) || $user_id === 0 || !is_array($reason_data) || count($reason_data) == 0) {
        throw new Exception("Missing essential trip data, user ID, or invalid reason format. (Check all required fields: Vehicle, Op Code, Times, User, and at least one Employee/Reason.)");
    }
    
    // *** MODIFIED LOGIC HERE ***
    // If distance is entered (>0), mark as DONE (1). Otherwise, mark as PENDING (0).
    if ($distance_float > 0) {
        $done_status = 1; 
    } else {
        $done_status = 0;
    }
    // ***************************

    // 2. Insert Main Trip Record
    // Note: 'user_id' acts as the creator. 'done' determines status.
    $main_sql = "INSERT INTO day_heldup_register 
                 (op_code, vehicle_no, date, out_time, in_time, distance, done, user_id) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                 
    $main_stmt = $conn->prepare($main_sql);
    if (!$main_stmt) {
        throw new Exception("Trip Insert Prepare Failed: " . $conn->error);
    }
    
    // Parameters array for binding
    $params = [
        'sssssdii', 
        &$op_code, 
        &$vehicle_no, 
        &$date, 
        &$out_time, 
        &$in_time, 
        &$distance_float, 
        &$done_status, 
        &$user_id 
    ];
    
    // Helper function for dynamic binding
    function bindParamsRef($stmt, $params) {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    if (!bindParamsRef($main_stmt, $params)) {
        throw new Exception("Trip Insert Bind Failed: " . $main_stmt->error);
    }

    // --- Execution continues here ---
    if (!$main_stmt->execute()) {
        throw new Exception("Trip Insert Execute Failed: " . $main_stmt->error);
    }
    $trip_id = $conn->insert_id; // Capture the newly generated trip_id
    $main_stmt->close();
    
    
    // 3. Insert Employee Reasons
    $reasons_inserted = 0;
    $reason_sql = "INSERT INTO dh_emp_reason (trip_id, emp_id, reason_code) VALUES (?, ?, ?)";
    $reason_stmt = $conn->prepare($reason_sql);

    foreach ($reason_data as $reason) {
        $emp_id = strtoupper(trim($reason['emp_id']));
        $reason_code = trim($reason['reason_code']);
        
        if (!$reason_stmt) {
             throw new Exception("Reason Insert Prepare Failed: " . $conn->error);
        }
        
        // i s s (trip_id is INT, emp_id and reason_code are strings)
        $reason_stmt->bind_param('iss', $trip_id, $emp_id, $reason_code);
        
        if (!$reason_stmt->execute()) {
             if ($conn->errno == 1062) {
                  error_log("Ignoring duplicate reason entry for EMP: {$emp_id} in Trip {$trip_id}");
             } else {
                  throw new Exception("Reason Insert Execute Failed for {$emp_id}: " . $reason_stmt->error);
             }
        } else {
            $reasons_inserted++;
        }
    }
    if ($reason_stmt) $reason_stmt->close();
    
    if ($reasons_inserted === 0) {
        throw new Exception("Database failed to process any employee reasons.");
    }

    // 4. Commit Transaction
    $conn->commit();
    
    // Custom success message based on status
    $status_msg = ($done_status == 1) ? "COMPLETED" : "PENDING";
    
    $response['success'] = true;
    $response['message'] = "New Heldup Trip (ID: {$trip_id}) recorded successfully. Status: {$status_msg}";

} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = "ERROR: " . $e->getMessage();
    error_log("Heldup Trip Add Transaction Failed: " . $e->getMessage());
}

if (isset($conn)) $conn->close();
echo json_encode($response);
exit();
?>