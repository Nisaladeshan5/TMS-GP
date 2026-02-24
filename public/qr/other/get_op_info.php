<?php
ob_start();
include('../../../includes/db.php');

ob_clean();
header('Content-Type: application/json');

$input = file_get_contents('php://input');
$data = json_decode($input, true);
$op_code = $data['op_code'] ?? '';

if (empty($op_code)) {
    echo json_encode(['success' => false, 'message' => 'No code provided']);
    exit;
}

$stmt = $conn->prepare("SELECT vehicle_no, supplier_code FROM op_services WHERE op_code = ?");
$stmt->bind_param("s", $op_code);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'success' => true, 
        'vehicle_no' => $row['vehicle_no'], 
        'supplier_code' => $row['supplier_code']
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid Operational Code!']);
}