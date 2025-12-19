<?php
require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

// ***************************************************************
// 1. CRITICAL FIX: Add Output Buffering at the very top 
// ***************************************************************
ob_start(); 

// Ensure this path is correct
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');
include('../../../includes/config.php');

// Fixed GL Code for Staff Transport
$staff_transport_gl_code = '623400';

// --- Helper Functions ---

/**
 * Function to get route details (code and name) for the dropdown.
 * @param mysqli $conn The database connection object.
 * @return array An array of route details.
 */
function getRouteCodes($conn): array {
    // Added ORDER BY for better UI experience
    $sql = "SELECT route_code, route FROM route WHERE purpose='staff' ORDER BY route_code ASC"; 
    $result = $conn->query($sql);
    $routes = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $routes[] = $row;
        }
    }
    return $routes;
}

// --- Form Submission Handler ---
$message = '';
$status = '';
$success = false; // Initialize $success

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Data validation and sanitization
    $vehicleNo = filter_input(INPUT_POST, 'vehicle_no', FILTER_SANITIZE_STRING); 
    $date = filter_input(INPUT_POST, 'date', FILTER_SANITIZE_STRING);
    $amount = (float)filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT); // Amount paid to the Extra Vehicle
    $reason = filter_input(INPUT_POST, 'reason', FILTER_SANITIZE_STRING);
    $routeCode = filter_input(INPUT_POST, 'route_code', FILTER_SANITIZE_STRING);
    $shift = filter_input(INPUT_POST, 'shift', FILTER_SANITIZE_STRING); 
    
    // Initial check for required fields
    if (!$vehicleNo || !$date || $amount === false || $amount < 0 || !$reason || !$routeCode || !$shift) {
        $message = "Invalid or missing form data. Please check all fields and ensure the amount is valid.";
        $status = "error";
        $success = false;
        goto end_script;
    }
    
    $status_value = 0; 
    
    // ***************************************************************
    // 🔑 CRITICAL CHECK: Ensure no record exists for this route/date/shift
    // ***************************************************************
    try {
        $check_sql = "
            SELECT 'petty_cash' AS source, route_code AS route_id
            FROM petty_cash 
            WHERE route_code = ? AND date = ? AND shift = ?

            UNION ALL

            SELECT 'staff_transport' AS source, route AS route_id
            FROM staff_transport_vehicle_register 
            WHERE route = ? AND date = ? AND shift = ?

            UNION ALL

            SELECT 'extra_vehicle' AS source, route_code AS route_id
            FROM extra_vehicle_register 
            WHERE route_code = ? AND date = ? AND shift = ?;
        ";
        $stmt_check = $conn->prepare($check_sql);
        if ($stmt_check === false) {
             throw new Exception("Failed to prepare check statement: " . $conn->error);
        }
        $stmt_check->bind_param('sssssssss', $routeCode, $date, $shift, $routeCode, $date, $shift, $routeCode, $date, $shift);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        
        if ($check_result->num_rows > 0) {
            $row = $check_result->fetch_assoc();
            $source = ($row['source'] == 'staff_transport') ? 'Primary Vehicle Register' : (($row['source'] == 'extra_vehicle') ? 'Extra Vehicle Register' : 'Petty Cash Register');
            throw new Exception("A record already exists.");
        }
        $stmt_check->close();

    } catch (Exception $e) {
        $message = "Validation Failed: " . htmlspecialchars($e->getMessage());
        $status = "error";
        $success = false;
        goto end_script; // Jump to the redirection logic
    }
    
    
    // START TRANSACTION
    $conn->autocommit(false);
    $success = true; // Assume success at the start of transaction
    $monthly_payment_processed = false;
    $cost_allocated = false;
    $message_allocation = '';
    $net_adjustment = 0.00; // Initialize for error handling/finally block

    try {
        
        // --- 1. Calculate TRIP RATE (for Contractor Adjustment) and get Supplier Code ---
        $stmt_rate = $conn->prepare("SELECT supplier_code, fixed_amount, fuel_amount, distance FROM route WHERE route_code = ?");
        if ($stmt_rate === false) {
            throw new Exception("Failed to prepare route rate statement: " . $conn->error);
        }
        $stmt_rate->bind_param('s', $routeCode);
        $stmt_rate->execute();
        $result_rate = $stmt_rate->get_result();
        $rate_row = $result_rate->fetch_assoc();
        $stmt_rate->close();

        $trip_rate = 0.00; 
        $route_supplier_code = null; 

        if ($rate_row) {
            $route_supplier_code = $rate_row['supplier_code']; 
            $fixed_amount = (float)$rate_row['fixed_amount'];
            $fuel_amount = (float)$rate_row['fuel_amount'];
            $distance = (float)$rate_row['distance'];
            
            // Calculation: TRIP RATE = ((Fixed + Fuel) * Distance) / 2
            $trip_rate = (($fixed_amount + $fuel_amount) * $distance) / 2;
        } else {
            throw new Exception("Route configuration data (Trip Rate, Supplier Code) missing for route: $routeCode.");
        }
        
        if (empty($route_supplier_code)) {
             throw new Exception("The primary route supplier code is missing in the route table for $routeCode.");
        }

        // --- 2. Insert the new extra vehicle record ---
        $sql_insert = "INSERT INTO extra_vehicle_register (vehicle_no, date, amount, reason, route_code, shift)
                           VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);
        if ($stmt_insert === false) {
            throw new Exception("Failed to prepare extra vehicle statement: " . $conn->error);
        }
        $stmt_insert->bind_param('ssdsss', $vehicleNo, $date, $amount, $reason, $routeCode, $shift);

        if (!$stmt_insert->execute()) {
             throw new Exception("Error saving extra vehicle record: " . $stmt_insert->error);
        }
        $stmt_insert->close();
        
        // --- 3. Update monthly_payments_sf (Contractor Adjustment) ---
        
        // LOGIC: If standard trip rate is higher than actual paid, the difference is a credit to the primary contractor.
        if ($trip_rate < $amount) {
            // Positive adjustment value (credit/addition) to the contractor's monthly payment
            $net_adjustment = $trip_rate - $amount; 
        } else {
            // If actual cost is higher, the contractor gets no benefit, and the adjustment is 0.
            $net_adjustment = 0.00;
        }
        
        $month = (int)date('n', strtotime($date)); 
        $year = (int)date('Y', strtotime($date));
        
        // *** CRITICAL UPDATE: Using supplier_code as payment is supplier-based ***
        $sql_upsert_monthly = "
            INSERT INTO monthly_payments_sf (supplier_code, route_code, month, year, monthly_payment, total_distance)
            VALUES (?, ?, ?, ?, ?, 0.00)
            ON DUPLICATE KEY UPDATE 
            monthly_payment = monthly_payment + VALUES(monthly_payment)
        ";
        $stmt_monthly = $conn->prepare($sql_upsert_monthly);
        if ($stmt_monthly === false) {
             throw new Exception("Monthly Payment Prepare Failed: " . $conn->error);
        }
        // Bind parameters: (supplier_code (s), route_code (s), month (i), year (i), net_adjustment (d))
        $stmt_monthly->bind_param('sssid', $route_supplier_code, $routeCode, $month, $year, $net_adjustment); 

        if (!$stmt_monthly->execute()) {
             throw new Exception("Monthly Payment Update Failed: " . $stmt_monthly->error);
        }
        $stmt_monthly->close();
        $monthly_payment_processed = true;


        // --- 4. Department Cost Allocation (Consolidated Allocation) ---

        // BUSINESS RULE: Allocate the lower of the actual cost or the standard rate.
        $cost_to_allocate = min($amount, $trip_rate);

        // 4a. Get employee headcount per department and direct status
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
            throw new Exception("Failed to prepare employee details statement: " . $conn->error);
        }
        $stmt_emp->bind_param("s", $routeCode); 
        $stmt_emp->execute();
        $employee_results = $stmt_emp->get_result();
        
        $total_route_headcount = 0;
        $aggregated_allocations = [];
        $temp_results = [];
        
        while ($row = $employee_results->fetch_assoc()) {
            $total_route_headcount += (int)$row['headcount'];
            $temp_results[] = $row;
        }
        $stmt_emp->close(); 

        if ($total_route_headcount > 0 && $cost_to_allocate > 0) {
            
            $cost_per_employee = $cost_to_allocate / $total_route_headcount;

            foreach ($temp_results as $row) {
                $department = $row['department'];
                $di_status = $row['direct']; 
                $headcount = (int)$row['headcount'];
                
                $allocated_cost = $cost_per_employee * $headcount;

                if ($allocated_cost > 0) {
                    $key = $department . '-' . $di_status; 
                    $aggregated_allocations[$key] = ($aggregated_allocations[$key] ?? 0.00) + $allocated_cost;
                }
            }

            // 4b. Update the consolidated monthly_cost_allocation table
            $consolidated_update_sql = "
                INSERT INTO monthly_cost_allocation
                (supplier_code, gl_code, department, direct, month, year, monthly_allocation) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                monthly_allocation = monthly_allocation + VALUES(monthly_allocation)
            ";
            $cost_stmt = $conn->prepare($consolidated_update_sql);
            if ($cost_stmt === false) {
                throw new Exception("Cost Allocation Prepare Failed: " . $conn->error);
            }
            
            foreach ($aggregated_allocations as $key => $cost) {
                list($department, $di_status) = explode('-', $key, 2);
                
                // NOTE: Using the primary route supplier code for cost allocation tracking
                $cost_stmt->bind_param("sssssid", 
                    $route_supplier_code, 
                    $staff_transport_gl_code, 
                    $department, 
                    $di_status, 
                    $month, 
                    $year, 
                    $cost
                );

                if (!$cost_stmt->execute()) {
                    throw new Exception("Cost Allocation Update Failed for $key: " . $cost_stmt->error);
                }
            }
            $cost_stmt->close();
            
            $cost_allocated = true;
            
        } else {
            $message_allocation = " Warning: Headcount for route $routeCode is zero or amount is zero. Cost not allocated.";
        }


        // --- 5. Final message and Commit ---
        $status = "success"; 
        
        $final_message = "Extra vehicle record added for route **" . htmlspecialchars($routeCode) . "**, Shift: **" . htmlspecialchars($shift) . "** successfully. ";
        
        if ($net_adjustment > 0) {
             $final_message .= "Contractor (Supplier **" . htmlspecialchars($route_supplier_code) . "**) payment adjusted (**Credited**) by " . number_format($net_adjustment, 2) . " LKR.";
        } elseif ($amount > $trip_rate) {
             $final_message .= "Contractor adjustment skipped (Extra Vehicle cost was higher than Trip Rate; excess cost absorbed by company).";
             $status = "warning";
        } else {
             $final_message .= "No contractor adjustment necessary.";
        }
        
        if ($cost_allocated) {
            $final_message .= " Cost of " . number_format($cost_to_allocate, 2) . " LKR successfully allocated to departments.";
        } elseif (!empty($message_allocation)) {
            $final_message .= $message_allocation;
            if ($status != 'error') {
                 $status = "warning"; 
            }
        }
        $message = $final_message;
        
        $conn->commit(); 

    } catch (Exception $e) {
        $conn->rollback(); 
        $message = "Transaction Failed: " . htmlspecialchars($e->getMessage());
        $status = "error";
        $success = false;
    } finally {
        $conn->autocommit(true); // Restore autocommit mode
    }
    
    // --- 6. Redirection ---
    end_script: // Label for the goto statement
    if ($success || $status == 'error') {
        // Redirect on both success and transaction error to display the toast
        $register_url = BASE_URL . "registers/Staff%20transport%20vehicle%20register.php";
        header("Location: " . $register_url . "?status=" . urlencode($status) . "&message=" . urlencode($message));
        ob_end_flush(); 
        exit();
    }
}

