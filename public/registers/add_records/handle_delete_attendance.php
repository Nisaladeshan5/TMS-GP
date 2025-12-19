<?php
include('../../../includes/db.php'); // Your database connection

// Check for POST request and required fields
if ($_SERVER["REQUEST_METHOD"] == "POST" && 
    isset($_POST['op_code'], $_POST['attendance_date'], $_POST['user_id'], $_POST['remark'])) {

    $op_code = trim($_POST['op_code']);
    $attendance_date = trim($_POST['attendance_date']);
    $user_id = (int)$_POST['user_id'];
    $remark = trim($_POST['remark']);

    // Basic validation
    if (empty($op_code) || empty($attendance_date) || empty($remark) || $user_id === 0) {
        // Handle error: missing data
        die("Error: Required data is missing for deletion/logging.");
    }
    
    // Start Transaction for Atomicity
    $conn->begin_transaction();
    $success = true;

    // Step 1: Log the Deletion Action
    $log_sql = "INSERT INTO ne_delete_record (op_code, attendance_date, user_id, remark) VALUES (?, ?, ?, ?)";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("ssis", $op_code, $attendance_date, $user_id, $remark);

    if (!$log_stmt->execute()) {
        $success = false;
        error_log("Error logging attendance deletion: " . $log_stmt->error);
    }
    $log_stmt->close();

    // Step 2: Delete the record from the main table (or soft-delete).
    // NOTE: This assumes 'night_emergency_attendance' table has the primary key (op_code, date)
    // If you add an 'is_deleted' column to the main table, change this to an UPDATE (soft-delete).
    // Assuming a hard delete for simplicity based on the prompt, but soft-delete is recommended.
    
    // *** HARD DELETE IMPLEMENTATION (Use with caution) ***
    $delete_sql = "DELETE FROM night_emergency_attendance WHERE op_code = ? AND date = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("ss", $op_code, $attendance_date);

    if (!$delete_stmt->execute()) {
        $success = false;
        error_log("Error deleting attendance record: " . $delete_stmt->error);
    }
    $delete_stmt->close();
    
    // Commit or Rollback the transaction
    if ($success) {
        $conn->commit();
        // Redirect back to the register page with a success message
        header("Location: night_emergency_attendance.php?success=deleted");
        exit();
    } else {
        $conn->rollback();
        // Redirect back with an error message
        header("Location: night_emergency_attendance_register.php?error=deletion_failed");
        exit();
    }

} else {
    // Not a POST request or missing parameters
    header("Location: night_emergency_attendance_register.php?error=invalid_request");
    exit();
}
?>