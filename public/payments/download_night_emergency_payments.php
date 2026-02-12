<?php
// download_night_emergency_payments.php - Exports Night Emergency Payments to Excel (Styled)

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
$filename = "Night_Emergency_Summary_{$month_name}_{$selected_year}.xls";

// 3. CALCULATION LOGIC
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

$payment_data = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $payment_data[] = $row;
    }
}

$stmt->close();
$conn->close();

// 4. EXCEL GENERATION START
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        /* Force text format for codes to keep leading zeros */
        .text-format { mso-number-format:"\@"; } 
        /* Currency format */
        .currency-format { mso-number-format:"\#\,\#\#0\.00"; }
    </style>
</head>
<body>
    <table border="1">
        <thead>
            <tr>
                <th colspan="5" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">
                    Night Emergency Payment Summary - <?php echo "$month_name $selected_year"; ?>
                </th>
            </tr>
            <tr>
                <th style="background-color: #ADD8E6; font-weight: bold;">Op Code</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Supplier</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Supplier Code</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Worked Days</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Payment (LKR)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $grand_total_days = 0;
            $grand_total_payment = 0;

            if (!empty($payment_data)): 
                foreach ($payment_data as $row): 
                    $grand_total_days += $row['worked_days'];
                    $grand_total_payment += $row['total_payment'];
            ?>
                    <tr>
                        <td class="text-format"><?php echo htmlspecialchars($row['op_code']); ?></td>
                        <td><?php echo htmlspecialchars($row['supplier']); ?></td>
                        <td class="text-format"><?php echo htmlspecialchars($row['supplier_code']); ?></td>
                        <td style="text-align:center;"><?php echo $row['worked_days']; ?></td>
                        <td class="currency-format" style="font-weight:bold; text-align:right;">
                            <?php echo $row['total_payment']; ?>
                        </td>
                    </tr>
            <?php 
                endforeach; 
                // Grand Total Row
            ?>
                <tr>
                    <td colspan="3" style="text-align: right; font-weight: bold;">GRAND TOTALS</td>
                    <td style="text-align: center; font-weight: bold;"><?php echo $grand_total_days; ?></td>
                    <td class="currency-format" style="font-weight: bold; border-top: 2px solid black; background-color: #FFFFE0; text-align:right;">
                        <?php echo $grand_total_payment; ?>
                    </td>
                </tr>

            <?php else: ?>
                <tr>
                    <td colspan="5" style="text-align:center;">No records found for this period.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>