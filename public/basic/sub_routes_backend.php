<?php
// sub_routes_backend.php
include('../../includes/db.php');

// --- 1. GET Action: Fuel rates calculate kirima ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_fuel_rates') {
    $v_no = isset($_GET['vehicle_no']) ? $conn->real_escape_string($_GET['vehicle_no']) : '';
    
    if (empty($v_no)) {
        echo json_encode(['success' => false, 'message' => 'Vehicle number missing']);
        exit;
    }

    // Vehicle eke fuel efficiency (consumption) saha rate_id gannawa
    $sql = "SELECT v.fuel_efficiency, v.rate_id, c.distance as km_per_liter 
            FROM vehicle v 
            LEFT JOIN consumption c ON v.fuel_efficiency = c.c_id 
            WHERE v.vehicle_no = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $v_no);
    $stmt->execute();
    $v_res = $stmt->get_result()->fetch_assoc();

    if ($v_res) {
        // Latest fuel price eka gannawa
        $price_sql = "SELECT rate FROM fuel_rate WHERE rate_id = ? ORDER BY date DESC, id DESC LIMIT 1";
        $p_stmt = $conn->prepare($price_sql);
        $p_stmt->bind_param("i", $v_res['rate_id']);
        $p_stmt->execute();
        $p_res = $p_stmt->get_result()->fetch_assoc();

        echo json_encode([
            'success' => true,
            'fuel_cost_per_liter' => (float)($p_res['rate'] ?? 0),
            'km_per_liter' => (float)($v_res['km_per_liter'] ?? 1)
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Vehicle details not found']);
    }
    $stmt->close();
    $conn->close();
    exit;
}

// --- 2. POST Action: Add ho Edit Sub-Route ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    $sub_route_code = isset($_POST['sub_route_code']) ? trim($_POST['sub_route_code']) : null;
    $sub_route = isset($_POST['sub_route']) ? trim($_POST['sub_route']) : null;
    $route_code = isset($_POST['route_code']) ? trim($_POST['route_code']) : null;
    $vehicle_no = isset($_POST['vehicle_no']) ? trim($_POST['vehicle_no']) : null;
    $supplier_code = isset($_POST['supplier_code']) ? trim($_POST['supplier_code']) : null;
    $distance = isset($_POST['distance']) ? floatval($_POST['distance']) : 0;
    $fixed_rate = isset($_POST['fixed_rate']) ? floatval($_POST['fixed_rate']) : 0;
    $with_fuel = isset($_POST['with_fuel']) ? intval($_POST['with_fuel']) : 0;

    if (empty($sub_route) || empty($route_code) || empty($vehicle_no) || empty($supplier_code)) {
        echo "Error: Required fields are missing.";
        exit;
    }

    if ($action === 'add') {
        $sql = "INSERT INTO sub_route (sub_route_code, route_code, sub_route, distance, fixed_rate, with_fuel, vehicle_no, supplier_code, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssddiss", $sub_route_code, $route_code, $sub_route, $distance, $fixed_rate, $with_fuel, $vehicle_no, $supplier_code);
        
        if ($stmt->execute()) {
            echo "Success";
        } else {
            echo "Error: " . ($stmt->errno === 1062 ? "Duplicate code" : $stmt->error);
        }
    } elseif ($action === 'edit') {
        $sql = "UPDATE sub_route SET sub_route = ?, route_code = ?, distance = ?, fixed_rate = ?, with_fuel = ?, vehicle_no = ?, supplier_code = ? 
                WHERE sub_route_code = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssddisss", $sub_route, $route_code, $distance, $fixed_rate, $with_fuel, $vehicle_no, $supplier_code, $sub_route_code);
        echo $stmt->execute() ? "Success" : "Error: " . $stmt->error;
    }
    $stmt->close();
    $conn->close();
    exit;
}

// --- 3. Toggle Status Action ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['toggle_status'])) {
    $code = $conn->real_escape_string($_GET['sub_route_code']);
    $status = intval($_GET['new_status']);
    $sql = "UPDATE sub_route SET is_active = ? WHERE sub_route_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $status, $code);
    echo $stmt->execute() ? "Success" : "Error";
    $stmt->close();
    $conn->close();
    exit;
}
?>