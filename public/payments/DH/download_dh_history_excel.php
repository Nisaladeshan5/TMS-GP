<?php
// download_dh_history_excel.php
// Exports Day Heldup Monthly Payments History to Excel with a Yellow Title Header
require_once '../../../includes/session_check.php';

// Check if logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    exit("Access Denied");
}

include('../../../includes/db.php');

// Get Parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : 0;

if ($month == 0 || $year == 0) {
    exit("Invalid Date Selection");
}

// Format Month Name for the Title
$dateObj = DateTime::createFromFormat('!m', $month);
$monthName = $dateObj->format('F');
$reportTitle = "Day Heldup Monthly Payments History - " . $monthName . " " . $year;

// Fetch Data
$sql = "
    SELECT 
        mph.op_code,
        mph.month,
        mph.year,
        mph.total_distance,
        mph.monthly_payment,
        os.vehicle_no
    FROM 
        monthly_payments_dh mph
    LEFT JOIN 
        op_services os ON mph.op_code = os.op_code
    WHERE 
        mph.month = ? 
    AND 
        mph.year = ? 
    ORDER BY 
        mph.op_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$result = $stmt->get_result();

// --- EXCEL HEADERS ---
$filename = "Day_Heldup_Payments_" . $year . "_" . str_pad($month, 2, '0', STR_PAD_LEFT) . ".xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// --- OUTPUT TABLE DATA ---
echo '<table border="1">';

// 1. YELLOW TITLE HEADER ROW (Colspan 4 because there are 4 columns)
echo '<tr>';
echo '<td colspan="3" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">';
echo $reportTitle;
echo '</td>';
echo '</tr>';

// 2. COLUMN HEADERS ROW (BLUE)
echo '<tr style="color:white; font-weight:bold; text-align:center;">';
echo '<th style="background-color:#4F81BD; width: 250px;">Op Code (Vehicle No)</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Total Distance (km)</th>';
echo '<th style="background-color:#4F81BD; width: 180px;">Monthly Payment (LKR)</th>';
echo '</tr>';

// 3. DATA ROWS
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        
        // Op Code + Vehicle No
        echo '<td style="vertical-align: middle;">' . htmlspecialchars($row['op_code']) . ' (' . htmlspecialchars($row['vehicle_no'] ?? 'N/A') . ')</td>';
        
        // Distance (Right Align)
        echo '<td style="text-align:right; vertical-align: middle;">' . number_format($row['total_distance'], 2) . '</td>';
        
        // Payment (Right Align + Bold)
        echo '<td style="text-align:right; font-weight:bold; vertical-align: middle;">' . number_format($row['monthly_payment'], 2) . '</td>';
        
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="3" style="text-align:center; padding: 20px;">No records found for this period.</td></tr>';
}

echo '</table>';

$stmt->close();
$conn->close();
?>