<?php
// download_nh_payments_excel.php - Exports Night Heldup Monthly Summary to CSV
// Logic: Aggregates daily distance (Shift Based) BEFORE applying slab rates.

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    exit("Access Denied");
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// 2. Get Filter Inputs
$current_year = date('Y');
$current_month = date('m');

$filterYear = $_GET['year'] ?? $current_year;
$filterMonthNum = $_GET['month'] ?? $current_month; 

// Pad month
$filterMonthNum = str_pad($filterMonthNum, 2, '0', STR_PAD_LEFT);
$filterMonthName = date('F', mktime(0, 0, 0, (int)$filterMonthNum, 1));

$filename = "Night_Heldup_Summary_{$filterYear}_{$filterMonthName}.csv";

// 3. Set Headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// 4. CSV Column Headers
fputcsv($output, ['Op Code', 'Vehicle No', 'Days Paid', 'Total Actual Distance (km)', 'Total Payment (LKR)']);

// 5. CALCULATION LOGIC (Grouped by Night Shift Date)
function get_night_payment_data($conn, $month, $year) {
    
    // SQL Logic:
    // 1. Calculate 'effective_date': If time < 07:00:00, count as yesterday.
    // 2. Group by OpCode AND effective_date to sum up shift distance.
    // 3. Filter by the calculated month/year.
    
    $sql = "
        SELECT 
            nh.op_code,
            -- Effective Date Calculation
            IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date) as effective_date,
            MAX(nh.vehicle_no) as vehicle_no, 
            SUM(nh.distance) AS daily_distance,
            os.slab_limit_distance,
            os.extra_rate AS rate_per_km
        FROM 
            nh_register nh
        JOIN 
            op_services os ON nh.op_code = os.op_code
        WHERE 
            nh.done = 1 
            -- Filter using the shift date logic
            AND DATE_FORMAT(IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date), '%Y-%m') = ?
        GROUP BY 
            nh.op_code, effective_date
        ORDER BY 
            nh.op_code ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    
    $filter = "$year-$month";
    $stmt->bind_param("s", $filter);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $summary = [];

    while ($row = $result->fetch_assoc()) {
        $op_code = $row['op_code'];
        $daily_actual_dist = (float)$row['daily_distance'];
        $slab = (float)$row['slab_limit_distance'];
        $rate = (float)$row['rate_per_km'];
        $vehicle = $row['vehicle_no'];
        
        $payable_dist = 0;

        // --- Logic Check (Applied on Daily Total) ---
        if (strpos($op_code, 'NH') === 0) {
            // NH: If Daily Total < Slab, Pay Slab. Else Pay Actual.
            $payable_dist = max($daily_actual_dist, $slab);
        } elseif (strpos($op_code, 'EV') === 0) {
            // EV: Always Pay Actual
            $payable_dist = $daily_actual_dist;
        } else {
            $payable_dist = $daily_actual_dist;
        }

        $daily_payment = $payable_dist * $rate;

        // --- Aggregate to Monthly Summary ---
        if (!isset($summary[$op_code])) {
            $summary[$op_code] = [
                'op_code' => $op_code,
                'vehicle_no' => $vehicle,
                'days_paid' => 0,
                'tot_distance' => 0,
                'tot_payment' => 0
            ];
        }

        $summary[$op_code]['days_paid']++; // Increment day count
        $summary[$op_code]['tot_distance'] += $daily_actual_dist; // Sum actual distance
        $summary[$op_code]['tot_payment'] += $daily_payment; // Sum calculated payment
    }
    
    $stmt->close();
    return $summary;
}

// 6. Get Data & Write Rows
$records = get_night_payment_data($conn, $filterMonthNum, $filterYear);

if (!empty($records)) {
    foreach ($records as $row) {
        fputcsv($output, [
            $row['op_code'],
            $row['vehicle_no'],
            $row['days_paid'],
            number_format($row['tot_distance'], 2),
            number_format($row['tot_payment'], 2)
        ]);
    }
} else {
    fputcsv($output, ['No records found for this month']);
}

fclose($output);
$conn->close();
exit();
?>