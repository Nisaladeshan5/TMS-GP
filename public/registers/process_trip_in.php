<?php
// --- 1. Configuration & Includes ---
include('../../includes/db.php'); // Database connection
date_default_timezone_set('Asia/Colombo');

header('Content-Type: application/json');

// Check for POST data
if (!isset($_POST['trip_id']) || !isset($_POST['distance']) || !isset($_POST['ac_status']) || !isset($_POST['route_code'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required data.']);
    exit;
}

$trip_id = (int)$_POST['trip_id'];
$distance = (float)$_POST['distance'];
$ac_status = (int)$_POST['ac_status']; // 1 for AC, 0 for Non-AC
$route_code = trim($_POST['route_code']);
$in_time = date('Y-m-d H:i:s');
$amount = 0.00;

// --- 2. Amount Calculation Logic ---

// Get the third and fourth characters of the route code
$third_char = substr($route_code, 2, 1);
$fourth_char = substr($route_code, 3, 1);

try {
    // Determine the calculation method based on the route code
    if ($third_char === '-') {
        // --- TYPE 1: Route with '-' at the 3rd position (e.g., AB-C) ---
        // Fetch op_code and rates from op_services table

        $sql_rate = "SELECT op_code FROM op_services WHERE op_code = ?";
        $stmt_rate = $conn->prepare($sql_rate);
        $stmt_rate->bind_param("s", $route_code);
        $stmt_rate->execute();
        $rate_result = $stmt_rate->get_result();

        if ($rate_result->num_rows > 0) {
            $op_code = $rate_result->fetch_assoc()['op_code'];
            $stmt_rate->close();

            // Fetch the appropriate rate from the extra_rates table
            // Assuming the rates table is named 'extra_rates' and contains 'op_code', 'extra_rate', 'extra_rate_ac'
            $sql_extra = "SELECT extra_rate, extra_rate_ac FROM op_services WHERE op_code = ?";
            $stmt_extra = $conn->prepare($sql_extra);
            $stmt_extra->bind_param("s", $op_code);
            $stmt_extra->execute();
            $extra_result = $stmt_extra->get_result();

            if ($extra_result->num_rows > 0) {
                $rates = $extra_result->fetch_assoc();
                $rate = ($ac_status == 1) ? $rates['extra_rate_ac'] : $rates['extra_rate'];
                
                // Calculation: rate * distance
                $amount = $rate * $distance;

            } else {
                throw new Exception("No extra rates found for op_code: " . htmlspecialchars($op_code));
            }
            $stmt_extra->close();
        } else {
            throw new Exception("No op_code found for route: " . htmlspecialchars($route_code));
        }

    } elseif ($third_char !== '-' && $fourth_char === '-') {
        // --- TYPE 2: Route with '-' at the 4th position (e.g., ABC-D) ---
        // Fetch fixed_amount and fuel_amount from the route table
        
        $sql_route = "SELECT fixed_amount, fuel_amount FROM route WHERE route_code = ?";
        $stmt_route = $conn->prepare($sql_route);
        $stmt_route->bind_param("s", $route_code);
        $stmt_route->execute();
        $route_result = $stmt_route->get_result();

        if ($route_result->num_rows > 0) {
            $route_data = $route_result->fetch_assoc();
            $fixed_amount = $route_data['fixed_amount'];
            $fuel_amount = $route_data['fuel_amount'];

            // Calculation: fixed_amount + (fuel_amount * distance)
            $amount = $fixed_amount + ($fuel_amount * $distance);

        } else {
            throw new Exception("No route data found in 'route' table for code: " . htmlspecialchars($route_code));
        }
        $stmt_route->close();

    } else {
        throw new Exception("Unknown route code format for calculation: " . htmlspecialchars($route_code));
    }

    // Round the amount to 2 decimal places
    $amount = round($amount, 2);


    // --- 3. Update extra_vehicle_register ---

    $update_sql = "UPDATE extra_vehicle_register 
                   SET distance = ?, ac_status = ?, amount = ?, in_time = ?, done = 1 
                   WHERE id = ? AND done = 0";
    
    $stmt_update = $conn->prepare($update_sql);
    $stmt_update->bind_param("dsssi", $distance, $ac_status, $amount, $in_time, $trip_id);

    if ($stmt_update->execute()) {
        if ($stmt_update->affected_rows > 0) {
            echo json_encode([
                'success' => true, 
                'message' => 'Trip finalized.', 
                'amount' => number_format($amount, 2)
            ]);
        } else {
            throw new Exception("Trip not found or already finalized (ID: " . $trip_id . ").");
        }
    } else {
        throw new Exception("Database update failed: " . $stmt_update->error);
    }

    $stmt_update->close();

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>