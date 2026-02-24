<?php
include('../../../includes/db.php');

$trip_id = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : 0;
$participants = [];

if ($trip_id > 0) {
    $sql = "SELECT e.emp_id, e.calling_name, e.department 
            FROM nh_trip_departments ntd
            JOIN employee e ON ntd.emp_id = e.emp_id
            WHERE ntd.trip_id = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $trip_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $participants = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($participants);