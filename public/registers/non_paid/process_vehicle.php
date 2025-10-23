<?php
// Database connection file එක include කරන්න
include('../../../includes/db.php');

// HTTP POST request එකක්දැයි පරීක්ෂා කිරීම
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. Variable initialization
    $action = $_POST['action'] ?? '';
    $emp_id = $_POST['emp_id'] ?? '';
    
    // Default response setup
    $response = ['success' => false, 'message' => ''];
    $stmt = null;

    // 2. Action based processing
    try {
        
        if ($action == 'add' || $action == 'edit') {
            // Add/Edit Operations: Need all fields
            $vehicle_no = $_POST['vehicle_no'] ?? '';
            $distance = $_POST['distance'] ?? null;
            $fuel_efficiency = $_POST['fuel_efficiency'] ?? ''; // c_id
            $rate_id = $_POST['rate_id'] ?? ''; // rate_id

            // Input Validation
            if (empty($emp_id) || empty($vehicle_no) || empty($fuel_efficiency) || empty($rate_id)) {
                $response['message'] = 'Error: Required fields are missing for Add/Edit operation.';
                http_response_code(400); 
                echo json_encode($response);
                exit();
            }
            
            $distance = floatval($distance);

            if ($action == 'add') {
                // --- INSERT Operation ---
                
                // Check for duplicate entry
                $check_sql = "SELECT emp_id FROM own_vehicle WHERE emp_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("s", $emp_id);
                $check_stmt->execute();
                $check_stmt->store_result();

                if ($check_stmt->num_rows > 0) {
                    $response['message'] = 'Error: This employee already has a vehicle record.';
                    http_response_code(409); // Conflict
                } else {
                    $sql = "INSERT INTO own_vehicle (emp_id, vehicle_no, distance, fuel_efficiency, rate_id) VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    // ssddi: string, string, double, double, integer
                    $stmt->bind_param("ssddi", $emp_id, $vehicle_no, $distance, $fuel_efficiency, $rate_id);
                    
                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'New vehicle record added successfully!';
                        http_response_code(201); // Created
                    } else {
                        $response['message'] = 'Error adding vehicle record: ' . $stmt->error;
                        http_response_code(500);
                    }
                }
                $check_stmt->close();
                
            } elseif ($action == 'edit') {
                // --- UPDATE Operation ---
                $original_emp_id = $_POST['original_emp_id'] ?? $emp_id;
                
                $sql = "UPDATE own_vehicle SET vehicle_no = ?, distance = ?, fuel_efficiency = ?, rate_id = ? WHERE emp_id = ?";
                $stmt = $conn->prepare($sql);
                // sddis: string, double, double, integer, string
                $stmt->bind_param("sddis", $vehicle_no, $distance, $fuel_efficiency, $rate_id, $original_emp_id);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    if ($stmt->affected_rows > 0) {
                        $response['message'] = 'Vehicle record updated successfully!';
                    } else {
                         // No changes made or no record found (though technically we should find it)
                        $response['message'] = 'Vehicle record updated successfully (No changes detected).'; 
                    }
                    http_response_code(200);
                } else {
                    $response['message'] = 'Error updating vehicle record: ' . $stmt->error;
                    http_response_code(500);
                }
            }
            
        } elseif ($action == 'delete') {
            // --- DELETE Operation ---
            if (empty($emp_id)) {
                $response['message'] = 'Error: Employee ID is missing for deletion.';
                http_response_code(400); 
            } else {
                $sql = "DELETE FROM own_vehicle WHERE emp_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $emp_id);
                
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $response['success'] = true;
                        $response['message'] = 'Vehicle record deleted successfully!';
                        http_response_code(200);
                    } else {
                        $response['message'] = 'Error: No matching vehicle record found to delete.';
                        http_response_code(404); // Not Found
                    }
                } else {
                    $response['message'] = 'Error deleting vehicle record: ' . $stmt->error;
                    http_response_code(500);
                }
            }
        } else {
            // Action එක හඳුනාගත නොහැකි නම්
            $response['message'] = 'Error: Invalid action specified.';
            http_response_code(400); // Bad Request
        }
        
    } catch (Exception $e) {
        // පොදු දෝෂ හැසිරවීම
        $response['message'] = 'An unexpected error occurred: ' . $e->getMessage();
        http_response_code(500);
    } finally {
        // Statement එක වසා දැමීම
        if ($stmt) {
            $stmt->close();
        }
        // Database සම්බන්ධතාවය වසා දැමීම
        $conn->close();
    }
    
    // JSON ප්‍රතිචාරය ආපසු යැවීම
    header('Content-Type: application/json');
    echo json_encode($response);
    
} else {
    // POST නොවන request එකක් නම්
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Error: Method not allowed.']);
}
?>