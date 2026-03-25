<?php
// download_history_excel.php
// Exports Staff Monthly Payments History & Extra Trips Breakdown to Excel
require_once '../../includes/session_check.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// Get Parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

// Format Month Name for the Title
$dateObj = DateTime::createFromFormat('!m', $month);
$monthName = $dateObj->format('F');
$reportTitle = "Staff Monthly Payments History - " . $monthName . " " . $year;

// =======================================================================
// 1. FETCH MAIN SUMMARY DATA
// =======================================================================
$sql = "
    SELECT 
        m.route_code, 
        m.supplier_code, 
        s.supplier as supplier_name, /* Supplier table එකෙන් නම ගන්නවා */
        m.fixed_amount, 
        m.route_distance, 
        m.fuel_amount, 
        m.total_distance AS sf_distance, 
        m.monthly_payment AS sf_payment,
        r.route AS route_name,
        ev.monthly_payment AS ev_payment,
        ev.total_distance AS ev_distance
    FROM monthly_payments_sf m
    JOIN route r ON m.route_code = r.route_code
    LEFT JOIN supplier s ON m.supplier_code = s.supplier_code /* Supplier table එක join කළා */
    LEFT JOIN monthly_payments_ev ev ON 
        m.route_code = ev.code AND 
        m.supplier_code = ev.supplier_code AND 
        m.month = ev.month AND 
        m.year = ev.year
    WHERE m.month = ? 
    AND m.year = ? 
    AND SUBSTRING(m.route_code, 5, 1) = 'S' /* Staff routes විතරයි */
    ORDER BY m.route_code ASC, m.supplier_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$result = $stmt->get_result();

// --- EXCEL HEADERS ---
$filename = "Staff_Payments_With_Extra_" . $year . "_" . str_pad($month, 2, '0', STR_PAD_LEFT) . ".xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// --- OUTPUT MAIN SUMMARY TABLE ---
echo '<table border="1" style="font-family: Arial, sans-serif; border-collapse: collapse;">';

// 1. YELLOW TITLE HEADER ROW
echo '<tr>';
echo '<td colspan="11" style="font-size: 18px; font-weight: bold; text-align: center; background-color: #FFFF00; padding: 15px;">';
echo $reportTitle;
echo '</td>';
echo '</tr>';

// 2. ACCOUNTS INSTRUCTION ROW (Light Green Background)
echo '<tr>';
echo '<td colspan="11" style="font-size: 15px; font-weight: bold; text-align: center; background-color: #EBF1DE; color: #005c29; border: 2px solid #00B050; padding: 10px;">';
echo 'FOR ACCOUNTS DEPARTMENT: Only use the Green Column (Working Days Payment)';
echo '</td>';
echo '</tr>';

// 3. TRANSPORT WARNING ROW (Light Red Background)
echo '<tr>';
echo '<td colspan="11" style="font-size: 15px; font-weight: bold; text-align: center; background-color: #F2DCDB; color: #C00000; border: 2px solid #C00000; padding: 10px;">';
echo 'WARNING - FOR TRANSPORT DEPARTMENT ONLY: The Red Columns (Extra Dist, Extra Payment, Grand Total) are STRICTLY for internal records. DO NOT send these values to Accounts!';
echo '</td>';
echo '</tr>';

// 4. COLUMN HEADERS ROW
echo '<tr style="color:white; font-weight:bold; text-align:center;">';
echo '<th style="background-color:#4F81BD; width: 250px; padding: 8px;">Route</th>'; 
echo '<th style="background-color:#4F81BD; width: 250px; padding: 8px;">Supplier</th>';
echo '<th style="background-color:#4F81BD; width: 120px; padding: 8px;">Route Dist (km)</th>';
echo '<th style="background-color:#4F81BD; width: 120px; padding: 8px;">Fixed Amt (LKR)</th>';
echo '<th style="background-color:#4F81BD; width: 120px; padding: 8px;">Fuel Amt (LKR)</th>';
echo '<th style="background-color:#4F81BD; width: 120px; padding: 8px;">Working Days Dist (km)</th>';
echo '<th style="background-color:#4F81BD; width: 120px; padding: 8px;">Working Days</th>';
echo '<th style="background-color:#00B050; width: 150px; font-size: 13px; padding: 8px;">Working Days Payment (LKR)<br>(Accounts Valid)</th>';
echo '<th style="background-color:#C0504D; width: 130px; padding: 8px;">Extra Dist (km)</th>';
echo '<th style="background-color:#C0504D; width: 130px; padding: 8px;">Extra Payment (LKR)</th>';
echo '<th style="background-color:#C00000; width: 150px; font-size: 13px; padding: 8px;">Grand Total (LKR)<br>(Transport Only)</th>';
echo '</tr>';

