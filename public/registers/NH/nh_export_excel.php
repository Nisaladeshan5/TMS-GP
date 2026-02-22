<?php
// nh_export_excel.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ආරක්ෂාව සඳහා ලොග් වී ඇත්දැයි බැලීම
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die("Access Denied.");
}

include('../../../includes/db.php');

// URL එකෙන් එන Date එකෙන් මාසය සහ අවුරුද්ද නිවැරදිව ලබා ගැනීම
$filter_param = $_GET['month'] ?? date('Y-m-d');
$month_only = date('Y-m', strtotime($filter_param));
$start_date = $month_only . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Night_Heldup_Report_$month_only.xls");
header("Pragma: no-cache");
header("Expires: 0");

// Day Heldup Report එකේ Style එකම භාවිතා කිරීම
echo '<table border="1">';
echo '<tr>
        <th style="background-color: #2563eb; color: white;">ID</th>
        <th style="background-color: #2563eb; color: white;">Date</th>
        <th style="background-color: #2563eb; color: white;">Schedule</th>
        <th style="background-color: #2563eb; color: white;">Vehicle No</th>
        <th style="background-color: #2563eb; color: white;">Op Code</th>
        <th style="background-color: #2563eb; color: white;">Qty (Pax)</th>
        <th style="background-color: #2563eb; color: white;">Distance (km)</th>
        <th style="background-color: #2563eb; color: white;">Employees (ID - Name)</th>
      </tr>';

// GROUP_CONCAT එක ඇතුළත emp_id සහ calling_name එකතු කර ඇත
$sql = "SELECT nh.id, nh.date, nh.schedule_time, nh.vehicle_no, nh.op_code, nh.quantity, nh.distance,
               GROUP_CONCAT(CONCAT(e.emp_id, ' - ', e.calling_name) SEPARATOR ' / ') AS emp_list
        FROM nh_register nh
        LEFT JOIN nh_trip_departments ntd ON nh.id = ntd.trip_id
        LEFT JOIN employee e ON ntd.emp_id = e.emp_id
        WHERE nh.done = 1 
        AND nh.date BETWEEN ? AND ?
        GROUP BY nh.id
        ORDER BY nh.date ASC, nh.time ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td style="text-align: center;">' . $row['id'] . '</td>';
        echo '<td>' . $row['date'] . '</td>';
        echo '<td style="text-transform: uppercase;">' . htmlspecialchars($row['schedule_time'] ?? '-') . '</td>';
        echo '<td style="font-weight: bold; text-transform: uppercase;">' . htmlspecialchars($row['vehicle_no']) . '</td>';
        echo '<td style="text-align: center;">' . htmlspecialchars($row['op_code'] ?: '-') . '</td>';
        echo '<td style="text-align: center;">' . $row['quantity'] . '</td>';
        echo '<td style="text-align: right;">' . number_format($row['distance'], 2) . '</td>';
        // සේවක විස්තර: "ID - Name / ID - Name" ලෙස පෙන්වයි
        echo '<td>' . htmlspecialchars($row['emp_list'] ?? '-') . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="8" style="text-align: center; padding: 10px;">No completed records found for ' . $month_only . '</td></tr>';
}

echo '</table>';
$stmt->close();
$conn->close();
exit;