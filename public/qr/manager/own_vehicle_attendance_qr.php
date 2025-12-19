<?php
// Note: This file MUST NOT have any whitespace/characters before the opening <?php tag.

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- Database Functions ---

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
        // Log error but don't crash yet, handle in logic
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
 * Checks attendance status for the day.
 * Returns the existing row if found, otherwise returns null.
 */
function get_daily_attendance_record($conn, $emp_id, $date) {
    // We request 'out_time' specifically. If this column is missing in DB, prepare() will fail.
    $sql = "SELECT time, out_time FROM own_vehicle_attendance WHERE emp_id = ? AND date = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        // If the DB structure is wrong (e.g. missing out_time column), this throws an error directly
        throw new Exception("DB Read Error (Check 'out_time' column): " . $conn->error);
    }
    
    $stmt->bind_param('ss', $emp_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc(); 
    $stmt->close();
    
    return $row;
}


// ---------------------------------------------------------------------
// --- AJAX HANDLER ---
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $vehicle_no = strtoupper(trim($_POST['vehicle_no']));
    $today_date = date('Y-m-d');
    $current_time = date('H:i:s');
    
    if (empty($vehicle_no)) {
        echo json_encode(['success' => false, 'message' => 'Vehicle No is required.']);
        if (isset($conn)) $conn->close();
        exit(); 
    }

    $details = fetch_details_by_vehicle_no($conn, $vehicle_no);

    if (!$details || empty($details['emp_id'])) {
        echo json_encode(['success' => false, 'message' => "Error: Vehicle No {$vehicle_no} not linked to any Employee ID."]);
        if (isset($conn)) $conn->close();
        exit();
    }
    
    $emp_id = $details['emp_id'];
    $calling_name = $details['calling_name'];

    $conn->begin_transaction();

    try {
        // 2. Check existing attendance record for today
        $existing_record = get_daily_attendance_record($conn, $emp_id, $today_date);

        if (!$existing_record) {
            // --- CASE A: No Record -> INSERT (IN TIME) ---
            
            $attendance_sql = "INSERT INTO own_vehicle_attendance (emp_id, date, time, vehicle_no) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($attendance_sql);
            $stmt->bind_param('ssss', $emp_id, $today_date, $current_time, $vehicle_no);
            
            if (!$stmt->execute()) {
                throw new Exception("Check-IN Insert Failed: " . $stmt->error);
            }
            $stmt->close();
            
            $message = "Check-IN Recorded: {$calling_name} ({$vehicle_no}) at {$current_time}.";

        } else {
            // --- CASE B: Record Exists ---
            
            // Check if out_time is NULL or empty ('00:00:00')
            if (empty($existing_record['out_time']) || $existing_record['out_time'] == '00:00:00') {
                // --- Sub-CASE B1: UPDATE (OUT TIME) ---
                
                $update_sql = "UPDATE own_vehicle_attendance SET out_time = ? WHERE emp_id = ? AND date = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param('sss', $current_time, $emp_id, $today_date);
                
                if (!$stmt->execute()) {
                    throw new Exception("Check-OUT Update Failed: " . $stmt->error);
                }
                $stmt->close();

                $message = "Check-OUT Recorded.";

            } else {
                // --- Sub-CASE B2: Already Completed ---
                throw new Exception("Attendance already completed for today.");
            }
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => $message]);

    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
        
        // Handle explicit "Duplicate entry" error nicely if it still happens
        if (strpos($conn->error, 'Duplicate entry') !== false || strpos($error_msg, 'Duplicate entry') !== false) {
             echo json_encode(['success' => false, 'message' => "Error: Record exists but could not read it."]);
        } 
        else if (strpos($error_msg, 'Attendance already completed') !== false) {
             echo json_encode(['success' => false, 'message' => $error_msg]);
        } 
        else {
             // Return the REAL database error (like "Unknown column")
             echo json_encode(['success' => false, 'message' => $error_msg]);
        }
    }

    if (isset($conn)) $conn->close();
    exit(); 
}

// ---------------------------------------------------------------------
// HTML SECTION (Keep your existing HTML below)
// ---------------------------------------------------------------------
include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Own Vehicle Attendance Scan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .main-card {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
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
                <div class="text-lg font-semibold ml-3">Registers</div>
                <div class="flex gap-4 pr-4">
                    <a href="../../registers/non_paid/own_vehicle_attendance.php" class="hover:text-yellow-400 transition">Attendance Register</a>
                </div>
            </div>

            <div class="main-card bg-white p-6 sm:p-10 rounded-xl shadow-2xl w-full max-w-lg mx-auto mt-6">
                
                <h1 class="text-3xl font-extrabold mb-8 text-center text-gray-900 border-b-2 border-indigo-100 pb-3">
                    Own Vehicle Attendance <span class="text-indigo-600">(Scan)</span>
                </h1>

                <div class="mb-8">
                    <label for="vehicleNoInput" class="block text-gray-700 text-base font-semibold mb-2">Scan Vehicle QR/Barcode:</label>
                    <input type="text" id="vehicleNoInput" class="shadow-md appearance-none border border-gray-300 rounded-xl w-full py-4 px-6 text-gray-700 leading-tight focus:ring-4 focus:ring-indigo-300 focus:outline-none transition duration-150" placeholder="Scan here..." autofocus oninput="this.value=this.value.toUpperCase();">
                </div>

                <div id="statusMessage" class="mt-4 p-4 bg-gray-50 border border-gray-200 text-gray-700 rounded-lg text-center font-medium">
                    Ready to scan vehicle.
                </div>
            </div>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
    const vehicleNoInput = document.getElementById('vehicleNoInput');
    const statusMessage = document.getElementById('statusMessage');
    let scanTimeout;

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
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 5000); 
    }

    function submitAttendance(vehicleNo) {
        statusMessage.textContent = `Processing scan for Vehicle: ${vehicleNo}...`;
        statusMessage.className = 'mt-4 p-4 bg-blue-100 border border-blue-200 text-blue-700 rounded-lg text-center font-medium';

        const formData = new FormData();
        formData.append('add_record', '1');
        formData.append('vehicle_no', vehicleNo);

        fetch('own_vehicle_attendance_qr.php', {
            method: 'POST',
            body: formData
        })
        .then(response => { if (!response.ok) throw new Error('Network response was not ok'); return response.json(); })
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                statusMessage.textContent = `Scan successful. Ready for next scan.`;
                statusMessage.className = 'mt-4 p-4 bg-green-100 border border-green-200 text-green-700 rounded-lg text-center font-medium';
            } else {
                showToast(data.message, 'error');
                statusMessage.textContent = `Error processing scan. Ready for next scan.`;
                statusMessage.className = 'mt-4 p-4 bg-red-100 border border-red-200 text-red-700 rounded-lg text-center font-medium';
            }
        })
        .catch(error => {
            console.error('Submission error:', error);
            showToast('Network error or server failed.', 'error');
            statusMessage.textContent = `Network Error. Ready for next scan.`;
            statusMessage.className = 'mt-4 p-4 bg-yellow-100 border border-yellow-200 text-yellow-700 rounded-lg text-center font-medium';
        })
        .finally(() => {
            vehicleNoInput.value = '';
            vehicleNoInput.focus();
        });
    }

    vehicleNoInput.addEventListener('input', function(event) {
        clearTimeout(scanTimeout);
        scanTimeout = setTimeout(() => {
            const vehicleNo = vehicleNoInput.value.trim().toUpperCase();
            if (vehicleNo) { submitAttendance(vehicleNo); }
        }, 50); 
    });
    
    vehicleNoInput.focus();
    </script>
</body>
</html>