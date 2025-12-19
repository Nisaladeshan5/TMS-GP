<?php
// fetch_details.php
include('../../../../includes/db.php'); // Adjust path as needed

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Invalid Request.'];

if (isset($_GET['code_type']) && isset($_GET['trip_code'])) {
    
    $code_type = trim($_GET['code_type']);
    $trip_code = trim($_GET['trip_code']);
    
    $supplier_code = null;
    $vehicle_no = null;

    if ($code_type === 'Route') {
        // --- ROUTE CODE LOOKUP (SIMPLIFIED) ---
        // We now assume the 'route' table directly contains both supplier_code and vehicle_no
        $sql = "SELECT supplier_code, vehicle_no FROM route WHERE route_code = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $trip_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            // Capture both values from the single query
            $supplier_code = $row['supplier_code'];
            $vehicle_no = $row['vehicle_no']; // Capture vehicle_no directly
        }
        $stmt->close(); // Statement closing is necessary
        
    } elseif ($code_type === 'Operation') {
        // --- OPERATION CODE LOOKUP (Logic remains the same, as it links vehicle_no to supplier) ---
        
        // 1. Get vehicle_no from the 'op_services' table
        $sql_op = "SELECT vehicle_no FROM op_services WHERE op_code = ?";
        $stmt_op = $conn->prepare($sql_op);
        $stmt_op->bind_param("s", $trip_code);
        $stmt_op->execute();
        $result_op = $stmt_op->get_result();

        if ($result_op->num_rows > 0) {
            $row_op = $result_op->fetch_assoc();
            $vehicle_no = $row_op['vehicle_no'];

            // 2. Use the vehicle_no to find the supplier_code from the 'vehicle' table
            if ($vehicle_no) {
                $sql_veh = "SELECT supplier_code FROM vehicle WHERE vehicle_no = ?";
                $stmt_veh = $conn->prepare($sql_veh);
                $stmt_veh->bind_param("s", $vehicle_no);
                $stmt_veh->execute();
                $result_veh = $stmt_veh->get_result();

                if ($result_veh->num_rows > 0) {
                    $row_veh = $result_veh->fetch_assoc();
                    $supplier_code = $row_veh['supplier_code'];
                }
                $stmt_veh->close();
            }
        }
        $stmt_op->close();
    }

    // --- Final Response Check ---
    if ($supplier_code) {
        $response = [
            'success' => true,
            'supplier_code' => $supplier_code,
            'vehicle_no' => $vehicle_no 
        ];
    } else {
        $response = [
            'success' => false,
            'message' => 'Code not found or primary data (Supplier/Vehicle) missing.',
            'supplier_code' => null,
            'vehicle_no' => null
        ];
    }

} else {
    $response['message'] = 'Code or type not provided.';
}

echo json_encode($response);
$conn->close();
?>