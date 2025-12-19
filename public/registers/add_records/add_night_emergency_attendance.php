<?php
// Note: This file MUST NOT have any whitespace/characters before the opening <?php tag.

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

// The db.php include is assumed to be correct
include('../../../includes/db.php');

// Set timezone for consistency
date_default_timezone_set('Asia/Colombo');

// Error reporting settings
ini_set('display_errors', 1); // Set to 0 in production
ini_set('log_errors', 1);
error_reporting(E_ALL);

// --- Database Functions ---

/**
 * Fetches OP Codes from OP_SERVICES that start with 'NE' (Night Emergency).
 */
function fetch_op_services_codes($conn) {
    $op_codes = [];
    $sql = "
        SELECT DISTINCT op_code 
        FROM op_services
        WHERE op_code LIKE 'NE%'
        ORDER BY op_code ASC
    ";
    
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $op_codes[] = $row['op_code'];
            }
        }
        if (isset($result)) $result->free();
    } catch (Exception $e) {
        error_log("OP Code Fetch Error: " . $e->getMessage());
        return []; 
    }
    return $op_codes;
}

/**
 * Fetches vehicle numbers designated for 'night_emergency' use for the Datalist.
 */
function fetch_night_emergency_vehicles($conn) {
    $vehicles = [];
    $sql = "
        SELECT v.vehicle_no 
        FROM vehicle v
        INNER JOIN supplier s ON v.supplier_code = s.supplier_code
        WHERE v.purpose = 'night_emergency' 
        AND s.supplier_code LIKE 'NE%'
        ORDER BY v.vehicle_no ASC
    ";
    
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $vehicles[] = $row['vehicle_no'];
            }
        }
        if (isset($result)) $result->free();
    } catch (Exception $e) {
        error_log("Vehicle Fetch Error: " . $e->getMessage());
        return []; 
    }
    return $vehicles;
}

/**
 * Checks if a record already exists for the given date and OP CODE.
 */
function check_duplicate_entry($conn, $op_code, $date) {
    $sql = "SELECT COUNT(*) FROM night_emergency_attendance WHERE op_code = ? AND date = ?";
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("Duplicate Check Prepare Failed: " . $conn->error);
            return true; 
        }
        $stmt->bind_param('ss', $op_code, $date);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return $count > 0;
    } catch (Exception $e) {
        error_log("Duplicate Check Exception: " . $e->getMessage());
        return true; 
    }
}

/**
 * Fetches Vehicle No, Supplier Code, and Default Driver NIC/ID using the OP Code.
 * Driver NIC is fetched from the 'vehicle' table using the column name 'driver_NIC'.
 */
function fetch_vehicle_and_supplier_by_op_code($conn, $op_code) {
    $details = null;

    // 1. Get vehicle_no from op_services
    $sql_op = "SELECT vehicle_no FROM op_services WHERE op_code = ? LIMIT 1"; 
    $stmt_op = $conn->prepare($sql_op);
    if (!$stmt_op) { return null; }
    $stmt_op->bind_param('s', $op_code);
    $stmt_op->execute();
    $result_op = $stmt_op->get_result();
    $row_op = $result_op->fetch_assoc();
    $stmt_op->close();

    if (!$row_op) { return null; }

    $vehicle_no = $row_op['vehicle_no'];

    // 2. Get supplier_code, supplier name, AND driver_NIC from vehicle and supplier tables 
    $sql_vehicle = "
        SELECT v.supplier_code, s.supplier, v.driver_NIC  
        FROM vehicle v
        INNER JOIN supplier s ON v.supplier_code = s.supplier_code
        WHERE v.vehicle_no = ? 
        LIMIT 1
    ";

    $stmt_vehicle = $conn->prepare($sql_vehicle);
    if (!$stmt_vehicle) { 
        error_log("Vehicle Prepare Failed: " . $conn->error);
        return null; 
    }
    $stmt_vehicle->bind_param('s', $vehicle_no);
    $stmt_vehicle->execute();
    $result_vehicle = $stmt_vehicle->get_result();
    $row_vehicle = $result_vehicle->fetch_assoc();
    $stmt_vehicle->close();
    
    // Check if vehicle details are found
    if ($row_vehicle) {
        $details = [
            'vehicle_no' => $vehicle_no,
            'supplier_code' => $row_vehicle['supplier_code'] ?? 'N/A',
            'supplier_name' => $row_vehicle['supplier'] ?? 'N/A',
            'driver_nic' => $row_vehicle['driver_NIC'] ?? '' // Fetching driver_NIC here
        ];
    } else {
         // Return minimal details even if vehicle table lookup fails
        $details = [
            'vehicle_no' => $vehicle_no,
            'supplier_code' => 'N/A',
            'supplier_name' => 'Vehicle details missing',
            'driver_nic' => '' 
        ];
    }
    
    return $details;
}


