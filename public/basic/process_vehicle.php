<?php
// process_vehicle.php

// 1. Session & Headers
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json'); // Return JSON response

// 2. Database Connection
include('../../includes/db.php');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

// 3. Handle Actions
$action = $_POST['action'] ?? '';

try {
    // පොදු දත්ත ලබා ගැනීම (Common Data)
    $emp_id = $_POST['emp_id'] ?? '';
    $vehicle_no = $_POST['vehicle_no'] ?? '';
    $rate_id = $_POST['rate_id'] ?? '';
    
    // 0 අගයන් ලබා ගැනීමට පහත විදියට වෙනස් කරන ලදි
    $distance = $_POST['distance'] ?? '';
    $type = $_POST['type'] ?? '';
    $fixed_amount = $_POST['fixed_amount'] ?? '';
    
    // Form එකේ නම consumption_value හෝ consumption වෙන්න පුළුවන්
    $consumption = $_POST['consumption_value'] ?? $_POST['consumption'] ?? '';

    if ($action === 'add') {
        // --- ADD NEW VEHICLE ---
        
        // Validation - වෙනස් කරන ලදි
        // empty() වෙනුවට === '' භාවිතා කලේ 0 අගයන් වලංගු කිරීමටයි
        if (empty($emp_id) || empty($vehicle_no) || empty($type) || $consumption === '' || empty($rate_id) || $distance === '' || $fixed_amount === '') {
            throw new Exception("All fields are required.");
        }

        // Check duplicates
        $check_sql = "SELECT vehicle_no FROM own_vehicle WHERE emp_id = ? AND vehicle_no = ?";
        $stmt_check = $conn->prepare($check_sql);
        $stmt_check->bind_param("ss", $emp_id, $vehicle_no);
        $stmt_check->execute();
        if ($stmt_check->fetch()) {
            throw new Exception("This vehicle number already exists for this employee.");
        }
        $stmt_check->close();

        // Insert
        $sql = "INSERT INTO own_vehicle (emp_id, vehicle_no, rate_id, distance, type, fixed_amount, fuel_efficiency) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        // Types: s(str), s(str), i(int), d(double), s(str), d(double), s(str)
        $stmt->bind_param("ssidsds", $emp_id, $vehicle_no, $rate_id, $distance, $type, $fixed_amount, $consumption);
        
        if ($stmt->execute()) {
            $_SESSION['toast'] = ['type' => 'success', 'message' => 'Vehicle added successfully!'];
            echo json_encode(['success' => true, 'message' => 'Vehicle added successfully!']);
        } else {
            throw new Exception("Error adding vehicle: " . $stmt->error);
        }
        $stmt->close();

    } elseif ($action === 'edit') {
        // --- EDIT EXISTING VEHICLE ---
        
        $original_emp_id = $_POST['original_emp_id'] ?? $emp_id;
        $original_vehicle_no = $_POST['original_vehicle_no'] ?? ''; 

        if (empty($original_emp_id) || empty($original_vehicle_no)) {
            throw new Exception("Missing original data for update (ID or Vehicle No).");
        }
        
        // Validation - වෙනස් කරන ලදි (Edit එකේදිත් 0 වලංගු විය යුතුයි)
        if (empty($vehicle_no) || empty($type) || $consumption === '' || empty($rate_id) || $distance === '' || $fixed_amount === '') {
            throw new Exception("All fields are required.");
        }

        // 1. නම්බර් එක වෙනස් කරලා නම්, අලුත් නම්බර් එක ඩේටාබේස් එකේ තියෙනවද බලන්න ඕන
        if ($vehicle_no !== $original_vehicle_no) {
            $check_sql = "SELECT vehicle_no FROM own_vehicle WHERE emp_id = ? AND vehicle_no = ?";
            $stmt_check = $conn->prepare($check_sql);
            $stmt_check->bind_param("ss", $original_emp_id, $vehicle_no);
            $stmt_check->execute();
            if ($stmt_check->fetch()) {
                throw new Exception("Cannot update: The new vehicle number ($vehicle_no) already exists.");
            }
            $stmt_check->close();
        }

        // 2. Update Query
        $sql = "UPDATE own_vehicle 
                SET vehicle_no = ?, 
                    rate_id = ?, 
                    distance = ?, 
                    type = ?, 
                    fixed_amount = ?, 
                    fuel_efficiency = ? 
                WHERE emp_id = ? AND vehicle_no = ?";
                
        $stmt = $conn->prepare($sql);
        // Types: s, i, d, s, d, s, s, s
        $stmt->bind_param("sidsdsss", $vehicle_no, $rate_id, $distance, $type, $fixed_amount, $consumption, $original_emp_id, $original_vehicle_no);

        if ($stmt->execute()) {
            $_SESSION['toast'] = ['type' => 'success', 'message' => 'Vehicle details updated successfully!'];
            echo json_encode(['success' => true, 'message' => 'Vehicle updated successfully!']);
        } else {
            throw new Exception("Error updating vehicle: " . $stmt->error);
        }
        $stmt->close();

    } elseif ($action === 'delete') {
        // --- DELETE VEHICLE ---
        
        if (empty($emp_id) || empty($vehicle_no)) {
            throw new Exception("Employee ID and Vehicle Number are required for deletion.");
        }

        $sql = "DELETE FROM own_vehicle WHERE emp_id = ? AND vehicle_no = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $emp_id, $vehicle_no);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Vehicle record deleted successfully.']);
            } else {
                throw new Exception("No matching record found to delete.");
            }
        } else {
            throw new Exception("Error deleting record: " . $stmt->error);
        }
        $stmt->close();

    } else {
        throw new Exception("Invalid action requested.");
    }

} catch (Exception $e) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

if (isset($conn) && $conn->ping()) {
    $conn->close();
}
?>