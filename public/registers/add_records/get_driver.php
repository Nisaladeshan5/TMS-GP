<?php
include('../../../includes/db.php');

// Set content type to JSON
header('Content-Type: application/json');

// Check for the vehicle number parameter
if (!isset($_GET['vehicle_no']) || empty($_GET['vehicle_no'])) {
    echo json_encode(['status' => 'error', 'message' => 'Vehicle number not provided.']);
    exit();
}

$vehicle_no = trim($_GET['vehicle_no']);

try {
    // Step 1: Get driver NIC from the `vehicle` table
    $sql_vehicle = "SELECT driver_NIC FROM vehicle WHERE vehicle_no = ?";
    $stmt_vehicle = $conn->prepare($sql_vehicle);
    $stmt_vehicle->bind_param("s", $vehicle_no);
    $stmt_vehicle->execute();
    $result_vehicle = $stmt_vehicle->get_result();

    $driver_nic = null;
    if ($row_vehicle = $result_vehicle->fetch_assoc()) {
        $driver_nic = $row_vehicle['driver_NIC'];
    }
    $stmt_vehicle->close();

    // If no driver NIC is found, return an empty result
    if (!$driver_nic) {
        echo json_encode(['status' => 'success', 'data' => null]);
        $conn->close();
        exit();
    }

    // Step 2: Get the driver's name from the `driver` table using the NIC
    $sql_driver = "SELECT driver_NIC, calling_name FROM driver WHERE driver_NIC = ?";
    $stmt_driver = $conn->prepare($sql_driver);
    $stmt_driver->bind_param("s", $driver_nic);
    $stmt_driver->execute();
    $result_driver = $stmt_driver->get_result();

    if ($row_driver = $result_driver->fetch_assoc()) {
        echo json_encode(['status' => 'success', 'data' => $row_driver]);
    } else {
        // Driver NIC was found, but no matching record in the driver table
        echo json_encode(['status' => 'success', 'data' => null]);
    }
    $stmt_driver->close();

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database query failed: ' . $e->getMessage()]);
}

$conn->close();
?>