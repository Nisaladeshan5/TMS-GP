<?php
// sub_routes_backend.php
include('../../includes/db.php');

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Use null coalescing operator to safely access POST variables
    $sub_route_code = isset($_POST['sub_route_code']) ? trim($_POST['sub_route_code']) : null;
    $sub_route = isset($_POST['sub_route']) ? trim($_POST['sub_route']) : null;
    $route_code = isset($_POST['route_code']) ? trim($_POST['route_code']) : null;
    // New fields
    $vehicle_no = isset($_POST['vehicle_no']) ? trim($_POST['vehicle_no']) : null;
    $supplier_code = isset($_POST['supplier_code']) ? trim($_POST['supplier_code']) : null;
    
    $distance = isset($_POST['distance']) ? floatval($_POST['distance']) : null;
    $per_day_rate = isset($_POST['per_day_rate']) ? floatval($_POST['per_day_rate']) : null;

    // Check for required fields before proceeding
    if (empty($sub_route) || empty($route_code) || empty($vehicle_no) || empty($supplier_code) || !is_numeric($distance) || !is_numeric($per_day_rate)) {
        echo "Error: All required fields (Name, Route, Vehicle, Supplier, Distance, Rate) must be valid.";
        exit;
    }

    if ($action === 'add') {
        // Ensure sub_route_code is also present for the 'add' action
        if (empty($sub_route_code)) {
            echo "Error: Sub-route code is required.";
            exit;
        }

        // Updated SQL to include vehicle_no and supplier_code
        $sql = "INSERT INTO sub_route (sub_route_code, route_code, sub_route, distance, per_day_rate, vehicle_no, supplier_code) VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        // Types: s=string, d=double. Order: sub_route_code, route_code, sub_route, distance, rate, vehicle, supplier
        $stmt->bind_param("sssddss", $sub_route_code, $route_code, $sub_route, $distance, $per_day_rate, $vehicle_no, $supplier_code);

        if ($stmt->execute()) {
            echo "Success";
        } else {
            if ($stmt->errno === 1062) {
                echo "Error: Duplicate sub-route code.";
            } else {
                echo "Error: " . $stmt->error;
            }
        }
        $stmt->close();

    } elseif ($action === 'edit') {
        if (empty($sub_route_code)) {
            echo "Error: Sub-route code is missing for editing.";
            exit;
        }

        // Updated SQL to update vehicle_no and supplier_code
        $sql = "UPDATE sub_route SET sub_route = ?, route_code = ?, distance = ?, per_day_rate = ?, vehicle_no = ?, supplier_code = ? WHERE sub_route_code = ?";
        
        $stmt = $conn->prepare($sql);
        // Types: s=string, d=double. Order: sub_route, route_code, distance, rate, vehicle, supplier, sub_route_code (WHERE)
        $stmt->bind_param("ssddsss", $sub_route, $route_code, $distance, $per_day_rate, $vehicle_no, $supplier_code, $sub_route_code);
        
        if ($stmt->execute()) {
            echo "Success";
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['toggle_status'])) {
    // Toggle sub-route status logic remains the same
    $sub_route_code = isset($_GET['sub_route_code']) ? $_GET['sub_route_code'] : null;
    $new_status = isset($_GET['new_status']) ? intval($_GET['new_status']) : null;

    if (empty($sub_route_code) || !is_int($new_status)) {
        echo "Error: Invalid request.";
        exit;
    }

    $sql = "UPDATE sub_route SET is_active = ? WHERE sub_route_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $new_status, $sub_route_code);
    
    if ($stmt->execute()) {
        echo "Success";
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
} else {
    echo "Error: Invalid request method or action.";
}

$conn->close();
?>