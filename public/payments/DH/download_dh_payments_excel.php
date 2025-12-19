<?php
// download_dh_payments_excel.php - Generates a monthly summary report using the new distance logic

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

// --- 2. CORE CALCULATION FUNCTION (Same logic as day_heldup_payments.php) ---
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
        $distance_sum_sql = "
            SELECT 
                SUM(distance) AS total_distance 
            FROM 
                day_heldup_register 
            WHERE 
                op_code = ? AND date = ? AND done = 1
        ";
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

// --- 4. Prepare CSV Output ---

// Set headers for download
$filename = "Day_Heldup_Summary_{$monthName}_{$filterYear}.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Title Row
fputcsv($output, ["Day Heldup Payments Summary - {$monthName} {$filterYear}"]);
fputcsv($output, [""]); // Blank row

// Header Row
$header = [
    'OP CODE', 
    'VEHICLE NO', 
    'DAYS PAID', 
    'TOTAL DISTANCE (KM)', 
    'TOTAL PAYMENT (LKR)'
];
fputcsv($output, $header);

// Data Rows
$grand_total_payment = 0;
$grand_total_distance = 0;

foreach ($report_data as $data) {
    $row = [
        $data['op_code'],
        $data['vehicle_no'],
        number_format($data['total_days']),
        number_format($data['total_actual_distance'], 2),
        number_format($data['total_payment'], 2)
    ];
    fputcsv($output, $row);

    $grand_total_payment += $data['total_payment'];
    $grand_total_distance += $data['total_actual_distance'];
}

// Grand Total Row
fputcsv($output, [""]); // Blank row
fputcsv($output, [
    'GRAND TOTALS', 
    '', 
    '', 
    number_format($grand_total_distance, 2), 
    number_format($grand_total_payment, 2)
]);

fclose($output);
exit;
?>