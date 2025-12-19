<?php
// update_admin_role.php

header('Content-Type: application/json');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 1. Session and Login Check
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit();
}

// 2. Includes and Data Validation
include('../../includes/db.php'); // Assumed database connection file

$logged_in_user_role = $_SESSION['user_role'] ?? 'admin'; 
$current_user_emp_id = $_SESSION['user_emp_id'] ?? null; 

// Check for required POST data
if (!isset($_POST['emp_id']) || !isset($_POST['new_role'])) {
    echo json_encode(['success' => false, 'error' => 'Missing data.']);
    exit();
}

$target_emp_id = $_POST['emp_id'];
$new_role = strtolower($_POST['new_role']); // Ensure role is lowercase

// --- START: SECURITY LOGIC (Must be included here) ---
$role_hierarchy = [
    'admin' => 1,
    'super admin' => 2,
    'manager' => 3,
    'developer' => 4,
];

/**
 * Checks if the logged-in user has permission to perform a specific modification on a target user based on the NEW strict rules.
 * LATEST UPDATE: Prevents ANY promotion to the 'developer' role.
 */
function canModify($logged_in_role, $target_role, $action_type, $target_new_role = null) {
    global $role_hierarchy;

    $logged_in_level = $role_hierarchy[$logged_in_role] ?? 0;
    $target_level = $role_hierarchy[$target_role] ?? 0;
    
    // Rule 0: Cannot modify your own role
    if ($logged_in_level > 0 && $target_level > 0 && $logged_in_level === $target_level) {
        return false; 
    }
    
    // Rule 1: Logged-in user must be strictly higher level than the target user's current level (for Promote/Demote)
    if ($action_type !== 'remove' && $logged_in_level <= $target_level) {
        return false;
    }
    
    // --- SPECIAL RULE FOR REMOVE (Remove 'admin' (L1) only) ---
    if ($action_type === 'remove') {
        return ($logged_in_level > 1 && $target_role === 'admin');
    }
    // ------------------------------------------------

    // A. Developer (L4): Can Promote/Demote anyone below them (L1, L2, L3)
    if ($logged_in_role === 'developer') {
        
        // **NEW RULE CHECK for DEVELOPER:** Cannot promote anyone TO 'developer'.
        if ($action_type === 'promote' && $target_new_role === 'developer') {
            return false;
        }

        // Developer can demote L4 to L3, or L3 to L2, or L2 to L1.
        return true; 
    }

    // B. Admin (L1) - Cannot do anything

    // C. Super Admin (L2) - Cannot Promote or Demote
    if ($logged_in_role === 'super admin') {
        return false;
    }
    
    // D. Manager (L3) Specific Rules
    if ($logged_in_role === 'manager') {
        
        // Determine action type based on level change
        $current_new_level = $role_hierarchy[$target_new_role] ?? 0;
        $action_type_dynamic = ($current_new_level > $target_level) ? 'promote' : 'demote';

        if ($action_type_dynamic === 'promote' && $target_new_role) {
            
            // **IMPLEMENTING NEW RULE:** Cannot promote anyone TO 'developer'.
            if ($target_new_role === 'developer') {
                return false;
            }

            // Can Promote Admin (L1 -> L2) OR Super Admin (L2 -> L3)
            return (
                ($target_role === 'admin' && $target_new_role === 'super admin') || 
                ($target_role === 'super admin' && $target_new_role === 'manager')
            );
        } elseif ($action_type_dynamic === 'demote' && $target_new_role) {
            // Can Demote Super Admin (L2 -> L1)
            return ($target_role === 'super admin' && $target_new_role === 'admin');
        }
    }

    return false;
}
// --- END: SECURITY LOGIC ---


// 3. Fetch current role of the target user
$stmt = $conn->prepare("SELECT role FROM admin WHERE emp_id = ?");
$stmt->bind_param("s", $target_emp_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Target user not found in admin table.']);
    $stmt->close();
    exit();
}

$target_user = $result->fetch_assoc();
$current_target_role = $target_user['role'];
$stmt->close();

// 4. Determine Action and Perform Permission Check
// Since we don't know if it's promote or demote yet, we use a generic 'role_change' action type here, 
// and the canModify logic uses $target_new_role to determine validity based on levels.

if ($target_emp_id == $current_user_emp_id) {
    echo json_encode(['success' => false, 'error' => 'Cannot modify your own role.']);
    exit();
}

if (!in_array($new_role, array_keys($role_hierarchy))) {
    echo json_encode(['success' => false, 'error' => 'Invalid role specified.']);
    exit();
}

$can_update = canModify($logged_in_user_role, $current_target_role, 'promote', $new_role) || 
              canModify($logged_in_user_role, $current_target_role, 'demote', $new_role);


if (!$can_update) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. You cannot set ' . $target_emp_id . ' to ' . $new_role . '.']);
    exit();
}

// 5. Execute Database Update
$update_sql = "UPDATE admin SET role = ? WHERE emp_id = ?";
$stmt = $conn->prepare($update_sql);

if ($stmt === false) {
    error_log("Prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'Database error during preparation.']);
    exit();
}

$stmt->bind_param("ss", $new_role, $target_emp_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Role for user ' . $target_emp_id . ' successfully updated to ' . $new_role . '.']);
} else {
    error_log("Execute failed: " . $stmt->error);
    echo json_encode(['success' => false, 'error' => 'Database update failed.']);
}

$stmt->close();
$conn->close();
?>