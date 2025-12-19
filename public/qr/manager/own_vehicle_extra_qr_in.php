<?php
// Note: This file MUST NOT have any whitespace/characters before the opening <?php tag.

// require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
// if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
//     header("Location: ../../../includes/login.php");
//     exit();
// }

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- Database Functions ---

/**
 * Fetches Employee ID, Employee Name, and Vehicle No using the Scanned Vehicle No.
 */
function fetch_details_by_vehicle_no($conn, $vehicle_no) {
    $sql = "
        SELECT 
            ov.emp_id,
            e.calling_name, 
            ov.vehicle_no
        FROM own_vehicle AS ov
        LEFT JOIN employee AS e ON ov.emp_id = e.emp_id 
        WHERE ov.vehicle_no = ? 
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { 
        error_log("Vehicle Lookup Prepare Failed: " . $conn->error);
        return null; 
    }
    $stmt->bind_param('s', $vehicle_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row && !empty($row['emp_id'])) {
        return [
            'emp_id' => $row['emp_id'],
            'calling_name' => $row['calling_name'] ?? 'N/A', 
            'vehicle_no' => $row['vehicle_no']
        ];
    }
    return null;
}

/**
 * Attempts to find the ID of the open extra trip record for today (done=0) that has NO in_time.
 * This is used for SCENARIO 1 (Out Time was recorded, In Time needs update).
 */
function find_open_trip_to_complete_id($conn, $emp_id, $date) {
    $sql = "SELECT id FROM own_vehicle_extra WHERE emp_id = ? AND date = ? AND done = 0 AND in_time IS NULL LIMIT 1";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $emp_id, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ? $row['id'] : null;
    } catch (Exception $e) {
        error_log("Find Open Entry ID Exception: " . $e->getMessage());
        return null; 
    }
}

/**
 * Checks if the employee already has an In Time recorded today (regardless of Out Time), 
 * to prevent duplicate In Time entries for the same day.
 */
function check_existing_in_time_today($conn, $emp_id, $date) {
    $sql = "SELECT COUNT(*) FROM own_vehicle_extra WHERE emp_id = ? AND date = ? AND in_time IS NOT NULL";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $emp_id, $date);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return $count > 0;
    } catch (Exception $e) {
        error_log("Check Existing In Time Exception: " . $e->getMessage());
        return false;
    }
}


