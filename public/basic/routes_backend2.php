<?php
// routes_backend2.php

include('../../includes/db.php');

// Handle the AJAX request for fuel rates and km per liter
if (isset($_GET['action']) && $_GET['action'] == 'get_fuel_rates') {
    header('Content-Type: application/json');
    
    if (!isset($_GET['vehicle_no'])) {
        echo json_encode(['success' => false, 'message' => 'Vehicle number not provided.']);
        exit;
    }

    $vehicle_no = $_GET['vehicle_no'];
    
    $sql = "SELECT 
                c.distance as km_per_liter, 
                fr.rate AS fuel_cost_per_liter
            FROM 
                vehicle v
            -- Join fuel_rate to get the cost per liter
            JOIN 
                fuel_rate fr ON v.rate_id = fr.rate_id
            -- Join consumption to get the distance and fuel usage (c.c_id = v.fuel_efficiency)
            JOIN 
                consumption c ON v.fuel_efficiency = c.c_id 
            WHERE 
                v.vehicle_no = ?";
            
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param("s", $vehicle_no);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'km_per_liter' => $row['km_per_liter'],
            'fuel_cost_per_liter' => $row['fuel_cost_per_liter']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Vehicle or fuel rate not found.']);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}

// Handle Add/Edit logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $route_code = $_POST['route_code'];
    $route = $_POST['route'];
    $fixed_amount = $_POST['fixed_amount']; 
    $fuel_amount = $_POST['fuel_amount'];  
    $purpose = $_POST['purpose'];
    $distance = $_POST['distance'];
    $supplier_code = $_POST['supplier_code'];
    $vehicle_no = $_POST['vehicle_no'];   
    $assigned_person = $_POST['assigned_person'];
    
    // Capture the value from the new hidden input
    $with_fuel_value = $_POST['fuel_option_value'];

    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        // Update existing route with the 'with_fuel' column
        $sql = "UPDATE route SET route=?, purpose=?, distance=?, supplier_code=?, vehicle_no=?, fixed_amount=?, fuel_amount=?, assigned_person=?, with_fuel=? WHERE route_code=?";
        $stmt = $conn->prepare($sql);
        
        $stmt->bind_param("ssdssddsis", $route, $purpose, $distance, $supplier_code, $vehicle_no, $fixed_amount, $fuel_amount, $assigned_person, $with_fuel_value, $route_code);
    } else {
        // Check for duplicates
        $check_sql = "SELECT route_code FROM route WHERE route_code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $route_code);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            echo "Error: A route with this code already exists.";
            $check_stmt->close();
            $conn->close();
            exit();
        }
        $check_stmt->close();

        // If no duplicate is found, proceed with the insertion
        // New columns added to the INSERT statement: with_fuel and is_active
        $sql = "INSERT INTO route (route_code, route, purpose, distance, supplier_code, vehicle_no, fixed_amount, fuel_amount, assigned_person, with_fuel, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        // is_active is 1 by default for new routes
        $is_active = 1; 
        $stmt->bind_param("sssdssddsii", $route_code, $route, $purpose, $distance, $supplier_code, $vehicle_no, $fixed_amount, $fuel_amount, $assigned_person, $with_fuel_value, $is_active);
    }

    if ($stmt->execute()) {
        echo "Success";
    } else {
        echo "Error: " . $conn->error;
    }
    $stmt->close();
    $conn->close();
    exit();
}

// Handle Disable/Enable logic
if (isset($_GET['toggle_status']) && isset($_GET['route_code']) && isset($_GET['new_status'])) {
    $route_code = $_GET['route_code'];
    $new_status = (int)$_GET['new_status']; // Cast to integer

    $sql = "UPDATE route SET is_active = ? WHERE route_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $new_status, $route_code);

    if ($stmt->execute()) {
        echo "Success";
    } else {
        echo "Error: " . $conn->error;
    }

    $stmt->close();
    $conn->close();
    exit();
}
?>