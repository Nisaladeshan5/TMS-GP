<?php
// process_add_extra_vehicle.php

require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

include('../../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

try {
    // 1. Sanitize Inputs
    $vehicle_no = trim($_POST['vehicle_no'] ?? '');
    
    // Supplier Logic
    $auto_filled_supplier = trim($_POST['supplier_code_hidden'] ?? ''); 
    $manually_selected_supplier = trim($_POST['supplier_code'] ?? '');     
    $supplier_code = !empty($auto_filled_supplier) ? $auto_filled_supplier : $manually_selected_supplier;

    $from_location = trim($_POST['from_location'] ?? '');
    $to_location = trim($_POST['to_location'] ?? '');
    $trip_date = trim($_POST['date'] ?? '');
    $trip_time = trim($_POST['time'] ?? '');
    $distance = floatval($_POST['distance'] ?? 0);
    $done_flag = ($distance > 0) ? 1 : 0;

    $selected_route_code = trim($_POST['route_code'] ?? '');
    $selected_op_code = trim($_POST['op_code'] ?? '');
    
    $trip_op_code = null;
    $trip_route_code = null;

    if (!empty($selected_route_code)) {
        $trip_route_code = $selected_route_code;
    } elseif (!empty($selected_op_code)) {
        $trip_op_code = $selected_op_code;
    }
    
    $ac_status = isset($_POST['ac_status']) ? (int)$_POST['ac_status'] : 0;
    $reason_groups = $_POST['reason_group'] ?? []; 
    $emp_id_groups = $_POST['emp_id_group'] ?? [];

    // 2. Basic Validation
    $missing = [];
    if (empty($vehicle_no)) $missing[] = 'Vehicle No';
    if (empty($supplier_code)) $missing[] = 'Supplier Code';
    if (empty($from_location)) $missing[] = 'From Location';
    if (empty($to_location)) $missing[] = 'To Location';
    if (empty($trip_date)) $missing[] = 'Date';
    if (empty($trip_time)) $missing[] = 'Time';
    if (empty($trip_route_code) && empty($trip_op_code)) $missing[] = 'Route or Op Code';
    
    if (empty($reason_groups) || empty($emp_id_groups)) {
        $missing[] = 'At least one Reason & Employee';
    }

    if (!empty($missing)) {
        echo json_encode(['success' => false, 'message' => 'Missing fields: ' . implode(', ', $missing)]);
        exit();
    } 

    // =======================================================================
    // 3. ID VALIDATION (DUPLICATES + DB CHECK)
    // =======================================================================
    
    // A. Collect all submitted IDs from all groups into a single list
    $all_submitted_ids = [];
    foreach ($emp_id_groups as $group) {
        if (is_array($group)) {
            foreach ($group as $id) {
                $clean_id = trim($id);
                if (!empty($clean_id)) {
                    $all_submitted_ids[] = $clean_id;
                }
            }
        }
    }

    // B. NEW: CHECK FOR DUPLICATES WITHIN THE TRIP
    // array_count_values will count how many times each ID appears
    if (!empty($all_submitted_ids)) {
        $id_counts = array_count_values($all_submitted_ids);
        $duplicate_ids = [];

        foreach ($id_counts as $id => $count) {
            if ($count > 1) {
                $duplicate_ids[] = $id;
            }
        }

        if (!empty($duplicate_ids)) {
            echo json_encode([
                'success' => false, 
                'message' => "Duplicate Error: Employee ID(s) repeated in this trip: " . implode(', ', $duplicate_ids)
            ]);
            exit(); // Stop execution
        }
    }

    // C. Check against database (using unique list to be efficient)
    $unique_ids = array_unique($all_submitted_ids);

    if (!empty($unique_ids)) {
        $count = count($unique_ids);
        $placeholders = implode(',', array_fill(0, $count, '?'));
        $types = str_repeat('i', $count); // Assuming integer IDs
        
        $sql_check = "SELECT emp_id FROM employee WHERE emp_id IN ($placeholders)";
        $stmt_check = $conn->prepare($sql_check);
        
        if ($stmt_check) {
            $stmt_check->bind_param($types, ...$unique_ids);
            $stmt_check->execute();
            $result = $stmt_check->get_result();
            
            $found_ids = [];
            while ($row = $result->fetch_assoc()) {
                $found_ids[] = $row['emp_id'];
            }
            $stmt_check->close();
            
            $invalid_ids = array_diff($unique_ids, $found_ids);
            
            if (!empty($invalid_ids)) {
                echo json_encode([
                    'success' => false, 
                    'message' => "Validation Error: The following Employee IDs do not exist: " . implode(', ', $invalid_ids)
                ]);
                exit(); 
            }
        } else {
            throw new Exception("Database error during validation: " . $conn->error);
        }
    }
    // =======================================================================
    // END VALIDATION BLOCK
    // =======================================================================

    // 4. Start Transaction
    $conn->begin_transaction();
        
    // A. Insert into extra_vehicle_register
    $sql_main = "INSERT INTO extra_vehicle_register 
                    (vehicle_no, supplier_code, from_location, to_location, date, time, distance, done, op_code, route, ac_status, user_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"; 
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $stmt_main = $conn->prepare($sql_main);
    
    $stmt_main->bind_param("ssssssdissii", 
        $vehicle_no, $supplier_code, $from_location, $to_location, $trip_date, $trip_time, 
        $distance, $done_flag, 
        $trip_op_code, $trip_route_code, 
        $ac_status, $user_id
    );

    if (!$stmt_main->execute()) {
        throw new Exception("Error inserting trip: " . $stmt_main->error);
    }

    $trip_id = $conn->insert_id;
    $stmt_main->close();

    // B. Insert into ev_trip_employee_reasons
    $sql_details = "INSERT INTO ev_trip_employee_reasons (trip_id, reason_code, emp_id) VALUES (?, ?, ?)";
    $stmt_details = $conn->prepare($sql_details);

    foreach ($reason_groups as $group_index => $reason_code) {
        if (!empty($reason_code) && isset($emp_id_groups[$group_index]) && is_array($emp_id_groups[$group_index])) {
            foreach ($emp_id_groups[$group_index] as $emp_id) {
                $clean_emp_id = trim($emp_id);
                if (!empty($clean_emp_id)) {
                    $stmt_details->bind_param("iss", $trip_id, $reason_code, $clean_emp_id);
                    if (!$stmt_details->execute()) {
                        throw new Exception("Error inserting details for ID: {$clean_emp_id}");
                    }
                }
            }
        }
    }
    $stmt_details->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Trip added successfully!']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>