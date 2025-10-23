<?php
// Force clean output
ob_start();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Colombo');

// Database connection
include('../../../includes/db.php'); 

$response = [];

// Fixed GL Code for Staff Transport
$staff_transport_gl_code = '623400';

// TRANSACTION START - Ensure atomicity for all database operations
$conn->autocommit(false); 

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method.");
    }

    // Collect POST data safely
    $route_code       = $_POST['route_code'] ?? null;
    $transaction_type = $_POST['transaction_type'] ?? null; // 'in' or 'out'
    $shift            = $_POST['shift'] ?? null;
    $record_id        = $_POST['existing_record_id'] ?? null;

    $entered_vehicle_no = $_POST['vehicle_no'] ?? null;
    $entered_driver_nic = $_POST['driver_nic'] ?? null;
    $vehicle_status   = $_POST['vehicle_status'] ?? null;
    $driver_status    = $_POST['driver_status'] ?? null;

    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    $current_date   = date('Y-m-d');
    $current_time   = date('H:i:s');
    $current_month  = date('m');
    $current_year   = date('Y');

    // ========== 1. Fetch default route details and Supplier Code ==========
    $stmt_default = $conn->prepare("
        SELECT r.route, r.vehicle_no, v.driver_NIC, r.fixed_amount, r.fuel_amount, r.distance, r.supplier_code
        FROM route r 
        JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
        WHERE r.route_code = ?
    ");
    $stmt_default->bind_param("s", $route_code);
    $stmt_default->execute();
    $result_default = $stmt_default->get_result();

    if (!$default_data = $result_default->fetch_assoc()) {
        throw new Exception("Route code not found or route/vehicle data incomplete.");
    }
    $stmt_default->close();

    $assigned_vehicle_no = $default_data['vehicle_no'];
    $assigned_driver_nic = $default_data['driver_NIC'];
    $fixed_amount        = (float)$default_data['fixed_amount'];
    $fuel_amount         = (float)$default_data['fuel_amount'];
    $distance            = (float)$default_data['distance'];
    $route_supplier_code = $default_data['supplier_code']; // 🔑 FETCH SUPPLIER CODE

    if (empty($route_supplier_code)) {
         throw new Exception("Supplier code is missing for route: " . $route_code);
    }

    // ========== 2. Determine actual vehicle/driver (Unchanged) ==========
    $actual_vehicle_no_to_store = ($vehicle_status == 0) ? $entered_vehicle_no : $assigned_vehicle_no;
    $driver_nic_to_store        = ($driver_status == 0) ? $entered_driver_nic : $assigned_driver_nic;

    // ========== 3. Trip rate and distance per trip (Unchanged) ==========
    $trip_rate = (($fixed_amount + $fuel_amount) * $distance / 2);
    $distance_per_trip = $distance / 2; 

    $transaction_success = false;

    // ========== 4. Handle IN/OUT (Database Register Insert/Update) (Unchanged) ==========
    if ($transaction_type === 'in') {
        // --- Transaction A: Log the vehicle IN transaction ---
        $stmt = $conn->prepare("
            INSERT INTO staff_transport_vehicle_register
            (route, vehicle_no, actual_vehicle_no, vehicle_status, driver_NIC, driver_status, shift, date, in_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sssssssss",
            $route_code,
            $assigned_vehicle_no,
            $actual_vehicle_no_to_store,
            $vehicle_status,
            $driver_nic_to_store,
            $driver_status,
            $shift,
            $current_date,
            $current_time
        );

        if (!$stmt->execute()) {
            throw new Exception("Database error (IN transaction): ".$stmt->error);
        }
        $stmt->close();
        $transaction_success = true;

        // ========== 5. Monthly payment, Distance, and COST ALLOCATION ==========
        $payment_vehicle_no = $assigned_vehicle_no;
        
        // --- Transaction B: Update monthly_payments_sf (Unchanged Logic) ---
        $update_sf_sql = "
            INSERT INTO monthly_payments_sf 
            (route_code, supplier_code, month, year, monthly_payment, total_distance) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            monthly_payment = monthly_payment + VALUES(monthly_payment), 
            total_distance = total_distance + VALUES(total_distance)
        ";

        $stmt_sf = $conn->prepare($update_sf_sql);
        if ($stmt_sf === false) {
             throw new Exception("SF Payment Prepare Failed: " . $conn->error);
        }
        $stmt_sf->bind_param("ssisdd", $route_code, $route_supplier_code, $current_month, $current_year, $trip_rate, $distance_per_trip);
        
        if (!$stmt_sf->execute()) {
            throw new Exception("SF Payment Update Failed: " . $stmt_sf->error);
        }
        $stmt_sf->close();
        
        // --- Transaction C: Calculate and Allocate Trip Cost to Departments ---

        // 5a. Get total number of employees and their direct/indirect status for this route
        // NOTE: employee.direct contains 'YES' or 'NO' which is used for the 'direct' column.
        $employee_details_sql = "
            SELECT 
                department, 
                direct, 
                COUNT(emp_id) AS headcount 
            FROM 
                employee 
            WHERE 
                LEFT(route, 10) = ? 
            GROUP BY 
                department, direct
        ";
        $stmt_emp = $conn->prepare($employee_details_sql);
        if ($stmt_emp === false) {
             throw new Exception("Employee Details Prepare Failed: " . $conn->error);
        }
        $stmt_emp->bind_param("s", $route_code);
        $stmt_emp->execute();
        $employee_results = $stmt_emp->get_result();
        
        if ($employee_results->num_rows === 0) {
            $response['allocation_warning'] = "No employees found for route $route_code. Cost not allocated.";
            $stmt_emp->close();
        } else {
            $total_route_headcount = 0;
            $aggregated_allocations = [];
            $temp_results = [];
            
            // Calculate total headcount first and store results
            while ($row = $employee_results->fetch_assoc()) {
                $total_route_headcount += (int)$row['headcount'];
                $temp_results[] = $row;
            }
            $stmt_emp->close();

            if ($total_route_headcount > 0) {
                // Calculate cost per employee
                $cost_per_employee = $trip_rate / $total_route_headcount;

                // Loop through the aggregated employee data and calculate cost
                foreach ($temp_results as $row) {
                    $department = $row['department'];
                    $di_status  = $row['direct']; // Contains 'YES' or 'NO'
                    $headcount  = (int)$row['headcount'];
                    
                    // The cost portion this department/DI status receives
                    $allocated_cost = $cost_per_employee * $headcount;

                    if ($allocated_cost > 0) {
                        // Aggregate for the consolidated table (Key: Department-DI Status)
                        $key = $department . '-' . $di_status; 
                        $aggregated_allocations[$key] = ($aggregated_allocations[$key] ?? 0.00) + $allocated_cost;
                    }
                }

                // 5b. Update the CONSOLIDATED monthly_cost_allocation table
                $consolidated_update_sql = "
                    INSERT INTO monthly_cost_allocation
                    (supplier_code, gl_code, department, direct, month, year, monthly_allocation) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    monthly_allocation = monthly_allocation + VALUES(monthly_allocation)
                ";
                $cost_stmt = $conn->prepare($consolidated_update_sql);
                if ($cost_stmt === false) {
                     throw new Exception("Consolidated Cost Allocation Prepare Failed: " . $conn->error);
                }
                
                foreach ($aggregated_allocations as $key => $cost) {
                    list($department, $di_status) = explode('-', $key, 2); 
                    
                    // Bind parameters: supplier_code (s), gl_code (s), department (s), direct (s), month (i), year (i), monthly_allocation (d)
                    $cost_stmt->bind_param("sssssid", 
                        $route_supplier_code, 
                        $staff_transport_gl_code, 
                        $department, 
                        $di_status, 
                        $current_month, 
                        $current_year, 
                        $cost
                    );
                    
                    if (!$cost_stmt->execute()) {
                        throw new Exception("Consolidated Cost Allocation Update Failed for $key: " . $cost_stmt->error);
                    }
                }
                $cost_stmt->close();
                
                // 5c. REMOVED: Separate direct_indirect_cost_allocation update

                $response['allocation_status'] = true;
                $response['allocation_message'] = "Cost allocated to $total_route_headcount employees across departments using the consolidated table.";
                
            } else {
                $response['allocation_warning'] = "Headcount for route $route_code is zero. Cost not allocated.";
            }
        }


    } elseif ($transaction_type === 'out' && $record_id) {
        // --- Handle OUT Transaction (Unchanged) ---
        $stmt = $conn->prepare("UPDATE staff_transport_vehicle_register SET out_time = ? WHERE id = ?");
        $stmt->bind_param("si", $current_time, $record_id);
        if (!$stmt->execute()) {
            throw new Exception("Database error (OUT transaction): ".$stmt->error);
        }
        $stmt->close();
        $transaction_success = true;

    } else {
        throw new Exception("Invalid transaction type or missing record ID for 'out'.");
    }

    // COMMIT TRANSACTION if all operations succeeded
    if ($transaction_success) {
        $conn->commit();
        $response['success'] = true;
        $response['message'] = ucfirst($transaction_type) . " transaction recorded successfully!";
    }

} catch (Exception $e) {
    // ROLLBACK on any failure
    $conn->rollback();
    $response['success'] = false;
    $response['message'] = "Transaction Failed: " . $e->getMessage();
}

// Restore default autocommit mode
$conn->autocommit(true); 

// Close DB connection
if (isset($conn)) $conn->close();

// Output clean JSON only
ob_end_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

?>