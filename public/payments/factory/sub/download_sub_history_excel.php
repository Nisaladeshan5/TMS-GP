<?php
// download_sub_history_excel.php
// Exports Sub Route Monthly Payments History & Extra Trips Breakdown to Excel
require_once '../../../../includes/session_check.php';

// Check if logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    exit("Access Denied");
}

include('../../../../includes/db.php');

// Get Parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : 0;

if ($month == 0 || $year == 0) {
    exit("Invalid Date Selection");
}

// Format Month Name for the Title
$dateObj = DateTime::createFromFormat('!m', $month);
$monthName = $dateObj->format('F');
$reportTitle = "Sub Route Monthly Payments History - " . $monthName . " " . $year;

// =======================================================================
// 1. FETCH MAIN SUMMARY DATA (JOINING SUB, EV, ROUTE, and SUPPLIER)
// =======================================================================
$sql = "
    SELECT 
        mps.sub_route_code,
        mps.supplier_code,
        mps.no_of_attendance_days,
        mps.monthly_payment AS sub_payment,
        mps.fixed_rate,
        mps.fuel_rate,
        mps.distance,
        sr.sub_route AS sub_route_name,
        sr.vehicle_no,
        s.supplier AS supplier_name,
        ev.monthly_payment AS ev_payment,
        ev.total_distance AS ev_distance
    FROM 
        monthly_payments_sub mps
    LEFT JOIN 
        sub_route sr ON mps.sub_route_code = sr.sub_route_code
    LEFT JOIN 
        supplier s ON mps.supplier_code = s.supplier_code
    LEFT JOIN 
        monthly_payments_ev ev ON 
            mps.sub_route_code = ev.code AND /* Extra table එකේ sub route code එක save වෙන්නේ code column එකේ */
            mps.supplier_code = ev.supplier_code AND 
            mps.month = ev.month AND 
            mps.year = ev.year
    WHERE 
        mps.month = ? 
    AND 
        mps.year = ? 
    ORDER BY 
        sr.sub_route ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$result = $stmt->get_result();

// --- EXCEL HEADERS ---
$filename = "Sub_Route_Payments_With_Extra_" . $year . "_" . str_pad($month, 2, '0', STR_PAD_LEFT) . ".xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// --- OUTPUT MAIN SUMMARY TABLE ---
echo '<table border="1" style="font-family: Arial, sans-serif; border-collapse: collapse;">';

// 1. YELLOW TITLE HEADER ROW
echo '<tr>';
echo '<td colspan="10" style="font-size: 18px; font-weight: bold; text-align: center; background-color: #FFFF00; padding: 15px;">';
echo $reportTitle;
echo '</td>';
echo '</tr>';

// 2. ACCOUNTS INSTRUCTION ROW (Light Green Background)
echo '<tr>';
echo '<td colspan="10" style="font-size: 15px; font-weight: bold; text-align: center; background-color: #EBF1DE; color: #005c29; border: 2px solid #00B050; padding: 10px;">';
echo 'FOR ACCOUNTS DEPARTMENT: Only use the Green Column (Working Days Payment)';
echo '</td>';
echo '</tr>';

// 3. TRANSPORT WARNING ROW (Light Red Background)
echo '<tr>';
echo '<td colspan="10" style="font-size: 15px; font-weight: bold; text-align: center; background-color: #F2DCDB; color: #C00000; border: 2px solid #C00000; padding: 10px;">';
echo 'WARNING - FOR TRANSPORT DEPARTMENT ONLY: The Red Columns (Extra Dist, Extra Payment, Grand Total) are STRICTLY for internal records. DO NOT send these values to Accounts!';
echo '</td>';
echo '</tr>';

// 4. COLUMN HEADERS ROW
echo '<tr style="color:white; font-weight:bold; text-align:center;">';
echo '<th style="background-color:#4F81BD; width: 250px; padding: 8px;">Sub Route (Vehicle No)</th>'; 
echo '<th style="background-color:#4F81BD; width: 200px; padding: 8px;">Supplier</th>';
echo '<th style="background-color:#4F81BD; width: 100px; padding: 8px;">Fixed Rate</th>';
echo '<th style="background-color:#4F81BD; width: 100px; padding: 8px;">Fuel Rate</th>';
echo '<th style="background-color:#4F81BD; width: 100px; padding: 8px;">Distance</th>';
echo '<th style="background-color:#4F81BD; width: 100px; padding: 8px;">Days</th>';

// Official Payment (Green Theme - Accounts)
echo '<th style="background-color:#00B050; width: 150px; font-size: 13px; padding: 8px;">Working Days Payment (LKR)<br>(Accounts Valid)</th>';

// Transport Extra Data (Red Theme)
echo '<th style="background-color:#C0504D; width: 130px; padding: 8px;">Extra Dist (km)</th>';
echo '<th style="background-color:#C0504D; width: 130px; padding: 8px;">Extra Payment (LKR)</th>';
echo '<th style="background-color:#C00000; width: 150px; font-size: 13px; padding: 8px;">Grand Total (LKR)<br>(Transport Only)</th>';
echo '</tr>';

