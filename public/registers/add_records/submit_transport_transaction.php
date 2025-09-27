<?php
include('../../../includes/db.php');

header('Content-Type: application/json');

date_default_timezone_set('Asia/Colombo');

$response = array();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $route_code = $_POST['route_code'];
    $transaction_type = $_POST['transaction_type']; // 'in' or 'out'
    $shift = $_POST['shift'];
    $record_id = isset($_POST['existing_record_id']) ? $_POST['existing_record_id'] : null;

    $entered_vehicle_no = $_POST['vehicle_no'];
    $entered_driver_nic = $_POST['driver_nic'];
    $vehicle_status = $_POST['vehicle_status'];
    $driver_status = $_POST['driver_status'];

    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');
    
    // Fetch the assigned vehicle and driver from the route table
    $stmt_default = $conn->prepare("SELECT r.route, r.vehicle_no, v.driver_NIC FROM route r JOIN vehicle v ON r.vehicle_no = v.vehicle_no WHERE r.route_code = ?");
    $stmt_default->bind_param("s", $route_code);
    $stmt_default->execute();
    $result_default = $stmt_default->get_result();
    $default_data = $result_default->fetch_assoc();
    $assigned_vehicle_no = $default_data['vehicle_no'];
    $assigned_driver_nic = $default_data['driver_NIC'];
    $stmt_default->close();

    // Determine the final values to store in the database
    $actual_vehicle_no_to_store = ($vehicle_status == 0) ? $entered_vehicle_no : $assigned_vehicle_no;
    $driver_nic_to_store = ($driver_status == 0) ? $entered_driver_nic : $assigned_driver_nic;

    if ($transaction_type === 'in') {
        // Insert a new 'in' transaction with `time_in` only
        $stmt = $conn->prepare("INSERT INTO staff_transport_vehicle_register (route, vehicle_no, actual_vehicle_no, vehicle_status, driver_NIC, driver_status, shift, date, in_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssss", $route_code, $assigned_vehicle_no, $actual_vehicle_no_to_store, $vehicle_status, $driver_nic_to_store, $driver_status, $shift, $current_date, $current_time);

    } elseif ($transaction_type === 'out' && $record_id) {
        // Update the existing record with `out_time` only
        $stmt = $conn->prepare("UPDATE staff_transport_vehicle_register SET out_time = ? WHERE id = ?");
        $stmt->bind_param("si", $current_time, $record_id);

    } else {
        $response['success'] = false;
        $response['message'] = "Invalid transaction type or missing record ID for 'out' transaction.";
        echo json_encode($response);
        exit;
    }

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = ucfirst($transaction_type) . " transaction recorded successfully!";
    } else {
        $response['success'] = false;
        $response['message'] = "Database error: " . $stmt->error;
    }

    $stmt->close();
} else {
    $response['success'] = false;
    $response['message'] = "Invalid request method.";
}

$conn->close();
echo json_encode($response);
?>