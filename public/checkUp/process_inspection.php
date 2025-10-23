<?php
// Note: This file is the processing script and handles insertion/update into the database.

include('../../includes/db.php'); // Your database connection file

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- 1. Collect and Sanitize General Form Data ---
    $supplier_code = $conn->real_escape_string($_POST['supplier_code']);
    $vehicle_no = $conn->real_escape_string($_POST['vehicle_no']);
    $route = $conn->real_escape_string($_POST['route_name']);
    $transport_type = $conn->real_escape_string($_POST['transport_type']);
    $inspector = $conn->real_escape_string($_POST['inspector_name']);
    $date = $conn->real_escape_string($_POST['inspection_date']);
    $other_observations = $conn->real_escape_string($_POST['other_observations']);
    
    // Check for and sanitize the optional fitness certificate fields (only present for 'Bus' vehicles)
    $fitness_status = isset($_POST['vehicle_fitness_certificate_status']) ? 1 : 0;
    $fitness_remark = isset($_POST['vehicle_fitness_certificate_remark']) ? $conn->real_escape_string($_POST['vehicle_fitness_certificate_remark']) : '';

    // --- 2. Define the mapping from display text to database column prefix ---
    $criteria_mapping = [
        'Revenue License' => 'revenue_license',
        'Driver License' => 'driver_license',
        'Insurance' => 'insurance',
        'Driver Data sheet' => 'driver_data_sheet',
        'Driver NIC' => 'driver_nic',
        'Break' => 'break',
        'Tires' => 'tires',
        'Spare Wheel' => 'spare_wheel',
        'Lights (Head Lights/Signal Lights, Break Lights)' => 'lights',
        'Revers lights/ tones' => 'revers_lights',
        'Horns' => 'horns',
        'Windows and shutters' => 'windows',
        'Door locks' => 'door_locks',
        'No oil leaks' => 'no_oil_leaks',
        'No high smoke (Black smoke)' => 'no_high_smoke',
        'Seat condition' => 'seat_condition',
        'Seat Gap' => 'seat_gap',
        'Body condition' => 'body_condition',
        'Roof leek' => 'roof_leek',
        'Air Conditions' => 'air_conditions',
        'Noise' => 'noise'
    ];

    // --- 3. Build arrays for SQL columns, values, and UPDATE clauses dynamically ---
    $columns = [
        'supplier_code', 
        'vehicle_no', // vehicle_no is the UNIQUE KEY for the upsert
        'route', 
        'transport_type', 
        'inspector', 
        'date', 
        'other_observations',
        'vehicle_fitness_certificate_status', 
        'vehicle_fitness_certificate_remark',
    ];
    
    $values = [
        "'$supplier_code'", 
        "'$vehicle_no'", 
        "'$route'", 
        "'$transport_type'", 
        "'$inspector'", 
        "'$date'", 
        "'$other_observations'",
        $fitness_status,
        "'$fitness_remark'"
    ];
    
    // Array to hold the UPDATE part of the SQL query
    $update_clauses = [
        "supplier_code = VALUES(supplier_code)",
        "route = VALUES(route)",
        "transport_type = VALUES(transport_type)",
        "inspector = VALUES(inspector)",
        "date = VALUES(date)", // Assuming date should be updated
        "other_observations = VALUES(other_observations)",
        "vehicle_fitness_certificate_status = VALUES(vehicle_fitness_certificate_status)",
        "vehicle_fitness_certificate_remark = VALUES(vehicle_fitness_certificate_remark)",
    ];


    // Use the mapping to construct column names for the SQL query
    foreach ($criteria_mapping as $item_display_name => $db_column_prefix) {
        // The form uses the sanitized item name with _status/_remark suffix
        $sanitized_name = str_replace([' ', '/', '(', ')', '-'], '_', strtolower($item_display_name));
        $sanitized_name = preg_replace('/_+/', '_', $sanitized_name); 
        $sanitized_name = trim($sanitized_name, '_'); 

        $status_post_key = $sanitized_name . '_status';
        $remark_post_key = $sanitized_name . '_remark';
        
        // Collect status (1 if checked, 0 otherwise) and remark
        $status = isset($_POST[$status_post_key]) ? 1 : 0;
        $remark = isset($_POST[$remark_post_key]) ? $conn->real_escape_string($_POST[$remark_post_key]) : '';

        // Add to columns and values arrays using the correct DB column prefix
        $status_col = $db_column_prefix . '_status';
        $remark_col = $db_column_prefix . '_remark';
        
        $columns[] = $status_col;
        $values[] = $status;
        $columns[] = $remark_col;
        $values[] = "'$remark'";

        // Add to UPDATE clauses
        $update_clauses[] = "$status_col = VALUES($status_col)";
        $update_clauses[] = "$remark_col = VALUES($remark_col)";
    }

    // --- 4. Construct and Execute SQL INSERT ... ON DUPLICATE KEY UPDATE Statement ---
    // This requires 'vehicle_no' to be a UNIQUE KEY in the 'checkUp' table.
    $sql = "INSERT INTO checkUp (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ") 
            ON DUPLICATE KEY UPDATE " . implode(', ', $update_clauses);

    if ($conn->query($sql) === TRUE) {
        // Check if it was an INSERT (affected rows = 1) or an UPDATE (affected rows = 2)
        // If the inserted row had no changes, affected_rows will be 1 (for insert) or 0 (for update)
        // A simple query success is often enough for the user feedback.
        $message = "Vehicle inspection recorded successfully! (Inserted or Updated based on Vehicle No.) 🎉";
        $status_type = "success";
    } else {
        $message = "Error: " . $sql . "<br>" . $conn->error;
        $status_type = "error";
        error_log($message); // Log the error for debugging
    }

    $conn->close();

    // --- 5. Display Result to User (Tailwind styled) ---
    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Inspection Result</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-100 font-sans flex items-center justify-center h-screen">
        <div class="bg-white p-8 rounded-lg shadow-lg text-center max-w-md">
            ';
            if ($status_type == "success") {
                echo '<div class="text-green-600 text-5xl mb-4">✔</div>';
                echo '<h2 class="text-2xl font-bold text-gray-800 mb-4">Success!</h2>';
            } else {
                echo '<div class="text-red-600 text-5xl mb-4">✖</div>';
                echo '<h2 class="text-2xl font-bold text-gray-800 mb-4">Error!</h2>';
            }
            echo '
            <p class="text-gray-700 mb-6">' . $message . '</p>
            <a href="checkUp_category.php" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white font-bold rounded-md shadow-md hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500 focus:ring-opacity-50 transition ease-in-out duration-150">
                Go Back to Form
            </a>
        </div>
    </body>
    </html>';

} else {
    // If not a POST request, redirect to the form
    header("Location: checkUp_category.php");
    exit();
}
?>