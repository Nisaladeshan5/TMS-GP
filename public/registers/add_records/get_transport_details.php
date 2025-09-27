<?php
include('../../../includes/db.php');

header('Content-Type: application/json');

date_default_timezone_set('Asia/Colombo');

$response = array();

if (isset($_GET['route_code']) && isset($_GET['shift'])) {
    $route_code = $_GET['route_code'];
    $shift = $_GET['shift'];
    $current_date = date('Y-m-d');

    // 1. Check for a fully completed transaction for the day and shift
    $stmt_closed_check = $conn->prepare("SELECT id FROM staff_transport_vehicle_register WHERE route = ? AND shift = ? AND date = ? AND in_time IS NOT NULL AND out_time IS NOT NULL");
    $stmt_closed_check->bind_param("sss", $route_code, $shift, $current_date);
    $stmt_closed_check->execute();
    $result_closed_check = $stmt_closed_check->get_result();
    
    if ($result_closed_check->num_rows > 0) {
        $response = [
            'success' => false,
            'message' => 'This route has already completed both IN and OUT transactions for this shift.',
        ];
        echo json_encode($response);
        $stmt_closed_check->close();
        $conn->close();
        exit;
    }
    $stmt_closed_check->close();


    // 2. Check for an 'in' record that has not yet been "out" scanned
    $stmt_check = $conn->prepare("SELECT id, actual_vehicle_no, driver_NIC FROM staff_transport_vehicle_register WHERE route = ? AND shift = ? AND date = ? AND in_time IS NOT NULL AND out_time IS NULL ORDER BY id DESC LIMIT 1");
    $stmt_check->bind_param("sss", $route_code, $shift, $current_date);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check->num_rows > 0) {
        // A record exists with `in_time` but no `out_time`. The next transaction is 'out'.
        $existing_record = $result_check->fetch_assoc();
        $existing_record_id = $existing_record['id'];
        $vehicle_no = $existing_record['actual_vehicle_no'];
        $driver_nic = $existing_record['driver_NIC'];

        $stmt_route = $conn->prepare("SELECT route FROM route WHERE route_code = ?");
        $stmt_route->bind_param("s", $route_code);
        $stmt_route->execute();
        $route_result = $stmt_route->get_result();
        $route_data = $route_result->fetch_assoc();
        $stmt_route->close();

        $response = [
            'success' => true,
            'message' => 'Record found. Please confirm "Out" details.',
            'route_name' => $route_data['route'],
            'vehicle_no' => $vehicle_no, // Use the value from the 'in' record
            'driver_nic' => $driver_nic,  // Use the value from the 'in' record
            'transaction_type' => 'out',
            'existing_record_id' => $existing_record_id
        ];

    } else {
        // No unclosed record found. The next transaction is 'in'.
        $stmt_route = $conn->prepare("SELECT r.route, r.vehicle_no, v.driver_NIC FROM route r JOIN vehicle v ON r.vehicle_no = v.vehicle_no WHERE r.route_code = ?");
        $stmt_route->bind_param("s", $route_code);
        $stmt_route->execute();
        $route_result = $stmt_route->get_result();

        if ($route_result->num_rows > 0) {
            $route_data = $route_result->fetch_assoc();
            $default_vehicle_no = $route_data['vehicle_no'];
            $default_driver_nic = $route_data['driver_NIC'];

            $response = [
                'success' => true,
                'message' => 'New transaction. Please confirm "In" details.',
                'route_name' => $route_data['route'],
                'default_vehicle_no' => $default_vehicle_no,
                'default_driver_nic' => $default_driver_nic,
                'vehicle_no' => $default_vehicle_no,
                'driver_nic' => $default_driver_nic,
                'transaction_type' => 'in',
                'existing_record_id' => null
            ];
        } else {
             $response = [
                'success' => false,
                'message' => 'Error: Route barcode not found.'
            ];
        }
        $stmt_route->close();
    }
    $stmt_check->close();
} else {
    $response = [
        'success' => false,
        'message' => 'Invalid request.'
    ];
}

$conn->close();
echo json_encode($response);
?>