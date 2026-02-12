<?php
// download_ne_history_excel.php
// Exports Night Emergency Monthly Payments History to Excel
require_once '../../includes/session_check.php';

// Check if logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    exit("Access Denied");
}

include('../../includes/db.php');

// Get Parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : 0;

if ($month == 0 || $year == 0) {
    exit("Invalid Date Selection");
}

// Format Month Name for the Title
$dateObj = DateTime::createFromFormat('!m', $month);
$monthName = $dateObj->format('F');
$reportTitle = "Night Emergency Monthly Payments History - " . $monthName . " " . $year;

// Fetch Data
$sql = "
    SELECT 
        mpn.op_code,
        mpn.supplier_code,
        mpn.month,
        mpn.year,
        mpn.monthly_payment,
        mpn.worked_days,
        s.supplier AS supplier_name,
        os.vehicle_no
    FROM 
        monthly_payment_ne mpn
    LEFT JOIN 
        supplier s ON mpn.supplier_code = s.supplier_code
    LEFT JOIN 
        op_services os ON mpn.op_code = os.op_code
    WHERE 
        mpn.month = ? 
    AND 
        mpn.year = ? 
    ORDER BY 
        mpn.op_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$result = $stmt->get_result();

// --- EXCEL HEADERS ---
$filename = "Night_Emergency_Payments_" . $year . "_" . str_pad($month, 2, '0', STR_PAD_LEFT) . ".xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// --- OUTPUT TABLE DATA ---
echo '<table border="1">';

// 1. YELLOW TITLE HEADER ROW (Colspan 5)
echo '<tr>';
echo '<td colspan="5" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">';
echo $reportTitle;
echo '</td>';
echo '</tr>';

// 2. COLUMN HEADERS ROW (BLUE)
echo '<tr style="color:white; font-weight:bold; text-align:center;">';
echo '<th style="background-color:#4F81BD; width: 200px;">Op Code (Vehicle)</th>';
echo '<th style="background-color:#4F81BD; width: 250px;">Supplier</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Supplier Code</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Worked Days</th>';
echo '<th style="background-color:#4F81BD; width: 180px;">Monthly Payment (LKR)</th>';
echo '</tr>';

// 3. DATA ROWS
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        
        // Op Code + Vehicle
        echo '<td style="vertical-align: middle;">' . htmlspecialchars($row['op_code']) . ' (' . htmlspecialchars($row['vehicle_no'] ?? 'N/A') . ')</td>';
        
        // Supplier
        echo '<td style="vertical-align: middle;">' . htmlspecialchars($row['supplier_name'] ?? 'N/A') . '</td>';
        
        // Supplier Code
        echo '<td style="text-align:left; vertical-align: middle;">' . htmlspecialchars($row['supplier_code']) . '</td>';
        
        // Worked Days (Right Align)
        echo '<td style="text-align:right; vertical-align: middle;">' . number_format($row['worked_days']) . '</td>';
        
        // Payment (Right Align + Bold)
        echo '<td style="text-align:right; font-weight:bold; vertical-align: middle;">' . number_format($row['monthly_payment'], 2) . '</td>';
        
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="5" style="text-align:center; padding: 20px;">No records found for this period.</td></tr>';
}

echo '</table>';

$stmt->close();
$conn->close();
?>