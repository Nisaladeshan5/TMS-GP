<?php
// Note: This script assumes the included files exist and the database connection is valid.
include('../../includes/db.php'); // Include database connection

// Get selected month and year, default to current month/year
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

$payment_data = [];

// --- STAFF PAYMENT LOGIC (Replicated from payments_category.php) ---

// Fetch all routes with the staff payment type
$routes_sql = "SELECT route_code, route, fixed_amount, fuel_amount, distance FROM route WHERE purpose = 'staff' ORDER BY route ASC";
$routes_result = $conn->query($routes_sql);

if ($routes_result && $routes_result->num_rows > 0) {
    while ($route_row = $routes_result->fetch_assoc()) {
        $route_code = $route_row['route_code'];
        $route_name = $route_row['route'];
        $rate_per_km = $route_row['fixed_amount'] + $route_row['fuel_amount'];
        $route_distance = $route_row['distance']; 

        // 1. Find all distinct vehicles that operated on this route this month/year
        // This is done on the ASSIGNED vehicle (vehicle_no)
        $vehicles_sql = "
            SELECT DISTINCT stvr.vehicle_no, v.supplier_code /* <-- FETCH supplier_code here */
            FROM staff_transport_vehicle_register stvr
            JOIN vehicle v ON stvr.vehicle_no = v.vehicle_no /* <-- JOIN added to get supplier code */
            WHERE stvr.route = ? 
              AND MONTH(stvr.date) = ? 
              AND YEAR(stvr.date) = ?
        ";
        $vehicles_stmt = $conn->prepare($vehicles_sql);
        $vehicles_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $vehicles_stmt->execute();
        $vehicles_result = $vehicles_stmt->get_result();

        if ($vehicles_result->num_rows > 0) {
            
            while ($vehicle_row = $vehicles_result->fetch_assoc()) {
                $vehicle_no = $vehicle_row['vehicle_no'];
                $supplier_code = $vehicle_row['supplier_code']; // <-- Supplier code fetched
                
                // Initialized variables (now per-vehicle)
                $total_working_days = 0; 
                $total_distance = 0; 
                $monthly_payment_from_db = 0; 
                $calculated_base_payment = 0; 
                $other_amount = 0;
                $total_payments = 0;

                // 2. Calculate the number of working days (Total Trip Count) FOR THIS SPECIFIC VEHICLE
                // This correctly uses the VEHICLE number from the register table.
                $days_sql = "
                    SELECT 
                        COUNT(DISTINCT stvr.date) AS total_days 
                    FROM 
                        staff_transport_vehicle_register stvr
                    WHERE 
                        stvr.route = ? 
                        AND stvr.vehicle_no = ?
                        AND MONTH(stvr.date) = ? 
                        AND YEAR(stvr.date) = ?
                ";
                $days_stmt = $conn->prepare($days_sql);
                $days_stmt->bind_param("ssii", $route_code, $vehicle_no, $selected_month, $selected_year); 
                $days_stmt->execute();
                $days_result = $days_stmt->get_result();
                $days_row = $days_result->fetch_assoc();
                $total_working_days = $days_row['total_days'] ?? 0;
                $days_stmt->close();
                
                // --- Day Rate Calculation ---
                $day_rate = ($rate_per_km * $route_distance) / 2; 
                $calculated_base_payment = $day_rate * $total_working_days;

                // 3. Fetch DB Total Payment (monthly_payment) and Total Distance 
                // --- CORRECTED QUERY: Use supplier_code instead of vehicle_no ---
                $distance_sql = "
                    SELECT total_distance, monthly_payment 
                    FROM monthly_payments_sf 
                    WHERE route_code = ? AND supplier_code = ? AND month = ? AND year = ?
                ";
                $distance_stmt = $conn->prepare($distance_sql);
                // Bind route_code (s), supplier_code (s), month (i), year (i)
                $distance_stmt->bind_param("ssii", $route_code, $supplier_code, $selected_month, $selected_year); // <-- Bind with supplier_code
                $distance_stmt->execute();
                $distance_result = $distance_stmt->get_result();
                
                if ($distance_row = $distance_result->fetch_assoc()) {
                    $total_distance = $distance_row['total_distance'] ?? 0;
                    $monthly_payment_from_db = $distance_row['monthly_payment'] ?? 0; 
                } else {
                    $total_distance = 0; 
                }
                $distance_stmt->close();
                
                
                // 4. Calculate Total Payments (uses DB authority value) and Other Amount
                $current_month = date('m');
                $current_year = date('Y');

                if ($selected_year > $current_year || ($selected_year == $current_year && $selected_month > $current_month)) {
                    $total_payments = 0;
                    $other_amount = 0;
                } else {
                    $total_payments = $monthly_payment_from_db;
                    $other_amount = $total_payments - $calculated_base_payment;
                }
                
                $payment_data[] = [
                    'route_code' => $route_code, 
                    'vehicle_no' => $vehicle_no,
                    'supplier_code' => $supplier_code, // <-- Include supplier_code in output for debugging/reference
                    'route_vehicle' => $route_name . " (" . $vehicle_no . ")",
                    'day_rate' => $day_rate, 
                    'total_working_days' => $total_working_days,
                    'total_distance' => $total_distance,
                    'calculated_base_payment' => $calculated_base_payment,
                    'other_amount' => $other_amount, 
                    'payments' => $total_payments 
                ];
            }
            $vehicles_stmt->close();
        } 
    }
}

// Close the database connection
$conn->close();

// --- CSV GENERATION AND DOWNLOAD ---

$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));
$filename = "Staff_Payments_{$month_name}_{$selected_year}.csv";

// Set headers for file download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Define CSV Headers
$headers = [
    'Route Code', 
    'Vehicle No', 
    'Supplier Code', // <-- Updated Header
    'Route (Vehicle)', 
    'Day Rate (LKR)', 
    'Total Trip Count', 
    'Total Distance (km)', 
    'Base Payment (LKR)', 
    'Reduction (LKR)', 
    'Total Payments (LKR)'
];

// Write headers to CSV
fputcsv($output, $headers);

// Write data rows
if (!empty($payment_data)) {
    foreach ($payment_data as $row) {
        $csv_row = [
            $row['route_code'],
            $row['vehicle_no'],
            $row['supplier_code'], // <-- Output Supplier Code
            $row['route_vehicle'],
            number_format($row['day_rate'], 2),
            number_format($row['total_working_days'], 0),
            number_format($row['total_distance'], 2),
            number_format($row['calculated_base_payment'], 2),
            number_format($row['other_amount'], 2),
            number_format($row['payments'], 2)
        ];
        fputcsv($output, $csv_row);
    }
}

// Close the file handle
fclose($output);

// Ensure no other output (like HTML or whitespace) follows
exit;
?>