// ---------------------------------------------------------------------
// --- AJAX POST HANDLERS (MUST EXIT AFTER JSON OUTPUT) ---
// ---------------------------------------------------------------------

// 1. Handler for fetching details based on OP Code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_details']) && isset($_POST['op_code'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $op_code = trim($_POST['op_code']);
    
    if (empty($op_code)) {
        echo json_encode(['status' => 'error', 'message' => 'OP Code is required.']);
        if (isset($conn)) $conn->close();
        exit(); 
    }

    $details = fetch_vehicle_and_supplier_by_op_code($conn, $op_code);
    
    if ($details) {
        echo json_encode(['status' => 'success', 'details' => $details]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Vehicle or Supplier details not found for this OP Code.']);
    }

    if (isset($conn)) $conn->close();
    exit(); 
}

// 2. Handler for adding the attendance record AND updating payments/allocations (MODIFIED)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $op_code = trim($_POST['op_code']); 
    $vehicle_no = strtoupper(trim($_POST['vehicle_no']));
    $driver_nic = trim($_POST['driver']); // driver NIC value received from the form
    $date = trim($_POST['date']);
    $report_time = trim($_POST['report_time']); 
    
    $vehicle_status = 1;
    $driver_status = 1;

    // --- Define variables needed for Payment/Allocation ---
    $current_month = date('m', strtotime($date));
    $current_year = date('Y', strtotime($date));
    $supplier_code = ''; 
    $day_rate = 0.0; 
    $gl_code_allocation = '614003'; // Fixed GL Code
    $department_allocation = 'Production'; // Fixed Department
    $di_type = 'YES'; // Fixed Direct/Indirect type


    // Validation
    if (empty($op_code) || empty($vehicle_no) || empty($driver_nic) || empty($date) || empty($report_time)) {
        echo json_encode(['status' => 'error', 'message' => 'සියලුම ක්ෂේත්‍ර පිරවිය යුතුය. (All fields are required)']);
        if (isset($conn)) $conn->close();
        exit();
    }
    
    // Duplicate Check
    if (check_duplicate_entry($conn, $op_code, $date)) {
        echo json_encode(['status' => 'error', 'message' => "OP Code $op_code විසින් $date දිනට වාහනයක් දැනටමත් වාර්තා කර ඇත. (Only one entry per OP Code per day is allowed.)"]);
        if (isset($conn)) $conn->close();
        exit();
    }

    // Start Database Transaction 
    $conn->begin_transaction(); 

    try {
        // A. FETCH DAY RATE AND SUPPLIER CODE
        $rate_sql = "
            SELECT 
                o.day_rate, 
                v.supplier_code 
            FROM op_services AS o
            LEFT JOIN vehicle AS v ON o.vehicle_no = v.vehicle_no
            WHERE o.op_code = ?
        ";
        
        $rate_stmt = $conn->prepare($rate_sql);
        if ($rate_stmt === false) {
             throw new Exception("Rate Prepare Failed: " . $conn->error);
        }
        $rate_stmt->bind_param("s", $op_code); 
        $rate_stmt->execute();
        $rate_result = $rate_stmt->get_result();
        
        if ($rate_row = $rate_result->fetch_assoc()) {
            $day_rate = (float)$rate_row['day_rate'];
            $supplier_code = $rate_row['supplier_code']; // Set supplier_code here
        } else {
            error_log("Payment Rate Warning: No applicable day_rate found for OP Code: $op_code. Setting rate to 0.");
            $day_rate = 0.0;
        }
        $rate_stmt->close();


        // B. INSERT ATTENDANCE RECORD
        $attendance_sql = "INSERT INTO night_emergency_attendance (op_code, vehicle_no, date, driver_NIC, report_time, vehicle_status, driver_status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $attendance_stmt = $conn->prepare($attendance_sql);
        if ($attendance_stmt === false) {
             throw new Exception("Attendance Prepare Failed: " . $conn->error);
        }
        
        // Use $driver_nic which holds the value from $_POST['driver']
        $attendance_stmt->bind_param('sssssii', $op_code, $vehicle_no, $date, $driver_nic, $report_time, $vehicle_status, $driver_status);

        if (!$attendance_stmt->execute()) {
            throw new Exception("Attendance Insert Failed: " . $attendance_stmt->error);
        }
        $attendance_stmt->close();


        // C. Update MONTHLY PAYMENTS AND ALLOCATIONS (Only if a rate and supplier were found)
        if ($day_rate > 0 && !empty($supplier_code)) {
            
            // --- C.1: Update monthly_payment_ne (Supplier Payment) ---
            $update_payment_sql = "
                INSERT INTO monthly_payment_ne 
                (supplier_code, month, year, monthly_payment) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                monthly_payment = monthly_payment + VALUES(monthly_payment)
            ";
            
            $update_payment_stmt = $conn->prepare($update_payment_sql);
            if ($update_payment_stmt === false) {
                 throw new Exception("Payment Prepare Failed: " . $conn->error);
            }

            $update_payment_stmt->bind_param("sssd", $supplier_code, $current_month, $current_year, $day_rate);
            
            if (!$update_payment_stmt->execute()) {
                throw new Exception("Payment Update Failed: " . $update_payment_stmt->error);
            }
            $update_payment_stmt->close();

            // --- C.2: Combined Update for monthly_cost_allocation ---
            
            $update_allocation_sql = "
                INSERT INTO monthly_cost_allocation
                (supplier_code, gl_code, department, direct, month, year, monthly_allocation) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                monthly_allocation = monthly_allocation + VALUES(monthly_allocation)
            ";
            
            $update_allocation_stmt = $conn->prepare($update_allocation_sql);
            if ($update_allocation_stmt === false) {
                 throw new Exception("Cost Allocation Prepare Failed: " . $conn->error);
            }
            
            $update_allocation_stmt->bind_param(
                "sssssid", // supplier_code(s), gl_code(s), department(s), direct(s), month(s), year(s), monthly_allocation(d)
                $supplier_code, 
                $gl_code_allocation, 
                $department_allocation, 
                $di_type, // 'YES' for Direct
                $current_month, 
                $current_year, 
                $day_rate // The cost to allocate is the day_rate
            );
            
            if (!$update_allocation_stmt->execute()) {
                throw new Exception("Cost Allocation Update Failed: " . $update_allocation_stmt->error);
            }
            $update_allocation_stmt->close();

        } // End of if ($day_rate > 0 && !empty($supplier_code))

        // If everything succeeded, commit the transaction
        $conn->commit();
        
        echo json_encode(['status' => 'success', 'message' => 'වාර්තාව සාර්ථකව ඇතුළත් කරන ලදී. (Record added successfully, including payment updates!)']);

    } catch (Exception $e) {
        // If anything failed, rollback the transaction
        $conn->rollback();
        error_log("Insert/Update Transaction Failed: " . $e->getMessage());
        // Clean up the error message for the user interface
        $user_message = strstr($e->getMessage(), ':', true) ?: $e->getMessage();
        echo json_encode(['status' => 'error', 'message' => 'වාර්තාව ඇතුළු කිරීම අසාර්ථක විය: ' . $user_message]);
    }

    if (isset($conn)) $conn->close();
    exit(); 
}

