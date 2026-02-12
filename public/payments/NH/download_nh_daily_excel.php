<?php
// download_nh_daily_excel.php - Exports Night Heldup Daily Details to Excel (.xls)

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
$filename = "Night_Heldup_Daily_{$op_code}_{$year}_{$monthNum}.xls"; // Changed extension to .xls

// 3. Fetch Rates & Slab for this Op Code
$service_sql = "SELECT slab_limit_distance, extra_rate AS rate_per_km FROM op_services WHERE op_code = ? LIMIT 1";
$svc_stmt = $conn->prepare($service_sql);
$svc_stmt->bind_param("s", $op_code);
$svc_stmt->execute();
$service_data = $svc_stmt->get_result()->fetch_assoc();
$svc_stmt->close();

// Defaults if not found
$slab_limit = (float)($service_data['slab_limit_distance'] ?? 0);
$rate_per_km = (float)($service_data['rate_per_km'] ?? 0);

// 4. Fetch Data (Grouped by Night Shift Date)
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

// --- EXCEL GENERATION STARTS HERE ---

// 5. Set Headers for Excel Download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// 6. Start Outputting HTML Table (Excel interprets this as a spreadsheet)
echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
echo '<head><meta http-equiv="content-type" content="text/plain; charset=UTF-8"/></head>';
echo '<body>';
echo '<table border="1" style="border-collapse: collapse;">';

// Table Header Row with Styling
echo '<thead>';
echo '<tr style="color: #FFFFFF; font-weight: bold;">';
echo '<th style="background-color: #4F81BD; width: 120px; padding: 5px;">Date</th>';
echo '<th style="background-color: #4F81BD; width: 120px; padding: 5px;">Vehicle No</th>';
echo '<th style="background-color: #4F81BD; width: 100px; padding: 5px;">Rate (LKR)</th>';
echo '<th style="background-color: #4F81BD; width: 120px; padding: 5px;">Slab Limit (km)</th>';
echo '<th style="background-color: #4F81BD; width: 150px; padding: 5px;">Daily Total (km)</th>';
echo '<th style="background-color: #4F81BD; width: 150px; padding: 5px;">Payment Dist (km)</th>';
echo '<th style="background-color: #4F81BD; width: 150px; padding: 5px;">Payment (LKR)</th>';
echo '<th style="background-color: #4F81BD; width: 200px; padding: 5px;">Method</th>';
echo '</tr>';
echo '</thead>';

echo '<tbody>';

$total_payment = 0;

// 7. Loop and Calculate Logic
while ($row = $result->fetch_assoc()) {
    $actual_distance = (float)$row['total_daily_distance'];
    $payable_distance = 0;
    $method = '';
    $row_style = '';

    // Calculation Logic
    if (strpos($op_code, 'NH') === 0) {
        // NH: Apply Slab Limit
        if ($actual_distance < $slab_limit) {
            $payable_distance = $slab_limit;
            $method = 'Min Guarantee (Slab)';
            $row_style = 'background-color: #FFFFCC;'; // Light yellow for slab applied
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
    $total_payment += $daily_payment;

    // Output Table Row
    echo "<tr style='{$row_style}'>";
    echo "<td style='text-align: center;'>" . $row['effective_date'] . "</td>";
    echo "<td style='text-align: center; font-weight: bold;'>" . htmlspecialchars($row['vehicle_no']) . "</td>";
    echo "<td style='text-align: center;'>" . number_format($rate_per_km, 2) . "</td>";
    echo "<td style='text-align: right;'>" . number_format($slab_limit, 2) . "</td>";
    echo "<td style='text-align: right;'>" . number_format($actual_distance, 2) . "</td>";
    echo "<td style='text-align: right; font-weight: bold;'>" . number_format($payable_distance, 2) . "</td>";
    echo "<td style='text-align: right; font-weight: bold; color: #0000FF;'>" . number_format($daily_payment, 2) . "</td>";
    echo "<td style='text-align: center;'>" . $method . "</td>";
    echo "</tr>";
}

// Summary Row
echo '<tr style="font-weight: bold;">';
echo '<td colspan="6" style="background-color: #E0E0E0; text-align: right; padding: 5px;">TOTAL MONTHLY PAYMENT:</td>';
echo '<td style="background-color: #E0E0E0; text-align: right; padding: 5px; color: #000080;">' . number_format($total_payment, 2) . '</td>';
echo '<td style="background-color: #E0E0E0;></td>';
echo '</tr>';

echo '</tbody>';
echo '</table>';
echo '</body>';
echo '</html>';

$stmt->close();
$conn->close();
exit();
?>