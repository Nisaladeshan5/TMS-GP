<?php
// nh_validate_emp.php
include('../../../includes/db.php');

$emp_id = $_GET['id'] ?? '';

// SQL query එකට department column එකත් එකතු කළා
$stmt = $conn->prepare("SELECT calling_name, department FROM employee WHERE emp_id = ? LIMIT 1");
$stmt->bind_param("s", $emp_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'success' => true, 
        'name' => $row['calling_name'],
        'dept' => $row['department'] // මෙතනින් department එක JS එකට යවනවා
    ]);
} else {
    echo json_encode(['success' => false]);
}
$stmt->close();