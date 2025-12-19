<?php
// day_heldup_fetch_attendance_details.php
// Fetches the assigned vehicle_no based on op_code from the dh_attendance table for today's date.

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// =======================================================
// !!! SECURITY WARNING: LOGIN CHECK REMOVED AS REQUESTED !!!
// !!! This means anyone can access this endpoint.      !!!
// =======================================================
/* if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}
*/
// =======================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['op_code'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method or missing parameters.']);
    exit();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

$op_code = trim($_POST['op_code']);
$today_date = date('Y-m-d'); // Current date for filtering

$response = ['success' => false, 'vehicle_no' => ''];

if (empty($op_code)) {
     $response['message'] = "Op Code is empty.";
     goto output;
}

// Query dh_attendance for the vehicle assigned to this op_code today.
$sql = "
    SELECT 
        vehicle_no 
    FROM 
        dh_attendance 
    WHERE 
        op_code = ? AND DATE(date) = ? 
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("ss", $op_code, $today_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row && !empty($row['vehicle_no'])) {
        $response['success'] = true;
        // Return the vehicle number in uppercase
        $response['vehicle_no'] = strtoupper($row['vehicle_no']);
        $response['message'] = "Vehicle found in today's attendance.";
    } else {
         $response['message'] = "Vehicle not found in today's attendance for Op Code: {$op_code}.";
    }
} else {
    $response['message'] = "Database error: Could not prepare statement.";
}

output:
$conn->close();
header('Content-Type: application/json');
echo json_encode($response);
exit();
?>