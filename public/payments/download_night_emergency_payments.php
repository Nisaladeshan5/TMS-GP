<?php
// download_night_emergency_payments.php - Exports Night Emergency Payments to CSV (Includes Op Code)

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    exit("Access Denied");
}

include('../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// 2. Get Filter Inputs
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

$month_name = date('F', mktime(0, 0, 0, (int)$selected_month, 1));
$filename = "Night_Emergency_Summary_{$month_name}_{$selected_year}.csv";

// 3. Set Headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// 4. CSV Column Headers (Added Op Code)
fputcsv($output, [
    'Op Code',           // <-- Added
    'Supplier', 
    'Supplier Code', 
    'Total Worked Days', 
    'Total Payment (LKR)'
]);

// 5. CALCULATION LOGIC
// Group by Op Code to show details per vehicle/service
$sql = "
    SELECT 
        nea.op_code,
        s.supplier,
        s.supplier_code,
        COUNT(DISTINCT nea.date) as worked_days,
        (COUNT(DISTINCT nea.date) * os.day_rate) as total_payment
    FROM 
        night_emergency_attendance nea
    JOIN 
        op_services os ON nea.op_code = os.op_code
    JOIN 
        supplier s ON os.supplier_code = s.supplier_code
    WHERE 
        MONTH(nea.date) = ? AND YEAR(nea.date) = ?
    GROUP BY 
        nea.op_code  /* Grouping by Op Code to separate records */
    ORDER BY 
        s.supplier ASC, nea.op_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$result = $stmt->get_result();

// 6. Output Data Rows
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['op_code'],        // <-- Op Code Data
            $row['supplier'],
            $row['supplier_code'],
            $row['worked_days'],
            number_format($row['total_payment'], 2, '.', '') 
        ]);
    }
}

fclose($output);
$stmt->close();
$conn->close();
exit();
?>