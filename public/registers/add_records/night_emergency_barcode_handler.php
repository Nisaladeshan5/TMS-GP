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

    $supplier_code = $_POST['supplier_code'] ?? '';
    $vehicle_no = $_POST['vehicle_no'] ?? '';
    $driver_nic = $_POST['driver_nic'] ?? '';
    $vehicle_status = $_POST['vehicle_status'] ?? 0;
    $driver_status = $_POST['driver_status'] ?? 0;
    
    // Validate required fields
    if (empty($supplier_code) || empty($vehicle_no) || empty($driver_nic)) {
        echo json_encode(['status' => 'error', 'message' => 'All required fields must be filled.']);
        exit;
    }
    
    // Get current date, time, month, and year
    $current_datetime = new DateTime('now', new DateTimeZone('Asia/Colombo'));
    $date = $current_datetime->format('Y-m-d');
    $report_time = $current_datetime->format('H:i:s');
    $current_month = $current_datetime->format('m'); 
    $current_year = $current_datetime->format('Y');
    $day_rate = 0.00;
    
    try {
        // ====================================================================
        // PART 1: FETCH THE APPLICABLE DAY RATE (Latest rate <= current date)
        // ====================================================================
        $rate_sql = "
            SELECT day_rate 
            FROM night_emergency_day_rate 
            WHERE last_updated_date <= ? 
            ORDER BY last_updated_date DESC 
            LIMIT 1
        ";
        
        $rate_stmt = $conn->prepare($rate_sql);
        $rate_stmt->bind_param("s", $date); 
        $rate_stmt->execute();
        $rate_result = $rate_stmt->get_result();
        
        if ($rate_row = $rate_result->fetch_assoc()) {
            $day_rate = (float)$rate_row['day_rate'];
        } else {
            error_log("Payment Rate Error: No applicable day_rate found on or before $date.");
        }
        $rate_stmt->close();

        // ====================================================================
        // PART 2: CHECK ATTENDANCE (Prevent double-logging for the day)
        // ====================================================================
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM night_emergency_attendance WHERE supplier_code = ? AND date = ?");
        $check_stmt->bind_param("ss", $supplier_code, $date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $row = $check_result->fetch_row();
        $count = $row[0];
        $check_stmt->close();

        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Attendance for this supplier code has already been recorded today.']);
            exit;
        }

        // ====================================================================
        // PART 3: INSERT ATTENDANCE & UPDATE/INSERT MONTHLY PAYMENTS/ALLOCATIONS
        // ====================================================================
        
        // Begin Transaction to ensure all updates are atomic
        $conn->begin_transaction();
        
        // Step 3a: Insert Attendance Record
        $insert_attendance_stmt = $conn->prepare("INSERT INTO night_emergency_attendance (supplier_code, vehicle_no, driver_NIC, date, report_time, vehicle_status, driver_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_attendance_stmt->bind_param("sssssii", $supplier_code, $vehicle_no, $driver_nic, $date, $report_time, $vehicle_status, $driver_status);
        
        if (!$insert_attendance_stmt->execute()) {
            $conn->rollback();
            $insert_attendance_stmt->close();
            echo json_encode(['status' => 'error', 'message' => 'Failed to record attendance. Please try again. ❌']);
            exit;
        }
        $insert_attendance_stmt->close();

        // Step 3b & 3c: Update/Insert Monthly Payment & Consolidated Allocation
        if ($day_rate > 0) {
            
            // --- Step 3b: Update monthly_payment_ne (Supplier Payment) ---
            $update_payment_sql = "
                INSERT INTO monthly_payment_ne 
                (supplier_code, month, year, monthly_payment) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                monthly_payment = monthly_payment + VALUES(monthly_payment)
            ";
            
            $update_payment_stmt = $conn->prepare($update_payment_sql);
            $update_payment_stmt->bind_param("sssd", $supplier_code, $current_month, $current_year, $day_rate);
            
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
            $di_type = 'YES'; // Night Emergency is assumed to be a Direct Cost (Production related)
            
            $update_allocation_sql = "
                INSERT INTO monthly_cost_allocation
                (supplier_code, gl_code, department, direct, month, year, monthly_allocation) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                monthly_allocation = monthly_allocation + VALUES(monthly_allocation)
            ";
            
            $update_allocation_stmt = $conn->prepare($update_allocation_sql);
            
            $update_allocation_stmt->bind_param(
                "sssssid", // supplier_code(s), gl_code(s), department(s), direct(s), month(s), year(i), monthly_allocation(d)
                $supplier_code, 
                $gl_code_allocation, 
                $department_allocation, 
                $di_type, // 'YES'
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

            // ❌ REMOVED: Step 3d (direct_indirect_cost_allocation update) is now obsolete
            
        } // End of if ($day_rate > 0)

        // Commit transaction if all operations succeeded
        $conn->commit();
        
        // Final success message
        echo json_encode(['status' => 'success', 'message' => 'Attendance recorded and all monthly payments/allocations updated successfully. ✅']);

    } catch (Exception $e) {
        // Rollback on any Exception
        $conn->rollback();
        error_log("Transaction Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'An internal server error occurred.']);
    }

    if (isset($conn)) $conn->close();

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>