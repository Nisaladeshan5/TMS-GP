<?php
// night_emergency_barcode_handler.php
include('../../../includes/db.php');

date_default_timezone_set('Asia/Colombo');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['verify'])) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify the details before submitting.']);
        exit;
    }

    $operational_code = $_POST['operational_code'] ?? ''; 
    $vehicle_no = $_POST['vehicle_no'] ?? '';
    $driver_nic = $_POST['driver_nic'] ?? '';
    $vehicle_status = $_POST['vehicle_status'] ?? 0;
    $driver_status = $_POST['driver_status'] ?? 0;
    
    // Validate required fields
    if (empty($operational_code) || empty($vehicle_no) || empty($driver_nic)) {
        echo json_encode(['status' => 'error', 'message' => 'All required fields must be filled.']);
        exit;
    }
    
    $current_datetime = new DateTime('now', new DateTimeZone('Asia/Colombo'));
    $date = $current_datetime->format('Y-m-d');
    $report_time = $current_datetime->format('H:i:s');
    $current_month = $current_datetime->format('m'); 
    $current_year = $current_datetime->format('Y');
    $day_rate = 0.00;
    $linked_supplier_code = ''; // New variable to store the fetched supplier code
    
    try {
        // ====================================================================
        // PART 1: FETCH DAY RATE AND SUPPLIER CODE
        // JOIN op_services (for day_rate based on op_code) with vehicle (for supplier_code based on vehicle_no)
        // ====================================================================
        $rate_sql = "
            SELECT 
                o.day_rate, 
                v.supplier_code 
            FROM op_services AS o
            LEFT JOIN vehicle AS v ON o.vehicle_no = v.vehicle_no
            WHERE o.op_code = ?
        ";
        
        $rate_stmt = $conn->prepare($rate_sql);
        // Bind op_code (from scanner) and vehicle_no (from dropdown/input)
        $rate_stmt->bind_param("s", $operational_code); 
        $rate_stmt->execute();
        $rate_result = $rate_stmt->get_result();
        
        if ($rate_row = $rate_result->fetch_assoc()) {
            $day_rate = (float)$rate_row['day_rate'];
            // *** CORRECTION 1: Fetch supplier_code from the joined result ***
            $linked_supplier_code = $rate_row['supplier_code'] ?? ''; 
            
            if (empty($linked_supplier_code)) {
                error_log("Missing Data Error: Supplier code is NULL for Vehicle: $vehicle_no.");
                echo json_encode(['status' => 'error', 'message' => 'Vehicle is not linked to a supplier. Contact admin.']);
                exit;
            }

        } else {
            error_log("Payment Rate Error: No record found for OP Code: $operational_code and Vehicle: $vehicle_no.");
            echo json_encode(['status' => 'error', 'message' => 'Operational code or Vehicle combination is invalid/missing rate.']);
            exit;
        }
        $rate_stmt->close();

        // ====================================================================
        // PART 2: CHECK ATTENDANCE (Prevent double-logging for the day)
        // ====================================================================
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM night_emergency_attendance WHERE op_code = ? AND date = ?");
        $check_stmt->bind_param("ss", $operational_code, $date); 
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $row = $check_result->fetch_row();
        $count = $row[0];
        $check_stmt->close();

        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Attendance for this operational code has already been recorded today.']);
            exit;
        }

        // ====================================================================
        // PART 3: INSERT ATTENDANCE & UPDATE/INSERT MONTHLY PAYMENTS/ALLOCATIONS
        // ====================================================================
        
        $conn->begin_transaction();
        
        // Step 3a: Insert Attendance Record
        // We DO NOT insert supplier_code into night_emergency_attendance (based on your table structure)
        $insert_attendance_stmt = $conn->prepare("INSERT INTO night_emergency_attendance (op_code, vehicle_no, driver_NIC, date, report_time, vehicle_status, driver_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_attendance_stmt->bind_param("sssssii", $operational_code, $vehicle_no, $driver_nic, $date, $report_time, $vehicle_status, $driver_status);
        
        if (!$insert_attendance_stmt->execute()) {
            $conn->rollback();
            $insert_attendance_stmt->close();
            error_log("Attendance Insert Error: " . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Failed to record attendance. Please try again. ❌']);
            exit;
        }
        $insert_attendance_stmt->close();

        // Step 3b & 3c: Update/Insert Monthly Payment & Consolidated Allocation
        if ($day_rate > 0 && !empty($linked_supplier_code)) {
            
            // --- Step 3b: Update monthly_payment_ne (Supplier Payment) ---
            // *** CORRECTION 2: Use supplier_code column and linked_supplier_code variable ***
            $update_payment_sql = "
                INSERT INTO monthly_payment_ne 
                (supplier_code, month, year, monthly_payment) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                monthly_payment = monthly_payment + VALUES(monthly_payment)
            ";
            
            $update_payment_stmt = $conn->prepare($update_payment_sql);
            $update_payment_stmt->bind_param("sssd", $linked_supplier_code, $current_month, $current_year, $day_rate);
            
            if (!$update_payment_stmt->execute()) {
                $conn->rollback();
                $update_payment_stmt->close();
                error_log("Payment Update Error (monthly_payment_ne): " . $conn->error);
                echo json_encode(['status' => 'error', 'message' => 'Attendance recorded, but failed to update supplier payment. Contact admin.']);
                exit;
            }
            $update_payment_stmt->close();

            // 🔑 --- Step 3c: Update CONSOLIDATED monthly_cost_allocation (GL/Department/Direct Cost) ---
            $gl_code_allocation = '614003'; 
            $department_allocation = 'Production'; 
            $cost_to_allocate = $day_rate; 
            $di_type = 'YES'; 
            
            $update_allocation_sql = "
                INSERT INTO monthly_cost_allocation
                (supplier_code, gl_code, department, direct, month, year, monthly_allocation) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                monthly_allocation = monthly_allocation + VALUES(monthly_allocation)
            ";
            
            $update_allocation_stmt = $conn->prepare($update_allocation_sql);
            $update_allocation_stmt->bind_param(
                "sssssid", 
                $linked_supplier_code, // *** CORRECTION 3: Use linked_supplier_code for cost allocation tracking ***
                $gl_code_allocation, 
                $department_allocation, 
                $di_type, 
                $current_month, 
                $current_year, 
                $cost_to_allocate
            );
            
            if (!$update_allocation_stmt->execute()) {
                $conn->rollback();
                $update_allocation_stmt->close();
                error_log("Payment Update Error (monthly_cost_allocation): " . $conn->error);
                echo json_encode(['status' => 'error', 'message' => 'Attendance recorded, but failed to update cost allocation. Contact admin.']);
                exit;
            }
            $update_allocation_stmt->close();
            
        } // End of if ($day_rate > 0)

        // Commit transaction if all operations succeeded
        $conn->commit();
        
        echo json_encode(['status' => 'success', 'message' => 'Attendance recorded and all monthly payments/allocations updated successfully. ✅']);

    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        error_log("Transaction Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'An internal server error occurred.']);
    }

    if (isset($conn)) $conn->close();

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>