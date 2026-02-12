<?php
// download_sub_route_excel.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

// 1. Get Filters from POST
$month = isset($_POST['month']) ? (int)$_POST['month'] : (int)date('m');
$year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
$month_name = date('F', mktime(0, 0, 0, $month, 10));

// 2. Decode the Data sent from the page
$payment_data = [];
if (isset($_POST['payment_json'])) {
    $payment_data = json_decode($_POST['payment_json'], true);
}

// 3. Set Filename
$filename = "Sub_Route_Payments_{$month_name}_{$year}.xls";

// 4. Headers for Excel
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");
?>

<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        .text-format { mso-number-format:"\@"; } 
        .currency-format { mso-number-format:"\#\,\#\#0\.00"; }
        .header-bg { background-color: #ADD8E6; font-weight: bold; }
        .total-bg { background-color: #f0f0f0; font-weight: bold; }
    </style>
</head>
<body>
    <table border="1">
        <thead>
            <tr>
                <th colspan="9" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">
                    Sub Route Monthly Payment Report - <?php echo "$month_name $year"; ?>
                </th>
            </tr>
            <tr style="font-weight: bold; text-align: center;">
                <th class="header-bg">#</th>
                <th class="header-bg">Sub Route Code</th>
                <th class="header-bg">Sub Route Name</th>
                <th class="header-bg">Parent Route</th>
                <th class="header-bg">Vehicle No</th>
                <th class="header-bg">Daily Rate (LKR)</th>
                <th class="header-bg">Base Days</th>
                <th class="header-bg">Adj</th>
                <th class="header-bg">Total Payment (LKR)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $count = 1;
            $grand_total = 0;
            if (!empty($payment_data)) {
                foreach ($payment_data as $data) {
                    $grand_total += (float)$data['total_payment'];
                    ?>
                    <tr>
                        <td style="text-align: center;"><?php echo $count++; ?></td>
                        <td class="text-format"><?php echo htmlspecialchars($data['sub_route_code']); ?></td>
                        <td><?php echo htmlspecialchars($data['sub_route_name']); ?></td>
                        <td class="text-format"><?php echo htmlspecialchars($data['parent_route']); ?></td>
                        <td><?php echo htmlspecialchars($data['vehicle_no']); ?></td>
                        <td class="currency-format" style="text-align: right;"><?php echo (float)$data['day_rate']; ?></td>
                        <td style="text-align: center;"><?php echo (int)$data['base_days']; ?></td>
                        <td style="text-align: center;"><?php echo (int)$data['adjustments']; ?></td>
                        <td class="currency-format" style="text-align: right; font-weight: bold;"><?php echo (float)$data['total_payment']; ?></td>
                    </tr>
                    <?php
                }
                // Grand Total Row එක
                ?>
                <tr class="total-bg">
                    <td colspan="8" style="text-align: right; font-weight: bold;">Grand Total (LKR):</td>
                    <td class="currency-format" style="text-align: right; font-weight: bold; background-color: #e2e8f0;">
                        <?php echo $grand_total; ?>
                    </td>
                </tr>
                <?php
            } else {
                echo "<tr><td colspan='10' style='text-align:center;'>No data available to export.</td></tr>";
            }
            ?>
        </tbody>
    </table>
</body>
</html>