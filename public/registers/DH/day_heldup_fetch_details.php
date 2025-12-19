<?php
// day_heldup_fetch_details.php
// Fetches vehicle_no based on op_code from op_services table (NO LOGIN REQUIRED)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// =======================================================
// !!! LOGIN CHECK REMOVED AS REQUESTED !!!
// =======================================================
/*
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}
*/
// =======================================================

// Ensure the request method is POST and the op_code parameter is present
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['op_code'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method or missing parameters.']);
    exit();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

$op_code = trim($_POST['op_code']);
$response = ['success' => false, 'vehicle_no' => ''];

if (empty($op_code)) {
     $response['message'] = "Op Code is empty.";
     goto output;
}

// Fetch the vehicle_no associated with the op_code from the static services table
$sql = "SELECT vehicle_no FROM op_services WHERE op_code = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("s", $op_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row && !empty($row['vehicle_no'])) {
        $response['success'] = true;
        $response['vehicle_no'] = strtoupper($row['vehicle_no']);
        $response['message'] = "Vehicle found in op_services.";
    } else {
         $response['message'] = "Vehicle not assigned to this Op Code in op_services.";
    }
} else {
    $response['message'] = "Database error: Could not prepare statement.";
}

output:
$conn->close();
header('Content-Type: application/json');
echo json_encode($response);
exit();