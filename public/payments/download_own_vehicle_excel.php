<?php
// Note: This file MUST NOT have any whitespace/characters before the opening <?php tag.

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Include the database connection
include('../../includes/db.php');

// Get selected month and year, default to current month/year
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Set timezone for calculation consistency
date_default_timezone_set('Asia/Colombo');

$payment_data = [];

// --- Database Functions for Calculation (DYNAMIC RATE LOGIC) ---

/**
 * NEW CORE FUNCTION: Get the correct fuel price applicable on a specific date and time.
 * Uses the 'date' column in the fuel_rate table.
 */
function get_applicable_fuel_price($conn, $rate_id, $datetime) {
    $sql = "SELECT rate FROM fuel_rate 
            WHERE rate_id = ? AND date <= ?
            ORDER BY date DESC LIMIT 1"; 
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return 0;
    
    $stmt->bind_param("ss", $rate_id, $datetime);
    $stmt->execute();
    $result = $stmt->get_result();
    $price = $result->fetch_assoc()['rate'] ?? 0;
    $stmt->close();
    return (float)$price;
}

/**
 * Get attendance records filtered by Emp ID AND Vehicle No.
 */
function get_monthly_attendance_records($conn, $emp_id, $vehicle_no, $month, $year) {
    // *** MODIFICATION: Added vehicle_no check ***
    $sql = "SELECT date, time FROM own_vehicle_attendance 
            WHERE emp_id = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return [];

    $stmt->bind_param("ssii", $emp_id, $vehicle_no, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();
    return $records;
}

/**
 * Get extra distance records filtered by Emp ID AND Vehicle No.
 */
function get_monthly_extra_records($conn, $emp_id, $vehicle_no, $month, $year) {
    // *** MODIFICATION: Added vehicle_no check ***
    $sql = "SELECT date, out_time, distance FROM own_vehicle_extra 
            WHERE emp_id = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ? AND done = 1 AND distance IS NOT NULL";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return [];

    $stmt->bind_param("ssii", $emp_id, $vehicle_no, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();
    return $records;
}


// --- MAIN DATA FETCH AND CALCULATION ---
$employees_sql = "
    SELECT 
        e.emp_id, 
        e.calling_name,
        ov.vehicle_no,
        ov.fuel_efficiency AS consumption, 
        ov.fixed_amount,
        ov.distance, 
        ov.rate_id
    FROM 
        own_vehicle ov
    JOIN 
        employee e ON ov.emp_id = e.emp_id
    ORDER BY 
        e.calling_name ASC, ov.vehicle_no ASC;
";

$result = $conn->query($employees_sql);

if ($result && $result->num_rows > 0) {
    while ($employee_row = $result->fetch_assoc()) {
        $emp_id = $employee_row['emp_id'];
        $vehicle_no = $employee_row['vehicle_no']; // Get Vehicle No
        $rate_id = $employee_row['rate_id'];
        $consumption = (float)$employee_row['consumption']; 
        $daily_distance = (float)$employee_row['distance'];
        $fixed_amount = (float)$employee_row['fixed_amount'];

        // Initial Calculations
        $total_monthly_payment = 0.00; 
        $total_attendance_days = 0;
        $total_calculated_distance = 0.00;
        
        // Add fixed amount
        $total_monthly_payment += $fixed_amount;
        
        // 1. Process Attendance Payments (Pass Vehicle No)
        $attendance_records = get_monthly_attendance_records($conn, $emp_id, $vehicle_no, $selected_month, $selected_year);
        
        $base_payment_total = 0.00;

        foreach ($attendance_records as $record) {
            $datetime = $record['date'] . ' ' . $record['time']; 
            $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
            
            if ($fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
                 $day_rate = ($consumption / 100) * $daily_distance * $fuel_price;

                 $base_payment_total += $day_rate;
                 $total_calculated_distance += $daily_distance;
                 $total_attendance_days++;
            }
        }
        $total_monthly_payment += $base_payment_total;
        
        // 2. Process Extra Payments (Pass Vehicle No)
        $extra_records = get_monthly_extra_records($conn, $emp_id, $vehicle_no, $selected_month, $selected_year);
        
        $extra_payment_total = 0.00;

        foreach ($extra_records as $record) {
            $datetime = $record['date'] . ' ' . $record['out_time'];
            $extra_distance = (float)$record['distance'];

            $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
            
            if ($fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
                // Calculate rate per KM
                $day_rate_base = ($consumption / 100) * $daily_distance * $fuel_price;
                $rate_per_km = $day_rate_base / $daily_distance; 
                
                $extra_payment = $rate_per_km * $extra_distance;
                
                $extra_payment_total += $extra_payment;
                $total_calculated_distance += $extra_distance;
            }
        }
        $total_monthly_payment += $extra_payment_total;

        // --- D. Store Data for Excel Output ---
        // Only add to excel if there is some activity or fixed amount
        $payment_data[] = [
            'emp_id' => $emp_id,
            'calling_name' => $employee_row['calling_name'],
            'vehicle_no' => $vehicle_no,
            'attendance_days' => $total_attendance_days,
            'base_distance_km' => $daily_distance,
            'fixed_amount' => $fixed_amount,
            'total_base_payment' => $base_payment_total,
            'total_extra_payment' => $extra_payment_total,
            'total_calculated_distance' => $total_calculated_distance,
            'total_monthly_payment' => $total_monthly_payment,
        ];
    }
}
$conn->close();

// --- EXCEL OUTPUT ---

$filename = "OwnVehicle_Payments_" . date('Y_m', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . ".csv";

// Headers to force download as CSV/Excel
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Open file pointer for writing to the output buffer
$output = fopen('php://output', 'w');

// Write the main header row
fputcsv($output, [
    'Employee ID',
    'Employee Name',
    'Vehicle No',
    'Attendance Days',
    'Daily Base Distance (KM)',
    'Total Fixed Allowance (LKR)',
    'Total Base Travel Payment (LKR)',
    'Total Extra Trip Payment (LKR)',
    'Grand Total Distance (KM)',
    'GRAND TOTAL PAYMENT (LKR)'
]);

// Write data rows
foreach ($payment_data as $row) {
    fputcsv($output, [
        $row['emp_id'],
        $row['calling_name'],
        $row['vehicle_no'],
        $row['attendance_days'],
        $row['base_distance_km'],
        number_format($row['fixed_amount'], 2, '.', ''),
        number_format($row['total_base_payment'], 2, '.', ''),
        number_format($row['total_extra_payment'], 2, '.', ''),
        number_format($row['total_calculated_distance'], 2, '.', ''),
        number_format($row['total_monthly_payment'], 2, '.', '')
    ]);
}

fclose($output);
exit;
?>