// 5. DATA ROWS
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $ev_pay = (float)($row['ev_payment'] ?? 0);
        $ev_dist = (float)($row['ev_distance'] ?? 0);
        $combined_total = (float)$row['sub_payment'] + $ev_pay;

        $route_display = htmlspecialchars($row['sub_route_name']) . ' (' . htmlspecialchars($row['vehicle_no']) . ')';
        $supplier_display = !empty($row['supplier_name']) ? htmlspecialchars($row['supplier_name']) : htmlspecialchars($row['supplier_code'] ?? '');

        echo '<tr>';
        // Base Info
        echo '<td style="vertical-align: middle; padding: 5px;">' . $route_display . '</td>';
        echo '<td style="vertical-align: middle; padding: 5px;">' . $supplier_display . '</td>';
        echo '<td style="text-align:right; vertical-align: middle; padding: 5px;">' . number_format($row['fixed_rate'], 2) . '</td>';
        echo '<td style="text-align:right; vertical-align: middle; padding: 5px; color: #0000FF;">' . number_format($row['fuel_rate'], 2) . '</td>';
        echo '<td style="text-align:right; vertical-align: middle; padding: 5px;">' . number_format($row['distance'], 2) . '</td>';
        echo '<td style="text-align:center; vertical-align: middle; padding: 5px;">' . number_format($row['no_of_attendance_days'], 0) . '</td>';
        
        // ACCOUNTS VALID PAYMENT (Green Background) - This is the base monthly payment
        echo '<td style="text-align:right; font-weight:bold; vertical-align: middle; padding: 5px; background-color:#EBF1DE; border: 2px solid #00B050; color:#005c29;">' . number_format($row['sub_payment'], 2) . '</td>';
        
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
    echo '<tr><td colspan="10" style="text-align:center; padding: 20px;">No history records found for ' . $monthName . ' ' . $year . '.</td></tr>';
}

echo '</table>';
$stmt->close();


// =======================================================================
// 2. FETCH AND DISPLAY EXTRA TRIPS BREAKDOWN TABLE (SUB ROUTES)
// =======================================================================

// Add spacing between tables
echo '<br><br>';

echo '<table border="1" style="font-family: Arial, sans-serif; border-collapse: collapse;">';

// Title for breakdown table
echo '<tr>';
echo '<td colspan="7" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #F2DCDB; color: #C00000; padding: 10px; border: 2px solid #C00000;">';
echo 'Extra Trips Breakdown for Sub Routes (Transport Department Reference) - ' . $monthName . ' ' . $year;
echo '</td>';
echo '</tr>';

// Headers
echo '<tr style="color:white; font-weight:bold; text-align:center; background-color:#C0504D;">';
echo '<th style="padding: 8px; width: 100px;">Date</th>';
echo '<th style="padding: 8px; width: 200px;">Sub Route</th>';
echo '<th style="padding: 8px; width: 200px;">Supplier</th>';
echo '<th style="padding: 8px; width: 150px;">From</th>';
echo '<th style="padding: 8px; width: 150px;">To</th>';
echo '<th style="padding: 8px; width: 100px;">Distance (km)</th>';
echo '<th style="padding: 8px; width: 250px;">Remarks</th>';
echo '</tr>';

// Query for Extra Vehicle Register (Sub Routes Specific)
// Filtering where sub_route is not empty/null
$extra_sql = "
    SELECT 
        evr.date, 
        evr.sub_route AS route_code, 
        evr.supplier_code, 
        s.supplier AS supplier_name,
        evr.from_location, 
        evr.to_location, 
        evr.distance, 
        evr.remarks
    FROM extra_vehicle_register evr
    LEFT JOIN supplier s ON evr.supplier_code = s.supplier_code
    WHERE MONTH(evr.date) = ? 
    AND YEAR(evr.date) = ? 
    AND evr.done = 1 
    AND evr.sub_route IS NOT NULL 
    AND evr.sub_route != '' /* Sub Routes Only */
    ORDER BY evr.date ASC, evr.sub_route ASC
";

$extra_stmt = $conn->prepare($extra_sql);
$extra_stmt->bind_param("ii", $month, $year);
$extra_stmt->execute();
$extra_result = $extra_stmt->get_result();

if ($extra_result && $extra_result->num_rows > 0) {
    while ($extra = $extra_result->fetch_assoc()) {
        $ex_supplier = !empty($extra['supplier_name']) ? htmlspecialchars($extra['supplier_name']) : htmlspecialchars($extra['supplier_code'] ?? '');
        $ex_route = htmlspecialchars($extra['route_code'] ?? '');

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
    echo '<tr><td colspan="7" style="text-align:center; padding: 20px;">No extra trips recorded for sub routes this period.</td></tr>';
}

echo '</table>';
$extra_stmt->close();
$conn->close();
?>