// ---------------------------------------------------------------------
// --- STANDARD PAGE LOAD (Non-AJAX) ---
// ---------------------------------------------------------------------

$today_date = date('Y-m-d');
$op_codes_list = fetch_op_services_codes($conn); 
$night_emergency_vehicles = fetch_night_emergency_vehicles($conn); 

// Existing includes
include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Night Emergency Vehicle Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
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
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
        .auto-filled-display { background-color: #f3f4f6; color: #4b5563; } /* Use a specific class for disabled/read-only display */
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add Night Emergency Vehicle Record</h1>
            
            <?php if (empty($op_codes_list)): ?>
                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4" role="alert">
                    <p class="font-bold">Missing Data</p>
                    <p>No **OP Codes** found in the `op_services` table starting with **'NE'**.</p>
                </div>
            <?php else: ?>
                <form id="addVehicleForm" class="space-y-6">
                    <input type="hidden" name="add_record" value="1">
                    
                    <div>
                        <label for="op_code_select" class="block text-sm font-medium text-gray-700">OP Code (Starts with NE):</label>
                        <select id="op_code_select" name="op_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="" disabled selected>Select an OP Code</option>
                            <?php foreach ($op_codes_list as $code): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No:</label>
                            <input list="night_emergency_vehicles_list" type="text" id="vehicle_no" name="vehicle_no" required 
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                                placeholder="Auto-filled via OP Code or Enter Manually" autocomplete="off">
                            <datalist id="night_emergency_vehicles_list">
                                <?php foreach ($night_emergency_vehicles as $vehicle): ?>
                                    <option value="<?php echo htmlspecialchars($vehicle); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div>
                            <label for="supplier_code_display" class="block text-sm font-medium text-gray-700">Supplier Code / Name (For Billing):</label>
                            <input type="text" id="supplier_code_display" disabled 
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 auto-filled-display" 
                                placeholder="Auto-filled via OP Code">
                        </div>
                    </div>

                    <div>
                        <label for="driver" class="block text-sm font-medium text-gray-700">Driver NIC (driver_NIC):</label>
                        <input type="text" id="driver" name="driver" required 
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                                placeholder="Auto-filled via OP Code or Enter Manually">
                    </div>


                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                            <input type="date" id="date" name="date" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        </div>
                        <div>
                            <label for="report_time" class="block text-sm font-medium text-gray-700">Report Time:</label>
                            <input type="time" 
                                           id="report_time" 
                                           name="report_time" 
                                           required 
                                           class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        </div>
                    </div>


                    <div class="flex justify-between mt-6">
                        <a href="night_emergency_attendance.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105 mr-3">
                            Cancel
                        </a>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                            Add Record
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
        // Global variables for form elements
        const opCodeSelect = document.getElementById('op_code_select');
        const vehicleNoInput = document.getElementById('vehicle_no'); 
        const supplierCodeDisplay = document.getElementById('supplier_code_display'); 
        const driverNICInput = document.getElementById('driver'); 
        const form = document.getElementById('addVehicleForm');

        // Show toast notification 
        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                    ${type === 'success'
                        ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />'
                        : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />'
                    }
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

        // Function to reset fields
        function resetAutoFields() {
            vehicleNoInput.value = '';
            vehicleNoInput.placeholder = 'Auto-filled via OP Code or Enter Manually';
            supplierCodeDisplay.value = '';
            supplierCodeDisplay.placeholder = 'Auto-filled via OP Code';
            driverNICInput.value = '';
            driverNICInput.placeholder = 'Auto-filled via OP Code or Enter Manually';
            
            // Reset background color
            vehicleNoInput.classList.remove('bg-yellow-100');
            supplierCodeDisplay.classList.remove('bg-yellow-100');
            supplierCodeDisplay.classList.add('auto-filled-display'); 
            driverNICInput.classList.remove('bg-yellow-100');
        }

        // Function to fetch Vehicle No, Supplier Code, and Driver NIC via AJAX
        async function fetchDetailsByOpCode(opCode) {
            if (!opCode) {
                resetAutoFields();
                return;
            }

            const formData = new FormData();
            formData.append('fetch_details', '1');
            formData.append('op_code', opCode);

            // Set loading state
            vehicleNoInput.value = 'Fetching...';
            supplierCodeDisplay.value = 'Fetching...';
            driverNICInput.value = 'Fetching...'; 
            
            vehicleNoInput.classList.add('bg-yellow-100');
            supplierCodeDisplay.classList.add('bg-yellow-100');
            driverNICInput.classList.add('bg-yellow-100');
            supplierCodeDisplay.classList.remove('auto-filled-display');

            try {
                const response = await fetch('add_night_emergency_attendance.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' } 
                });

                const responseText = await response.text();
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    console.error('AJAX Fetch Error: JSON Parse Failed. Raw Response:', responseText);
                    resetAutoFields();
                    showToast('Server returned an invalid response. Check console.', 'error'); 
                    return;
                }

                if (result.status === 'success') {
                    const details = result.details;
                    
                    // Auto-fill Vehicle No 
                    vehicleNoInput.value = details.vehicle_no;
                    vehicleNoInput.classList.remove('bg-yellow-100');
                    
                    // Auto-fill Driver NIC 
                    driverNICInput.value = details.driver_nic; 
                    driverNICInput.classList.remove('bg-yellow-100');
                    driverNICInput.placeholder = 'Auto-filled or Enter Manually'; 
                    
                    // Display Supplier Code
                    supplierCodeDisplay.value = `${details.supplier_code} (${details.supplier_name})`;
                    supplierCodeDisplay.classList.remove('bg-yellow-100');
                    supplierCodeDisplay.classList.add('auto-filled-display');
                } else {
                    resetAutoFields();
                    vehicleNoInput.placeholder = 'Not Found! Enter Manually.';
                    supplierCodeDisplay.value = 'Not Found!';
                    driverNICInput.placeholder = 'Not Found! Enter Manually.';
                    showToast(result.message, 'error');
                }
            } catch (error) {
                console.error('AJAX Fetch Error:', error);
                resetAutoFields();
                showToast('An unexpected network error occurred.', 'error');
            }
        }

        // Attach event listener to OP Code Dropdown
        if (opCodeSelect) {
            opCodeSelect.addEventListener('change', (e) => {
                fetchDetailsByOpCode(e.target.value);
            });
        }


        // Handle form submit via AJAX
        if (form) {
            form.addEventListener('submit', async function(event) {
                event.preventDefault();

                // Final check to ensure Vehicle No and Driver NIC are not empty
                if (!vehicleNoInput.value.trim()) {
                    showToast('Vehicle No cannot be empty.', 'error');
                    vehicleNoInput.focus();
                    return;
                }
                if (!driverNICInput.value.trim()) {
                    showToast('Driver NIC cannot be empty.', 'error');
                    driverNICInput.focus();
                    return;
                }


                const formData = new FormData(this);
                formData.set('vehicle_no', vehicleNoInput.value.toUpperCase().trim());
                
                try {
                    const response = await fetch('add_night_emergency_attendance.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' } 
                    });

                    const responseText = await response.text();
                    let result;
                    try {
                        result = JSON.parse(responseText);

                        if (result.status === 'success') {
                            showToast(result.message, 'success');
                            setTimeout(() => window.location.href = 'night_emergency_attendance.php', 3000); 
                        } else {
                            showToast(result.message, 'error');
                        }
                    } catch (e) {
                        console.error('Submission error: Non-JSON response received. Raw response:', responseText);
                        showToast('Error: Received unexpected server response. Check console.', 'error');
                    }
                } catch (error) {
                    console.error('Submission error:', error);
                    showToast('An unexpected network error occurred.', 'error');
                }
            });
        }

        // Set today's date
        document.getElementById('date').value = '<?php echo $today_date; ?>';
        // Initial state reset
        resetAutoFields();
    </script>
</body>
</html>