<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ආරක්ෂාව සඳහා ලොග් වී ඇත්දැයි බැලීම
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die("Access Denied.");
}

include('../../../includes/db.php');

$filter_month = $_GET['month'] ?? date('Y-m');
$start_date = $filter_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Day_Heldup_Report_$filter_month.xls");
header("Pragma: no-cache");
header("Expires: 0");

echo '<table border="1">';
echo '<tr>
        <th style="background-color: #2563eb; color: white;">Trip ID</th>
        <th style="background-color: #2563eb; color: white;">Date</th>
        <th style="background-color: #2563eb; color: white;">Vehicle No</th>
        <th style="background-color: #2563eb; color: white;">Op Code</th>
        <th style="background-color: #2563eb; color: white;">In Time</th>
        <th style="background-color: #2563eb; color: white;">Out Time</th>
        <th style="background-color: #2563eb; color: white;">Distance (km)</th>
        <th style="background-color: #2563eb; color: white;">Employees & Reasons</th>
      </tr>';

// GROUP_CONCAT භාවිතා කර එකම පේළියට දත්ත ලබා ගැනීම
$sql = "SELECT dhr.trip_id, dhr.date, dhr.vehicle_no, dhr.op_code, dhr.in_time, dhr.out_time, dhr.distance,
               GROUP_CONCAT(CONCAT(e.calling_name, ' (', r.reason, ')') SEPARATOR ' / ') AS emp_details
        FROM day_heldup_register dhr
        JOIN dh_emp_reason dher ON dhr.trip_id = dher.trip_id
        JOIN reason r ON dher.reason_code = r.reason_code
        LEFT JOIN employee e ON dher.emp_id = e.emp_id
        WHERE dhr.done = 1 
        AND dhr.date BETWEEN ? AND ?
        GROUP BY dhr.trip_id
        ORDER BY dhr.date ASC, dhr.trip_id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . $row['trip_id'] . '</td>';
        echo '<td>' . $row['date'] . '</td>';
        echo '<td>' . $row['vehicle_no'] . '</td>';
        echo '<td>' . $row['op_code'] . '</td>';
        echo '<td>' . $row['in_time'] . '</td>';
        echo '<td>' . $row['out_time'] . '</td>';
        echo '<td>' . number_format($row['distance'], 2) . '</td>';
        // මෙහිදී සේවක නම සහ හේතුව "නම (හේතුව) / නම (හේතුව)" ලෙස පෙන්වයි
        echo '<td>' . htmlspecialchars($row['emp_details'] ?? '') . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="8">No records found.</td></tr>';
}

echo '</table>';
$stmt->close();
$conn->close();
exit;