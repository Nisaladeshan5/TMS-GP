<?php
// fetch_rate.php

// REVISED LOGIC:
// - Route Codes (Route): Always fetch base (Non A/C) rates: fixed_amount + fuel_amount.
// - Operation Codes (Operation): Check ac_status (1 or 0) to select between extra_rate_ac and extra_rate.

include('../../../../includes/db.php'); // Include your database connection

header('Content-Type: application/json');

$response = ['success' => false, 'rate' => 0, 'is_fixed' => false, 'message' => 'Invalid Request or missing A/C Status.'];

if (isset($_GET['code_type']) && isset($_GET['trip_code']) && isset($_GET['ac_status'])) {
    
    $code_type = trim($_GET['code_type']);
    $trip_code = trim($_GET['trip_code']);
    
    // *** NEW: Convert the A/C status parameter to an integer for correct comparison (it will be '1' or '0' as a string) ***
    $ac_status_int = (int)trim($_GET['ac_status']); 
    
    $rate_value = 0;
    
    if ($code_type === 'Route') {
        // --- ROUTE CODE LOOKUP ---
        // Requirement: Always use the base/Non A/C rate columns for Route codes
        $sql = "SELECT fixed_amount, fuel_amount FROM route WHERE route_code = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $trip_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            // The rate is the sum of fixed and fuel amount
            $rate_value = (float)($row['fixed_amount'] ?? 0) + (float)($row['fuel_amount'] ?? 0);
        }
        $stmt->close();
        
    } elseif ($code_type === 'Operation') {
        // --- OPERATION CODE LOOKUP ---
        // Requirement: Check A/C status (1 for A/C, 0 for Non A/C) to select the rate column
        
        // *** UPDATED LOGIC: Check for integer 1 or 0 ***
        // 1 means A/C (use extra_rate_ac)
        // 0 means Non A/C (use extra_rate)
        $rate_column = ($ac_status_int === 1) ? 'extra_rate_ac' : 'extra_rate'; 
        
        $sql_op = "SELECT {$rate_column} AS selected_rate FROM op_services WHERE op_code = ?";
        $stmt_op = $conn->prepare($sql_op);
        $stmt_op->bind_param("s", $trip_code);
        $stmt_op->execute();
        $result_op = $stmt_op->get_result();

        if ($result_op->num_rows > 0) {
            $row_op = $result_op->fetch_assoc();
            $rate_value = (float)($row_op['selected_rate'] ?? 0);
        }
        $stmt_op->close();
    }

    if ($rate_value > 0) { 
        $response = [
            'success' => true,
            'rate' => $rate_value,
            'is_fixed' => false // Always false for distance * rate calculation
        ];
    } else {
        $ac_label = ($ac_status_int === 1) ? "A/C" : "Non A/C";
        $message_detail = ($code_type === 'Operation') ? "{$ac_label} rate" : "base rate";
        $response['message'] = "Code found, but {$message_detail} is zero or missing for {$trip_code}.";
    }

}

echo json_encode($response);
$conn->close();
?>