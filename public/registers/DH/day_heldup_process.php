<?php
// day_heldup_process.php
// Handles actions: 'complete' (POST), 'edit_distance' (POST), 'delete' (POST), and redirection for 'edit_reasons' (GET)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login status early
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// Get the logged-in user ID from the session. If logged out, this will be empty/null, which is used for the check.
$logged_in_user_id = (string)($_SESSION['user_id'] ?? ''); 

// Default response setup for POST/AJAX requests
$response = ['success' => false, 'message' => 'Invalid request method or missing action.'];

// --- 1. Handle POST Requests (AJAX Actions) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Set headers for AJAX response
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $action = $_POST['action'];
    $trip_id = (int)($_POST['trip_id'] ?? 0);
    $distance = $_POST['distance'] ?? null;
    
    // Check essential parameters first
    if ($trip_id === 0) {
        $response['message'] = "Trip ID is missing.";
        goto output;
    }

    // --- Action: Complete Trip (Initial Submission) / Edit Distance ---
    if ($action === 'complete' || $action === 'edit_distance') {
        // These actions REQUIRE a logged-in user
        if (empty($logged_in_user_id)) {
             $response['message'] = "Authentication required for this action.";
             goto output;
        }

        if (!is_numeric($distance) || (float)$distance < 0) {
            $response['message'] = "Distance must be a valid positive number.";
            goto output;
        }
        $distance_float = (float)$distance;
        
        $conn->begin_transaction();
        try {
            if ($action === 'complete') {
                // Update the record: Set distance, done=1, AND claim with current user_id
                $update_sql = "UPDATE day_heldup_register SET distance = ?, done = 1, user_id = ? WHERE trip_id = ? AND done = 0";
                $update_stmt = $conn->prepare($update_sql);
                if ($update_stmt === false) { throw new Exception("Complete Prepare Failed: " . $conn->error); }
                
                // Bind types: d (distance), s (logged_in_user_id), i (trip_id)
                $update_stmt->bind_param('dsi', $distance_float, $logged_in_user_id, $trip_id); 
                
                if (!$update_stmt->execute() || $update_stmt->affected_rows === 0) { throw new Exception("Trip may already be completed, or In Time is missing."); }
                $update_stmt->close();
                
                $response['message'] = "Trip ID {$trip_id} successfully completed.";

            } elseif ($action === 'edit_distance') {
                // SECURITY CHECK: Verify the current user matches the user who claimed the trip
                $check_user_sql = "SELECT user_id FROM day_heldup_register WHERE trip_id = ? AND done = 1 LIMIT 1";
                $check_user_stmt = $conn->prepare($check_user_sql);
                $check_user_stmt->bind_param('i', $trip_id);
                $check_user_stmt->execute();
                $result = $check_user_stmt->get_result();
                $row = $result->fetch_assoc();
                $check_user_stmt->close();
                
                // Compare user IDs (DB user_id must match logged_in_user_id)
                if (!$row || (string)$row['user_id'] !== $logged_in_user_id) {
                    throw new Exception("Permission denied. Only the user who initially completed the trip can edit.");
                }

                // Update the distance only
                $update_sql = "UPDATE day_heldup_register SET distance = ? WHERE trip_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('di', $distance_float, $trip_id); 
                
                if (!$update_stmt->execute()) { throw new Exception("Update Execute Failed: " . $update_stmt->error); }
                $update_stmt->close();
                
                $response['message'] = "Distance for Trip ID {$trip_id} updated.";
            }
            
            $conn->commit();
            $response['success'] = true;
            
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Heldup Trip Action Failed: " . $e->getMessage());
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
    } 
    // --- Action: Set In Time (REVISED LOGIC) ---
    elseif ($action === 'set_in_time') {
        
        // --- LOGIC CHECK: DENY IF USER IS LOGGED IN ---
        if ($is_logged_in) {
             $response['message'] = "This action is for non-logged-in users only. Please use the 'Complete' button to claim and finish the trip.";
             goto output;
        }
        // ---------------------------------------------
        
        $current_server_date = date('Y-m-d'); // Current date on server
        $current_in_time = date('H:i:s'); // Server's current time
        
        $conn->begin_transaction();
        try {
            // 1. Check if the trip is pending, in_time is NULL, AND user_id is NULL/0
            $check_sql = "SELECT done, date, user_id, in_time FROM day_heldup_register WHERE trip_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('i', $trip_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $trip_data = $result->fetch_assoc();
            $check_stmt->close();

            if (!$trip_data || (int)$trip_data['done'] !== 0) {
                 throw new Exception("Trip is not pending or does not exist.");
            }
            
            if (!empty($trip_data['user_id'])) {
                // This means a logged-in user has already claimed the trip.
                throw new Exception("Trip is already claimed by a system user. Cannot set In Time.");
            }
            
            if (!empty($trip_data['in_time'])) {
                 throw new Exception("In Time is already set.");
            }

            $trip_date = $trip_data['date'];
            
            // 2. ENFORCE RULE: In Time date must be equal to the Trip Date (No crossing days for simple Heldup)
            if ($current_server_date !== $trip_date) {
                throw new Exception("Cannot set In Time: Trip date ({$trip_date}) is not today ({$current_server_date}).");
            }


            // 3. Update only the in_time field. Crucially, user_id remains NULL/0.
            $update_sql = "UPDATE day_heldup_register SET in_time = ? WHERE trip_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt === false) { throw new Exception("Update Prepare Failed: " . $conn->error); }
            
            // Bind types: s (in_time), i (trip_id)
            $update_stmt->bind_param('si', $current_in_time, $trip_id); 
            
            if (!$update_stmt->execute()) { throw new Exception("Update Execute Failed: " . $update_stmt->error); }
            $update_stmt->close();
            $conn->commit();
            
            $response['success'] = true;
            $response['message'] = "Trip ID {$trip_id}: In Time recorded as {$current_in_time}.";

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Heldup Trip Set In Time Failed: " . $e->getMessage());
            $response['message'] = 'Error: ' . $e->getMessage();
        }

    } 
    // --- Action: Delete Trip (Requires User Check and PIN) ---
    elseif ($action === 'delete') {
        $pin = $_POST['pin'] ?? null;
        $security_type = $_POST['security_type'] ?? null;
        // The front-end sends the session user ID via POST
        $session_user_id_post = (string)($_POST['session_user_id'] ?? ''); 
        
        // This should be the current date in DDMYYY format
        $expected_pin = date('dmY'); 

        try {
            // 1. Fetch Trip Data to determine current ownership status
            $check_sql = "SELECT done, user_id FROM day_heldup_register WHERE trip_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('i', $trip_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $trip_data = $result->fetch_assoc();
            $check_stmt->close();

            if (!$trip_data) { throw new Exception("Trip ID not found."); }
            if ((int)$trip_data['done'] !== 0) { throw new Exception("Cannot delete: Trip is already marked DONE."); }
            
            // Cast DB user_id to string for consistent comparison
            $trip_owner_id = (string)($trip_data['user_id'] ?? '0'); // Treat NULL as '0' for comparison

            // 2. SECURITY CHECK based on ownership status:
            
            // Scenario A: Trip has a recorded owner (user_id IS NOT NULL/0 in DB)
            if (!empty($trip_owner_id) && $trip_owner_id !== '0') {
                // Must be the owner to delete
                if ($trip_owner_id !== $session_user_id_post) {
                    throw new Exception("Permission denied. Only the user who created this trip can delete it.");
                }
                // Owner matches, check security confirmation placeholder
                if ($security_type !== 'OWNER' || $pin !== 'OWNER_CONFIRMED') {
                    // This error is highly unlikely if JS button logic is correct, but safe to keep.
                    throw new Exception("Security confirmation error."); 
                }
            }
            
            // Scenario B: Trip has NO recorded owner (user_id IS NULL/empty/0 in DB)
            else { 
                // Deletion requires PIN validation
                if ($security_type !== 'PIN_REQUIRED') { throw new Exception("Security mismatch error."); }
                if ($pin !== $expected_pin) { throw new Exception("Invalid Security PIN."); }
            }
            
            // 3. Perform the deletion (Start Transaction)
            $conn->begin_transaction();

            // CRITICAL FIX: DELETE ASSOCIATED REASONS FIRST
            $delete_reasons_sql = "DELETE FROM dh_emp_reason WHERE trip_id = ?";
            $delete_reasons_stmt = $conn->prepare($delete_reasons_sql);
            $delete_reasons_stmt->bind_param('i', $trip_id);
            if (!$delete_reasons_stmt->execute()) {
                throw new Exception("Deletion failed for employee reasons: " . $delete_reasons_stmt->error);
            }
            $delete_reasons_stmt->close();

            // Perform the deletion of the main record
            $delete_sql = "DELETE FROM day_heldup_register WHERE trip_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param('i', $trip_id);
            
            if (!$delete_stmt->execute()) {
                throw new Exception("Deletion failed for main trip record: " . $delete_stmt->error);
            }
            $delete_stmt->close();
            
            $conn->commit();
            $response['success'] = true;
            $response['message'] = "Trip ID {$trip_id} successfully deleted.";

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Heldup Trip Deletion Failed: " . $e->getMessage());
            $response['message'] = $e->getMessage();
        }
    } else {
        $response['message'] = "Action not supported.";
    }


output:
    // Output JSON response and exit for POST requests
    if (isset($conn)) $conn->close();
    echo json_encode($response);
    exit();
} 
// --- End POST Requests ---

// --- 2. Handle GET Requests (Redirect for Edit Reasons) ---
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'edit_reasons') {
    
    $trip_id = (int)($_GET['trip_id'] ?? 0);
    
    if ($trip_id !== 0) {
        // Redirect to the dedicated edit page
        if (isset($conn)) $conn->close();
        header("Location: day_heldup_edit_reasons.php?trip_id={$trip_id}");
        exit();
    }
}


// --- 3. Default Error Output (If request didn't match POST or redirect GET) ---
if (ob_get_length()) ob_clean(); 
header('Content-Type: application/json');
$response['message'] = "Invalid or unsupported request parameters.";
if (isset($conn)) $conn->close();
echo json_encode($response);
exit();
?>