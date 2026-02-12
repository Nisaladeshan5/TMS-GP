<?php
// Note: This file MUST NOT have any whitespace/characters before the opening <?php tag.

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

ini_set('display_errors', 1); 
ini_set('log_errors', 1);
error_reporting(E_ALL);

$logged_in_user_id = $_SESSION['user_id'] ?? null; 

// --- Database Functions ---

function fetch_own_vehicle_employee_ids($conn) {
    $emp_list = [];
    
    // --- CHANGE 1: Added e.department to SELECT ---
    $sql = "SELECT DISTINCT ov.emp_id, e.calling_name, e.department
            FROM own_vehicle ov
            LEFT JOIN employee e ON ov.emp_id = e.emp_id
            WHERE ov.vehicle_no IS NOT NULL AND ov.vehicle_no != '' AND ov.is_active = 1
            ORDER BY ov.emp_id ASC";
    
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $emp_list[] = [
                    'emp_id' => $row['emp_id'],
                    'calling_name' => $row['calling_name'] ?? 'Unknown',
                    // --- CHANGE 2: Capture Department ---
                    'department' => $row['department'] ?? '' 
                ];
            }
        }
    } catch (Exception $e) { return []; }
    return $emp_list;
}

// UPDATED: Fetches ALL vehicles for a specific employee (Returns Array)
function fetch_vehicles_by_emp_id($conn, $emp_id) {
    $vehicles = [];
    $sql = "SELECT vehicle_no FROM own_vehicle WHERE emp_id = ? AND vehicle_no != '' AND is_active = 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    
    $stmt->bind_param('s', $emp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $vehicles[] = $row['vehicle_no'];
    }
    $stmt->close();
    
    return $vehicles;
}

// ---------------------------------------------------------------------
// --- AJAX POST HANDLERS ---
// ---------------------------------------------------------------------

