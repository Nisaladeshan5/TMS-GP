<?php
require_once '../../includes/session_check.php';
include('../../includes/db.php');

// Page eken ewana date eka gannawa (Default today)
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : date('Y-m-d');
$view_filter = isset($_GET['view_filter']) ? $_GET['view_filter'] : 'routes';

// Date eken Month eka wen karala gannawa (e.g. 2024-05)
$month_year = date('Y-m', strtotime($date_filter));
$display_month = date('F Y', strtotime($date_filter)); // e.g. May 2024

// Filename generation
$filename = "Fuel_Issue_Monthly_Report_" . $view_filter . "_" . $month_year . ".xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Fetch Data - Monthly Filter logic
if ($view_filter === 'routes') {
    // Route issues for the WHOLE MONTH
    $sql = "SELECT fi.code, fi.date, fi.issued_qty, 
                   COALESCE(r.vehicle_no, sr.vehicle_no) AS vehicle_no,
                   COALESCE(r.route, sr.sub_route) AS display_name,
                   CASE WHEN r.route_code IS NOT NULL THEN 'Main' ELSE 'Sub' END AS category
            FROM fuel_issues fi
            LEFT JOIN route r ON fi.code = r.route_code
            LEFT JOIN sub_route sr ON fi.code = sr.sub_route_code
            WHERE DATE_FORMAT(fi.date, '%Y-%m') = '$month_year'
            ORDER BY fi.date ASC, category ASC";
} else {
    // Employee issues for the WHOLE MONTH
    $sql = "SELECT efi.emp_id AS code, efi.issue_date AS date, efi.issued_qty, 
                   efi.reason AS display_name, e.calling_name, 'Employee' AS category
            FROM employee_fuel_issues efi
            LEFT JOIN employee e ON efi.emp_id = e.emp_id
            WHERE DATE_FORMAT(efi.issue_date, '%Y-%m') = '$month_year'
            ORDER BY efi.issue_date ASC";
}

$result = $conn->query($sql);
?>

<table border="1">
    <thead>
        <tr>
            <th colspan="5" style="background-color: #1E3A8A; color: white; font-size: 14px; height: 30px;">
                Monthly Fuel Issue Report - <?php echo $display_month; ?> (<?php echo ucfirst($view_filter); ?>)
            </th>
        </tr>
        <tr style=" color: white; font-weight: bold;">
            <th style="background-color: #2563EB;" width="100">Date</th>
            <th style="background-color: #2563EB;" width="120">Code / ID</th>
            <th style="background-color: #2563EB;" width="250"><?php echo ($view_filter === 'routes') ? 'Route Name' : 'Employee Name'; ?></th>
            <th style="background-color: #2563EB;" width="200"><?php echo ($view_filter === 'routes') ? 'Vehicle No' : 'Reason / Purpose'; ?></th>
            <th style="background-color: #2563EB;" width="120">Issued Qty (L)</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $total_qty = 0;
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $name = ($view_filter === 'routes') ? $row["display_name"] : ($row["calling_name"] ?? 'Unknown');
                $third = ($view_filter === 'routes') ? $row["vehicle_no"] : $row["display_name"];
                $total_qty += (float)$row['issued_qty'];
                
                echo "<tr>";
                echo "<td>" . $row['date'] . "</td>";
                echo "<td>" . $row['code'] . "</td>";
                echo "<td>" . $name . "</td>";
                echo "<td>" . $third . "</td>";
                echo "<td align='right' style='font-family: Arial;'>" . number_format($row['issued_qty'], 2) . "</td>";
                echo "</tr>";
            }
            // Add Total Row at the end
            echo "<tr style='background-color: #F3F4F6; font-weight: bold;'>";
            echo "<td colspan='4' align='right'>MONTHLY TOTAL:</td>";
            echo "<td align='right'>" . number_format($total_qty, 2) . "</td>";
            echo "</tr>";
        } else {
            echo "<tr><td colspan='5' align='center'>No data found for the selected month.</td></tr>";
        }
        ?>
    </tbody>
</table>