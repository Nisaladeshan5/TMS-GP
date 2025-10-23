<?php
session_start();
// The db.php include is assumed to be correct
include('../../../includes/db.php');

// Set timezone for consistency
date_default_timezone_set('Asia/Colombo');

// Disable error display (good practice for production)
ini_set('display_errors', 1); // Set to 0 in production
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

// --- Database Functions (Unchanged) ---

/**
 * Fetches only suppliers who are associated with vehicles having purpose = 'night_emergency'.
 */
function fetch_suppliers($conn) {
    $suppliers = [];
    
    $sql = "SELECT DISTINCT s.supplier_code, s.supplier 
            FROM supplier s
            INNER JOIN vehicle v ON s.supplier_code = v.supplier_code
            WHERE v.purpose = 'night_emergency'
            ORDER BY s.supplier ASC";
    
    try {
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $suppliers = $result->fetch_all(MYSQLI_ASSOC);
        }
        if (isset($result)) $result->free();
    } catch (Exception $e) {
        error_log("Supplier Fetch Error: " . $e->getMessage());
        return []; 
    }
    
    return $suppliers;
}

/**
 * Fetches ALL vehicle numbers designated for 'night_emergency' use (for the Datalist).
 */
function fetch_night_emergency_vehicles($conn) {
    $vehicles = [];
    
    $sql = "SELECT vehicle_no FROM vehicle WHERE purpose = 'night_emergency' ORDER BY vehicle_no ASC";
    
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
 * Checks if a record already exists for the given date and SUPPLIER CODE.
 * @return bool True if a duplicate exists, False otherwise.
 */
function check_duplicate_entry($conn, $supplier_code, $date) {
    // UPDATED: Checking based on supplier_code and date
    $sql = "SELECT COUNT(*) FROM night_emergency_attendance WHERE supplier_code = ? AND date = ?";
    
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("Duplicate Check Prepare Failed: " . $conn->error);
            return true; // Treat prepare failure as a critical error, blocking insert
        }

        // Bind parameters: supplier_code (s), date (s)
        $stmt->bind_param('ss', $supplier_code, $date);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        
        return $count > 0;
    } catch (Exception $e) {
        error_log("Duplicate Check Exception: " . $e->getMessage());
        return true; // Treat unexpected error as a critical error
    }
}

// ---------------------------------------------------------------------
// --- AJAX POST HANDLER ---
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $supplier_code = trim($_POST['supplier_code']);
    $vehicle_no = strtoupper(trim($_POST['vehicle_no']));
    $driver = trim($_POST['driver']); 
    $date = trim($_POST['date']);
    $report_time = trim($_POST['report_time']); 
    
    $vehicle_status = 1;
    $driver_status = 1;
    $day_rate = 0.0;

    // Derived values for payment calculation
    $current_month = date('m', strtotime($date));
    $current_year = date('Y', strtotime($date));

    // 1. Initial Validation
    if (empty($supplier_code) || empty($vehicle_no) || empty($driver) || empty($date) || empty($report_time)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        if (isset($conn)) $conn->close();
        exit();
    }
    
    // 2. DUPLICATE CHECK (Supplier + Date)
    if (check_duplicate_entry($conn, $supplier_code, $date)) {
        // Fetch supplier info only if needed for the error message
        $all_suppliers = fetch_suppliers($conn);
        $supplier_info = array_filter($all_suppliers, function($s) use ($supplier_code) {
            return $s['supplier_code'] === $supplier_code;
        });
        $supplier_name = !empty($supplier_info) ? reset($supplier_info)['supplier'] : 'This Supplier';

        echo json_encode(['status' => 'error', 'message' => "$supplier_name has already reported a vehicle for duty on $date. Only one entry per supplier per day is allowed."]);
        if (isset($conn)) $conn->close();
        exit();
    }

    // --- TRANSACTION START ---
    $conn->autocommit(false); // Start transaction mode
    $success = true;

    try {
        // A. GET DAY RATE FOR PAYMENT
        $rate_sql = "
            SELECT day_rate 
            FROM night_emergency_day_rate 
            WHERE last_updated_date <= ? 
            ORDER BY last_updated_date DESC 
            LIMIT 1
        ";
        
        $rate_stmt = $conn->prepare($rate_sql);
        if ($rate_stmt === false) {
             throw new Exception("Rate Prepare Failed: " . $conn->error);
        }
        $rate_stmt->bind_param("s", $date); // Bind current date
        $rate_stmt->execute();
        $rate_result = $rate_stmt->get_result();
        
        if ($rate_row = $rate_result->fetch_assoc()) {
            $day_rate = (float)$rate_row['day_rate'];
        } else {
            error_log("Payment Rate Warning: No applicable day_rate found on or before $date. Setting rate to 0.");
            $day_rate = 0.0;
        }
        $rate_stmt->close();


        // B. INSERT ATTENDANCE RECORD
        $attendance_sql = "INSERT INTO night_emergency_attendance (supplier_code, vehicle_no, date, driver_NIC, report_time, vehicle_status, driver_status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $attendance_stmt = $conn->prepare($attendance_sql);
        if ($attendance_stmt === false) {
             throw new Exception("Attendance Prepare Failed: " . $conn->error);
        }
        
        $attendance_stmt->bind_param('sssssii', $supplier_code, $vehicle_no, $date, $driver, $report_time, $vehicle_status, $driver_status);

        if (!$attendance_stmt->execute()) {
            throw new Exception("Attendance Insert Failed: " . $attendance_stmt->error);
        }
        $attendance_stmt->close();


        // C. Update MONTHLY PAYMENTS AND ALLOCATIONS (Only if a rate was found)
        if ($day_rate > 0) {
            
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

            // --- C.2: Combined Update for monthly_cost_allocation (GL, Department, and Direct/Indirect Cost) ---
            // ASSUMING monthly_cost_allocation table schema now includes:
            // (supplier_code, gl_code, department, direct, month, year, monthly_allocation)
            $gl_code_allocation = '614003'; 
            $department_allocation = 'Production'; 
            $cost_to_allocate = $day_rate; 
            $di_type = 'YES'; // Assuming 'YES' means Direct
            
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
                "sssssid", // supplier_code(s), gl_code(s), department(s), direct(s), month(s), year(s - though often stored as INT, 's' is safer if year is two-digit or mixed), monthly_allocation(d)
                $supplier_code, 
                $gl_code_allocation, 
                $department_allocation, 
                $di_type, // 'YES' for Direct
                $current_month, 
                $current_year, 
                $cost_to_allocate
            );
            
            if (!$update_allocation_stmt->execute()) {
                throw new Exception("Cost Allocation Update Failed: " . $update_allocation_stmt->error);
            }
            $update_allocation_stmt->close();

        } // End of if ($day_rate > 0)

        // D. COMMIT TRANSACTION
        $conn->commit();
        $message = 'Record added successfully! ';
        if ($day_rate == 0) {
            $message .= 'WARNING: Payment rate was not found or is zero. Payment and allocations were not calculated.';
        } else {
            $message .= 'Payment of ' . number_format($day_rate, 2) . ' calculated and added to monthly totals, including the combined cost allocation.';
        }
        
        echo json_encode(['status' => 'success', 'message' => $message]);

    } catch (Exception $e) {
        // E. ROLLBACK on any failure
        $conn->rollback();
        error_log("Transaction Failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Failed to add record and update allocations due to an error: ' . $e->getMessage()]);
    }

    $conn->autocommit(true); // Restore default mode
    if (isset($conn)) $conn->close();
    exit(); 
}

