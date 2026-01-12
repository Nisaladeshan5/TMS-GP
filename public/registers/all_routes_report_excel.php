<?php
// all_routes_report_excel.php

require_once '../../includes/session_check.php';
include('../../includes/db.php');

// 1. Get Filter Parameters
$filterDate = $_GET['month_year'] ?? date('Y-m');
list($filterYear, $filterMonth) = explode('-', $filterDate);

// Display Month Name
$monthName = date('F Y', strtotime($filterDate));
date_default_timezone_set('Asia/Colombo');
// 2. Set Headers for Excel Download
$filename = "Route_Summary_" . $monthName . ".xls"; // .xls extension allows HTML table to open in Excel
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// 3. SQL Query to get Summary
// We join 'route' table with 'register' table to get ALL routes, even if they have 0 trips.
$sql = "SELECT 
            r.route, 
            r.route_code,
            COUNT(DISTINCT DATE(stvr.date)) as days_operated,
            COUNT(CASE WHEN stvr.shift = 'morning' THEN 1 END) as morning_trips,
            COUNT(CASE WHEN stvr.shift = 'evening' THEN 1 END) as evening_trips,
            COUNT(stvr.id) as total_trips
        FROM 
            route r
        LEFT JOIN 
            staff_transport_vehicle_register stvr 
            ON r.route_code = stvr.route 
            AND YEAR(stvr.date) = ? 
            AND MONTH(stvr.date) = ? 
            AND stvr.is_active = 1
        WHERE 
            r.route_code LIKE '____S%' AND r.is_active = 1
        GROUP BY 
            r.route_code, r.route
        ORDER BY 
            days_operated DESC, r.route ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $filterYear, $filterMonth);
$stmt->execute();
$result = $stmt->get_result();

?>

<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #4F81BD; color: white; border: 1px solid #000; padding: 5px; }
        td { border: 1px solid #000; padding: 5px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .bg-gray { background-color: #f2f2f2; }
    </style>
</head>
<body>
    
    <table>
        <tr>
            <td colspan="6" style="font-size: 18px; font-weight: bold; text-align: center; height: 40px; vertical-align: middle;">
                Transport Route Summary - <?php echo $monthName; ?>
            </td>
        </tr>
        <tr>
            <td colspan="6" style="text-align: center;">Generated on: <?php echo date('Y-m-d H:i:s'); ?></td>
        </tr>
        <tr></tr> <tr>
            <th style="width: 50px;">#</th>
            <th style="width: 200px;">Route Name</th>
            <th style="width: 100px;">Route Code</th>
            <th style="width: 120px; background-color: #F79646;">Days Operated</th>
            <th style="width: 100px;">Morning Trips</th>
            <th style="width: 100px;">Evening Trips</th>
        </tr>

        <?php 
        $count = 1;
        $total_days_sum = 0;
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $total_days_sum += $row['days_operated'];
                ?>
                <tr>
                    <td class="text-center"><?php echo $count++; ?></td>
                    <td><?php echo htmlspecialchars($row['route']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($row['route_code']); ?></td>
                    
                    <td class="text-center font-bold" style="background-color: #ffeb9c; color: #9c5700;">
                        <?php echo $row['days_operated']; ?>
                    </td>
                    
                    <td class="text-center"><?php echo $row['morning_trips']; ?></td>
                    <td class="text-center"><?php echo $row['evening_trips']; ?></td>
                </tr>
                <?php 
            }
        } else {
            echo "<tr><td colspan='6' class='text-center'>No data found for this month.</td></tr>";
        }
        ?>
    </table>
</body>
</html>