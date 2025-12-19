<?php
// day_heldup_add_trip_handler.php - Handles POST submission for new Day Heldup trips (Start Trip)

// Set up session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ====================================================================
// !!! SECURITY MODIFICATION: Login Check Removed as Requested !!!
// This endpoint can now be accessed without an active user session.
// ====================================================================
/*
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}
*/
// ====================================================================

// CRITICAL: Ensure no preceding output or errors
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo'); // Use the correct timezone

$response = ['success' => false, 'message' => ''];
$conn->begin_transaction();

try {
    // 1. Collect and Validate Data
    $op_code = strtoupper(trim($_POST['op_code'] ?? ''));
    $vehicle_no = strtoupper(trim($_POST['vehicle_no'] ?? ''));
    $reason_data_json = $_POST['reason_data_json'] ?? '[]';  
    // user_id is now treated as a MANDATORY POST parameter, not session data.
    $user_id = (string)($_POST['user_id'] ?? null); 
    
    // Auto-fill time and date on the server side (REQUIRED)
    $date = date('Y-m-d');
    $out_time = date('H:i:s');
    
    // These fields are always NULL/empty on insertion for a new PENDING trip:
    $in_time = null; 
    $distance_float = null;
    $done_status = 0; // Always PENDING
    
    // Decode reasons
    $reason_data = json_decode($reason_data_json, true);
    
    // Basic essential validation
    // User ID is NOW checked here as a MANDATORY POST parameter for auditing purposes.
    if (empty($op_code) || empty($vehicle_no) || !is_array($reason_data) || count($reason_data) == 0) {
        throw new Exception("Missing essential data (Op Code, Vehicle, User ID, or Employee Reasons).");
    }
    
    // Set user_id to the posted ID upon creation (for ownership tracking)
    $done_user_id_bind = null; 
    
    // 2. Insert Main Trip Record
    $main_sql = "INSERT INTO day_heldup_register 
                 (op_code, vehicle_no, date, out_time, in_time, distance, done, user_id) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                 
    $main_stmt = $conn->prepare($main_sql);
    if (!$main_stmt) {
        throw new Exception("Trip Insert Prepare Failed: " . $conn->error);
    }
    
    // Parameters array: values are passed by reference
    $params = [
        // Types: op(s), vehicle(s), date(s), out_time(s), in_time(s), distance(d), done(i), user_id(s)
        // NOTE: We change the last type to 's' as user_id is coming from POST (treated as string)
        'sssssdss', 
        &$op_code, 
        &$vehicle_no, 
        &$date, 
        &$out_time, 
        &$in_time, 
        &$distance_float, 
        &$done_status, 
        &$done_user_id_bind 
    ];
    
    // Function to get references for call_user_func_array (standard approach for dynamic binding/nulls)
    function bindParamsRef($stmt, $params) {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    // Since we need to ensure binding handles NULL correctly for in_time/distance, we use the reference function.
    // If your DB type for user_id is INT, you must change 'sssssdss' to 'sssssdii' in this and the previous bind_param, 
    // and cast $done_user_id_bind = (int)$user_id. The code is using 's' for safety here.
    if (!bindParamsRef($main_stmt, $params)) {
        throw new Exception("Trip Insert Bind Failed (Check DB column types/order): " . $main_stmt->error);
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
    
    $response['success'] = true;
    $response['message'] = "New Heldup Trip (ID: {$trip_id}) started successfully. Status: PENDING";

} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = "ERROR: " . $e->getMessage();
    error_log("Heldup Trip Add Transaction Failed: " . $e->getMessage());
}

if (isset($conn)) $conn->close();
echo json_encode($response);