// ---------------------------------------------------------------------
// --- AJAX HANDLER (Submission only) ---
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $vehicle_no = strtoupper(trim($_POST['vehicle_no']));
    $today_date = date('Y-m-d');
    $yesterday_date = date('Y-m-d', strtotime('-1 day'));
    $current_time = date('H:i:s');
    $done_status = 0; 

    if (empty($vehicle_no)) {
        echo json_encode(['success' => false, 'message' => 'Vehicle No is required.']);
        if (isset($conn)) $conn->close();
        exit(); 
    }

    // 1. Fetch employee details
    $details = fetch_details_by_vehicle_no($conn, $vehicle_no);

    if (!$details || empty($details['emp_id'])) {
        echo json_encode(['success' => false, 'message' => "Error: Vehicle No {$vehicle_no} not linked to any Employee ID."]);
        if (isset($conn)) $conn->close();
        exit();
    }
    
    $emp_id = $details['emp_id'];
    $calling_name = $details['calling_name'];
    
    // 2. --- RULE 1 & 3 CHECK: Prevent duplicate In Time entry today ---
    if (check_existing_in_time_today($conn, $emp_id, $today_date)) {
        echo json_encode([
            'success' => false, 
            'message' => "Error: In Time already recorded for {$calling_name} ({$emp_id}) today. Cannot record IN again."
        ]);
        if (isset($conn)) $conn->close();
        exit();
    }
    
    // 3. Check for an *open* trip today (done=0 AND in_time IS NULL)
    $open_record_id = find_open_trip_to_complete_id($conn, $emp_id, $today_date);
    
    $conn->begin_transaction(); 

    try {
        if ($open_record_id) {
            // SCENARIO 1: OPEN TRIP FOUND TODAY (Out Time was recorded, In Time needs update)
            $action = 'Update';
            $sql = "UPDATE own_vehicle_extra SET in_time = ? WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            if ($stmt === false) throw new Exception("Update Prepare Failed: " . $conn->error);
            
            $stmt->bind_param('si', $current_time, $open_record_id); 
            
        } else {
            // SCENARIO 2: NO OPEN TRIP FOUND TODAY (Missing Out Time, inserting only In Time or checking yesterday)
            
            // --- RULE 2 CHECK: Check if yesterday's trip is pending In Time (Out Time IS NULL) ---
            // If we insert a new record, it means Out Time was missed. 
            // We insert for TODAY's date, as that is when the scanner was used.
            $action = 'Insert';
            $sql = "INSERT INTO own_vehicle_extra (emp_id, vehicle_no, date, in_time, done) 
                             VALUES (?, ?, ?, ?, ?)";
                             
            $stmt = $conn->prepare($sql);
            if ($stmt === false) throw new Exception("Insert Prepare Failed: " . $conn->error);

            // s s s s i (emp_id, vehicle_no, date, in_time, done)
            $stmt->bind_param('ssssi', $emp_id, $vehicle_no, $today_date, $current_time, $done_status); 
        }

        if (!$stmt->execute()) {
            throw new Exception("Record {$action} Failed: " . $stmt->error);
        }
        $stmt->close();

        $conn->commit();
        
        $message = ($action == 'Update')
                 ? "Extra Trip IN recorded for {$calling_name} ({$emp_id}) at {$current_time}. (Pending Distance)"
                 : "Out Time missed! Recording only IN Time for {$calling_name} ({$emp_id}) at {$current_time}. (Pending Completion)";

        echo json_encode(['success' => true, 'message' => $message]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("IN Transaction Failed: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database Error: Failed to record IN time.']);
    }

    if (isset($conn)) $conn->close();
    exit(); 
}


// ---------------------------------------------------------------------
// --- HTML / PAGE LOAD (No PHP Includes as they are done above) ---
// ---------------------------------------------------------------------

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Own Vehicle Extra Trip End (Scan)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .main-card {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        /* Toast Notification Styling */
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
            min-width: 250px;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">

    <div class="w-full">
        <div class="w-[85%] ml-[15%]">
            <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-full">
                <div class="text-lg font-semibold ml-3">Extra Travel Register</div>
                <!-- <div class="flex gap-4 pr-4">
                    <a href="../../registers/non_paid/own_vehicle_extra_register.php" class="hover:text-yellow-400 transition">View Register</a>
                </div> -->
            </div>

            <div class="main-card bg-white p-6 sm:p-10 rounded-xl shadow-2xl w-full max-w-lg mx-auto mt-6">
                
                <h1 class="text-3xl font-extrabold mb-8 text-center text-gray-900 border-b-2 border-indigo-100 pb-3">
                    Own Vehicle Extra Trip <span class="text-indigo-600">END (In Time)</span>
                </h1>

                <div class="mb-8">
                    <label for="vehicleNoInput" class="block text-gray-700 text-base font-semibold mb-2">Scan Vehicle QR/Barcode:</label>
                    <input type="text" id="vehicleNoInput" class="shadow-md appearance-none border border-gray-300 rounded-xl w-full py-4 px-6 text-gray-700 leading-tight focus:ring-4 focus:ring-indigo-300 focus:outline-none transition duration-150" placeholder="Scan Vehicle No here..." autofocus oninput="this.value=this.value.toUpperCase();">
                </div>

                <div id="statusMessage" class="mt-4 p-4 bg-gray-50 border border-gray-200 text-gray-700 rounded-lg text-center font-medium">
                    Ready to scan vehicle to record **In Time**.
                </div>
            </div>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
    const vehicleNoInput = document.getElementById('vehicleNoInput');
    const statusMessage = document.getElementById('statusMessage');

    let scanTimeout;

    // --- Toast Notification Functions ---
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container') || document.body.appendChild(document.createElement('div'));
        if (toastContainer.id !== 'toast-container') toastContainer.id = 'toast-container';

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
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 5000); 
    }

    function submitExtraIn(vehicleNo) {
        // Update local status message
        statusMessage.textContent = `Processing scan for Vehicle: ${vehicleNo} to record In Time...`;
        statusMessage.className = 'mt-4 p-4 bg-blue-100 border border-blue-200 text-blue-700 rounded-lg text-center font-medium';

        const formData = new FormData();
        formData.append('add_record', '1');
        formData.append('vehicle_no', vehicleNo);

        // NOTE: The fetch URL should point to this file (own_vehicle_extra_qr_in.php)
        fetch('own_vehicle_extra_qr_in.php', {
            method: 'POST',
            body: formData
        })
        .then(response => { 
            if (!response.ok) throw new Error('Network response was not ok'); 
            return response.json(); 
        })
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                // Reset to ready state
                statusMessage.textContent = `Extra Trip END recorded. Ready for next scan.`;
                statusMessage.className = 'mt-4 p-4 bg-green-100 border border-green-200 text-green-700 rounded-lg text-center font-medium';
            } else {
                showToast(data.message, 'error');
                // Reset to error state
                statusMessage.textContent = `Error processing scan. Ready for next scan.`;
                statusMessage.className = 'mt-4 p-4 bg-red-100 border border-red-200 text-red-700 rounded-lg text-center font-medium';
            }
        })
        .catch(error => {
            console.error('Submission error:', error);
            showToast('An unexpected network error occurred.', 'error');
            // Reset to ready state
            statusMessage.textContent = `Network Error. Ready for next scan.`;
            statusMessage.className = 'mt-4 p-4 bg-yellow-100 border border-yellow-200 text-yellow-700 rounded-lg text-center font-medium';
        })
        .finally(() => {
            // Clear input and refocus quickly for the next scan
            vehicleNoInput.value = '';
            vehicleNoInput.focus();
        });
    }

    // --- Event Listener ---

    // QR/Barcode Scan Handler
    vehicleNoInput.addEventListener('input', function(event) {
        clearTimeout(scanTimeout);

        scanTimeout = setTimeout(() => {
            const vehicleNo = vehicleNoInput.value.trim().toUpperCase();
            if (vehicleNo) {
                submitExtraIn(vehicleNo);
            }
        }, 50); // Small delay to capture full barcode/QR input
    });
    
    // Initial setup on page load
    vehicleNoInput.focus();
    </script>
</body>
</html>