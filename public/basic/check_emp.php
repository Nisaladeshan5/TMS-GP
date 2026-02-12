<?php
include('../../includes/db.php');

$emp_id = $_GET['emp_id'] ?? '';
$response = ['isValid' => false, 'message' => ''];

if (!empty($emp_id)) {
    // 1. First check if exists in employees
    $stmt = $conn->prepare("SELECT calling_name FROM employee WHERE emp_id = ?");
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $name = $res->fetch_assoc()['calling_name'];
        
        // 2. Check if ALREADY has access (Duplicate check)
        $log = $conn->prepare("SELECT emp_id FROM manager_log WHERE emp_id = ?");
        $log->bind_param("s", $emp_id);
        $log->execute();
        
        if ($log->get_result()->num_rows > 0) {
            $response['message'] = "Already Registered: Account exists for $name!";
            $response['isValid'] = false;
        } else {
            $response['isValid'] = true;
            $response['name'] = $name;
        }
    } else {
        $response['message'] = "Employee ID not found!";
    }
}
echo json_encode($response);