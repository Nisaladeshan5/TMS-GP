<?php
// update_admin_role.php (Updated for Viewer Role)

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
include('../../includes/db.php'); 

$logged_in_user_role = $_SESSION['user_role'] ?? 'viewer'; 
$current_user_emp_id = $_SESSION['user_emp_id'] ?? null; 

// Check for required POST data
if (!isset($_POST['emp_id']) || !isset($_POST['new_role'])) {
    echo json_encode(['success' => false, 'error' => 'Missing data.']);
    exit();
}

$target_emp_id = $_POST['emp_id'];
$new_role = strtolower(trim($_POST['new_role'])); 

// --- START: SECURITY LOGIC ---

// 1. Updated Hierarchy with Viewer
$role_hierarchy = [
    'viewer' => 0,      // Lowest Level
    'admin' => 1,
    'super admin' => 2,
    'manager' => 3,
    'developer' => 4,
];

// Function to check permissions
function canModify($logged_in_role, $target_role, $action_type, $target_new_role) {
    global $role_hierarchy;

    $logged_in_level = $role_hierarchy[$logged_in_role] ?? 0;
    $target_level = $role_hierarchy[$target_role] ?? 0;
    $new_role_level = $role_hierarchy[$target_new_role] ?? 0;
    
    // Rule 0: Cannot modify your own role
    if ($logged_in_level > 0 && $target_level > 0 && $logged_in_level === $target_level) {
        return false; 
    }
    
    // Rule 1: Logged-in user must be STRICTLY higher level than the target user's CURRENT level
    if ($logged_in_level <= $target_level) {
        return false;
    }

    // A. Developer (L4)
    if ($logged_in_role === 'developer') {
        // Cannot promote anyone TO 'developer'
        if ($target_new_role === 'developer') return false;
        return true; 
    }

    // B. Manager (L3)
    if ($logged_in_role === 'manager') {
        // Cannot promote to Developer
        if ($target_new_role === 'developer') return false;

        // Can Promote: Viewer -> Admin OR Admin -> Super Admin OR Super Admin -> Manager
        if ($action_type === 'promote') {
            return ($target_new_role === 'admin' || $target_new_role === 'super admin' || $target_new_role === 'manager');
        }
        
        // Can Demote: Manager -> Super Admin OR Super Admin -> Admin OR Admin -> Viewer
        if ($action_type === 'demote') {
            return ($target_new_role === 'super admin' || $target_new_role === 'admin' || $target_new_role === 'viewer');
        }
    }

    // C. Super Admin (L2) - NOW HAS POWER
    if ($logged_in_role === 'super admin') {
        // Can Promote: Viewer -> Admin
        if ($action_type === 'promote') {
            return ($target_role === 'viewer' && $target_new_role === 'admin');
        }
        // Can Demote: Admin -> Viewer
        if ($action_type === 'demote') {
            return ($target_role === 'admin' && $target_new_role === 'viewer');
        }
        // Cannot touch Managers or other Super Admins
        return false;
    }

    // D. Admin (L1) & Viewer (L0) - No powers
    return false;
}
// --- END: SECURITY LOGIC ---


// 3. Fetch current role of the target user
$stmt = $conn->prepare("SELECT role FROM admin WHERE emp_id = ?");
$stmt->bind_param("s", $target_emp_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Target user not found.']);
    $stmt->close();
    exit();
}

$target_user = $result->fetch_assoc();
$current_target_role = $target_user['role'];
$stmt->close();

// 4. Determine Action Type (Promote vs Demote)
if ($target_emp_id == $current_user_emp_id) {
    echo json_encode(['success' => false, 'error' => 'Cannot modify your own role.']);
    exit();
}

if (!array_key_exists($new_role, $role_hierarchy)) {
    echo json_encode(['success' => false, 'error' => 'Invalid role specified.']);
    exit();
}

$current_level = $role_hierarchy[$current_target_role] ?? 0;
$new_level = $role_hierarchy[$new_role] ?? 0;

if ($new_level > $current_level) {
    $action = 'promote';
} elseif ($new_level < $current_level) {
    $action = 'demote';
} else {
    echo json_encode(['success' => false, 'error' => 'New role is same as current role.']);
    exit();
}

// 5. Check Permission
if (!canModify($logged_in_user_role, $current_target_role, $action, $new_role)) {
    echo json_encode(['success' => false, 'error' => 'Permission denied.']);
    exit();
}

// 6. Execute Database Update
$update_sql = "UPDATE admin SET role = ? WHERE emp_id = ?";
$stmt = $conn->prepare($update_sql);

if ($stmt === false) {
    error_log("Prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
    exit();
}

$stmt->bind_param("ss", $new_role, $target_emp_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => "User role updated to $new_role."]);
} else {
    error_log("Execute failed: " . $stmt->error);
    echo json_encode(['success' => false, 'error' => 'Database update failed.']);
}

$stmt->close();
$conn->close();
?>