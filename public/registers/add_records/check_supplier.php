<?php
// check_supplier.php
include('../../../includes/db.php');

header('Content-Type: application/json');

$response = ['exists' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $supplierCode = $input['supplier_code'] ?? '';

    if (!empty($supplierCode)) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM supplier WHERE supplier_code = ?");
        $stmt->bind_param("s", $supplierCode);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count > 0) {
            $response['exists'] = true;
        }
    }
}

echo json_encode($response);
?>