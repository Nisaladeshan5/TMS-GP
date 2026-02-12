<?php
// download_f_history_excel.php
// Exports Factory Monthly Payments History to Excel with a Yellow Title Header
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

// Format Month Name for the Title (e.g., "January 2026")
$dateObj = DateTime::createFromFormat('!m', $month);
$monthName = $dateObj->format('F');
$reportTitle = "Factory Monthly Payments History - " . $monthName . " " . $year;

// Fetch Data
$sql = "
    SELECT 
        m.route_code, 
        m.supplier_code, 
        m.fixed_amount, 
        m.route_distance, 
        m.fuel_amount, 
        m.total_distance, 
        m.monthly_payment,
        r.route AS route_name
    FROM monthly_payments_f m
    JOIN route r ON m.route_code = r.route_code
    WHERE m.month = ? 
    AND m.year = ? 
    AND SUBSTRING(m.route_code, 5, 1) = 'F' 
    ORDER BY m.route_code ASC, m.supplier_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$result = $stmt->get_result();

// --- EXCEL HEADERS ---
$filename = "Factory_Payments_" . $year . "_" . str_pad($month, 2, '0', STR_PAD_LEFT) . ".xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// --- OUTPUT TABLE DATA ---
echo '<table border="1">';

// 1. YELLOW TITLE HEADER ROW
// colspan="6" දැම්මේ columns 6ක් තියෙන නිසා. 
echo '<tr>';
echo '<td colspan="6" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">';
echo $reportTitle;
echo '</td>';
echo '</tr>';
// Header Row
echo '<tr style=" color:white; font-weight:bold;">';
echo '<th style="background-color:#4F81BD;">Route (Supplier)</th>';
echo '<th style="background-color:#4F81BD;">Route Distance (km)</th>';
echo '<th style="background-color:#4F81BD;">Fixed Amount (LKR)</th>';
echo '<th style="background-color:#4F81BD;">Fuel Amount (LKR)</th>';
echo '<th style="background-color:#4F81BD;">Total Distance (km)</th>';
echo '<th style="background-color:#4F81BD;">Total Payment (LKR)</th>';
echo '</tr>';

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['route_name']) . ' (' . htmlspecialchars($row['supplier_code']) . ')</td>';
        echo '<td style="text-align:right;">' . number_format($row['route_distance'], 2) . '</td>';
        echo '<td style="text-align:right;">' . number_format($row['fixed_amount'], 2) . '</td>';
        echo '<td style="text-align:right;">' . number_format($row['fuel_amount'], 2) . '</td>';
        echo '<td style="text-align:right;">' . number_format($row['total_distance'], 2) . '</td>';
        echo '<td style="text-align:right; font-weight:bold;">' . number_format($row['monthly_payment'], 2) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="6" style="text-align:center;">No records found for this period.</td></tr>';
}

echo '</table>';

$stmt->close();
$conn->close();
?>