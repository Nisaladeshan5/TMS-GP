<?php
// download_dh_payments_excel.php
require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- 1. Get and Validate Inputs ---
$filterMonthNum = $_GET['month'] ?? date('m');
$filterYear = $_GET['year'] ?? date('Y');

if (!is_numeric($filterMonthNum) || !is_numeric($filterYear)) {
    die("Invalid date parameters.");
}

$filterMonthNum = str_pad($filterMonthNum, 2, '0', STR_PAD_LEFT);
$filterYearMonth = "{$filterYear}-{$filterMonthNum}";
$monthName = date('F', mktime(0, 0, 0, (int)$filterMonthNum, 1));

// --- 2. CORE CALCULATION FUNCTION ---
function calculate_day_heldup_payments_for_report($conn, $month, $year) {
    
    $attendance_sql = "
        SELECT 
            dha.op_code, 
            dha.date,
            dha.vehicle_no,
            dha.ac, 
            os.slab_limit_distance,
            os.extra_rate_ac,
            os.extra_rate AS extra_rate_nonac 
        FROM 
            dh_attendance dha
        JOIN 
            op_services os ON dha.op_code = os.op_code
        WHERE 
            DATE_FORMAT(dha.date, '%Y-%m') = ?
        ORDER BY
            dha.op_code ASC, dha.date ASC
    ";
    
    $stmt = $conn->prepare($attendance_sql);
    if (!$stmt) return ['error' => 'Attendance Prepare Failed'];
    
    $filter_month_year = "{$year}-{$month}";
    $stmt->bind_param("s", $filter_month_year);
    $stmt->execute();
    $attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $monthly_summary = [];

    foreach ($attendance_records as $record) {
        $date = $record['date'];
        $op_code = $record['op_code'];
        $vehicle_no = $record['vehicle_no'];

        // Sum Distance from day_heldup_register
        $distance_sum_sql = "SELECT SUM(distance) AS total_distance FROM day_heldup_register WHERE op_code = ? AND date = ? AND done = 1";
        $dist_stmt = $conn->prepare($distance_sum_sql);
        if (!$dist_stmt) continue;
        
        $dist_stmt->bind_param("ss", $op_code, $date);
        $dist_stmt->execute();
        $distance_sum = (float)($dist_stmt->get_result()->fetch_assoc()['total_distance'] ?? 0.00); 
        $dist_stmt->close();

        // Calculation Variables
        $slab_limit = (float)$record['slab_limit_distance'];
        $extra_rate_ac = (float)$record['extra_rate_ac'];
        $extra_rate_nonac = (float)$record['extra_rate_nonac'];
        $ac_status = (int)$record['ac'];
        $payment = 0.00;
        $rate_per_km = 0.00;
        
        // Determine the Rate Per KM
        if ($ac_status === 1) {
            $rate_per_km = $extra_rate_ac;
        } else {
            $rate_per_km = $extra_rate_nonac;
        }
        
        // FINAL PAYMENT LOGIC: max(Actual distance, Slab limit) * Rate
        $payment_distance = max($distance_sum, $slab_limit);
        $payment = $payment_distance * $rate_per_km;

        // Aggregate monthly summary
        if (!isset($monthly_summary[$op_code])) {
            $monthly_summary[$op_code] = [
                'op_code' => $op_code,
                'vehicle_no' => $vehicle_no, 
                'total_payment' => 0.00,
                'total_days' => 0,
                'total_actual_distance' => 0.00,
            ];
        }
        
        $monthly_summary[$op_code]['total_payment'] += $payment;
        $monthly_summary[$op_code]['total_days']++;
        $monthly_summary[$op_code]['total_actual_distance'] += $distance_sum;
    }

    return $monthly_summary;
}

// --- 3. Generate Report Data ---
$report_data = calculate_day_heldup_payments_for_report($conn, $filterMonthNum, $filterYear);
$conn->close();

if (isset($report_data['error'])) {
    die("Error generating report: " . htmlspecialchars($report_data['error']));
}

// --- 4. EXCEL GENERATION START (Styled) ---
$filename = "Day_Heldup_Summary_{$monthName}_{$filterYear}.xls";

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
                <th colspan="5" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">
                    Day Heldup Payments Summary - <?php echo "$monthName $filterYear"; ?>
                </th>
            </tr>
            <tr>
                <th style="background-color: #ADD8E6; font-weight: bold;">OP Code</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Vehicle No</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Days Paid</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Distance (km)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Payment (LKR)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $grand_total_payment = 0;
            $grand_total_distance = 0;
            
            if (!empty($report_data)): 
                foreach ($report_data as $row): 
                    $grand_total_payment += $row['total_payment'];
                    $grand_total_distance += $row['total_actual_distance'];
            ?>
                    <tr>
                        <td class="text-format"><?php echo htmlspecialchars($row['op_code']); ?></td>
                        <td><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                        <td style="text-align:center;"><?php echo $row['total_days']; ?></td>
                        <td style="text-align:center;"><?php echo number_format($row['total_actual_distance'], 2); ?></td>
                        <td class="currency-format" style="font-weight:bold;">
                            <?php echo $row['total_payment']; ?>
                        </td>
                    </tr>
            <?php 
                endforeach; 
                // Grand Total Row
            ?>
                <tr>
                    <td colspan="3" style="text-align: right; font-weight: bold;">GRAND TOTALS</td>
                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($grand_total_distance, 2); ?></td>
                    <td class="currency-format" style="font-weight: bold; border-top: 2px solid black;">
                        <?php echo $grand_total_payment; ?>
                    </td>
                </tr>

            <?php else: ?>
                <tr>
                    <td colspan="5" style="text-align:center;">No records found for this period.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>