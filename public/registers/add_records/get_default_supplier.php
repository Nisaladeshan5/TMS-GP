<?php
// get_default_supplier.php (AJAX endpoint to find a route's default supplier)

// --- DEBUGGING LINES (Keep for now, but clean up later) ---
ini_set('display_errors', 0);
error_reporting(0); 
// --- END DEBUGGING ---

header('Content-Type: application/json');

require_once '../../../includes/session_check.php'; // Check path is correct relative to this file
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include('../../../includes/db.php'); // Check path is correct relative to this file

$route_code = $_GET['route_code'] ?? '';

if (empty($route_code)) {
    echo json_encode(['success' => false, 'message' => 'Route code missing.']);
    // Removed $conn->close() here as it closes below, but ensures proper exit.
    exit();
}

// Fetch the default supplier_code assigned to this route
$sql = "SELECT supplier_code FROM route WHERE route_code = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB Prepare Error. Check SQL.']);
    $conn->close();
    exit();
}

$stmt->bind_param("s", $route_code);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
$stmt->close();
$conn->close();

if ($data && !empty($data['supplier_code'])) {
    echo json_encode(['success' => true, 'supplier_code' => $data['supplier_code']]);
} else {
    echo json_encode(['success' => false, 'message' => 'No default supplier found for this route.']);
}