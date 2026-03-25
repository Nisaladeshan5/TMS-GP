<?php
require_once '../../includes/session_check.php';
include('../../includes/db.php');

// --- 1. SET HEADERS ---
$filename = "Employee_Report_" . date('Y-m-d_H-i') . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");

// --- 2. GET FILTERS FROM URL ---
$filters = [
    'emp_id'         => $_GET['emp_id'] ?? '',
    'department'     => $_GET['department'] ?? '',
    'route_code'     => $_GET['route_code'] ?? '',
    'sub_route_code' => $_GET['sub_route_code'] ?? '',
    'staff_type'     => $_GET['staff_type'] ?? ''
];

// --- 3. SQL QUERY WITH FILTERS ---
// r_name eka hadala thiyenne 12 idan patan aran anthima character eka ain karana widiyata
$sql = "SELECT 
            e.emp_id, 
            e.calling_name, 
            e.department, 
            e.gender, 
            e.phone_no, 
            e.near_bus_stop,
            SUBSTRING(e.route, 1, 10) AS r_code,
            SUBSTRING(e.route, 12, LENGTH(e.route) - 12) AS r_name,
            (SELECT GROUP_CONCAT(sub_route SEPARATOR ', ') 
             FROM sub_route 
             WHERE FIND_IN_SET(sub_route_code, e.sub_route_code) > 0) AS sub_route_names
        FROM employee e
        WHERE e.is_active = 1";

$params = [];
$types = "";

if (!empty($filters['emp_id'])) {
    $sql .= " AND (e.emp_id LIKE ? OR e.calling_name LIKE ?)";
    $val = "%" . $filters['emp_id'] . "%";
    $params[] = $val; $params[] = $val;
    $types .= "ss";
}
if (!empty($filters['department'])) {
    $sql .= " AND e.department = ?";
    $params[] = $filters['department'];
    $types .= "s";
}
if (!empty($filters['route_code'])) {
    $sql .= " AND SUBSTRING(e.route, 1, 10) = ?";
    $params[] = $filters['route_code'];
    $types .= "s";
}
if (!empty($filters['sub_route_code'])) {
    if ($filters['sub_route_code'] === 'NONE') {
        $sql .= " AND (e.sub_route_code IS NULL OR e.sub_route_code = '')";
    } else {
        $sql .= " AND FIND_IN_SET(?, e.sub_route_code) > 0";
        $params[] = $filters['sub_route_code'];
        $types .= "s";
    }
}
if (!empty($filters['staff_type'])) {
    $char = strtoupper(substr($filters['staff_type'], 0, 1));
    $sql .= " AND SUBSTRING(e.route, 5, 1) = ?";
    $params[] = $char;
    $types .= "s";
}

$sql .= " ORDER BY e.emp_id";

$stmt = $conn->prepare($sql);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

// --- 4. EXCEL OUTPUT ---
?>
<table border="1">
    <thead>
        <tr style="color: white;">
            <th style="background-color: #1e40af;">Emp ID</th>
            <th style="background-color: #1e40af;">Name</th>
            <th style="background-color: #1e40af;">Dept</th>
            <th style="background-color: #1e40af;">Gender</th>
            <th style="background-color: #1e40af;">Phone</th>
            <th style="background-color: #1e40af;">Route Code</th>
            <th style="background-color: #1e40af;">Route Name</th>
            <th style="background-color: #1e40af;">Bus Stop</th>
            <th style="background-color: #1e40af;">Sub Route</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td style="vnd.ms-excel.numberformat:@"><?php echo $row['emp_id']; ?></td>
                <td><?php echo htmlspecialchars($row['calling_name']); ?></td>
                <td><?php echo htmlspecialchars($row['department']); ?></td>
                <td><?php echo $row['gender']; ?></td>
                <td style="vnd.ms-excel.numberformat:@"><?php echo $row['phone_no']; ?></td>
                <td><?php echo $row['r_code']; ?></td>
                <td><?php echo htmlspecialchars($row['r_name']); ?></td>
                <td><?php echo htmlspecialchars($row['near_bus_stop']); ?></td>
                <td><?php echo htmlspecialchars($row['sub_route_names'] ?? '-'); ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
<?php
if ($stmt) { $stmt->close(); }
$conn->close();
?>