<?php
// download_route_payments.php (Staff Monthly Payments CSV Export)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

// Note: This script assumes the included files exist and the database connection is valid.
include('../../../includes/db.php'); // Include database connection

// Get selected month and year, default to current month/year
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$payment_data = [];


/**
 * Get Total Reduction Amount (from the 'reduction' table)
 * Fetches the sum of 'amount' from the reduction table for the given route, supplier, and period.
 * * **UPDATED: NOW FILTERS BY supplier_code**
 */
function get_total_adjustment_amount($conn, $route_code, $supplier_code, $month, $year) // ADDED $supplier_code
{
    $total_adjustment = 0.00;

    // 1. Sum 'amount' from the 'reduction' table
    $reduction_sql = "
        SELECT SUM(amount) AS total_adjustment_amount 
        FROM reduction 
        WHERE route_code = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ?
    ";
    $reduction_stmt = $conn->prepare($reduction_sql);
    
    if (!$reduction_stmt) {
        error_log("SQL Prepare failed for reduction table: " . $conn->error);
        return 0.00; 
    }
    
    // BINDING PARAMETERS UPDATED to include supplier_code (type 's' for string)
    $reduction_stmt->bind_param("ssii", $route_code, $supplier_code, $month, $year);
    if ($reduction_stmt->execute()) {
        $reduction_result = $reduction_stmt->get_result();
        $row = $reduction_result->fetch_assoc();
        // Use the fetched sum
        $total_adjustment = (float)($row['total_adjustment_amount'] ?? 0);
        $reduction_result->free();
    }
    $reduction_stmt->close();

    return $total_adjustment; // This is the total positive reduction sum.
}


// --- STAFF PAYMENT LOGIC ---

// Fetch all routes with the staff purpose
$routes_sql = "SELECT route_code, route, fixed_amount, fuel_amount, distance FROM route WHERE purpose = 'factory' ORDER BY route ASC";
$routes_result = $conn->query($routes_sql);

if ($routes_result && $routes_result->num_rows > 0) {
    while ($route_row = $routes_result->fetch_assoc()) {
        $route_code = $route_row['route_code'];
        $route_name = $route_row['route'];
        $rate_per_km = $route_row['fixed_amount'] + $route_row['fuel_amount'];
        $route_distance = (float)$route_row['distance']; 

        // 1. Find all distinct SUPPLIERS that operated on this route this month/year
        $suppliers_sql = "
            SELECT DISTINCT ftvr.supplier_code 
            FROM factory_transport_vehicle_register ftvr
            WHERE ftvr.route = ? 
              AND MONTH(ftvr.date) = ? 
              AND YEAR(ftvr.date) = ?
        ";
        $suppliers_stmt = $conn->prepare($suppliers_sql);
        $suppliers_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $suppliers_stmt->execute();
        $suppliers_result = $suppliers_stmt->get_result();

        if ($suppliers_result->num_rows > 0) {
            
            while ($supplier_row = $suppliers_result->fetch_assoc()) {
                $supplier_code = $supplier_row['supplier_code']; 

                // --- Variables initialized per Supplier ---
                $total_working_days = 0; 
                $calculated_base_payment = 0; 
                $other_amount = 0; // Will hold the final adjustment (-1 * reduction)
                $total_payments = 0;

                // 2. Calculate the number of working days (Total Trip Count) FOR THIS SPECIFIC SUPPLIER ON THIS ROUTE
                $days_sql = "
                    SELECT 
                        COUNT(id) AS total_days 
                    FROM 
                        factory_transport_vehicle_register
                    WHERE 
                        route = ? 
                        AND supplier_code = ?
                        AND MONTH(date) = ? 
                        AND YEAR(date) = ?
                        AND is_active = 1
                ";
                $days_stmt = $conn->prepare($days_sql);
                $days_stmt->bind_param("ssii", $route_code, $supplier_code, $selected_month, $selected_year); 
                $days_stmt->execute();
                $days_result = $days_stmt->get_result();
                $days_row = $days_result->fetch_assoc();
                $total_working_days = $days_row['total_days'] ?? 0;
                $days_stmt->close();
                
                // --- Day Rate Calculation ---
                $day_rate = ($rate_per_km * $route_distance) / 2; 
                $calculated_base_payment = $day_rate * $total_working_days;

                // Calculated Total Distance
                $total_distance = ($route_distance / 2) * $total_working_days;
                
                
                // 3. Calculate Adjustment (Reduction) and Final Total Payment
                
                $current_month = (int)date('m');
                $current_year = (int)date('Y');

                if ($selected_year > $current_year || ($selected_year == $current_year && $selected_month > $current_month)) {
                    // Future months: set everything to zero
                    $total_payments = 0;
                    $other_amount = 0;
                } else {
                    // Get total reduction amount (positive sum from the table)
                    // CALL UPDATED TO INCLUDE $supplier_code
                    $raw_reduction_amount = get_total_adjustment_amount($conn, $route_code, $supplier_code, $selected_month, $selected_year);

                    // Adjustment amount is the negative of the reduction sum
                    $other_amount = $raw_reduction_amount * -1; 
                    
                    // Total Payments = Base Payment + Other Amount (which is now a negative reduction)
                    $total_payments = $calculated_base_payment + $other_amount; 
                }
                
                
                // 4. Fetch a representative vehicle number for display
                $vehicle_no_display = 'N/A';
                $vehicle_lookup_sql = "SELECT vehicle_no FROM factory_transport_vehicle_register WHERE route = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ? LIMIT 1";
                $vehicle_lookup_stmt = $conn->prepare($vehicle_lookup_sql);
                $vehicle_lookup_stmt->bind_param("ssii", $route_code, $supplier_code, $selected_month, $selected_year);
                $vehicle_lookup_stmt->execute();
                $vehicle_lookup_result = $vehicle_lookup_stmt->get_result();
                if ($v_row = $vehicle_lookup_result->fetch_assoc()) {
                    $vehicle_no_display = $v_row['vehicle_no'];
                }
                $vehicle_lookup_stmt->close();


                // Store data for the supplier/route combination
                $payment_data[] = [
                    'route_code' => $route_code, 
                    'vehicle_no' => $vehicle_no_display, 
                    'supplier_code' => $supplier_code, 
                    'route' => $route_name,
                    'day_rate' => $day_rate, 
                    'total_working_days' => $total_working_days,
                    'total_distance' => $total_distance,
                    'calculated_base_payment' => $calculated_base_payment,
                    'other_amount' => $other_amount, 
                    'payments' => $total_payments 
                ];
            }
            $suppliers_stmt->close();
        } 
    }
}

// Close the database connection
$conn->close();

// --- CSV GENERATION AND DOWNLOAD ---

$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));
$filename = "factory_Payments_{$month_name}_{$selected_year}.csv";

// Set headers for file download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Define CSV Headers
$headers = [
    'Route Code', 
    'Route',
    'Vehicle No (Representative)', 
    'Supplier Code',  
    'Trip Rate (LKR)', 
    'Total Trip Count', 
    'Total Distance (km)', 
    'Base Payment (LKR)', 
    'Adjustment (LKR)', 
    'Total Payments (LKR)'
];

// Write headers to CSV
fputcsv($output, $headers);

// Write data rows
if (!empty($payment_data)) {
    foreach ($payment_data as $row) {
        $csv_row = [
            $row['route_code'],
            $row['route'],
            $row['vehicle_no'],
            $row['supplier_code'], 
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