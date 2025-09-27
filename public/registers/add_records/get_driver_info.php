<?php
// get_driver_info.php
include('../../../includes/db.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $vehicle_no = $data['vehicle_no'] ?? '';

    if (empty($vehicle_no)) {
        echo json_encode(['status' => 'error', 'message' => 'Vehicle number not provided.']);
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT d.driver_NIC, d.calling_name FROM driver AS d JOIN vehicle AS v ON d.driver_NIC = v.driver_NIC WHERE v.vehicle_no = ?");
        $stmt->bind_param("s", $vehicle_no);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            echo json_encode(['status' => 'success', 'driver_nic' => $row['driver_NIC'], 'calling_name' => $row['calling_name']]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Driver not found for this vehicle.']);
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