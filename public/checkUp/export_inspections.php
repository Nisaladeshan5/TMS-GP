<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php'); // Your database connection file

// Define the mapping from display text to database column prefix
// This needs to be consistent across all files that interact with the criteria
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

if (isset($_GET['supplier_name']) && !empty($_GET['supplier_name'])) {
    $supplier_name = $conn->real_escape_string($_GET['supplier_name']);

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inspections_' . urlencode($supplier_name) . '_' . date('Y-m-d') . '.csv"');

    // Create a file pointer connected to the output stream
    $output = fopen('php://output', 'w');

    // Define the CSV header row
    $header_columns = [
        'Supplier', 'Vehicle No.', 'Route', 'Transport Type', 'Inspector', 'Inspection Date'
    ];
    foreach ($criteria_mapping as $display_name => $db_prefix) {
        $header_columns[] = $display_name . ' Status';
        $header_columns[] = $display_name . ' Remark';
    }
    // Add the new headers for the vehicle fitness certificate
    $header_columns[] = 'Vehicle Fitness Certificate Status';
    $header_columns[] = 'Vehicle Fitness Certificate Remark';
    $header_columns[] = 'Other Observations';

    fputcsv($output, $header_columns);

    // Fetch all inspection data for the given supplier and join with the vehicle table to get the type
    $sql = "SELECT c.*, v.type FROM checkUp c INNER JOIN vehicle v ON c.vehicle_no = v.vehicle_no WHERE c.supplier_code = ? ORDER BY c.vehicle_no ASC, c.date DESC";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("s", $supplier_name);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $data_row = [
                $row['supplier_code'],
                $row['vehicle_no'],
                $row['route'],
                $row['transport_type'],
                $row['inspector'],
                $row['date']
            ];

            foreach ($criteria_mapping as $display_name => $db_prefix) {
                $status_column = $db_prefix . '_status';
                $remark_column = $db_prefix . '_remark';
                
                // Add explicit text for Failed status to enable conditional formatting in Excel
                $status_value = (isset($row[$status_column]) && $row[$status_column] == 1) ? 'Passed' : 'Failed';
                $remark_value = isset($row[$remark_column]) ? $row[$remark_column] : '';

                $data_row[] = $status_value;
                $data_row[] = $remark_value;
            }

            // Conditionally add the vehicle fitness certificate data based on vehicle type
            $fitness_status_value = '';
            if (isset($row['type']) && strcasecmp($row['type'], 'bus') == 0) {
                $fitness_status_value = (isset($row['vehicle_fitness_certificate_status']) && $row['vehicle_fitness_certificate_status'] == 1) ? 'Passed' : 'Failed';
            }
            $fitness_remark_value = isset($row['vehicle_fitness_certificate_remark']) ? $row['vehicle_fitness_certificate_remark'] : '';
            $data_row[] = $fitness_status_value;
            $data_row[] = $fitness_remark_value;
            
            $data_row[] = $row['other_observations'];

            fputcsv($output, $data_row);
        }
        $stmt->close();
    } else {
        // Log error if query fails, but no output to browser as headers are already sent
        error_log("Failed to prepare statement for export: " . $conn->error);
    }

    fclose($output);
    $conn->close();
    exit(); // Important to exit after file download
} else {
    // Redirect back if no supplier name is provided
    header("Location: view_supplier.php");
    exit();
}
?>
