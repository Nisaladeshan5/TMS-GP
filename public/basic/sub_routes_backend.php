<?php
// sub_routes_backend.php
include('../../includes/db.php');

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Use null coalescing operator to safely access POST variables
    // This prevents "Undefined array key" warnings
    $sub_route_code = isset($_POST['sub_route_code']) ? trim($_POST['sub_route_code']) : null;
    $sub_route = isset($_POST['sub_route']) ? trim($_POST['sub_route']) : null;
    $route_code = isset($_POST['route_code']) ? trim($_POST['route_code']) : null;
    $distance = isset($_POST['distance']) ? floatval($_POST['distance']) : null;
    $per_day_rate = isset($_POST['per_day_rate']) ? floatval($_POST['per_day_rate']) : null;

    // Check for required fields before proceeding
    if (empty($sub_route) || empty($route_code) || !is_numeric($distance) || !is_numeric($per_day_rate)) {
        echo "Error: All required fields must be valid.";
        exit;
    }

    if ($action === 'add') {
        // Ensure sub_route_code is also present for the 'add' action
        if (empty($sub_route_code)) {
            echo "Error: Sub-route code is required for adding a new sub-route.";
            exit;
        }

        $sql = "INSERT INTO sub_route (sub_route_code, route_code, sub_route, distance, per_day_rate) VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        // The 'd' type for distance and per_day_rate is more accurate for floats
        $stmt->bind_param("sssdd", $sub_route_code, $route_code, $sub_route, $distance, $per_day_rate);

        if ($stmt->execute()) {
            echo "Success";
        } else {
            // Check for duplicate entry error specifically for the primary key
            if ($stmt->errno === 1062) {
                echo "Error: Duplicate sub-route code. Please choose a unique code.";
            } else {
                echo "Error: " . $stmt->error;
            }
        }
        $stmt->close();

    } elseif ($action === 'edit') {
        // sub_route_code is required for the 'edit' action
        if (empty($sub_route_code)) {
            echo "Error: Sub-route code is missing for editing.";
            exit;
        }

        $sql = "UPDATE sub_route SET sub_route = ?, route_code = ?, distance = ?, per_day_rate = ? WHERE sub_route_code = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssdds", $sub_route, $route_code, $distance, $per_day_rate, $sub_route_code);
        
        if ($stmt->execute()) {
            echo "Success";
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['toggle_status'])) {
    // Toggle sub-route status
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
    // Handle invalid requests
    echo "Error: Invalid request method or action.";
}

$conn->close();
?>