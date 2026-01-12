<?php
// download_nh_daily_excel.php - Exports Night Heldup Daily Details to CSV (With Night Shift Logic)

// 1. Session & DB Setup
require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    exit("Access Denied");
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// 2. Get Input Parameters
$op_code = $_GET['op_code'] ?? '';
$monthNum = $_GET['month_num'] ?? date('m');
$year = $_GET['year'] ?? date('Y');

if (empty($op_code)) {
    die("Invalid Op Code");
}

// Format Dates
$monthNum = str_pad($monthNum, 2, '0', STR_PAD_LEFT);
$filterYearMonth = "$year-$monthNum";
$filename = "Night_Heldup_Daily_{$op_code}_{$year}_{$monthNum}.csv";

// 3. Set Headers for Download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

// 4. CSV Column Headers
fputcsv($output, [
    'Date', 
    'Vehicle No', 
    'Rate (LKR)', 
    'Slab Limit (km)', 
    'Daily Total Distance (km)', 
    'Payment Distance (km)', 
    'Daily Payment (LKR)',
    'Calculation Method'
]);

// 5. Fetch Rates & Slab for this Op Code
$service_sql = "SELECT slab_limit_distance, extra_rate AS rate_per_km FROM op_services WHERE op_code = ? LIMIT 1";
$svc_stmt = $conn->prepare($service_sql);
$svc_stmt->bind_param("s", $op_code);
$svc_stmt->execute();
$service_data = $svc_stmt->get_result()->fetch_assoc();
$svc_stmt->close();

// Defaults if not found
$slab_limit = (float)($service_data['slab_limit_distance'] ?? 0);
$rate_per_km = (float)($service_data['rate_per_km'] ?? 0);

// 6. Fetch Data (Grouped by Night Shift Date)
// Logic: If time < 7AM, shift date is yesterday.
$sql = "
    SELECT 
        IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date) as effective_date,
        MAX(nh.vehicle_no) as vehicle_no, 
        SUM(nh.distance) AS total_daily_distance
    FROM 
        nh_register nh
    WHERE 
        nh.op_code = ? 
        AND nh.done = 1 
    GROUP BY 
        effective_date
    HAVING 
        DATE_FORMAT(effective_date, '%Y-%m') = ?
    ORDER BY 
        effective_date ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $op_code, $filterYearMonth);
$stmt->execute();
$result = $stmt->get_result();

// 7. Loop and Calculate Logic
while ($row = $result->fetch_assoc()) {
    $actual_distance = (float)$row['total_daily_distance'];
    $payable_distance = 0;
    $method = '';

    // Calculation Logic
    if (strpos($op_code, 'NH') === 0) {
        // NH: Apply Slab Limit
        if ($actual_distance < $slab_limit) {
            $payable_distance = $slab_limit;
            $method = 'Min Guarantee (Slab)';
        } else {
            $payable_distance = $actual_distance;
            $method = 'Actual Distance';
        }
    } elseif (strpos($op_code, 'EV') === 0) {
        // EV: Always Actual
        $payable_distance = $actual_distance;
        $method = 'Actual (EV)';
    } else {
        // Default
        $payable_distance = $actual_distance;
        $method = 'Actual';
    }

    $daily_payment = $payable_distance * $rate_per_km;

    // Write Row to CSV
    fputcsv($output, [
        $row['effective_date'], // Use the calculated effective date
        $row['vehicle_no'],
        number_format($rate_per_km, 2),
        number_format($slab_limit, 2),
        number_format($actual_distance, 2),
        number_format($payable_distance, 2),
        number_format($daily_payment, 2),
        $method
    ]);
}

$stmt->close();
$conn->close();
fclose($output);
exit();
?>