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
    'Route',
    'Monthly Rental (LKR)',
    'Working Days',
    'Total Distance (km)',
    'Extra Distance (km)',
    'Fuel Amount (LKR)',
    'Other Amount (LKR)',
    'Extra Days',
    'Extra Days Amount (LKR)',
    'Total Payments (LKR)'
]);

// Get current month and year for future date check
$current_month = date('m');
$current_year = date('Y');

// Fetch all routes with purpose = 'staff' and their details from the 'route' table
$routes_sql = "SELECT route_code, route, monthly_fixed_rental, working_days, distance FROM route WHERE purpose = 'staff' ORDER BY route ASC";
$routes_result = $conn->query($routes_sql);

if ($routes_result && $routes_result->num_rows > 0) {
    while ($route_row = $routes_result->fetch_assoc()) {
        $route_code = $route_row['route_code'];
        $route_name = $route_row['route'];
        $monthly_fixed_rental = $route_row['monthly_fixed_rental'];
        $working_days_quota = $route_row['working_days'];
        $daily_distance = $route_row['distance'];

        $total_extra_distance = 0;
        $actual_days_worked = 0;
        $km_per_liter = 10; // Default
        $price_per_liter = 0; // Will be fetched later
        
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
        
        // Calculate Actual Days Worked and get vehicle's details for fuel calculation
        $register_sql = "SELECT vehicle_no FROM staff_transport_vehicle_register WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $register_stmt = $conn->prepare($register_sql);
        $register_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $register_stmt->execute();
        $register_result = $register_stmt->get_result();
        $actual_days_worked = $register_result->num_rows;

        if ($actual_days_worked > 0) {
            $first_entry = $register_result->fetch_assoc();
            $first_vehicle_no = $first_entry['vehicle_no'];

            $vehicle_info_sql = "
                SELECT 
                    c.distance, 
                    fr.rate 
                FROM 
                    vehicle v 
                JOIN 
                    consumption c ON v.condition_type = c.c_type 
                JOIN 
                    fuel_rate fr ON v.rate_id = fr.rate_id 
                WHERE 
                    v.vehicle_no = ? 
                ORDER BY 
                    fr.date DESC 
                LIMIT 1";
            $vehicle_info_stmt = $conn->prepare($vehicle_info_sql);
            $vehicle_info_stmt->bind_param("s", $first_vehicle_no);
            $vehicle_info_stmt->execute();
            $vehicle_info_result = $vehicle_info_stmt->get_result();

            if ($vehicle_info_row = $vehicle_info_result->fetch_assoc()) {
                $km_per_liter = $vehicle_info_row['distance'];
                $price_per_liter = $vehicle_info_row['rate'];
            }
            $vehicle_info_stmt->close();
        }
        $register_stmt->close();

        // Calculate other amount (Trip - Extra Vehicle - Petty Cash)
        $trip_amount = 0;
        $extra_vehicle_amount = 0;
        $petty_cash_amount = 0;

        $trip_sql = "SELECT SUM(amount) AS total_trip_amount FROM trip WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $trip_stmt = $conn->prepare($trip_sql);
        $trip_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $trip_stmt->execute();
        $trip_result = $trip_stmt->get_result();
        if ($trip_row = $trip_result->fetch_assoc()) {
            $trip_amount = $trip_row['total_trip_amount'] ?? 0;
        }
        $trip_stmt->close();

        $extra_vehicle_sql = "SELECT sum(amount) AS total_extra_vehicle FROM extra_vehicle_register WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $extra_vehicle_stmt = $conn->prepare($extra_vehicle_sql);
        $extra_vehicle_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $extra_vehicle_stmt->execute();
        $extra_vehicle_result = $extra_vehicle_stmt->get_result();
        if ($extra_vehicle_row = $extra_vehicle_result->fetch_assoc()) {
            $extra_vehicle_amount = $extra_vehicle_row['total_extra_vehicle'] ?? 0;
        }
        $extra_vehicle_stmt->close();
        
        $petty_cash_sql = "SELECT SUM(amount) AS total_petty_cash FROM petty_cash WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $petty_cash_stmt = $conn->prepare($petty_cash_sql);
        $petty_cash_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $petty_cash_stmt->execute();
        $petty_cash_result = $petty_cash_stmt->get_result();
        if ($petty_cash_row = $petty_cash_result->fetch_assoc()) {
            $petty_cash_amount = $petty_cash_row['total_petty_cash'] ?? 0;
        }
        $petty_cash_stmt->close();

        $other_amount = $trip_amount - $extra_vehicle_amount - $petty_cash_amount;

        // Perform main calculations
        $working_days_limit = $working_days_quota * 2;
        $days_for_fuel = min($actual_days_worked, $working_days_limit);
        $total_distance_for_fuel = ($daily_distance / 2) * $days_for_fuel;
        
        $fuel_amount = 0;
        if ($km_per_liter > 0 && $price_per_liter > 0) {
            $fuel_amount = (($total_distance_for_fuel + $total_extra_distance) / $km_per_liter) * $price_per_liter;
        }
        
        $extra_day_rate = 0;
        if ($km_per_liter > 0 && $price_per_liter > 0) {
            $extra_day_rate = ($monthly_fixed_rental / $working_days_quota) + (($daily_distance / 2 / $km_per_liter) * $price_per_liter);
        }
        
        $extra_days_worked = max(0, $actual_days_worked - $working_days_limit);
        $extra_days = $extra_days_worked / 2;
        $extra_days_amount = $extra_days * $extra_day_rate;
        
        $total_payments = 0;

        // Check for future months
        if ($selected_year > $current_year || ($selected_year == $current_year && $selected_month > $current_month)) {
            $total_payments = 0;
        } else {
            $total_payments = $monthly_fixed_rental + $fuel_amount + $extra_days_amount + $other_amount;
        }

        // Write the data row to the CSV
        fputcsv($output, [
            $route_name,
            number_format($monthly_fixed_rental, 2, '.', ''),
            $working_days_quota,
            number_format($total_distance_for_fuel, 0, '.', ''),
            number_format($total_extra_distance, 0, '.', ''),
            number_format($fuel_amount, 2, '.', ''),
            number_format($other_amount, 2, '.', ''),
            $extra_days,
            number_format($extra_days_amount, 2, '.', ''),
            number_format($total_payments, 2, '.', '')
        ]);
    }
}

// Close the file pointer and database connection
fclose($output);
$conn->close();
exit();
?>