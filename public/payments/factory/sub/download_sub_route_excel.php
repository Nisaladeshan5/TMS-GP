<?php
// download_sub_route_excel.php
require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

include('../../../../includes/db.php');

// 1. Get Filters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// 2. Set Filename
$month_name = date('F', mktime(0, 0, 0, $month, 10));
$filename = "Sub_Route_Payments_{$month_name}_{$year}.xls";

// 3. Set Headers for Excel Download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// 4. Helper Functions (Query Logic)
function get_attendance($conn, $parent_route, $m, $y) {
    $sql = "SELECT COUNT(DISTINCT date) as days FROM factory_transport_vehicle_register WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $parent_route, $m, $y);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['days'] ?? 0);
}

function get_adjustment($conn, $sub_code, $m, $y) {
    $sql = "SELECT SUM(adjustment_days) as adj FROM sub_route_adjustments WHERE sub_route_code = ? AND month = ? AND year = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $sub_code, $m, $y);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['adj'] ?? 0);
}

// 5. Fetch Data
$sql = "SELECT sub_route_code, route_code, sub_route, vehicle_no, per_day_rate FROM sub_route WHERE is_active = 1 ORDER BY sub_route_code ASC";
$result = $conn->query($sql);

// 6. Output Data Table
?>
<table border="1">
    <thead>
        <tr>
            <th colspan="10" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">
                Sub Route Monthly Payment Report - <?php echo "$month_name $year"; ?>
            </th>
        </tr>
        <tr>
            <th style="background-color: #ADD8E6; font-weight: bold;">#</th>
            <th style="background-color: #ADD8E6; font-weight: bold;">Sub Route Code</th>
            <th style="background-color: #ADD8E6; font-weight: bold;">Sub Route Name</th>
            <th style="background-color: #ADD8E6; font-weight: bold;">Parent Route</th>
            <th style="background-color: #ADD8E6; font-weight: bold;">Vehicle No</th>
            <th style="background-color: #ADD8E6; font-weight: bold;">Daily Rate (LKR)</th>
            <th style="background-color: #ADD8E6; font-weight: bold;">Base Days</th>
            <th style="background-color: #ADD8E6; font-weight: bold;">Adjustments</th>
            <th style="background-color: #ADD8E6; font-weight: bold;">Final Days</th>
            <th style="background-color: #ADD8E6; font-weight: bold;">Total Payment (LKR)</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $count = 1;
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $sub_code = $row['sub_route_code'];
                $parent = $row['route_code'];
                $rate = (float)$row['per_day_rate'];

                // Calculate
                $base = get_attendance($conn, $parent, $month, $year);
                $adj = get_adjustment($conn, $sub_code, $month, $year);
                
                $final = $base + $adj;
                if ($final < 0) $final = 0;
                
                $total = $final * $rate;

                // Color for Adjustment
                $adj_style = ($adj != 0) ? 'background-color: #FFE4B5;' : ''; // Light Orange if adjusted
                ?>
                <tr>
                    <td style="text-align: center;"><?php echo $count++; ?></td>
                    <td><?php echo $sub_code; ?></td>
                    <td><?php echo $row['sub_route']; ?></td>
                    <td><?php echo $parent; ?></td>
                    <td><?php echo $row['vehicle_no']; ?></td>
                    <td style="text-align: right;"><?php echo number_format($rate, 2); ?></td>
                    <td style="text-align: center;"><?php echo $base; ?></td>
                    <td style="text-align: center; <?php echo $adj_style; ?>"><?php echo ($adj > 0 ? "+$adj" : $adj); ?></td>
                    <td style="text-align: center; font-weight: bold;"><?php echo $final; ?></td>
                    <td style="text-align: right; font-weight: bold;"><?php echo number_format($total, 2); ?></td>
                </tr>
                <?php
            }
        } else {
            echo "<tr><td colspan='10' style='text-align:center;'>No records found</td></tr>";
        }
        ?>
    </tbody>
</table>
<?php $conn->close(); ?>