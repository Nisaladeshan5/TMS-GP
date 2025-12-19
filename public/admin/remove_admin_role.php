<?php
// remove_admin_role.php

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
if (!isset($_POST['emp_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing data.']);
    exit();
}

$target_emp_id = $_POST['emp_id'];

// --- START: SECURITY LOGIC (Must be included here) ---
$role_hierarchy = [
    'admin' => 1,
    'super admin' => 2,
    'manager' => 3,
    'developer' => 4,
];

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
        // Remove allowed ONLY if the target is 'admin' (L1) AND the logged-in user is higher than 'admin' (L2, L3, L4).
        return ($logged_in_level > 1 && $target_role === 'admin');
    }
    // ------------------------------------------------

    // A. Developer (L4)
    if ($logged_in_role === 'developer') {
        return true; 
    }

    // B. Admin (L1) - Cannot do anything

    // C. Super Admin (L2) - Cannot Promote or Demote
    if ($logged_in_role === 'super admin') {
        return false;
    }
    
    // D. Manager (L3) Specific Rules (Promote/Demote logic not relevant for remove, but kept for consistency)
    if ($logged_in_role === 'manager') {
        // Promotion/Demotion rules are complex, but for 'remove' we rely solely on the SPECIAL RULE above.
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

// 4. Perform Permission Check
if ($target_emp_id == $current_user_emp_id) {
    echo json_encode(['success' => false, 'error' => 'Cannot remove your own admin role.']);
    exit();
}

// Check permission to remove (Only L2, L3, L4 can remove L1)
$can_remove = canModify($logged_in_user_role, $current_target_role, 'remove');

if (!$can_remove) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. You can only remove users with the "admin" role.']);
    exit();
}

// 5. Execute Database Deletion
$delete_sql = "DELETE FROM admin WHERE emp_id = ?";
$stmt = $conn->prepare($delete_sql);

if ($stmt === false) {
    error_log("Prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'Database error during preparation.']);
    exit();
}

$stmt->bind_param("s", $target_emp_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Admin role for user ' . $target_emp_id . ' successfully removed.']);
} else {
    error_log("Execute failed: " . $stmt->error);
    echo json_encode(['success' => false, 'error' => 'Database deletion failed.']);
}

$stmt->close();
$conn->close();
?>