// ---------------------------------------------------------------------
// --- STANDARD PAGE LOAD (Non-AJAX) ---
// ---------------------------------------------------------------------

$today_date = date('Y-m-d');
$suppliers = fetch_suppliers($conn); 
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
        /* Toast styles remain here */
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
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add Night Emergency Vehicle Record</h1>
            
            <?php if (empty($suppliers) || empty($night_emergency_vehicles)): ?>
                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4" role="alert">
                    <p class="font-bold">Missing Data</p>
                    <p>
                        <?php if (empty($suppliers)) echo 'No **Suppliers** available (They must have a vehicle with purpose `night_emergency`).'; ?>
                        <?php if (empty($suppliers) && empty($night_emergency_vehicles)) echo ' And '; ?>
                        <?php if (empty($night_emergency_vehicles)) echo 'No **Night Emergency Vehicles** found in the `vehicle` table.'; ?>
                    </p>
                </div>
            <?php else: ?>
                <form id="addVehicleForm" class="space-y-6">
                    <input type="hidden" name="add_record" value="1">
                    
                    <div>
                        <label for="supplier_code" class="block text-sm font-medium text-gray-700">Supplier Store:</label>
                        <select id="supplier_code" name="supplier_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="" disabled selected>Select a Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>"><?php echo htmlspecialchars($supplier['supplier'] . ' (' . $supplier['supplier_code'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No:</label>
                            <input list="night_emergency_vehicles_list" type="text" id="vehicle_no" name="vehicle_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" placeholder="e.g., WP-ABC-1234">
                            <datalist id="night_emergency_vehicles_list">
                                <?php foreach ($night_emergency_vehicles as $vehicle): ?>
                                    <option value="<?php echo htmlspecialchars($vehicle); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div>
                            <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                            <input type="date" id="date" name="date" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="driver" class="block text-sm font-medium text-gray-700">Driver License ID:</label>
                            <input type="text" id="driver" name="driver" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" placeholder="Enter Driver's ID">
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

                    <div class="flex justify-end mt-6">
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

            // Adjust toast color/type if it contains a WARNING but is fundamentally a success
            if (type === 'success' && message.includes('WARNING')) {
                toast.style.backgroundColor = '#FFC107'; // Yellow for warning
            }

            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);

            // Hide and remove
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 5000); // Increased duration for warnings/detailed messages
        }

        // Handle form submit via AJAX
        const form = document.getElementById('addVehicleForm');
        if (form) {
            form.addEventListener('submit', async function(event) {
                event.preventDefault();
                const formData = new FormData(this);

                // Add Vehicle No normalization to uppercase before submission
                const vehicleNoInput = document.getElementById('vehicle_no');
                formData.set('vehicle_no', vehicleNoInput.value.toUpperCase().trim());
                
                // Remove unused field just in case
                formData.delete('description');

                try {
                    const response = await fetch('add_night_emergency_attendance.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });

                    const responseText = await response.text();
                    try {
                        const result = JSON.parse(responseText);

                        if (result.status === 'success') {
                            showToast(result.message, 'success');
                            // Redirect on success
                            setTimeout(() => window.location.href = 'night_emergency_attendance.php', 3000); 
                        } else {
                            // Show the error message returned from the server (including the duplicate error)
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
    </script>
</body>
</html>