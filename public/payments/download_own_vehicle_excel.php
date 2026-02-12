<?php
// download_own_vehicle_payments.php
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
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Set timezone for calculation consistency
date_default_timezone_set('Asia/Colombo');

$payment_data = [];

// --- Database Functions for Calculation (DYNAMIC RATE LOGIC) ---

/**
 * NEW CORE FUNCTION: Get the correct fuel price applicable on a specific date and time.
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
        ov.paid,
        ov.rate_id
    FROM 
        own_vehicle ov
    JOIN 
        employee e ON ov.emp_id = e.emp_id
    WHERE 
        ov.is_active = 1
    ORDER BY 
        e.calling_name ASC, ov.vehicle_no ASC;
";

$result = $conn->query($employees_sql);

if ($result && $result->num_rows > 0) {
    while ($employee_row = $result->fetch_assoc()) {
        $emp_id = $employee_row['emp_id'];
        $vehicle_no = $employee_row['vehicle_no']; 
        $rate_id = $employee_row['rate_id'];
        $is_paid_status = (int)$employee_row['paid'];
        $consumption = (float)$employee_row['consumption']; 
        $daily_distance = (float)$employee_row['distance'];
        $fixed_amount = (float)$employee_row['fixed_amount'];

        // Fixed Amount set to 0 if paid = 0
        $fixed_amount = ($is_paid_status === 1) ? (float)$employee_row['fixed_amount'] : 0.00;

        // Initial Calculations
        $total_monthly_payment = 0.00; 
        $total_attendance_days = 0;
        $total_calculated_distance = 0.00;
        
        // Add fixed amount
        $total_monthly_payment += $fixed_amount;
        
        // 1. Process Attendance Payments
        $attendance_records = get_monthly_attendance_records($conn, $emp_id, $vehicle_no, $selected_month, $selected_year);
        
        $base_payment_total = 0.00;

        foreach ($attendance_records as $record) {
            $datetime = $record['date'] . ' ' . $record['time']; 
            $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
            
            if ($fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
                 $day_rate = ($consumption / 100) * $daily_distance * $fuel_price;

                // Only add to payment if paid = 1
                if ($is_paid_status === 1) {
                $base_payment_total += $day_rate;
                }
                $total_calculated_distance += $daily_distance;
                $total_attendance_days++;
            }
        }
        $total_monthly_payment += $base_payment_total;
        
        // 2. Process Extra Payments
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
                
                // Only add to payment if paid = 1
                if ($is_paid_status === 1) {
                    $extra_payment_total += $extra_payment;
                }
                $total_calculated_distance += $extra_distance;
            }
        }
        $total_monthly_payment += $extra_payment_total;

        // Store Data
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

// --- EXCEL GENERATION START ---
$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));
$filename = "Own_Vehicle_Payments_{$month_name}_{$selected_year}.xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        /* Force text format for codes to keep leading zeros */
        .text-format { mso-number-format:"\@"; } 
        /* Currency format */
        .currency-format { mso-number-format:"\#\,\#\#0\.00"; }
    </style>
</head>
<body>
    <table border="1">
        <thead>
            <tr>
                <th colspan="10" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">
                    Own Vehicle Payment Summary - <?php echo "$month_name $selected_year"; ?>
                </th>
            </tr>
            <tr>
                <th style="background-color: #ADD8E6; font-weight: bold;">Employee ID</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Employee Name</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Vehicle No</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Attendance Days</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Daily Base Dist (KM)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Fixed Allowance (LKR)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Base Travel Pay (LKR)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Extra Trip Pay (LKR)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Distance (KM)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Payment (LKR)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $grand_total_payment = 0;
            $grand_fixed = 0;
            $grand_base = 0;
            $grand_extra = 0;
            $grand_distance = 0;

            if (!empty($payment_data)): 
                foreach ($payment_data as $row): 
                    $grand_total_payment += $row['total_monthly_payment'];
                    $grand_fixed += $row['fixed_amount'];
                    $grand_base += $row['total_base_payment'];
                    $grand_extra += $row['total_extra_payment'];
                    $grand_distance += $row['total_calculated_distance'];
            ?>
                    <tr>
                        <td class="text-format"><?php echo htmlspecialchars($row['emp_id']); ?></td>
                        <td><?php echo htmlspecialchars($row['calling_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                        <td style="text-align:center;"><?php echo $row['attendance_days']; ?></td>
                        <td style="text-align:center;"><?php echo number_format($row['base_distance_km'], 2); ?></td>
                        
                        <td class="currency-format" style="text-align:right;">
                            <?php echo $row['fixed_amount']; ?>
                        </td>
                        <td class="currency-format" style="text-align:right;">
                            <?php echo $row['total_base_payment']; ?>
                        </td>
                        <td class="currency-format" style="text-align:right;">
                            <?php echo $row['total_extra_payment']; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php echo number_format($row['total_calculated_distance'], 2); ?>
                        </td>
                        <td class="currency-format" style="font-weight:bold; text-align:right;">
                            <?php echo $row['total_monthly_payment']; ?>
                        </td>
                    </tr>
            <?php 
                endforeach; 
                // Grand Total Row
            ?>
                <tr>
                    <td colspan="5" style="text-align: right; font-weight: bold;">GRAND TOTALS</td>
                    <td class="currency-format" style="font-weight: bold;"><?php echo $grand_fixed; ?></td>
                    <td class="currency-format" style="font-weight: bold;"><?php echo $grand_base; ?></td>
                    <td class="currency-format" style="font-weight: bold;"><?php echo $grand_extra; ?></td>
                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($grand_distance, 2); ?></td>
                    <td class="currency-format" style="font-weight: bold; border-top: 2px solid black; background-color: #FFFFE0;">
                        <?php echo $grand_total_payment; ?>
                    </td>
                </tr>

            <?php else: ?>
                <tr>
                    <td colspan="10" style="text-align:center;">No records found for this period.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>