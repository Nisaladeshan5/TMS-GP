<?php
include('../../includes/db.php');

$action = $_POST['action'] ?? '';
$emp_id = $_POST['emp_id'] ?? '';

if ($action === 'revoke_access') {
    $stmt = $conn->prepare("DELETE FROM manager_log WHERE emp_id = ?");
    $stmt->bind_param("s", $emp_id);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Access revoked!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to revoke.']);
    }
} 

elseif ($action === 'reset_pass') {
    // 12345678 password eka hash karanawa
    $new_pass = password_hash('12345678', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE manager_log SET password = ?, first_log = 1 WHERE emp_id = ?");
    $stmt->bind_param("ss", $new_pass, $emp_id);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Password reset to 12345678!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to reset password.']);
    }
}
?>