// 1. Handler for fetching vehicle details (Returns List now)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_details']) && isset($_POST['emp_id'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $emp_id = trim($_POST['emp_id']);
    
    if (empty($emp_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Employee ID is required.']);
        exit(); 
    }

    $vehicles = fetch_vehicles_by_emp_id($conn, $emp_id);
    
    if (!empty($vehicles)) {
        echo json_encode(['status' => 'success', 'vehicles' => $vehicles]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No vehicles found for this employee.']);
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

    if (empty($emp_id) || empty($vehicle_no) || empty($date) || empty($out_time) || empty($in_time) || !is_numeric($distance)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required correctly.']);
        exit();
    }
    
    $conn->begin_transaction(); 

    try {
        $extra_sql = "INSERT INTO own_vehicle_extra 
                      (emp_id, vehicle_no, date, out_time, in_time, distance, done, user_id) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $extra_stmt = $conn->prepare($extra_sql);
        if ($extra_stmt === false) {
            throw new Exception("Prepare Failed: " . $conn->error);
        }
        
        $extra_stmt->bind_param('sssssdis', $emp_id, $vehicle_no, $date, $out_time, $in_time, $distance, $done, $current_user_id);

        if (!$extra_stmt->execute()) {
            throw new Exception("Insert Failed: " . $extra_stmt->error);
        }
        $extra_stmt->close();

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => "Extra record added successfully!"]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Failed to add record! ' . $e->getMessage()]);
    }

    if (isset($conn)) $conn->close();
    exit(); 
}

// ---------------------------------------------------------------------
// --- STANDARD PAGE LOAD ---
// ---------------------------------------------------------------------

$today_date = date('Y-m-d');
$employee_ids_list = fetch_own_vehicle_employee_ids($conn); 

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
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transform: translateY(-20px); opacity: 0; transition: all 0.3s; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
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
                        <label for="emp_id_select" class="block text-sm font-medium text-gray-700">Employee (Search by Name/ID):</label>
                        <select id="emp_id_select" name="emp_id" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm p-2 bg-white">
                            <option value="" disabled selected>Select an Employee</option>
                            <?php foreach ($employee_ids_list as $emp): ?>
                                <option value="<?php echo htmlspecialchars($emp['emp_id']); ?>">
                                    <?php 
                                        $deptInfo = !empty($emp['department']) ? ' (' . $emp['department'] . ')' : '';
                                        echo htmlspecialchars($emp['emp_id'] . ' - ' . $emp['calling_name'] . $deptInfo); 
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No (Assigned):</label>
                        <select id="vehicle_no" name="vehicle_no" required 
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm p-2 bg-gray-50 cursor-not-allowed">
                            <option value="" disabled selected>Select Employee First</option>
                        </select>
                    </div>

                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                        <input type="date" id="date" name="date" required value="<?php echo $today_date; ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm p-2">
                    </div>

                    <div class="grid md:grid-cols-3 gap-6 bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <div>
                            <label for="out_time" class="block text-sm font-bold text-gray-700">Out Time:</label>
                            <input type="time" id="out_time" name="out_time" required 
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm p-2">
                        </div>
                        <div>
                            <label for="in_time" class="block text-sm font-bold text-gray-700">In Time:</label>
                            <input type="time" id="in_time" name="in_time" required
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm p-2">
                        </div>
                         <div>
                            <label for="distance" class="block text-sm font-bold text-gray-700">Distance (km):</label>
                            <input type="number" step="0.1" min="0" id="distance" name="distance" required 
                                placeholder="0.0"
                                class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm p-2">
                        </div>
                    </div>

                    <div class="flex justify-between mt-6">
                        <a href="own_vehicle_extra_register.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition mr-3">
                            Cancel
                        </a>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition">
                            Add Extra Record
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
        const empIdSelect = document.getElementById('emp_id_select');
        const vehicleNoSelect = document.getElementById('vehicle_no'); 
        const form = document.getElementById('addExtraForm');

        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<span class="mr-2 text-xl">${type==='success'?'✓':'✖'}</span><span>${message}</span>`;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => { toast.classList.remove('show'); toast.remove(); }, 4000); 
        }

        // Reset Dropdown logic
        function resetVehicleDropdown(placeholder = 'Select Employee First') {
            vehicleNoSelect.innerHTML = `<option value="" disabled selected>${placeholder}</option>`;
            vehicleNoSelect.classList.add('bg-gray-50', 'cursor-not-allowed');
            vehicleNoSelect.classList.remove('bg-white');
        }

        async function fetchDetailsByEmpId(empId) {
            if (!empId) { resetVehicleDropdown(); return; }

            // Loading state
            vehicleNoSelect.innerHTML = '<option>Loading...</option>';

            const formData = new FormData();
            formData.append('fetch_details', '1');
            formData.append('emp_id', empId);

            try {
                const response = await fetch('add_own_vehicle_extra.php', { method: 'POST', body: formData });
                const result = await response.json();

                vehicleNoSelect.innerHTML = ''; // Clear previous

                if (result.status === 'success' && result.vehicles.length > 0) {
                    
                    // Add "Select Vehicle" option
                    const defaultOption = document.createElement('option');
                    defaultOption.text = "Select Vehicle";
                    defaultOption.value = "";
                    defaultOption.disabled = true;
                    if(result.vehicles.length > 1) defaultOption.selected = true; 
                    vehicleNoSelect.add(defaultOption);

                    // Add vehicles from DB
                    result.vehicles.forEach((veh, index) => {
                        const option = document.createElement('option');
                        option.value = veh;
                        option.text = veh;
                        // Auto-select if only one vehicle exists
                        if (result.vehicles.length === 1 && index === 0) {
                            option.selected = true;
                        }
                        vehicleNoSelect.add(option);
                    });

                    // Enable Dropdown
                    vehicleNoSelect.classList.remove('bg-gray-50', 'cursor-not-allowed');
                    vehicleNoSelect.classList.add('bg-white');

                } else {
                    resetVehicleDropdown('No vehicles found');
                    showToast(result.message || 'No vehicles found', 'error');
                }
            } catch (error) {
                resetVehicleDropdown('Error loading vehicles');
                showToast('Network error occurred.', 'error');
            }
        }

        if (empIdSelect) {
            empIdSelect.addEventListener('change', (e) => fetchDetailsByEmpId(e.target.value));
        }

        if (form) {
            form.addEventListener('submit', async function(event) {
                event.preventDefault();

                // Basic validation
                if (!vehicleNoSelect.value.trim()) {
                    showToast('Please select a Vehicle No.', 'error');
                    return;
                }

                const formData = new FormData(this);
                
                try {
                    const response = await fetch('add_own_vehicle_extra.php', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.status === 'success') {
                        showToast(result.message, 'success');
                        setTimeout(() => window.location.href = 'own_vehicle_extra_register.php', 1500);
                    } else {
                        showToast(result.message, 'error');
                    }
                } catch (error) {
                    showToast('An unexpected error occurred.', 'error');
                }
            });
        }
    </script>
</body>
</html>