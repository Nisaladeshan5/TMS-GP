<?php
// night_emergency_barcode_handler.php
include('../../../includes/db.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['verify'])) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify the details before submitting.']);
        exit;
    }

    $supplier_code = $_POST['supplier_code'] ?? '';
    $vehicle_no = $_POST['vehicle_no'] ?? '';
    $driver_nic = $_POST['driver_nic'] ?? '';
    $vehicle_status = $_POST['vehicle_status'] ?? 0;
    $driver_status = $_POST['driver_status'] ?? 0;
    
    // Validate required fields
    if (empty($supplier_code) || empty($vehicle_no) || empty($driver_nic)) {
        echo json_encode(['status' => 'error', 'message' => 'All required fields must be filled.']);
        exit;
    }
    
    // Get current date and time
    $current_datetime = new DateTime('now', new DateTimeZone('Asia/Colombo'));
    $date = $current_datetime->format('Y-m-d');
    $report_time = $current_datetime->format('H:i:s');
    
    try {
        // Step 1: Check if an attendance record with this supplier code already exists for today.
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM night_emergency_attendance WHERE supplier_code = ? AND date = ?");
        $check_stmt->bind_param("ss", $supplier_code, $date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $row = $check_result->fetch_row();
        $count = $row[0];
        $check_stmt->close();

        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Attendance for this supplier code has already been recorded today.']);
            exit;
        }

        // Step 2: If no record exists, proceed with the INSERT query.
        $stmt = $conn->prepare("INSERT INTO night_emergency_attendance (supplier_code, vehicle_no, driver_NIC, date, report_time, vehicle_status, driver_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->bind_param("sssssii", $supplier_code, $vehicle_no, $driver_nic, $date, $report_time, $vehicle_status, $driver_status);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Attendance recorded successfully. ✅']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to record attendance. Please try again. ❌']);
        }

        $stmt->close();
    } catch (Exception $e) {
        error_log("Database Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'An internal server error occurred.']);
    }

    $conn->close();

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>