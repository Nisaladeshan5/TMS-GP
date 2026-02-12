<?php
// download_sub_history_excel.php
// Exports Sub Route Monthly Payments History to Excel with a Yellow Title Header
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

// Fetch Data (Updated SQL with Fixed, Fuel, Distance)
$sql = "
    SELECT 
        mps.sub_route_code,
        mps.supplier_code,
        mps.no_of_attendance_days,
        mps.monthly_payment,
        mps.fixed_rate,
        mps.fuel_rate,
        mps.distance,
        sr.sub_route AS sub_route_name,
        sr.vehicle_no,
        s.supplier AS supplier_name
    FROM 
        monthly_payments_sub mps
    LEFT JOIN 
        sub_route sr ON mps.sub_route_code = sr.sub_route_code
    LEFT JOIN 
        supplier s ON mps.supplier_code = s.supplier_code
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
$filename = "Sub_Route_Payments_History_" . $year . "_" . str_pad($month, 2, '0', STR_PAD_LEFT) . ".xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// --- OUTPUT TABLE DATA ---
echo '<table border="1">';

// 1. YELLOW TITLE HEADER ROW (Colspan 7 because there are 7 columns)
echo '<tr>';
echo '<td colspan="7" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">';
echo $reportTitle;
echo '</td>';
echo '</tr>';

// 2. COLUMN HEADERS ROW (BLUE)
echo '<tr style="color:white; font-weight:bold; text-align:center;">';
echo '<th style="background-color:#4F81BD; width: 250px;">Sub Route (Vehicle No)</th>';
echo '<th style="background-color:#4F81BD; width: 200px;">Supplier</th>';
echo '<th style="background-color:#4F81BD; width: 100px;">Fixed Rate</th>';
echo '<th style="background-color:#4F81BD; width: 100px;">Fuel Rate</th>';
echo '<th style="background-color:#4F81BD; width: 100px;">Distance</th>';
echo '<th style="background-color:#4F81BD; width: 100px;">Days</th>';
echo '<th style="background-color:#4F81BD; width: 180px;">Payment (LKR)</th>';
echo '</tr>';

// 3. DATA ROWS
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        
        // Sub Route + Vehicle No
        echo '<td style="vertical-align: middle;">' . htmlspecialchars($row['sub_route_name']) . ' (' . htmlspecialchars($row['vehicle_no']) . ')</td>';
        
        // Supplier
        echo '<td style="vertical-align: middle;">' . htmlspecialchars($row['supplier_name'] ?? 'N/A') . '</td>';
        
        // Fixed Rate (Right)
        echo '<td style="text-align:right; vertical-align: middle;">' . number_format($row['fixed_rate'], 2) . '</td>';

        // Fuel Rate (Right)
        echo '<td style="text-align:right; vertical-align: middle; color: #0000FF;">' . number_format($row['fuel_rate'], 2) . '</td>';

        // Distance (Right)
        echo '<td style="text-align:right; vertical-align: middle;">' . number_format($row['distance'], 2) . '</td>';

        // Attendance Days (Center)
        echo '<td style="text-align:center; vertical-align: middle;">' . number_format($row['no_of_attendance_days'], 0) . '</td>';
        
        // Total Payment (Right + Bold)
        echo '<td style="text-align:right; font-weight:bold; vertical-align: middle;">' . number_format($row['monthly_payment'], 2) . '</td>';
        
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="7" style="text-align:center; padding: 20px;">No records found for this period.</td></tr>';
}

echo '</table>';

$stmt->close();
$conn->close();
?>