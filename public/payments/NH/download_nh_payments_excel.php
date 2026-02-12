<?php
// download_nh_payments_excel.php
// Exports Night Heldup Monthly Summary to Excel (.xls) with Styling

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    exit("Access Denied");
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// 1. Get Filter Inputs
$current_year = date('Y');
$current_month = date('m');

$filterYear = $_GET['year'] ?? $current_year;
$filterMonthNum = $_GET['month'] ?? $current_month; 

// Pad month
$filterMonthNum = str_pad($filterMonthNum, 2, '0', STR_PAD_LEFT);
$filterMonthName = date('F', mktime(0, 0, 0, (int)$filterMonthNum, 1));

// 2. CALCULATION LOGIC (Grouped by Night Shift Date)
function get_night_payment_data($conn, $month, $year) {
    
    // SQL Logic:
    // 1. Calculate 'effective_date': If time < 07:00:00, count as yesterday.
    // 2. Group by OpCode AND effective_date to sum up shift distance.
    // 3. Filter by the calculated month/year.
    
    $sql = "
        SELECT 
            nh.op_code,
            -- Effective Date Calculation
            IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date) as effective_date,
            MAX(nh.vehicle_no) as vehicle_no, 
            SUM(nh.distance) AS daily_distance,
            os.slab_limit_distance,
            os.extra_rate AS rate_per_km
        FROM 
            nh_register nh
        JOIN 
            op_services os ON nh.op_code = os.op_code
        WHERE 
            nh.done = 1 
            -- Filter using the shift date logic
            AND DATE_FORMAT(IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date), '%Y-%m') = ?
        GROUP BY 
            nh.op_code, effective_date
        ORDER BY 
            nh.op_code ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    
    $filter = "$year-$month";
    $stmt->bind_param("s", $filter);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $summary = [];

    while ($row = $result->fetch_assoc()) {
        $op_code = $row['op_code'];
        $daily_actual_dist = (float)$row['daily_distance'];
        $slab = (float)$row['slab_limit_distance'];
        $rate = (float)$row['rate_per_km'];
        $vehicle = $row['vehicle_no'];
        
        $payable_dist = 0;

        // --- Logic Check (Applied on Daily Total) ---
        if (strpos($op_code, 'NH') === 0) {
            // NH: If Daily Total < Slab, Pay Slab. Else Pay Actual.
            $payable_dist = max($daily_actual_dist, $slab);
        } elseif (strpos($op_code, 'EV') === 0) {
            // EV: Always Pay Actual
            $payable_dist = $daily_actual_dist;
        } else {
            $payable_dist = $daily_actual_dist;
        }

        $daily_payment = $payable_dist * $rate;

        // --- Aggregate to Monthly Summary ---
        if (!isset($summary[$op_code])) {
            $summary[$op_code] = [
                'op_code' => $op_code,
                'vehicle_no' => $vehicle,
                'days_paid' => 0,
                'tot_distance' => 0,
                'tot_payment' => 0
            ];
        }

        $summary[$op_code]['days_paid']++; // Increment day count
        $summary[$op_code]['tot_distance'] += $daily_actual_dist; // Sum actual distance
        $summary[$op_code]['tot_payment'] += $daily_payment; // Sum calculated payment
    }
    
    $stmt->close();
    return $summary;
}

// 3. Get Data
$payment_data = get_night_payment_data($conn, $filterMonthNum, $filterYear);
$conn->close();

// 4. EXCEL GENERATION START
$filename = "Night_Heldup_Summary_{$filterYear}_{$filterMonthName}.xls";

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
                    Night Heldup Payment Summary - <?php echo "$filterMonthName $filterYear"; ?>
                </th>
            </tr>
            <tr>
                <th style="background-color: #ADD8E6; font-weight: bold;">OP Code</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Vehicle No</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Days Paid</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Actual Distance (km)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Payment (LKR)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $grand_total_payment = 0;
            $grand_total_distance = 0;

            if (!empty($payment_data)): 
                foreach ($payment_data as $row): 
                    $grand_total_payment += $row['tot_payment'];
                    $grand_total_distance += $row['tot_distance'];
            ?>
                    <tr>
                        <td class="text-format"><?php echo htmlspecialchars($row['op_code']); ?></td>
                        <td><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                        <td style="text-align:center;"><?php echo $row['days_paid']; ?></td>
                        <td style="text-align:center;"><?php echo number_format($row['tot_distance'], 2); ?></td>
                        <td class="currency-format" style="font-weight:bold;">
                            <?php echo $row['tot_payment']; ?>
                        </td>
                    </tr>
            <?php 
                endforeach; 
                // Grand Total Row
            ?>
                <tr>
                    <td colspan="3" style="text-align: right; font-weight: bold;">GRAND TOTALS</td>
                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($grand_total_distance, 2); ?></td>
                    <td class="currency-format" style="font-weight: bold; border-top: 2px solid black;">
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