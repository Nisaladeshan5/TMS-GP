<?php
// save_sub_route_adjustment.php
require_once '../../../../includes/session_check.php';
include('../../../../includes/db.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $sub_route_code = $input['sub_route_code'] ?? '';
    $month = (int)($input['month'] ?? 0);
    $year = (int)($input['year'] ?? 0);
    $type = $input['type'] ?? ''; // 'add' or 'deduct'
    $quantity = (int)($input['quantity'] ?? 0); // අලුත් කොටස: දවස් ගණන
    $reason = trim($input['reason'] ?? '');

    if (empty($sub_route_code) || empty($reason) || $month == 0 || $year == 0 || $quantity <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid input data']);
        exit;
    }

    // දවස් ගණන ධන හෝ ඍණ ලෙස සකස් කිරීම
    $adjustment_days = ($type === 'add') ? $quantity : -1 * $quantity;

    $stmt = $conn->prepare("INSERT INTO sub_route_adjustments (sub_route_code, month, year, adjustment_days, reason) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("siiss", $sub_route_code, $month, $year, $adjustment_days, $reason);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    $stmt->close();
}
$conn->close();
?>