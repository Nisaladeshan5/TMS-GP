<?php
// download_ev_history_excel.php
// Exports Extra Vehicle Monthly Payments History to Excel
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
$reportTitle = "Extra Vehicle Monthly Payments History - " . $monthName . " " . $year;

// --- FETCH CODES FOR CATEGORIZATION (NEW) ---
$op_codes_list = [];
$route_codes_list = [];
$sub_route_codes_list = [];

$check_op = $conn->query("SHOW TABLES LIKE 'op_services'");
if ($check_op && $check_op->num_rows > 0) {
    $res_op = $conn->query("SELECT op_code FROM op_services");
    if($res_op) while($r = $res_op->fetch_assoc()) $op_codes_list[] = $r['op_code'];
}

$res_rt = $conn->query("SELECT route_code FROM route");
if($res_rt) while($r = $res_rt->fetch_assoc()) $route_codes_list[] = $r['route_code'];

$res_sub = $conn->query("SELECT sub_route_code FROM sub_route");
if($res_sub) while($r = $res_sub->fetch_assoc()) $sub_route_codes_list[] = $r['sub_route_code'];

// --- Fetch History Data ---
$sql = "
    SELECT 
        mph.code,
        mph.supplier_code,
        mph.rate,
        mph.total_distance,
        mph.monthly_payment,
        s.supplier
    FROM 
        monthly_payments_ev mph
    LEFT JOIN 
        supplier s ON mph.supplier_code = s.supplier_code
    WHERE 
        mph.month = ? 
    AND 
        mph.year = ? 
    ORDER BY 
        mph.code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$result = $stmt->get_result();

// --- EXCEL HEADERS ---
$filename = "Extra_Vehicle_Payments_" . $year . "_" . str_pad($month, 2, '0', STR_PAD_LEFT) . ".xls";

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
echo '<th style="background-color:#4F81BD; width: 200px;">Identifier (Op/Route/Sub)</th>'; // UPDATED HEADER
echo '<th style="background-color:#4F81BD; width: 250px;">Supplier</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Rate (LKR)</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Total Distance (km)</th>';
echo '<th style="background-color:#4F81BD; width: 180px;">Monthly Payment (LKR)</th>';
echo '</tr>';

// 3. DATA ROWS
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        
        // --- Identifier Logic ---
        $code_val = $row['code'];
        $display_code = $code_val;

        if (in_array($code_val, $route_codes_list)) {
            $display_code = "RT: " . $code_val;
        } elseif (in_array($code_val, $sub_route_codes_list)) {
            $display_code = "SUB: " . $code_val;
        } elseif (in_array($code_val, $op_codes_list)) {
            $display_code = "OP: " . $code_val;
        }

        echo '<td style="vertical-align: middle; font-weight: bold;">' . htmlspecialchars($display_code) . '</td>';
        
        // Supplier + Code
        $supDisplay = htmlspecialchars($row['supplier'] ?? 'N/A') . ' (' . htmlspecialchars($row['supplier_code']) . ')';
        echo '<td style="vertical-align: middle;">' . $supDisplay . '</td>';
        
        // Rate (Right Align)
        echo '<td style="text-align:right; vertical-align: middle;">' . number_format($row['rate'], 2) . '</td>';
        
        // Distance (Right Align)
        echo '<td style="text-align:right; vertical-align: middle;">' . number_format($row['total_distance'], 2) . '</td>';
        
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