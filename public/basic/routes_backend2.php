<?php
// routes_backend2.php - Handles AJAX requests and POST submissions for Routes

// Includes
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // Note: In a real backend, we should return a JSON error for AJAX requests
    if (isset($_GET['action']) && $_GET['action'] == 'get_fuel_rates') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'User not authenticated.']);
        exit();
    }
    header("Location: ../../includes/login.php");
    exit();
}

// 🔑 User ID එක ලබා ගැනීම (Audit Log සඳහා)
$user_id = $_SESSION['user_id'] ?? 0; 

include('../../includes/db.php');


// -------------------------------------------------------------------------
// --- 1. Handle GET request for Fuel Rates (AJAX) ---
// -------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'get_fuel_rates') {
    header('Content-Type: application/json');
    
    if (!isset($_GET['vehicle_no'])) {
        echo json_encode(['success' => false, 'message' => 'Vehicle number not provided.']);
        exit;
    }

    $vehicle_no = $_GET['vehicle_no'];
    
    // SQL to fetch km_per_liter and fuel_cost_per_liter
    $sql = "SELECT 
                c.distance AS km_per_liter, 
                fr.rate AS fuel_cost_per_liter
            FROM 
                vehicle v
            JOIN 
                fuel_rate fr ON v.rate_id = fr.rate_id
            JOIN 
                consumption c ON v.fuel_efficiency = c.c_id 
            WHERE 
                v.vehicle_no = ?";
            
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        $conn->close();
        exit;
    }

    $stmt->bind_param("s", $vehicle_no);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        $km_per_liter = (float)$row['km_per_liter'];
        $fuel_cost_per_liter = (float)$row['fuel_cost_per_liter'];

        if ($km_per_liter > 0 && $fuel_cost_per_liter >= 0) {
            echo json_encode([
                'success' => true,
                'km_per_liter' => $km_per_liter,
                'fuel_cost_per_liter' => $fuel_cost_per_liter
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Fuel efficiency or cost data is invalid (cannot be zero or negative).']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Vehicle or fuel rate data not found.']);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}


