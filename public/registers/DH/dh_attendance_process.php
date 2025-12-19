<?php
// dh_attendance_process.php - Handles Attendance related actions (e.g., delete, set_ac_status_final)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// Get the logged-in user ID (Retained as string for comparison)
$logged_in_user_id = (string)$_SESSION['user_id'] ?? ''; 

// Default response setup
$response = ['success' => false, 'message' => 'Invalid request or missing action.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Set headers for AJAX response
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $action = $_POST['action'];
    
    // COMPOSITE KEY INPUTS
    $op_code = strtoupper(trim($_POST['op_code'] ?? ''));
    $record_date = trim($_POST['record_date'] ?? '');
    
    $posted_user_id = (string)($_POST['user_id'] ?? ''); 

    // Check essential parameters
    if (empty($op_code) || empty($record_date)) {
        $response['message'] = "Op Code and Date are missing.";
        goto output;
    }
    if (empty($logged_in_user_id)) {
        $response['message'] = "User session ID is missing. Cannot proceed.";
        goto output;
    }
    // Security check: Ensure the user ID sent by JS matches the active session ID
    if ($logged_in_user_id !== $posted_user_id) {
        $response['message'] = "Security error: Session mismatch.";
        goto output;
    }

    // --- Action: Delete Attendance Record (Unchanged) ---
    if ($action === 'delete_attendance_composite') {
        
        $conn->begin_transaction();
        try {
            // 1. Verify Ownership
            $check_sql = "SELECT user_id FROM dh_attendance WHERE op_code = ? AND date = ? LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ss', $op_code, $record_date);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $record_data = $result->fetch_assoc();
            $check_stmt->close();

            if (!$record_data) { throw new Exception("Attendance record not found."); }
            
            $record_owner_id = (string)$record_data['user_id']; 

            // Check if the current logged-in user is the owner
            if (empty($record_owner_id) || $record_owner_id !== $logged_in_user_id) {
                throw new Exception("Permission denied. Only the user who created this record can delete it.");
            }
            
            // 2. Perform the deletion
            $delete_sql = "DELETE FROM dh_attendance WHERE op_code = ? AND date = ? AND user_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param('sss', $op_code, $record_date, $logged_in_user_id); 
            
            if (!$delete_stmt->execute()) { throw new Exception("Deletion failed: " . $delete_stmt->error); }
            
            if ($delete_stmt->affected_rows === 0) { throw new Exception("Deletion failed or record not found."); }
            
            $delete_stmt->close();
            $conn->commit();
            
            $response['success'] = true;
            $response['message'] = "Attendance Record for {$op_code} on {$record_date} successfully deleted.";

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Attendance Deletion Failed: " . $e->getMessage());
            $response['message'] = $e->getMessage();
        }
    } 
    
    // --- Action: Set AC Status (Final Version) ---
    elseif ($action === 'set_ac_status_final') {
        
        $ac_status = (int)($_POST['ac_status'] ?? 0); // New status: 1 (AC) or 2 (Non-AC)
        
        $conn->begin_transaction();
        try {
            // 1. Fetch Current Permissions (Record Owner and AC Setter)
            // We fetch user_id here too just for completeness, but the main check is ac_user_id
            $check_sql = "SELECT user_id, ac_user_id FROM dh_attendance WHERE op_code = ? AND date = ? LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ss', $op_code, $record_date);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $record_data = $result->fetch_assoc();
            $check_stmt->close();

            if (!$record_data) { throw new Exception("Attendance record not found."); }
            
            $current_ac_setter_id = (string)$record_data['ac_user_id']; 

            // Determine if the current user has high privileges (can act if ac_user_id is NULL)
            $is_high_privilege_user = in_array($_SESSION['user_role'] ?? 'guest', ['super admin', 'admin', 'developer', 'manager']);
            
            // 2. CHECK PERMISSION LOGIC: 
            
            $is_ac_status_marked = !empty($current_ac_setter_id);
            
            if ($is_ac_status_marked) {
                // Scenario 2 (Edit): AC status is already set. Must be the original setter to edit.
                if ($current_ac_setter_id !== $logged_in_user_id) {
                    throw new Exception("Permission denied. Only the user who initially set the AC status can edit it.");
                }
            } else {
                // Scenario 1 (Initial Mark): AC status is NULL. Must have high privilege to set.
                if (!$is_high_privilege_user) {
                    throw new Exception("Permission denied. AC status is unassigned; only Administrators can mark the initial status.");
                }
            }
            
            // 3. Perform the update: Set AC status AND the new ac_user_id
            $update_sql = "UPDATE dh_attendance SET ac = ?, ac_user_id = ? WHERE op_code = ? AND date = ?";
            $update_stmt = $conn->prepare($update_sql);
            // Binding: i (ac_status), s (ac_user_id), s (op_code), s (date)
            $update_stmt->bind_param('isss', $ac_status, $logged_in_user_id, $op_code, $record_date); 
            
            if (!$update_stmt->execute()) {
                throw new Exception("AC status update failed: " . $update_stmt->error);
            }
            
            $update_stmt->close();
            $conn->commit();
            
            $new_status_text = ($ac_status === 1) ? 'AC' : 'NON-AC';
            $response['success'] = true;
            $response['message'] = "AC Status for {$op_code} updated to {$new_status_text}. Recorded by you.";

        } catch (Exception $e) {
            $conn->rollback();
            error_log("AC Status Update Failed: " . $e->getMessage());
            $response['message'] = $e->getMessage();
        }
    }
    
    else {
        $response['message'] = "Action not supported.";
    }


output:
    if (isset($conn)) $conn->close();
    echo json_encode($response);
    exit();
} 
?>