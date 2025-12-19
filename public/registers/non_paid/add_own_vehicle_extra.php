<?php
// Note: This file MUST NOT have any whitespace/characters before the opening <?php tag.

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// Error reporting settings
ini_set('display_errors', 1); // Set to 0 in production
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Get the logged-in User ID from the session (Assuming 'user_id' holds the ID)
$logged_in_user_id = $_SESSION['user_id'] ?? null; 

// --- Database Functions ---
/**
 * Fetches all active Employee IDs who have a vehicle assigned for the Datalist.
 */
function fetch_own_vehicle_employee_ids($conn) {
    $emp_ids = [];
    $sql = "
        SELECT DISTINCT emp_id 
        FROM own_vehicle
        WHERE vehicle_no IS NOT NULL AND vehicle_no != ''
        ORDER BY emp_id ASC
    ";
    
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $emp_ids[] = $row['emp_id'];
            }
        }
        if (isset($result)) $result->free();
    } catch (Exception $e) {
        error_log("Employee ID Fetch Error: " . $e->getMessage());
        return []; 
    }
    return $emp_ids;
}

/**
 * Fetches Employee Name (from employee) and assigned Vehicle No (from own_vehicle) using the Employee ID.
 */
function fetch_employee_and_vehicle_by_id($conn, $emp_id) {
    $details = null;

    $sql = "
        SELECT 
            e.calling_name, 
            ov.vehicle_no 
        FROM employee AS e
        LEFT JOIN own_vehicle AS ov ON e.emp_id = ov.emp_id 
        WHERE e.emp_id = ? 
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { 
        error_log("Employee Prepare Failed: " . $conn->error);
        return null; 
    }
    $stmt->bind_param('s', $emp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row) {
        $details = [
            'calling_name' => $row['calling_name'] ?? 'N/A',
            'vehicle_no' => $row['vehicle_no'] ?? '' 
        ];
    } else {
        $details = [
            'calling_name' => 'Employee Missing',
            'vehicle_no' => '' 
        ];
    }
    
    return $details;
}

/**
 * Checks if an Own Vehicle EXTRA travel record already exists for the given date and Employee ID.
 */
function check_duplicate_extra_entry($conn, $emp_id, $date) {
    $sql = "SELECT COUNT(*) FROM own_vehicle_extra WHERE emp_id = ? AND date = ?";
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("Extra Duplicate Check Prepare Failed: " . $conn->error);
            return true; // Conservative approach
        }
        $stmt->bind_param('ss', $emp_id, $date);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return $count > 0;
    } catch (Exception $e) {
        error_log("Extra Duplicate Check Exception: " . $e->getMessage());
        return true; 
    }
}


// ---------------------------------------------------------------------
// --- AJAX POST HANDLERS (MUST EXIT AFTER JSON OUTPUT) ---
// ---------------------------------------------------------------------

