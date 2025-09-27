<?php
include('../../includes/db.php');

// Set timezone to Sri Lanka
date_default_timezone_set('Asia/Colombo');

if (isset($_POST['id'])) {
    $record_id = $_POST['id'];
    $in_time = date('H:i:s'); // Get the current time

    // Prepare and execute the update statement
    $sql = "UPDATE night_emergency_vehicle_register SET in_time = ?, status = 'available' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $in_time, $record_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => 'No record ID provided.']);
}

$conn->close();
?>