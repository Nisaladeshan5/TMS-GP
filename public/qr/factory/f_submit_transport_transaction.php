<?php
// Force clean output
ob_start();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Colombo');

// Database connection
include('../../../includes/db.php'); 

$response = [];

// $factory_transport_gl_code = '623401'; // REMOVED: No longer needed
$transaction_type = 'in'; // FIXED: Only 'in' transactions are allowed

// TRANSACTION START - Ensure atomicity for all database operations
$conn->autocommit(false); 

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method.");
    }

    // Collect POST data safely
    $route_code       = $_POST['route_code'] ?? null;
    $shift            = $_POST['shift'] ?? null;

    $entered_vehicle_no = $_POST['vehicle_no'] ?? null;
    $entered_driver_nic = $_POST['driver_nic'] ?? null;
    $vehicle_status   = $_POST['vehicle_status'] ?? null;
    $driver_status    = $_POST['driver_status'] ?? null;

    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    $current_date   = date('Y-m-d');
    $current_time   = date('H:i:s');
    // REMOVED: $current_month and $current_year

    // ==========================================================
    // **STEP 1: Check for existing IN record for this Shift/Route/Date**
    // ==========================================================
    $stmt_check = $conn->prepare("
        SELECT id FROM factory_transport_vehicle_register 
        WHERE route = ? AND shift = ? AND date = ?
    ");
    $stmt_check->bind_param("sss", $route_code, $shift, $current_date);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $stmt_check->close();
        throw new Exception("Arrival transaction for route {$route_code} in the {$shift} shift has already been recorded today.");
    }
    $stmt_check->close();


    // ========== 2. Fetch default route details and Supplier Code (Minimal Fetch) ==========
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
        throw new Exception("Route code not found.");
    }
    $stmt_default->close();

    $assigned_vehicle_no = $default_data['vehicle_no'];
    $assigned_driver_nic = $default_data['driver_NIC'];
    $route_supplier_code = $default_data['supplier_code']; 
    
    // REMOVED: $fixed_amount, $fuel_amount, $distance

    if (empty($route_supplier_code)) {
        throw new Exception("Supplier code is missing for route: " . $route_code);
    }

    // ========== 3. Determine actual vehicle/driver (Unchanged) ==========
    $actual_vehicle_no_to_store = ($vehicle_status == 0) ? $entered_vehicle_no : $assigned_vehicle_no;
    $driver_nic_to_store        = ($driver_status == 0) ? $entered_driver_nic : $assigned_driver_nic;

    // REMOVED: Step 4. Trip rate and distance per trip calculation.

    // ========== 5. Handle IN (Database Register Insert) ==========
    
    // --- Transaction A: Log the vehicle IN transaction ONLY ---
    $stmt = $conn->prepare("
        INSERT INTO factory_transport_vehicle_register
        (route, supplier_code, vehicle_no, actual_vehicle_no, vehicle_status, driver_NIC, driver_status, shift, date, in_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // Parameters: sssssissss (String * 5, Int, String * 4) - assuming vehicle_status and driver_status are integers 0/1.
    // NOTE: The original code had 10 's' types, but the statuses are typically integers (i). I'm keeping 's' to prevent a new failure if your DB stores them as strings '0'/'1', but 'i' is technically correct for boolean/int status. STICKING TO ORIGINAL BINDING TYPE (s) FOR SAFETY.
    $stmt->bind_param(
        "ssssssssss",
        $route_code,
        $route_supplier_code,
        $assigned_vehicle_no,
        $actual_vehicle_no_to_store,
        $vehicle_status,
        $driver_nic_to_store,
        $driver_status,
        $shift,
        $current_date,
        $current_time
    );

    if (!$stmt->execute()) {
        throw new Exception("Database error (IN transaction insertion): ".$stmt->error);
    }
    $stmt->close();
    
    // REMOVED: Step 6. Monthly payment, Distance, and COST ALLOCATION (ALL REMOVED)

    // COMMIT TRANSACTION since only the register insertion succeeded
    $conn->commit();
    $response['success'] = true;
    $response['message'] = "Arrival (IN) transaction recorded successfully in the Vehicle Register.";

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