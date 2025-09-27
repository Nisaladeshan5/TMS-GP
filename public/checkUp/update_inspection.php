<?php
include('../../includes/db.php'); // Your database connection file

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check for a valid database connection
    if (!isset($conn) || $conn->connect_error) {
        $message = "Database connection error. Please try again later.";
        $status_type = "error";
        error_log("Database connection error: " . $conn->connect_error);
    } else {
        // --- 1. Collect and Sanitize General Form Data ---
        $inspection_id = $_POST['inspection_id'];
        $supplier_code = $_POST['supplier_code'];
        $vehicle_no = $_POST['vehicle_no'];
        $route = $_POST['route_name'];
        $transport_type = $_POST['transport_type'];
        $inspector = $_POST['inspector_name'];
        $date = $_POST['inspection_date'];
        $other_observations = $_POST['other_observations'];

        // --- 2. Define the mapping for inspection criteria ---
        $criteria_mapping = [
            'revenue_license', 'driver_license', 'insurance', 'driver_data_sheet',
            'driver_nic', 'break', 'tires', 'spare_wheel', 'lights',
            'revers_lights', 'horns', 'windows', 'door_locks', 'no_oil_leaks',
            'no_high_smoke', 'seat_condition', 'seat_gap', 'body_condition',
            'roof_leek', 'air_conditions', 'noise'
        ];

        // --- 3. Build the SQL UPDATE statement dynamically for all fields ---
        $update_parts = [];
        $params = [];
        $param_types = '';

        // Add general fields
        $update_parts[] = "supplier_code = ?";
        $params[] = $supplier_code;
        $param_types .= 's';

        $update_parts[] = "vehicle_no = ?";
        $params[] = $vehicle_no;
        $param_types .= 's';

        $update_parts[] = "route = ?";
        $params[] = $route;
        $param_types .= 's';

        $update_parts[] = "transport_type = ?";
        $params[] = $transport_type;
        $param_types .= 's';

        $update_parts[] = "inspector = ?";
        $params[] = $inspector;
        $param_types .= 's';

        $update_parts[] = "date = ?";
        $params[] = $date;
        $param_types .= 's';

        $update_parts[] = "other_observations = ?";
        $params[] = $other_observations;
        $param_types .= 's';

        // Add inspection criteria fields
        foreach ($criteria_mapping as $db_column_prefix) {
            $status_post_key = $db_column_prefix . '_status';
            $remark_post_key = $db_column_prefix . '_remark';
            
            $status = isset($_POST[$status_post_key]) ? 1 : 0;
            $remark = isset($_POST[$remark_post_key]) ? $_POST[$remark_post_key] : '';

            $update_parts[] = "{$db_column_prefix}_status = ?";
            $params[] = $status;
            $param_types .= 'i';

            $update_parts[] = "{$db_column_prefix}_remark = ?";
            $params[] = $remark;
            $param_types .= 's';
        }

        // Handle the 'Vehicle Fitness Certificate' field which is conditional
        $fitness_status = isset($_POST['vehicle_fitness_certificate_status']) ? 1 : 0;
        $fitness_remark = isset($_POST['vehicle_fitness_certificate_remark']) ? $_POST['vehicle_fitness_certificate_remark'] : '';

        $update_parts[] = "vehicle_fitness_certificate_status = ?";
        $params[] = $fitness_status;
        $param_types .= 'i';

        $update_parts[] = "vehicle_fitness_certificate_remark = ?";
        $params[] = $fitness_remark;
        $param_types .= 's';

        // Add the WHERE clause and its parameter
        $sql = "UPDATE checkUp SET " . implode(', ', $update_parts) . " WHERE id = ?";
        $params[] = $inspection_id;
        $param_types .= 'i';

        // --- 4. Prepare and Execute the SQL Statement ---
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($param_types, ...$params);
            
            if ($stmt->execute()) {
                $message = "Vehicle inspection updated successfully! 🎉";
                $status_type = "success";
            } else {
                $message = "Error updating record: " . $stmt->error;
                $status_type = "error";
                error_log($message);
            }
            $stmt->close();
        } else {
            $message = "Database query preparation error: " . $conn->error;
            $status_type = "error";
            error_log($message);
        }
        $conn->close();
    }

    // --- 5. Display Result to User (Tailwind styled) ---
    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Update Result</title>
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
            <a href="edit_inspection.php" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white font-bold rounded-md shadow-md hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500 focus:ring-opacity-50 transition ease-in-out duration-150">
                Go Back to Edit Form
            </a>
        </div>
    </body>
    </html>';

} else {
    // If not a POST request, redirect to the edit form
    header("Location: edit_inspection.php");
    exit();
}
?>
