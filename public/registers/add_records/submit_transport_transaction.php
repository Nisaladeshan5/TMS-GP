<?php
include('../../../includes/db.php');

header('Content-Type: application/json');

$route_code = $_POST['route_code'] ?? '';
$entered_vehicle_no = $_POST['vehicle_no'] ?? '';
$entered_driver_calling_name = $_POST['calling_name'] ?? '';
$transaction_type = $_POST['transaction_type'] ?? 'in'; // 'in' or 'out'
$existing_record_id = $_POST['existing_record_id'] ?? null;

if (empty($route_code) || empty($entered_vehicle_no) || empty($entered_driver_calling_name)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit();
}

$current_datetime = date('Y-m-d H:i:s');
$current_date = date('Y-m-d');
$current_hour = date('H');
$shift = ($current_hour >= 0 && $current_hour < 12) ? 'morning' : 'evening';

// Get the actual route name from route_code (for consistency and safety)
$route_name = '';
$stmt_route = $conn->prepare("SELECT route FROM route WHERE route_code = ?");
$stmt_route->bind_param("s", $route_code);
$stmt_route->execute();
$result_route = $stmt_route->get_result();
if ($result_route->num_rows > 0) {
    $route_name = $result_route->fetch_assoc()['route'];
}
$stmt_route->close();

if (empty($route_name)) {
    echo json_encode(['success' => false, 'message' => 'Invalid route code provided.']);
    exit();
}

$success = false;
$message = '';

if ($transaction_type === 'in') {
    // Insert new 'in' record
    $stmt = $conn->prepare("INSERT INTO staff_transport_vehicle_register (vehicle_no, date, shift, route, driver, in_time) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $entered_vehicle_no, $current_date, $shift, $route_name, $entered_driver_calling_name, $current_datetime);

    if ($stmt->execute()) {
        $success = true;
        $message = 'In-time recorded successfully.';
    } else {
        // Check for duplicate entry error (e.g., if unique_in_scan constraint is violated)
        if ($conn->errno == 1062) { // MySQL error code for duplicate entry
            $message = 'An "in" record already exists for this vehicle, route, and shift on this date. Please record an "out" time or scan a different route.';
        } else {
            $message = 'Failed to record in-time: ' . $stmt->error;
        }
    }
    $stmt->close();

} elseif ($transaction_type === 'out' && !empty($existing_record_id)) {
    // Update existing record with 'out' time
    $stmt = $conn->prepare("UPDATE staff_transport_vehicle_register SET out_time = ?, vehicle_no = ?, driver = ? WHERE id = ? AND out_time IS NULL");
    $stmt->bind_param("sssi", $current_datetime, $entered_vehicle_no, $entered_driver_calling_name, $existing_record_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $success = true;
            $message = 'Out-time recorded successfully.';
        } else {
            $message = 'Failed to update out-time. Record not found or out-time already recorded.';
        }
    } else {
        $message = 'Failed to record out-time: ' . $stmt->error;
    }
    $stmt->close();
} else {
    $message = 'Invalid transaction type or missing record ID for out-time.';
}

$conn->close();
echo json_encode(['success' => $success, 'message' => $message]);
?>
