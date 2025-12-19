<?php
// check_operational_code.php (robust fixed version)
ini_set('display_errors', 0);    // do not output PHP errors to client
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

include('../../../includes/db.php'); // ensure this sets $conn (mysqli)

// Simple DB connection check
if (!isset($conn) || !$conn) {
    error_log("DB Connection Error in check_operational_code.php: " . (isset($conn) ? mysqli_connect_error() : 'conn not set'));
    echo json_encode(['exists' => false, 'message' => 'Error: Database connection failed. Contact admin.']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
$operationalCode = isset($data['operational_code']) ? trim($data['operational_code']) : null;

if (empty($operationalCode)) {
    echo json_encode(['exists' => false, 'message' => 'Error: Operational code not provided.']);
    $conn->close();
    exit;
}

$currentDate = date('Y-m-d'); // Asia/Colombo should be set by calling scripts; set here if needed
// If you want to be certain about timezone, uncomment:
// date_default_timezone_set('Asia/Colombo');

$response = ['exists' => false, 'message' => ''];

// 1) Check op_services active
$op_services_query = "
    SELECT COUNT(op_code) AS cnt
    FROM op_services
    WHERE TRIM(op_code) COLLATE utf8mb4_general_ci = ? AND is_active = 1
";

if (!($stmt = $conn->prepare($op_services_query))) {
    error_log("Prepare failed (op_services): " . $conn->error);
    echo json_encode(['exists' => false, 'message' => 'Internal server error during validation.']);
    $conn->close();
    exit;
}

$stmt->bind_param('s', $operationalCode);
if (!$stmt->execute()) {
    error_log("Execute failed (op_services): " . $stmt->error);
    $stmt->close();
    echo json_encode(['exists' => false, 'message' => 'Internal server error during validation.']);
    $conn->close();
    exit;
}

$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$op_services_count = $row ? (int)$row['cnt'] : 0;
$stmt->close();

if ($op_services_count === 0) {
    echo json_encode(['exists' => false, 'message' => 'Error: Code is invalid or not active.']);
    $conn->close();
    exit;
}

// 2) Check if attendance already recorded today
// NOTE: match the column name your handler uses. Your handler inserts `date` column, so we use that.
$attendance_query = "
    SELECT COUNT(id) AS cnt
    FROM night_emergency_attendance
    WHERE TRIM(op_code) COLLATE utf8mb4_general_ci = ? AND `date` = ?
";

if (!($stmt2 = $conn->prepare($attendance_query))) {
    error_log("Prepare failed (attendance): " . $conn->error);
    echo json_encode(['exists' => false, 'message' => 'Internal server error during validation.']);
    $conn->close();
    exit;
}

$stmt2->bind_param('ss', $operationalCode, $currentDate);
if (!$stmt2->execute()) {
    error_log("Execute failed (attendance): " . $stmt2->error);
    $stmt2->close();
    echo json_encode(['exists' => false, 'message' => 'Internal server error during validation.']);
    $conn->close();
    exit;
}

$result2 = $stmt2->get_result();
$row2 = $result2 ? $result2->fetch_assoc() : null;
$attendance_count = $row2 ? (int)$row2['cnt'] : 0;
$stmt2->close();

if ($attendance_count > 0) {
    echo json_encode(['exists' => false, 'message' => 'Error: Attendance for this code already recorded for today.']);
    $conn->close();
    exit;
}

// success
echo json_encode(['exists' => true, 'message' => 'OK']);
$conn->close();
exit;
