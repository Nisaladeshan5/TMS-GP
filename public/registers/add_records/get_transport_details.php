<?php
include('../../../includes/db.php');

header('Content-Type: application/json');

$route_code = $_GET['route_code'] ?? '';

if (empty($route_code)) {
    // Return a JSON error if route code is missing
    echo json_encode(['success' => false, 'message' => 'Route code is required.']);
    exit(); // Stop script execution
}

// Get current date and determine shift
$current_date = date('Y-m-d');
$current_hour = date('H');
$shift = ($current_hour >= 0 && $current_hour < 12) ? 'morning' : 'evening'; // Morning (00:00-11:59) or Evening (12:00-23:59)

// Initialize the response data array
$response_data = [
    'success' => false,
    'message' => 'Route not found.',
    'route_name' => '', // This will be populated from the database
    'default_vehicle_no' => '',
    'default_driver_calling_name' => '',
    'transaction_type' => 'in', // Default to 'in'
    'existing_record_id' => null // To store ID if an 'in' record exists
];

// 1. Get route details and default vehicle based on the scanned route_code
$stmt = $conn->prepare("SELECT route, vehicle_no FROM route WHERE route_code = ?");
$stmt->bind_param("s", $route_code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $route_data = $result->fetch_assoc();
    // Populate the response_data with fetched route details
    $response_data['route_name'] = $route_data['route'];
    $response_data['default_vehicle_no'] = $route_data['vehicle_no'];
    $response_data['success'] = true; // Set success to true if route is found
    $response_data['message'] = 'Route details fetched.';

    // 2. Get default driver calling name based on the default vehicle number
    if (!empty($route_data['vehicle_no'])) {
        $stmt_vehicle = $conn->prepare("SELECT d.calling_name, d.driver_NIC FROM vehicle v JOIN driver d ON v.driver_NIC = d.driver_NIC WHERE v.vehicle_no = ?");
        $stmt_vehicle->bind_param("s", $route_data['vehicle_no']);
        $stmt_vehicle->execute();
        $result_vehicle = $stmt_vehicle->get_result();
        if ($result_vehicle->num_rows > 0) {
            $driver_data = $result_vehicle->fetch_assoc();
            $response_data['default_driver_calling_name'] = $driver_data['calling_name'];
            
        }
        $stmt_vehicle->close();
    }

    // 3. Check for an existing 'in' record for this default vehicle, route, date, and shift
    $check_stmt = $conn->prepare("SELECT id, in_time, out_time FROM staff_transport_vehicle_register WHERE vehicle_no = ? AND date = ? AND shift = ? AND route = ?");
    $check_stmt->bind_param("ssss", $response_data['default_vehicle_no'], $current_date, $shift, $response_data['route_name']);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $existing_record = $check_result->fetch_assoc();
        if (empty($existing_record['out_time'])) {
            // An 'in' record exists, but no 'out' time yet. This is an 'out' scan.
            $response_data['transaction_type'] = 'out';
            $response_data['existing_record_id'] = $existing_record['id'];
            $response_data['message'] = 'Existing "in" record found. Ready for "out" scan.';
        } else {
            // Both 'in' and 'out' times are recorded. This implies a completed trip.
            // For now, we'll suggest a new 'in' scan, but the unique constraint will prevent duplicates.
            $response_data['transaction_type'] = 'in'; // Still an 'in' type if previous is completed
            $response_data['message'] = 'Previous trip completed. Ready for new "in" scan.';
        }
    } else {
        // No existing record found, so it's an 'in' scan.
        $response_data['transaction_type'] = 'in';
        $response_data['message'] = 'Ready for "in" scan.';
    }
    $check_stmt->close();

} else {
    // If route_code is not found in the 'route' table
    $response_data['success'] = false;
    $response_data['message'] = 'Route not found for this barcode.';
}

$stmt->close();
$conn->close();

// Encode the final response_data array as JSON and output it
echo json_encode($response_data);
?>