// -------------------------------------------------------------------------
// --- 2. Handle POST request for Add/Edit Route ---
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $route_code = $_POST['route_code'] ?? '';
    $route = $_POST['route'] ?? '';
    $fixed_amount = filter_var($_POST['fixed_amount'] ?? 0, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION); 
    $fuel_amount = filter_var($_POST['fuel_amount'] ?? 0, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);  
    $purpose = $_POST['purpose'] ?? '';
    $distance = filter_var($_POST['distance'] ?? 0, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $supplier_code = $_POST['supplier_code'] ?? '';
    $vehicle_no = $_POST['vehicle_no'] ?? '';   
    $assigned_person = $_POST['assigned_person'] ?? '';
    $with_fuel_value = (int)($_POST['fuel_option_value'] ?? 0);
    $action = $_POST['action'] ?? 'add';

    if (empty($route_code) || empty($route) || empty($supplier_code) || empty($vehicle_no)) {
        echo "Error: Missing required fields.";
        $conn->close();
        exit();
    }
    
    $stmt = null;
    
    if ($action === 'edit') {
        // --- EDIT Route Logic: Get Old Data and Compare ---
        
        // 1. වත්මන් (පැරණි) දත්ත ලබා ගැනීම
        $old_data_sql = "SELECT * FROM route WHERE route_code = ?";
        $old_stmt = $conn->prepare($old_data_sql);
        $old_stmt->bind_param("s", $route_code);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result();
        $old_data = $old_result->fetch_assoc();
        $old_stmt->close();

        if (!$old_data) {
            echo "Error: Route code not found.";
            $conn->close();
            exit();
        }

        // 2. නව දත්ත Array එකක් ලෙස සකස් කිරීම
        $new_data = [
            'route' => $route,
            'purpose' => $purpose,
            'distance' => (string)$distance, 
            'supplier_code' => $supplier_code,
            'vehicle_no' => $vehicle_no,
            'fixed_amount' => (string)$fixed_amount,
            'fuel_amount' => (string)$fuel_amount,
            'assigned_person' => $assigned_person,
            'with_fuel' => (string)$with_fuel_value,
        ];
        
        // 3. වෙනස් වූ Fields Array එකක් සාදා ගැනීම
        $changes = [];
        $is_updated = false;

        foreach ($new_data as $field => $new_value) {
            $old_value = (string)$old_data[$field];
            if (trim($old_value) !== trim($new_value)) {
                $changes[] = [
                    'field' => $field,
                    'old' => $old_value,
                    'new' => $new_value,
                ];
                $is_updated = true;
            }
        }

        // 4. වෙනස්කම් ඇත්නම් පමණක් DB එක UPDATE කර Log කිරීම
        if ($is_updated) {
            $sql = "UPDATE route SET 
                        route=?, 
                        purpose=?, 
                        distance=?, 
                        supplier_code=?, 
                        vehicle_no=?, 
                        fixed_amount=?, 
                        fuel_amount=?, 
                        assigned_person=?, 
                        with_fuel=? 
                    WHERE route_code=?";
            $stmt = $conn->prepare($sql);
            
            if ($stmt) {
                $stmt->bind_param("ssdssddsis", $route, $purpose, $distance, $supplier_code, $vehicle_no, $fixed_amount, $fuel_amount, $assigned_person, $with_fuel_value, $route_code);
                
                if ($stmt->execute()) {
                    $log_sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id, field_name, old_value, new_value) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt_log = $conn->prepare($log_sql);
                    
                    if ($stmt_log) {
                        $action_type = "UPDATE";
                        $table_name = "route";
                        
                        foreach ($changes as $change) {
                            $stmt_log->bind_param("sssisss", 
                                $table_name, 
                                $route_code, 
                                $action_type, 
                                $user_id, 
                                $change['field'], 
                                $change['old'], 
                                $change['new']
                            );
                            $stmt_log->execute();
                        }
                        $stmt_log->close();
                    }
                    echo "Success";
                } else {
                    echo "Error: " . $stmt->error;
                }
                $stmt->close();
            } else {
                echo "Error: Failed to prepare database statement for UPDATE.";
            }
        } else {
            echo "Success"; 
        }
    
    } else {
        // --- ADD Route Logic (INSERT) ---
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

        $sql = "INSERT INTO route (route_code, route, purpose, distance, supplier_code, vehicle_no, fixed_amount, fuel_amount, assigned_person, with_fuel, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $is_active = 1; 
            $stmt->bind_param("sssdssddsii", $route_code, $route, $purpose, $distance, $supplier_code, $vehicle_no, $fixed_amount, $fuel_amount, $assigned_person, $with_fuel_value, $is_active);
        }
        
        if ($stmt && $stmt->execute()) {
            $log_sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id) 
                        VALUES (?, ?, ?, ?)";
            $stmt_log = $conn->prepare($log_sql);
            
            if ($stmt_log) {
                $action_type = "INSERT";
                $table_name = "route";
                
                $stmt_log->bind_param("sssi", $table_name, $route_code, $action_type, $user_id);
                $stmt_log->execute();
                $stmt_log->close();
            }
            echo "Success";
        } elseif ($stmt) {
            echo "Error: " . $stmt->error;
        } else {
            echo "Error: Failed to prepare database statement for INSERT.";
        }
        
    }
    
    $conn->close();
    exit();
}


// -------------------------------------------------------------------------
// --- 3. Handle GET request for Disable/Enable logic ---
// -------------------------------------------------------------------------
if (isset($_GET['toggle_status']) && isset($_GET['route_code']) && isset($_GET['new_status'])) {
    $route_code = $_GET['route_code'];
    $new_status = (int)$_GET['new_status'];
    
    // ✅ FIX: Create a variable to avoid "Argument could not be passed by reference" error
    $new_status_str = (string)$new_status;

    $sql = "UPDATE route SET is_active = ? WHERE route_code = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        echo "Error: Prepare failed: " . $conn->error;
        $conn->close();
        exit();
    }
    
    $stmt->bind_param("is", $new_status, $route_code);

    if ($stmt->execute()) {
        
        if ($stmt->affected_rows > 0) {
            $status_text = ($new_status == 1) ? 'Activated' : 'Deactivated';
            $old_status = ($new_status == 1) ? '0' : '1';
            
            $log_sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id, field_name, old_value, new_value) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt_log = $conn->prepare($log_sql);
            
            if ($stmt_log) {
                $action_type = "UPDATE";
                $table_name = "route";
                $field_name = "is_active";
                
                // ✅ FIXED: Using $new_status_str
                $stmt_log->bind_param("sssisss", 
                    $table_name, $route_code, $action_type, $user_id, 
                    $field_name, $old_status, $new_status_str
                );
                $stmt_log->execute();
                $stmt_log->close();
            }
        }
        echo "Success";
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
    exit();
}
?>