// Get data for the form 
$routes = getRouteCodes($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Extra Vehicle Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* CSS for toast */
        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .toast {
            display: flex;
            align-items: center;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            color: white;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            background-color: #4CAF50;
        }
        .toast.warning {
            background-color: #ff9800;
        }
        .toast.error {
            background-color: #F44336;
        }

        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100 font-sans">
    <div id="toast-container"></div>
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-6">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add Absent Extra Vehicle Record (One Trip)</h1>
            <form method="POST" class="space-y-3">
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Extra Vehicle No:</label>
                        <input type="text" name="vehicle_no" id="vehicle_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" placeholder="e.g., WP CA-1234" />
                    </div>
                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                        <input type="date" name="date" id="date" required value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" />
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="route_code" class="block text-sm font-medium text-gray-700">Route:</label>
                        <select name="route_code" id="route_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">Select a Route</option>
                            <?php foreach ($routes as $route): ?>
                                <option value="<?php echo htmlspecialchars($route['route_code']); ?>">
                                    <?php echo htmlspecialchars($route['route']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700">Amount Paid to Extra Vehicle (LKR) - For One Trip:</label>
                        <input type="number" step="0.01" min="0" name="amount" id="amount" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" />
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="shift" class="block text-sm font-medium text-gray-700">Shift:</label>
                        <select name="shift" id="shift" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">Select Shift</option>
                            <option value="morning">Morning</option>
                            <option value="evening">Evening</option>
                        </select>
                    </div>
                    <div></div> 
                </div>

                <div>
                    <label for="reason" class="block text-sm font-medium text-gray-700">Reason:</label>
                    <textarea name="reason" id="reason" rows="3" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"></textarea>
                </div>
                
                <hr class="border-gray-300" />
                
                <div class="flex justify-end gap-4 mt-6">
                    <a href="<?= BASE_URL ?>registers/Staff%20transport%20vehicle%20register.php"
                       class="inline-flex items-center px-6 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition duration-300">
                        Cancel
                    </a>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Save Record
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        /**
         * Displays a toast notification.
         */
        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            let iconPath;
            let iconColor = 'currentColor';

            switch (type) {
                case 'success':
                    iconPath = '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />';
                    break;
                case 'warning':
                case 'error':
                    iconPath = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />';
                    break;
                default:
                    iconPath = '';
            }

            toast.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="${iconColor}" class="toast-icon">
                    ${iconPath}
                </svg>
                <span>${message}</span>
            `;
            
            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 5000); 
        }

        // ** REDIRECTED PAGE LOGIC: Read message from URL parameters **
        const urlParams = new URLSearchParams(window.location.search);
        const status = urlParams.get('status');
        const message = urlParams.get('message');
        
        if (status && message) {
            showToast(decodeURIComponent(message), status);

            // Remove URL parameters after displaying the toast
            window.history.replaceState(null, null, window.location.pathname);
        }

        // Handle POST errors that fall through
        <?php 
        // Only run this if the script had a validation error or hit the goto label/fallthrough
        if ($status == 'error' && $message): 
        ?>
            document.addEventListener('DOMContentLoaded', () => {
                showToast('<?php echo addslashes($message); ?>', 'error');
            });
        <?php endif; ?>
    </script>
</body>
</html>

<?php
if (isset($conn)) {
    $conn->close();
}
// Final flush of the output buffer
if (ob_get_level() > 0) {
    ob_end_flush(); 
}
?>