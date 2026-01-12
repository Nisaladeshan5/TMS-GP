<?php
// export_own_vehicle.php

require_once '../../includes/session_check.php';
require_once '../../includes/db.php';

// 1. Set Headers for Excel Download
$filename = "Own_Vehicle_Detailed_Report_" . date('Y-m-d') . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// 2. SQL Query
// We use LEFT JOINs to handle cases where an employee or fuel rate might be missing/deleted
$sql = "SELECT 
            ov.emp_id,
            e.calling_name,
            ov.vehicle_no,
            ov.type AS vehicle_type,
            fr.type AS fuel_type_name,
            ov.fixed_amount,
            ov.fuel_efficiency, 
            ov.rate_id,
            ov.distance
        FROM own_vehicle ov
        LEFT JOIN employee e ON ov.emp_id = e.emp_id
        LEFT JOIN fuel_rate fr ON ov.rate_id = fr.rate_id
        GROUP BY ov.vehicle_no
        ORDER BY ov.emp_id ASC";

$result = $conn->query($sql);

?>
<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        /* Excel Table Styling */
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 12px; }
        th { background-color: #2d3748; color: #ffffff; border: 1px solid #000000; padding: 10px; text-align: center; font-size: 13px; font-weight: bold; }
        td { border: 1px solid #cbd5e0; padding: 8px; text-align: center; vertical-align: middle; color: #333; }
        
        /* Alignment Classes */
        .text-left { text-align: left !important; padding-left: 10px; }
        .text-right { text-align: right !important; padding-right: 10px; }
        
        /* Status Colors */
        .active-status { color: green; font-weight: bold; }
        .inactive-status { color: red; font-weight: bold; }
    </style>
</head>
<body>

<table>
    <tr>
        <td colspan="10" style="font-size: 18px; font-weight: bold; text-align: center; background-color: #edf2f7; border: 1px solid #000; padding: 15px;">
            Own Vehicle Detailed Register - <?php echo date('F j, Y'); ?>
        </td>
    </tr>

    <thead>
        <tr>
            <th style="width: 80px;">Emp ID</th>
            <th style="width: 180px;">Employee Name</th>
            <th style="width: 120px;">Vehicle No</th>
            <th style="width: 100px;">Vehicle Type</th>
            <th style="width: 120px;">Fuel Type</th>
            <th style="width: 100px;">Efficiency (km/L)</th>
            <th style="width: 120px;">Fixed Amount</th>
            <th style="width: 100px;">Distance</th>
        </tr>
    </thead>

    <tbody>
        <?php 
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                
                // Handle NULLs
                $empName = !empty($row['calling_name']) ? htmlspecialchars($row['calling_name']) : "<span style='color:red;'>N/A</span>";
                $fuelTypeName = !empty($row['fuel_type_name']) ? htmlspecialchars($row['fuel_type_name']) : "-";
                
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['emp_id']) . "</td>";
                echo "<td class='text-left'>" . $empName . "</td>";
                echo "<td style='font-weight:bold;'>" . htmlspecialchars($row['vehicle_no']) . "</td>";
                echo "<td>" . htmlspecialchars($row['vehicle_type']) . "</td>";
                echo "<td>" . $fuelTypeName . "</td>";
                echo "<td>" . htmlspecialchars($row['fuel_efficiency']) . "</td>";
                echo "<td class='text-right'>" . number_format((float)$row['fixed_amount'], 2) . "</td>";
                echo "<td class='text-right'>" . htmlspecialchars($row['distance']) . "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='10'>No records found</td></tr>";
        }
        ?>
    </tbody>
</table>

</body>
</html>
<?php 
if(isset($conn)) $conn->close();
exit(); 
?>