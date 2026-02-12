<?php
// night_emergency_barcode_handler.php
include('../../../includes/db.php');

date_default_timezone_set('Asia/Colombo');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['verify'])) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify the details before submitting.']);
        exit;
    }

    $operational_code = $_POST['operational_code'] ?? ''; 
    $vehicle_no = $_POST['vehicle_no'] ?? '';
    $driver_nic = $_POST['driver_nic'] ?? '';
    $vehicle_status = $_POST['vehicle_status'] ?? 0;
    $driver_status = $_POST['driver_status'] ?? 0;
    
    // Validate required fields
    if (empty($operational_code) || empty($vehicle_no) || empty($driver_nic)) {
        echo json_encode(['status' => 'error', 'message' => 'All required fields must be filled.']);
        exit;
    }
    
    $current_datetime = new DateTime('now', new DateTimeZone('Asia/Colombo'));
    $date = $current_datetime->format('Y-m-d');
    $report_time = $current_datetime->format('H:i:s');
    
    try {
        // ====================================================================
        // PART 1: CHECK ATTENDANCE (Prevent double-logging for the day)
        // ====================================================================
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM night_emergency_attendance WHERE op_code = ? AND date = ?");
        $check_stmt->bind_param("ss", $operational_code, $date); 
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $row = $check_result->fetch_row();
        $count = $row[0];
        $check_stmt->close();

        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Attendance for this operational code has already been recorded today.']);
            exit;
        }

        // ====================================================================
        // PART 2: INSERT ATTENDANCE ONLY
        // ====================================================================
        // Note: Payment calculations and Cost Allocation parts have been removed as requested.
        
        $insert_attendance_stmt = $conn->prepare("INSERT INTO night_emergency_attendance (op_code, vehicle_no, driver_NIC, date, report_time, vehicle_status, driver_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_attendance_stmt->bind_param("sssssii", $operational_code, $vehicle_no, $driver_nic, $date, $report_time, $vehicle_status, $driver_status);
        
        if ($insert_attendance_stmt->execute()) {
             // Success message
             echo json_encode(['status' => 'success', 'message' => 'Attendance recorded successfully. ✅']);
        } else {
             // Insert failed
             error_log("Attendance Insert Error: " . $conn->error);
             echo json_encode(['status' => 'error', 'message' => 'Failed to record attendance. Please try again. ❌']);
        }
        $insert_attendance_stmt->close();

    } catch (Exception $e) {
        error_log("System Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'An internal server error occurred.']);
    }

    if (isset($conn)) $conn->close();

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>