<?php
include('../../../includes/db.php');

header('Content-Type: application/json');

date_default_timezone_set('Asia/Colombo');

$response = array();

if (isset($_GET['route_code']) && isset($_GET['shift'])) {
    $route_code = $_GET['route_code'];
    $shift = $_GET['shift'];
    $current_date = date('Y-m-d');

    // ==========================================================
    // 1. Check for an existing 'IN' record for the day and shift
    // ==========================================================
    $stmt_check_existing = $conn->prepare("
        SELECT id 
        FROM factory_transport_vehicle_register 
        WHERE route = ? AND shift = ? AND date = ? AND in_time IS NOT NULL
    ");
    $stmt_check_existing->bind_param("sss", $route_code, $shift, $current_date);
    $stmt_check_existing->execute();
    $result_check_existing = $stmt_check_existing->get_result();
    
    if ($result_check_existing->num_rows > 0) {
        // Record already exists for this route/shift/date
        $response = [
            'success' => false,
            'message' => "The Arrival (IN) transaction for route {$route_code} in the {$shift} shift has already been recorded today.",
        ];
        echo json_encode($response);
        $stmt_check_existing->close();
        $conn->close();
        exit;
    }
    $stmt_check_existing->close();

    // ==========================================================
    // 2. No existing record found. Proceed to fetch route defaults for a NEW 'IN' transaction.
    // ==========================================================
    $stmt_route = $conn->prepare("
        SELECT r.route, r.vehicle_no, v.driver_NIC 
        FROM route r 
        JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
        WHERE r.route_code = ? AND r.purpose='factory'
    ");
    $stmt_route->bind_param("s", $route_code);
    $stmt_route->execute();
    $route_result = $stmt_route->get_result();

    if ($route_result->num_rows > 0) {
        $route_data = $route_result->fetch_assoc();
        $default_vehicle_no = $route_data['vehicle_no'];
        $default_driver_nic = $route_data['driver_NIC'];

        $response = [
            'success' => true,
            'message' => 'Ready for Arrival (IN) transaction. Please confirm details.',
            'route_name' => $route_data['route'],
            'default_vehicle_no' => $default_vehicle_no,
            'default_driver_nic' => $default_driver_nic,
            'transaction_type' => 'in', // Fixed to 'in'
            'existing_record_id' => null // Always null for a new IN transaction
        ];
    } else {
         // Route code is invalid or missing linked vehicle/driver details
         $response = [
             'success' => false,
             'message' => 'Error: Route code not found.'
         ];
    }
    $stmt_route->close();
    
} else {
    // Missing required GET parameters
    $response = [
        'success' => false,
        'message' => 'Invalid request. Missing route code or shift parameter.'
    ];
}

$conn->close();
echo json_encode($response);
?>