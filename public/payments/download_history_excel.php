<?php
// download_history_excel.php
// Exports Staff Monthly Payments History to Excel with a Yellow Title Header
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

// Format Month Name for the Title (e.g., "January 2026")
$dateObj = DateTime::createFromFormat('!m', $month);
$monthName = $dateObj->format('F');
$reportTitle = "Staff Monthly Payments History - " . $monthName . " " . $year;

// --- FETCH DATA (STAFF LOGIC) ---
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
    FROM monthly_payments_sf m
    JOIN route r ON m.route_code = r.route_code
    WHERE m.month = ? 
    AND m.year = ? 
    AND SUBSTRING(m.route_code, 5, 1) = 'S' 
    ORDER BY m.route_code ASC, m.supplier_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$result = $stmt->get_result();

// --- EXCEL HEADERS ---
$filename = "Staff_Payment_History_" . $year . "_" . str_pad($month, 2, '0', STR_PAD_LEFT) . ".xls";

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

// 2. COLUMN HEADERS ROW (BLUE)
echo '<tr style="color:white; font-weight:bold; text-align:center;">';
echo '<th style="background-color:#4F81BD; width: 250px;">Route (Supplier)</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Route Distance (km)</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Fixed Amount (LKR)</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Fuel Amount (LKR)</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Total Distance (km)</th>';
echo '<th style="background-color:#4F81BD; width: 180px;">Total Payment (LKR)</th>';
echo '</tr>';

// 3. DATA ROWS
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        // Route Name
        echo '<td style="vertical-align: middle;">' . htmlspecialchars($row['route_name']) . ' (' . htmlspecialchars($row['supplier_code']) . ')</td>';
        
        // Numeric Columns (Right Aligned)
        echo '<td style="text-align:right; vertical-align: middle;">' . number_format($row['route_distance'], 2) . '</td>';
        echo '<td style="text-align:right; vertical-align: middle;">' . number_format($row['fixed_amount'], 2) . '</td>';
        echo '<td style="text-align:right; vertical-align: middle;">' . number_format($row['fuel_amount'], 2) . '</td>';
        echo '<td style="text-align:right; vertical-align: middle;">' . number_format($row['total_distance'], 2) . '</td>';
        
        // Total Payment (Bold)
        echo '<td style="text-align:right; font-weight:bold; vertical-align: middle;">' . number_format($row['monthly_payment'], 2) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="6" style="text-align:center; padding: 20px;">No history records found for ' . $monthName . ' ' . $year . '.</td></tr>';
}

echo '</table>';

$stmt->close();
$conn->close();
?>