<?php
// check_supplier.php - CORRECTED
include('../../../includes/db.php');

date_default_timezone_set('Asia/Colombo');

header('Content-Type: application/json');

$response = ['exists' => false, 'message' => 'Invalid or unauthorized supplier code.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $supplierCode = $input['supplier_code'] ?? '';

    if (!empty($supplierCode)) {
        
        // **NEW LOGIC:** Check if the scanned $supplierCode exists as an 'owner_code'
        // or equivalent link for a vehicle that has 'purpose = night_emergency'.
        // ASSUMPTION: 'owner_code' is the column in the 'vehicle' table that holds the supplier/owner identifier.
        // PLEASE ADJUST 'owner_code' if your column name is different (e.g., 'supplier_code', 'owner_id', etc.).
        
        $sql = "
            SELECT COUNT(*) 
            FROM vehicle 
            WHERE supplier_code = ? 
            AND purpose = 'night_emergency'
        ";

        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
             // Handle SQL preparation error
             $response['message'] = 'SQL Prepare failed: ' . $conn->error;
        } else {
            $stmt->bind_param("s", $supplierCode);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();

            if ($count > 0) {
                $response['exists'] = true;
                $response['message'] = 'Supplier verified. Proceed to vehicle details.';
            } else {
                // If count is 0, the supplier either doesn't exist OR has no night_emergency vehicles.
                $response['message'] = 'Supplier is not authorized or has no active night emergency vehicles.';
            }
        }
    } else {
         $response['message'] = 'Supplier code is empty.';
    }
}

echo json_encode($response);
?>