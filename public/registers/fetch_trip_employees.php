<?php
include('../../includes/db.php'); // Assuming db connection is here

// Set header for JSON output
header('Content-Type: application/json');

// Check if trip_id is set
if (!isset($_GET['trip_id']) || empty($_GET['trip_id'])) {
    echo json_encode(['success' => false, 'error' => 'No Trip ID provided.']);
    exit;
}

$trip_id = $_GET['trip_id'];

// SQL Query for the employee reasons
// ev_trip_employee_reasons columns: id, trip_id, emp_id, reason
$sql = "SELECT emp_id, reason FROM ev_trip_employee_reasons WHERE trip_id = ?";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['success' => false, 'error' => 'Database prepare failed: ' . $conn->error]);
    exit;
}

// Bind the trip_id parameter (i = integer)
$stmt->bind_param("i", $trip_id);
$stmt->execute();
$result = $stmt->get_result();

$employees = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
}

$stmt->close();
$conn->close();

// Output the results as JSON
echo json_encode(['success' => true, 'employees' => $employees]);
?>