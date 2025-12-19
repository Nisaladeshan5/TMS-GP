<?php
// fetch_vehicles_for_op.php (Use this file to filter vehicles via AJAX)

// Set headers for JSON response
header('Content-Type: application/json');

include '../../includes/db.php'; 

// Check for the vehicle purpose parameter
if (!isset($_GET['purpose']) || empty($_GET['purpose'])) {
    echo json_encode([]);
    exit;
}

$purpose = $_GET['purpose'];

// --- Key Change: Use the passed purpose variable in the WHERE clause ---
// SQL Query to fetch vehicles based on their purpose (vehicle_purpose column)
$sql = "SELECT vehicle_no 
        FROM vehicle 
        WHERE purpose = ? AND is_active = 1
        ORDER BY vehicle_no";

$vehicles = [];

try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $purpose);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $vehicles[] = $row;
    }

    echo json_encode($vehicles);
    
} catch (Exception $e) {
    error_log("Vehicle fetch error: " . $e->getMessage());
    echo json_encode([]); 
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>