<?php
// add_day_rate_ajax.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// Quick JSON response helper
function jsonResponse($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['status'=>'error', 'message'=>'Invalid request method']);
}

// Obtain and validate fields
$supplier_code = isset($_POST['supplier_code']) ? trim($_POST['supplier_code']) : '';
$day_rate = isset($_POST['day_rate']) ? trim($_POST['day_rate']) : '';
$last_updated_date = isset($_POST['last_updated_date']) ? trim($_POST['last_updated_date']) : '';

if ($supplier_code === '' || $day_rate === '' || $last_updated_date === '') {
    http_response_code(400);
    jsonResponse(['status'=>'error', 'message'=>'Missing required fields']);
}

// Validate numeric day_rate
if (!is_numeric($day_rate)) {
    http_response_code(400);
    jsonResponse(['status'=>'error', 'message'=>'Day rate must be a number']);
}
$day_rate = (float)$day_rate;

// Validate date (simple YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_updated_date) || !strtotime($last_updated_date)) {
    http_response_code(400);
    jsonResponse(['status'=>'error', 'message'=>'Invalid date format']);
}

// Insert using prepared statement
$insert_sql = "INSERT INTO night_emergency_day_rate (supplier_code, day_rate, last_updated_date) VALUES (?, ?, ?)";
if ($stmt = $conn->prepare($insert_sql)) {
    $stmt->bind_param("sds", $supplier_code, $day_rate, $last_updated_date);
    if ($stmt->execute()) {
        $stmt->close();
        jsonResponse(['status'=>'success', 'message'=>'Day rate added successfully. Recorded with date: ' . $last_updated_date]);
    } else {
        $err = $conn->error;
        $stmt->close();
        http_response_code(500);
        jsonResponse(['status'=>'error', 'message'=>'Database error: ' . $err]);
    }
} else {
    http_response_code(500);
    jsonResponse(['status'=>'error', 'message'=>'Failed to prepare statement: ' . $conn->error]);
}

$conn->close();
?>
