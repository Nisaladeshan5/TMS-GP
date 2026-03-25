<?php
// fetch_details.php
include('../../../../includes/db.php'); // ඔබේ db path එක නිවැරදිදැයි බලන්න

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Invalid Request.'];

if (isset($_GET['code_type']) && isset($_GET['trip_code'])) {
    
    $code_type = trim($_GET['code_type']);
    $trip_code = trim($_GET['trip_code']);
    
    $supplier_code = null;
    $vehicle_no = null;

    if ($code_type === 'Route') {
        // --- ROUTE LOOKUP ---
        // route table එකෙන් supplier සහ vehicle ලබා ගැනීම
        $sql = "SELECT supplier_code, vehicle_no FROM route WHERE route_code = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $trip_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $supplier_code = $row['supplier_code'];
            $vehicle_no = $row['vehicle_no'];
        }
        $stmt->close();
        
    } elseif ($code_type === 'Sub_Route') {
        // --- SUB ROUTE LOOKUP (NEW) ---
        // sub_route table එකෙන් supplier සහ vehicle ලබා ගැනීම
        $sql = "SELECT supplier_code, vehicle_no FROM sub_route WHERE sub_route_code = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $trip_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $supplier_code = $row['supplier_code'];
            $vehicle_no = $row['vehicle_no'];
        }
        $stmt->close();

    } elseif ($code_type === 'Operation') {
        // --- OPERATION LOOKUP ---
        // දැන් කෙලින්ම op_services table එකේ ඇති supplier_code එක ලබා ගනී
        $sql = "SELECT supplier_code, vehicle_no FROM op_services WHERE op_code = ? LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $trip_code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // වාහනය මත පදනම් නොවී, Operation එකට අදාළ Supplier ලබා ගනී
            $supplier_code = $row['supplier_code'];
            $vehicle_no = $row['vehicle_no'];
        }
        $stmt->close();
    }

    // දත්ත පරීක්ෂාව
    if ($supplier_code) {
        $response = [
            'success' => true,
            'supplier_code' => $supplier_code,
            'vehicle_no' => $vehicle_no 
        ];
    } else {
        $response = [
            'success' => false,
            'message' => 'Supplier not assigned to this ' . $code_type,
            'supplier_code' => null,
            'vehicle_no' => null
        ];
    }

} else {
    $response['message'] = 'Code or type missing.';
}

echo json_encode($response);
$conn->close();
?>