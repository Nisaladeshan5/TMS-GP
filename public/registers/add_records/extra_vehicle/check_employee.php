<?php
// check_employee.php
include('../../../../includes/db.php'); // Database connection path eka hariyata danna

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['emp_id'])) {
    $emp_id = trim($_POST['emp_id']);

    if (empty($emp_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Empty ID']);
        exit;
    }

    // Prepare statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT calling_name FROM employee WHERE emp_id = ?");
    $stmt->bind_param("i", $emp_id); // Assuming emp_id is integer/string
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode(['status' => 'success', 'name' => $row['calling_name']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
    }
    
    $stmt->close();
    exit;
}
?>