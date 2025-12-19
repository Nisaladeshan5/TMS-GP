<?php
// Force clean output
ob_start();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Colombo');

// Database connection
include('../../../includes/db.php'); 

$response = [];

// Fixed GL Code for Staff Transport - NOT USED NOW, BUT KEPT FOR CONTEXT
// $staff_transport_gl_code = '623400';

// TRANSACTION START - Ensure atomicity for all database operations
$conn->autocommit(false); 

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method.");
    }

    // Collect POST data safely
    $route_code       = $_POST['route_code'] ?? null;
    $transaction_type = $_POST['transaction_type'] ?? null; // 'in' or 'out'
    $shift            = $_POST['shift'] ?? null;
    $record_id        = $_POST['existing_record_id'] ?? null;

    $entered_vehicle_no = $_POST['vehicle_no'] ?? null;
    $entered_driver_nic = $_POST['driver_nic'] ?? null;
    $vehicle_status   = $_POST['vehicle_status'] ?? null;
    $driver_status    = $_POST['driver_status'] ?? null;

    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    $current_date   = date('Y-m-d');
    $current_time   = date('H:i:s');
    // $current_month  = date('m'); // No longer needed
    // $current_year   = date('Y'); // No longer needed

    // ========== 1. Fetch default route details and Supplier Code (Only essential data) ==========
    $stmt_default = $conn->prepare("
        SELECT r.vehicle_no, v.driver_NIC, r.supplier_code
        FROM route r 
        JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
        WHERE r.route_code = ?
    ");
    $stmt_default->bind_param("s", $route_code);
    $stmt_default->execute();
    $result_default = $stmt_default->get_result();

    if (!$default_data = $result_default->fetch_assoc()) {
        throw new Exception("Route code not found or route/vehicle data incomplete.");
    }
    $stmt_default->close();

    $assigned_vehicle_no = $default_data['vehicle_no'];
    $assigned_driver_nic = $default_data['driver_NIC'];
    $route_supplier_code = $default_data['supplier_code']; // 🔑 FETCH SUPPLIER CODE

    if (empty($route_supplier_code)) {
          throw new Exception("Supplier code is missing for route: " . $route_code);
    }
    
    // Cost calculation variables are removed here (fixed_amount, fuel_amount, distance, trip_rate, distance_per_trip)

    // ========== 2. Determine actual vehicle/driver (Unchanged) ==========
    $actual_vehicle_no_to_store = ($vehicle_status == 0) ? $entered_vehicle_no : $assigned_vehicle_no;
    $driver_nic_to_store        = ($driver_status == 0) ? $entered_driver_nic : $assigned_driver_nic;

    $transaction_success = false;

    // ========== 3. Handle IN/OUT (Database Register Insert/Update) ==========
    if ($transaction_type === 'in') {
        // --- Transaction A: Log the vehicle IN transaction ---
        $stmt = $conn->prepare("
            INSERT INTO staff_transport_vehicle_register
            (route, supplier_code, vehicle_no, actual_vehicle_no, vehicle_status, driver_NIC, driver_status, shift, date, in_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        // Adjusted the bind_param types and variables: 10 parameters (10 * s/i)
        $stmt->bind_param(
            "ssssisssss",
            $route_code,
            $route_supplier_code,
            $assigned_vehicle_no,
            $actual_vehicle_no_to_store,
            $vehicle_status, // integer (i)
            $driver_nic_to_store,
            $driver_status,  // integer (i)
            $shift,
            $current_date,
            $current_time
        );

        if (!$stmt->execute()) {
            throw new Exception("Database error (IN transaction): ".$stmt->error);
        }
        $stmt->close();
        $transaction_success = true;

        // ========== 4. Monthly payment, Distance, and COST ALLOCATION REMOVED ==========
        // All logic related to monthly_payments_sf and monthly_cost_allocation has been removed.

    } elseif ($transaction_type === 'out' && $record_id) {
        // --- Handle OUT Transaction (Unchanged) ---
        $stmt = $conn->prepare("UPDATE staff_transport_vehicle_register SET out_time = ? WHERE id = ?");
        $stmt->bind_param("si", $current_time, $record_id);
        if (!$stmt->execute()) {
            throw new Exception("Database error (OUT transaction): ".$stmt->error);
        }
        $stmt->close();
        $transaction_success = true;

    } else {
        throw new Exception("Invalid transaction type or missing record ID for 'out'.");
    }

    // COMMIT TRANSACTION if all operations succeeded
    if ($transaction_success) {
        $conn->commit();
        $response['success'] = true;
        $response['message'] = ucfirst($transaction_type) . " recorded successfully in the Vehicle Register!";
    }

} catch (Exception $e) {
    // ROLLBACK on any failure
    $conn->rollback();
    $response['success'] = false;
    $response['message'] = "Transaction Failed: " . $e->getMessage();
}

// Restore default autocommit mode
$conn->autocommit(true); 

// Close DB connection
if (isset($conn)) $conn->close();

// Output clean JSON only
ob_end_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

?>