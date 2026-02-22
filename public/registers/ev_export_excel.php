<?php
// ev_export_excel.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ආරක්ෂාව සඳහා ලොග් වී ඇත්දැයි බැලීම
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die("Access Denied.");
}

include('../../includes/db.php');

// URL එකෙන් එන Date එකෙන් මාසය සහ අවුරුද්ද ලබා ගැනීම
$filter_param = $_GET['month'] ?? date('Y-m-d');
$month_only = date('Y-m', strtotime($filter_param));
$start_date = $month_only . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Extra_Vehicle_Report_$month_only.xls");
header("Pragma: no-cache");
header("Expires: 0");

// Style එක කලින් රිපෝට් එකේ විදියටම (Day/Night Heldup)
echo '<table border="1">';
echo '<tr>
        <th style="background-color: #2563eb; color: white;">ID</th>
        <th style="background-color: #2563eb; color: white;">Date</th>
        <th style="background-color: #2563eb; color: white;">Vehicle No</th>
        <th style="background-color: #2563eb; color: white;">Supplier</th>
        <th style="background-color: #2563eb; color: white;">Op/Route Code</th>
        <th style="background-color: #2563eb; color: white;">A/C</th>
        <th style="background-color: #2563eb; color: white;">Distance (km)</th>
        <th style="background-color: #2563eb; color: white;">Passengers & Reasons (ID - Name)</th>
      </tr>';

// JOIN Query එක - extra_vehicle_register සහ සේවක විස්තර සම්බන්ධ කර ඇත
$sql = "SELECT evr.*, s.supplier,
               GROUP_CONCAT(CONCAT(e.emp_id, ' - ', e.calling_name, ' (', r.reason, ')') SEPARATOR ' / ') AS passenger_details
        FROM extra_vehicle_register evr
        LEFT JOIN supplier s ON evr.supplier_code = s.supplier_code
        LEFT JOIN ev_trip_employee_reasons eter ON evr.id = eter.trip_id
        LEFT JOIN employee e ON eter.emp_id = e.emp_id
        LEFT JOIN reason r ON eter.reason_code = r.reason_code
        WHERE evr.done = 1 
        AND evr.date BETWEEN ? AND ?
        GROUP BY evr.id
        ORDER BY evr.date ASC, evr.time ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $op_or_route = !empty($row['route']) ? "RT: ".$row['route'] : "OP: ".$row['op_code'];
        $ac_label = ($row['ac_status'] == 1) ? "Yes" : "No";

        echo '<tr>';
        echo '<td style="text-align: center;">' . $row['id'] . '</td>';
        echo '<td>' . $row['date'] . '</td>';
        echo '<td style="font-weight: bold; text-transform: uppercase;">' . htmlspecialchars($row['vehicle_no']) . '</td>';
        echo '<td>' . htmlspecialchars($row['supplier'] ?? '-') . '</td>';
        echo '<td style="text-align: center;">' . htmlspecialchars($op_or_route) . '</td>';
        echo '<td style="text-align: center;">' . $ac_label . '</td>';
        echo '<td style="text-align: right;">' . number_format($row['distance'], 2) . '</td>';
        // සේවක විස්තර: "ID - Name (Reason) / ID - Name (Reason)"
        echo '<td>' . htmlspecialchars($row['passenger_details'] ?? '-') . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="8" style="text-align: center; padding: 10px;">No completed records found for ' . $month_only . '</td></tr>';
}

echo '</table>';
$stmt->close();
$conn->close();
exit;