// 1. Handler for fetching details based on Employee ID (Same as previous file)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_details']) && isset($_POST['emp_id'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $emp_id = trim($_POST['emp_id']);
    
    if (empty($emp_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Employee ID is required.']);
        if (isset($conn)) $conn->close();
        exit(); 
    }

    $details = fetch_employee_and_vehicle_by_id($conn, $emp_id);
    
    if ($details) {
        echo json_encode(['status' => 'success', 'details' => $details]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Employee details not found for this ID.']);
    }

    if (isset($conn)) $conn->close();
    exit(); 
}

// 2. Handler for adding the EXTRA travel record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $emp_id = strtoupper(trim($_POST['emp_id'])); 
    $vehicle_no = strtoupper(trim($_POST['vehicle_no']));
    $date = trim($_POST['date']);
    $out_time = trim($_POST['out_time']);
    $in_time = trim($_POST['in_time']);
    $distance = trim($_POST['distance']);
    $done = 1; 
    
    $current_user_id = $logged_in_user_id;

    // Validation
    if (empty($emp_id) || empty($vehicle_no) || empty($date) || empty($out_time) || empty($in_time) || !is_numeric($distance)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields (ID, Vehicle No, Date, Times, Distance) must be filled correctly.']);
        if (isset($conn)) $conn->close();
        exit();
    }
    if (empty($current_user_id)) {
        echo json_encode(['status' => 'error', 'message' => 'User session ID is missing. Cannot record history.']);
        if (isset($conn)) $conn->close();
        exit();
    }
    
    // Start Database Transaction 
    $conn->begin_transaction(); 

    try {
        // Update INSERT SQL to include user_id
        $extra_sql = "INSERT INTO own_vehicle_extra 
                      (emp_id, vehicle_no, date, out_time, in_time, distance, done, user_id) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $extra_stmt = $conn->prepare($extra_sql);
        if ($extra_stmt === false) {
            throw new Exception("Extra Record Prepare Failed: " . $conn->error);
        }
        
        // Bind types: (s, s, s, s, s, d, i, s)
        $extra_stmt->bind_param('sssssdis', $emp_id, $vehicle_no, $date, $out_time, $in_time, $distance, $done, $current_user_id);

        if (!$extra_stmt->execute()) {
            throw new Exception("Extra Record Insert Failed: " . $extra_stmt->error);
        }
        $extra_stmt->close();

        $conn->commit();
        
        echo json_encode(['status' => 'success', 'message' => "Extra record added successfully! Redirecting to register."]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Extra Insert Transaction Failed: " . $e->getMessage());
        $user_message = strstr($e->getMessage(), ':', true) ?: $e->getMessage();
        echo json_encode(['status' => 'error', 'message' => 'Failed to add extra record! ' . $user_message]);
    }

    if (isset($conn)) $conn->close();
    exit(); 
}

// ---------------------------------------------------------------------
// --- STANDARD PAGE LOAD (Non-AJAX) ---
// ---------------------------------------------------------------------

$today_date = date('Y-m-d');
$current_time = date('H:i'); // Default current time for convenience
$employee_ids_list = fetch_own_vehicle_employee_ids($conn); 

// Existing includes
include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Own Vehicle Extra Travel</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Toast Notification Styling (Same as previous files) */
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
        .auto-filled-display { background-color: #f3f4f6; color: #4b5563; }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add Own Vehicle Extra Travel Record</h1>
            
            <?php if (empty($employee_ids_list)): ?>
                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4" role="alert">
                    <p class="font-bold">Missing Data</p>
                    <p>No Employee IDs found in the `own_vehicle` table with an assigned vehicle.</p>
                </div>
            <?php else: ?>
                <form id="addExtraForm" class="space-y-6">
                    <input type="hidden" name="add_record" value="1">
                    
                    <div>
                        <label for="emp_id_select" class="block text-sm font-medium text-gray-700">Employee ID:</label>
                        <select id="emp_id_select" name="emp_id" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="" disabled selected>Select an Employee ID</option>
                            <?php foreach ($employee_ids_list as $id): ?>
                                <option value="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($id); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="employee_name_display" class="block text-sm font-medium text-gray-700">Employee Name:</label>
                            <input type="text" id="employee_name_display" disabled 
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2 auto-filled-display" 
                                placeholder="Auto-filled via Employee ID">
                        </div>
                        <div>
                            <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No (Assigned Vehicle):</label>
                            <input type="text" id="vehicle_no" name="vehicle_no" required 
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                                placeholder="Auto-filled or Enter Manually" autocomplete="off">
                            <p class="text-xs text-gray-500 mt-1">Required for the extra trip record.</p>
                        </div>
                    </div>

                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                        <input type="date" id="date" name="date" required 
                            value="<?php echo $today_date; ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                    </div>

                    <div class="grid md:grid-cols-3 gap-6">
                        <div>
                            <label for="out_time" class="block text-sm font-medium text-gray-700">Out Time:</label>
                            <input type="time" id="out_time" name="out_time" required 
                                value=""
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        </div>
                        <div>
                            <label for="in_time" class="block text-sm font-medium text-gray-700">In Time:</label>
                            <input type="time" id="in_time" name="in_time" 
                                value=""
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        </div>
                         <div>
                            <label for="distance" class="block text-sm font-medium text-gray-700">Distance (km):</label>
                            <input type="number" step="0.1" min="0" id="distance" name="distance" required 
                                placeholder="Enter Distance"
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        </div>
                    </div>

                    <div class="flex justify-between mt-6">
                        <a href="own_vehicle_extra_register.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105 mr-3">
                            Cancel
                        </a>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                            Add Extra Record
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
        // Global variables for form elements
        const empIdSelect = document.getElementById('emp_id_select');
        const vehicleNoInput = document.getElementById('vehicle_no'); 
        const employeeNameDisplay = document.getElementById('employee_name_display'); 
        const form = document.getElementById('addExtraForm');

        // Show toast notification (Same as previous files)
        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const iconContent = type === 'success' ? 
                '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />' : 
                '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />';
            
            toast.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                    ${iconContent}
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
            vehicleNoInput.placeholder = 'Auto-filled or Enter Manually';
            employeeNameDisplay.value = '';
            employeeNameDisplay.placeholder = 'Auto-filled via Employee ID';
            
            // Reset background color
            vehicleNoInput.classList.remove('bg-yellow-100');
            employeeNameDisplay.classList.remove('bg-yellow-100');
            employeeNameDisplay.classList.add('auto-filled-display'); 
        }

        // Function to fetch Employee Name and Vehicle No via AJAX
        async function fetchDetailsByEmpId(empId) {
            if (!empId) {
                resetAutoFields();
                return;
            }

            const formData = new FormData();
            formData.append('fetch_details', '1');
            formData.append('emp_id', empId);

            // Set loading state
            vehicleNoInput.value = 'Fetching...';
            employeeNameDisplay.value = 'Fetching...';
            
            vehicleNoInput.classList.add('bg-yellow-100');
            employeeNameDisplay.classList.add('bg-yellow-100');
            employeeNameDisplay.classList.remove('auto-filled-display');

            try {
                const response = await fetch('add_own_vehicle_extra.php', {
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
                    
                    // Display Employee Name 
                    employeeNameDisplay.value = `${details.calling_name}`;
                    employeeNameDisplay.classList.remove('bg-yellow-100');
                    employeeNameDisplay.classList.add('auto-filled-display');
                } else {
                    resetAutoFields();
                    vehicleNoInput.placeholder = 'Not Found! Enter Manually.';
                    employeeNameDisplay.value = 'Employee Not Found!';
                    showToast(result.message, 'error');
                }
            } catch (error) {
                console.error('AJAX Fetch Error:', error);
                resetAutoFields();
                showToast('An unexpected network error occurred.', 'error');
            }
        }

        // Attach event listener to Employee ID Dropdown
        if (empIdSelect) {
            empIdSelect.addEventListener('change', (e) => {
                fetchDetailsByEmpId(e.target.value);
            });
        }


        // Handle form submit via AJAX
        if (form) {
            form.addEventListener('submit', async function(event) {
                event.preventDefault();

                // Final checks
                if (!vehicleNoInput.value.trim()) {
                    showToast('Vehicle No cannot be empty.', 'error');
                    vehicleNoInput.focus();
                    return;
                }
                const distanceInput = document.getElementById('distance');
                if (isNaN(parseFloat(distanceInput.value)) || parseFloat(distanceInput.value) <= 0) {
                    showToast('Distance must be a valid number greater than zero.', 'error');
                    distanceInput.focus();
                    return;
                }

                const formData = new FormData(this);
                // Ensure text inputs are uppercase and trimmed
                formData.set('vehicle_no', vehicleNoInput.value.toUpperCase().trim());
                
                try {
                    const response = await fetch('add_own_vehicle_extra.php', {
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
                            
                            // *** MODIFICATION: Redirect to register page after success ***
                            setTimeout(() => {
                                window.location.href = 'own_vehicle_extra_register.php';
                            }, 1500); // 1.5 second delay before redirect
                            
                        } else {
                            showToast(result.message, 'error');
                        }
                    } catch (e) {
                        console.error('Submission error: JSON Parse Failed. Raw Response:', responseText);
                        showToast('Error: Received unexpected server response. Check console.', 'error');
                    }
                } catch (error) {
                    console.error('Submission error:', error);
                    showToast('An unexpected network error occurred.', 'error');
                }
            });
        }

        // Initial reset on load
        resetAutoFields();
    </script>
</body>
</html>