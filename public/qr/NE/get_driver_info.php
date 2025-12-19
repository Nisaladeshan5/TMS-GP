<?php
// get_driver_info.php
// Assumes $conn is a valid mysqli connection object provided by db.php

include('../../../includes/db.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $vehicle_no = $data['vehicle_no'] ?? '';

    // Check for vehicle number
    if (empty($vehicle_no)) {
        echo json_encode(['status' => 'error', 'message' => 'Vehicle number not provided.']);
        // The connection should not be closed yet if we are using procedural style and $conn is global
        exit;
    }

    // Use prepared statements for security
    // The query joins driver and vehicle tables based on the common driver_NIC.
    $stmt = mysqli_prepare($conn, "SELECT d.driver_NIC, d.calling_name 
                                    FROM driver AS d 
                                    JOIN vehicle AS v ON d.driver_NIC = v.driver_NIC 
                                    WHERE v.vehicle_no = ?");
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $vehicle_no);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && $row = mysqli_fetch_assoc($result)) {
            // Success: Driver found
            echo json_encode([
                'status' => 'success', 
                'driver_nic' => $row['driver_NIC'], 
                'calling_name' => $row['calling_name']
            ]);
        } else {
            // Error: No driver assigned to vehicle
            echo json_encode(['status' => 'error', 'message' => 'Driver not found for this vehicle.']);
        }

        mysqli_stmt_close($stmt);

    } else {
        // Error: Statement preparation failed
        error_log("Database Error (get_driver_info): " . mysqli_error($conn));
        echo json_encode(['status' => 'error', 'message' => 'An internal server error occurred during query preparation.']);
    }

    // No need to close the connection here if it's managed globally by db.php/includes.
    // If db.php includes mysqli_close(), remove it from there or ensure it doesn't break other includes.
    // Assuming $conn is used by other includes, we won't close it here.
    // If you explicitly manage connection closing: mysqli_close($conn); 

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>