<?php
include('../../includes/db.php');

header('Content-Type: application/json');

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Get and sanitize the input data
$vehicle_no = isset($_POST['vehicle_no']) ? trim($_POST['vehicle_no']) : '';
$column = isset($_POST['column']) ? trim($_POST['column']) : '';
$value = isset($_POST['value']) ? $_POST['value'] : 0;

// Basic validation
if (empty($vehicle_no) || empty($column) || !is_numeric($value)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid data provided.']);
    exit;
}

// Whitelist allowed columns to prevent SQL injection
$allowed_columns = ['worked_days', 'day_rate'];
if (!in_array($column, $allowed_columns)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid column name.']);
    exit;
}

// Prepare the UPDATE statement
$sql = "UPDATE vehicle SET $column = ? WHERE vehicle_no = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Database prepare failed.']);
    exit;
}

// Use 'i' for integer or 'd' for float based on the column
if ($column === 'worked_days') {
    $value = intval($value); // Convert to integer
    $stmt->bind_param("is", $value, $vehicle_no);
} else {
    $value = floatval($value); // Convert to float
    $stmt->bind_param("ds", $value, $vehicle_no);
}

$result = $stmt->execute();

if ($result) {
    echo json_encode(['status' => 'success', 'message' => 'Update successful.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database update failed.']);
}

$stmt->close();
$conn->close();
?>