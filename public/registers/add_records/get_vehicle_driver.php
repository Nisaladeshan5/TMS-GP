<?php
include('../../../includes/db.php');

header('Content-Type: application/json');

$response = ['vehicle_no' => null, 'driver' => null];

if (isset($_GET['route_code'])) {
    $routeCode = $_GET['route_code'];

    // Join the route and vehicle tables to get the assigned vehicle and driver NIC
    $sql = "SELECT r.vehicle_no, v.driver_NIC FROM route r JOIN vehicle v ON r.vehicle_no = v.vehicle_no WHERE r.route_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $routeCode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $response['vehicle_no'] = $row['vehicle_no'];
        $response['driver'] = $row['driver_NIC']; // Use driver_NIC for the driver value
    }
    
    $stmt->close();
}

echo json_encode($response);
?>