<?php
include('../../includes/db.php');

// Handle Add/Edit logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $route_code = $_POST['route_code'];
    $route = $_POST['route'];
    $purpose = $_POST['purpose'];
    $distance = $_POST['distance'];
    $workingDays = $_POST['workingDays'];
    $supplier_code = $_POST['supplier_code'];
    $vehicle_no = $_POST['vehicle_no'];
    $monthly_fixed_rental = $_POST['monthly_fixed_rental'];
    $payment_type = '1';                  
    $assigned_person = $_POST['assigned_person'];

    // Check if the action is 'edit'
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        // Update existing route
        $sql = "UPDATE route SET route=?, purpose=?, distance=?, working_days=?, supplier_code=?, vehicle_no=?, monthly_fixed_rental=?, payment_type=?, assigned_person=? WHERE route_code=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiissdsss", $route, $purpose, $distance, $workingDays, $supplier_code, $vehicle_no, $monthly_fixed_rental, $payment_type, $assigned_person, $route_code);
    } else {
        // This is for a new route, so we must check for duplicates
        // First, check if the route_code already exists
        $check_sql = "SELECT route_code FROM route WHERE route_code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $route_code);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            // A route with this code already exists, so don't add it
            echo "Error: A route with this code already exists.";
            $check_stmt->close();
            $conn->close();
            exit(); // Stop further execution
        }
        $check_stmt->close();

        // If no duplicate is found, proceed with the insertion
        $sql = "INSERT INTO route (route_code, route, purpose, distance, working_days, supplier_code, vehicle_no, monthly_fixed_rental, payment_type, assigned_person) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssdissdss", $route_code, $route, $purpose, $distance, $workingDays, $supplier_code, $vehicle_no, $monthly_fixed_rental, $payment_type, $assigned_person);
    }

    // Execute the final query (either INSERT or UPDATE)
    if ($stmt->execute()) {
        echo "Success"; // Send a success message back to the client
    } else {
        echo "Error: " . $conn->error;
    }
}

// Handle Delete logic
if (isset($_GET['delete_code'])) {
    $route_code = $_GET['delete_code'];
    $sql = "DELETE FROM route WHERE route_code=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $route_code);
    if ($stmt->execute()) {
        echo "Success";
    } else {
        echo "Error: " . $conn->error;
    }
}

$conn->close();
?>