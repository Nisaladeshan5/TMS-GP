<?php
include('../../../includes/db.php');

if (isset($_POST['emp_id'])) {
    $emp_id = $conn->real_escape_string($_POST['emp_id']);
    // 'employee' table eke 'emp_id' saha 'calling_name' thiyenawa kiyala hithala liyala thiyenne
    $sql = "SELECT calling_name FROM employee WHERE emp_id = '$emp_id' LIMIT 1";
    $result = $conn->query($sql);

    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'name' => $row['calling_name']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Not Found']);
    }
}
$conn->close();
?>