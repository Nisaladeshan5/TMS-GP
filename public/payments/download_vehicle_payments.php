<?php
// Ensure this file is accessible only when needed, consider adding authentication/authorization checks.
include('../../includes/db.php');

// Get selected month and year from GET parameters
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="route_payments_' . date('Y_m', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Add CSV headers (matching your table columns)
fputcsv($output, [
    'Vehicle No',
    'Route',
    'Monthly Rental (LKR)',
    'Working Days',
    'Total Distance (km)',
    'Extra Distance (km)',
    'Fuel Amount (LKR)',
    'Reduce Amount (LKR)',
    'Extra Days',
    'Extra Days Amount (LKR)',
    'Total Payments (LKR)'
]);

// Fetch fuel rate (replicate necessary parts of route_payments.php)
$sql_rate = "SELECT rate, date FROM fuel_rate ORDER BY date DESC LIMIT 1";
$result_rate = $conn->query($sql_rate);
$latest_fuel_rate = $result_rate && $result_rate->num_rows > 0 ? $result_rate->fetch_assoc() : null;

// Get current month and year for future date check
$current_month = date('m');
$current_year = date('Y');

// Fetch all routes and their details from the 'route' table
$routes_sql = "SELECT route_code, route, vehicle_no, monthly_fixed_rental, working_days, distance, extra_day_rate FROM route ORDER BY route ASC";
$routes_result = $conn->query($routes_sql);

if ($routes_result && $routes_result->num_rows > 0) {
    while ($route_row = $routes_result->fetch_assoc()) {
        $vehicle_no = $route_row['vehicle_no'];
        $route_code = $route_row['route_code'];
        $route_name = $route_row['route'];
        $monthly_fixed_rental = $route_row['monthly_fixed_rental'];
        $working_days = $route_row['working_days'];
        $daily_distance = $route_row['distance'];
        $extra_day_rate = $route_row['extra_day_rate'];

        $total_extra_distance = 0;
        $actual_days_worked = 0;
        $total_reduce = 0;
        $km_per_liter = 0;

        // Calculate Total Extra Distance
        $extra_dist_sql = "SELECT SUM(distance) AS total_distance FROM extra_distance WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $extra_dist_stmt = $conn->prepare($extra_dist_sql);
        $extra_dist_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $extra_dist_stmt->execute();
        $extra_dist_result = $extra_dist_stmt->get_result();
        if ($extra_dist_row = $extra_dist_result->fetch_assoc()) {
            $total_extra_distance = $extra_dist_row['total_distance'] ?? 0;
        }
        $extra_dist_stmt->close();
        
        // Calculate Actual Days Worked and get vehicle's fuel efficiency
        $register_sql = "SELECT vehicle_no, date FROM staff_transport_vehicle_register WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $register_stmt = $conn->prepare($register_sql);
        $register_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $register_stmt->execute();
        $register_result = $register_stmt->get_result();
        $actual_days_worked = $register_result->num_rows;

        if ($actual_days_worked > 0) {
            $register_result->data_seek(0); // Reset pointer
            $first_entry = $register_result->fetch_assoc();
            $first_vehicle_no = $first_entry['vehicle_no'];

            $efficiency_sql = "SELECT c.distance FROM vehicle v JOIN consumption c ON v.condition_type = c.c_type WHERE v.vehicle_no = ?";
            $efficiency_stmt = $conn->prepare($efficiency_sql);
            $efficiency_stmt->bind_param("s", $first_vehicle_no);
            $efficiency_stmt->execute();
            $efficiency_result = $efficiency_stmt->get_result();

            if ($efficiency_row = $efficiency_result->fetch_assoc()) {
                $km_per_liter = $efficiency_row['distance'];
            }
            $efficiency_stmt->close();
        }
        $register_stmt->close();

        // Calculate total reduce amount
        $ruduce_sql = "SELECT sum(amount) AS total_reduce FROM extra_vehicle_register WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $ruduce_stmt = $conn->prepare($ruduce_sql);
        $ruduce_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $ruduce_stmt->execute();
        $ruduce_result = $ruduce_stmt->get_result();
        if ($reduce_row = $ruduce_result->fetch_assoc()) {
            $total_reduce = $reduce_row['total_reduce'] ?? 0;
        }
        $ruduce_stmt->close();
        
        // Perform calculations
        $total_distance_calculated = $daily_distance * $working_days;
        
        $fuel_amount = 0;
        if ($km_per_liter > 0 && $latest_fuel_rate) {
            $fuel_amount = (($total_distance_calculated + $total_extra_distance) / $km_per_liter) * $latest_fuel_rate['rate'];
        }

        $extra_days = max(0, $actual_days_worked - $working_days);
        $extra_days_amount = $extra_days * $extra_day_rate;
        $total_payments = 0;

        // Check for future months
        if ($selected_year > $current_year || ($selected_year == $current_year && $selected_month > $current_month)) {
            $total_payments = 0;
        } else {
            $total_payments = $monthly_fixed_rental + $fuel_amount + $extra_days_amount - $total_reduce;
        }

        // Write the data row to the CSV
        fputcsv($output, [
            $vehicle_no,
            $route_name,
            number_format($monthly_fixed_rental, 2, '.', ''), // Use '.' for decimal separator for CSV
            $working_days,
            number_format($total_distance_calculated, 0, '.', ''),
            number_format($total_extra_distance, 0, '.', ''),
            number_format($fuel_amount, 2, '.', ''),
            number_format($total_reduce, 2, '.', ''),
            $extra_days,
            number_format($extra_days_amount, 2, '.', ''),
            number_format($total_payments, 2, '.', '')
        ]);
    }
}

// Close the file pointer and database connection
fclose($output);
$conn->close();
exit(); // Ensure no further output is sent
?>
