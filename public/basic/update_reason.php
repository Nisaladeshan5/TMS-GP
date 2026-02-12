<?php
require_once '../../includes/session_check.php';
include('../../includes/db.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit;
    }

    $reason_code = $_POST['reason_code'] ?? '';
    $new_reason = trim($_POST['reason'] ?? '');

    if (empty($reason_code) || empty($new_reason)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE reason SET reason = ? WHERE reason_code = ?");
    $stmt->bind_param("ss", $new_reason, $reason_code);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $stmt->error]);
    }
    
    $stmt->close();
    $conn->close();
}
?>