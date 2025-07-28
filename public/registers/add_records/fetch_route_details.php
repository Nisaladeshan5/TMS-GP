<?php
include('../../../includes/db.php');

// Initialize the response array with default empty values
$response = array(
    'route' => '',
    'vehicle_no' => '',
    'driver_name' => ''
);

// Get the route code from the GET request
$routeCode = isset($_GET['route_code']) ? $_GET['route_code'] : '';

// If route code is provided, fetch the relevant details from the database
if ($routeCode) {
    $sql = "SELECT r.route, v.vehicle_no, d.calling_name AS driver_name
            FROM route r
            JOIN vehicle v ON r.vehicle_no = v.vehicle_no
            JOIN driver d ON v.driver_NIC = d.driver_NIC
            WHERE r.route_code = ?";
    
    // Prepare the statement
    $stmt = $conn->prepare($sql);
    
    // Bind the route code to the query
    $stmt->bind_param('s', $routeCode);
    
    // Execute the query
    $stmt->execute();
    $result = $stmt->get_result();

    // Check if data is found for the given route code
    if ($row = $result->fetch_assoc()) {
        // Populate the response array with data from the database
        $response['route'] = $row['route'];
        $response['vehicle_no'] = $row['vehicle_no'];
        $response['driver_name'] = $row['driver_name'];
    }
}

// Return the response as JSON
header('Content-Type: application/json');
echo json_encode($response);
?>