// 5. DATA ROWS
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $ev_pay = $row['ev_payment'] ?? 0;
        $ev_dist = $row['ev_distance'] ?? 0;
        $combined_total = $row['sf_payment'] + $ev_pay;
        
        // Ensure no division by zero error for working days
        $working_days = ($row['route_distance'] > 0) ? ($row['sf_distance'] / $row['route_distance']) : 0; 

        // Supplier display logic
        $supplier_display = !empty($row['supplier_name']) ? htmlspecialchars($row['supplier_name']) : htmlspecialchars($row['supplier_code'] ?? '');

        echo '<tr>';
        // Base Info
        echo '<td style="vertical-align: middle; padding: 5px;">' . htmlspecialchars($row['route_name']) . '</td>';
        echo '<td style="vertical-align: middle; padding: 5px;">' . $supplier_display . '</td>';
        echo '<td style="text-align:right; vertical-align: middle; padding: 5px;">' . number_format($row['route_distance'], 2) . '</td>';
        echo '<td style="text-align:right; vertical-align: middle; padding: 5px;">' . number_format($row['fixed_amount'], 2) . '</td>';
        echo '<td style="text-align:right; vertical-align: middle; padding: 5px;">' . number_format($row['fuel_amount'], 2) . '</td>';
        echo '<td style="text-align:right; vertical-align: middle; padding: 5px;">' . number_format($row['sf_distance'], 2) . '</td>';
        echo '<td style="text-align:right; vertical-align: middle; padding: 5px;">' . ($working_days > 0 ? round($working_days, 1) : '-') . '</td>';
        
        // ACCOUNTS VALID PAYMENT (Green Background)
        echo '<td style="text-align:right; font-weight:bold; vertical-align: middle; padding: 5px; background-color:#EBF1DE; border: 2px solid #00B050; color:#005c29;">' . number_format($row['sf_payment'], 2) . '</td>';
        
        // TRANSPORT EXTRA DATA (Red Borders)
        echo '<td style="text-align:right; vertical-align: middle; padding: 5px; color:#C00000; border-left: 2px solid #C00000;">' . ($ev_dist > 0 ? number_format($ev_dist, 2) : '-') . '</td>';
        echo '<td style="text-align:right; vertical-align: middle; padding: 5px; color:#C00000;">' . ($ev_pay > 0 ? number_format($ev_pay, 2) : '-') . '</td>';
        
        // TRANSPORT GRAND TOTAL (Red Background)
        echo '<td style="text-align:right; font-weight:bold; vertical-align: middle; padding: 5px; background-color:#F2DCDB; border: 2px solid #C00000; color:#C00000;">';
        echo number_format($combined_total, 2);
        echo '</td>';
        
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="11" style="text-align:center; padding: 20px;">No history records found for ' . $monthName . ' ' . $year . '.</td></tr>';
}

echo '</table>';
$stmt->close();

// =======================================================================
// 2. FETCH AND DISPLAY EXTRA TRIPS BREAKDOWN TABLE (STAFF)
// =======================================================================

// Add spacing between tables
echo '<br><br>';

echo '<table border="1" style="font-family: Arial, sans-serif; border-collapse: collapse;">';

// Title for breakdown table
echo '<tr>';
echo '<td colspan="7" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #F2DCDB; color: #C00000; padding: 10px; border: 2px solid #C00000;">';
echo 'Extra Trips Breakdown (Transport Department Reference) - ' . $monthName . ' ' . $year;
echo '</td>';
echo '</tr>';

// Headers
echo '<tr style="color:white; font-weight:bold; text-align:center; background-color:#C0504D;">';
echo '<th style="padding: 8px; width: 100px;">Date</th>';
echo '<th style="padding: 8px; width: 200px;">Route</th>';
echo '<th style="padding: 8px; width: 200px;">Supplier</th>';
echo '<th style="padding: 8px; width: 150px;">From</th>';
echo '<th style="padding: 8px; width: 150px;">To</th>';
echo '<th style="padding: 8px; width: 100px;">Distance (km)</th>';
echo '<th style="padding: 8px; width: 250px;">Remarks</th>';
echo '</tr>';

// Query for Extra Vehicle Register (Staff Specific)
$extra_sql = "
    SELECT 
        evr.date, 
        evr.route AS route_code, 
        r.route AS route_name,
        evr.supplier_code, 
        s.supplier AS supplier_name,
        evr.from_location, 
        evr.to_location, 
        evr.distance, 
        evr.remarks
    FROM extra_vehicle_register evr
    LEFT JOIN route r ON evr.route = r.route_code
    LEFT JOIN supplier s ON evr.supplier_code = s.supplier_code
    WHERE MONTH(evr.date) = ? 
    AND YEAR(evr.date) = ? 
    AND evr.done = 1 
    AND SUBSTRING(evr.route, 5, 1) = 'S' /* Staff Routes Only */
    ORDER BY evr.date ASC, evr.route ASC
";

$extra_stmt = $conn->prepare($extra_sql);
$extra_stmt->bind_param("ii", $month, $year);
$extra_stmt->execute();
$extra_result = $extra_stmt->get_result();

if ($extra_result && $extra_result->num_rows > 0) {
    while ($extra = $extra_result->fetch_assoc()) {
        $ex_supplier = !empty($extra['supplier_name']) ? htmlspecialchars($extra['supplier_name']) : htmlspecialchars($extra['supplier_code'] ?? '');
        $ex_route = !empty($extra['route_name']) ? htmlspecialchars($extra['route_name']) : htmlspecialchars($extra['route_code'] ?? '');

        echo '<tr>';
        echo '<td style="text-align:center; padding: 5px;">' . date('Y-m-d', strtotime($extra['date'])) . '</td>';
        echo '<td style="padding: 5px;">' . $ex_route . '</td>';
        echo '<td style="padding: 5px;">' . $ex_supplier . '</td>';
        echo '<td style="padding: 5px;">' . htmlspecialchars($extra['from_location'] ?? '') . '</td>'; // Null safe
        echo '<td style="padding: 5px;">' . htmlspecialchars($extra['to_location'] ?? '') . '</td>';   // Null safe
        echo '<td style="text-align:right; padding: 5px;">' . number_format($extra['distance'], 2) . '</td>';
        echo '<td style="padding: 5px;">' . htmlspecialchars($extra['remarks'] ?? '') . '</td>';       // Null safe
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="7" style="text-align:center; padding: 20px;">No extra trips recorded for this period.</td></tr>';
}

echo '</table>';
$extra_stmt->close();
$conn->close();
?>