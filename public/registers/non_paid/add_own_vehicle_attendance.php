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

// --- Database Functions ---

function fetch_own_vehicle_employee_ids($conn) {
    $emp_list = [];
    // Join employee table to get the name
    $sql = "SELECT DISTINCT ov.emp_id, e.calling_name 
            FROM own_vehicle ov 
            LEFT JOIN employee e ON ov.emp_id = e.emp_id 
            WHERE ov.vehicle_no IS NOT NULL AND ov.vehicle_no != '' 
            ORDER BY ov.emp_id ASC";
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $emp_list[] = [
                    'emp_id' => $row['emp_id'],
                    'calling_name' => $row['calling_name'] ?? 'Unknown'
                ];
            }
        }
    } catch (Exception $e) { return []; }
    return $emp_list;
}

function fetch_employee_and_vehicle_by_id($conn, $emp_id) {
    $details = null;
    $sql = "SELECT ov.vehicle_no FROM own_vehicle AS ov WHERE ov.emp_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('s', $emp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row) {
        $details = ['vehicle_no' => $row['vehicle_no'] ?? ''];
    } else {
        $details = ['vehicle_no' => ''];
    }
    return $details;
}

function check_duplicate_entry($conn, $emp_id, $date) {
    $sql = "SELECT COUNT(*) FROM own_vehicle_attendance WHERE emp_id = ? AND date = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $emp_id, $date);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count > 0;
}

// ---------------------------------------------------------------------
// --- AJAX POST HANDLERS ---
// ---------------------------------------------------------------------

// 1. Fetch Details (Only Vehicle No needed now)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_details'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    $emp_id = trim($_POST['emp_id']);
    $details = fetch_employee_and_vehicle_by_id($conn, $emp_id);
    echo json_encode($details ? ['status' => 'success', 'details' => $details] : ['status' => 'error', 'message' => 'Not found']);
    exit(); 
}

// 2. Add Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $emp_id = strtoupper(trim($_POST['emp_id'])); 
    $vehicle_no = strtoupper(trim($_POST['vehicle_no'])); 
    $date = trim($_POST['date']);
    
    $in_time = !empty($_POST['time']) ? trim($_POST['time']) : null;
    $out_time = !empty($_POST['out_time']) ? trim($_POST['out_time']) : null;
    
    if (empty($emp_id) || empty($vehicle_no) || empty($date)) {
        echo json_encode(['status' => 'error', 'message' => 'Employee, Vehicle, and Date are required.']);
        exit();
    }
    if (empty($in_time) && empty($out_time)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter at least In Time or Out Time.']);
        exit();
    }

    if (check_duplicate_entry($conn, $emp_id, $date)) {
        echo json_encode(['status' => 'error', 'message' => 'Record already exists for this date. Please use Edit option.']);
        exit();
    }

    $conn->begin_transaction(); 

    try {
        $sql = "INSERT INTO own_vehicle_attendance (emp_id, date, vehicle_no, time, out_time) VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare Failed: " . $conn->error);

        $stmt->bind_param('sssss', $emp_id, $date, $vehicle_no, $in_time, $out_time);

        if (!$stmt->execute()) {
            throw new Exception("Insert Failed: " . $stmt->error);
        }
        $stmt->close();
        
        $msg_part = "";
        if($in_time) $msg_part .= "IN: $in_time ";
        if($out_time) $msg_part .= "OUT: $out_time";

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => "Record Added! ($msg_part)"]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
    }

    if (isset($conn)) $conn->close();
    exit(); 
}

// ---------------------------------------------------------------------
// --- PAGE LOAD ---
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
    <title>Add Own Vehicle Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); color: white; transform: translateY(-20px); opacity: 0; transition: all 0.3s; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add Own Vehicle Attendance</h1>
            
            <?php if (empty($employee_ids_list)): ?>
                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4">
                    <p class="font-bold">Missing Data</p>
                    <p>No Employee IDs found in the `own_vehicle` table.</p>
                </div>
            <?php else: ?>
                <form id="addVehicleForm" class="space-y-6">
                    <input type="hidden" name="add_record" value="1">
                    
                    <div>
                        <label for="emp_id_select" class="block text-sm font-medium text-gray-700">Employee (Search by Name/ID):</label>
                        <select id="emp_id_select" name="emp_id" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm p-2 bg-white">
                            <option value="" disabled selected>Select an Employee</option>
                            <?php foreach ($employee_ids_list as $emp): ?>
                                <option value="<?php echo htmlspecialchars($emp['emp_id']); ?>">
                                    <?php echo htmlspecialchars($emp['emp_id'] . ' - ' . $emp['calling_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Vehicle No:</label>
                        <input type="text" id="vehicle_no" name="vehicle_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm p-2" placeholder="Auto-filled or Enter">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Date:</label>
                        <input type="date" id="date" name="date" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm p-2">
                    </div>

                    <div class="grid md:grid-cols-2 gap-6 bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <div>
                            <label for="time" class="block text-sm font-bold text-green-700">In Time:</label>
                            <input type="time" id="time" name="time" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm p-2">
                        </div>
                        <div>
                            <label for="out_time" class="block text-sm font-bold text-red-700">Out Time:</label>
                            <input type="time" id="out_time" name="out_time" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:ring-red-500 focus:border-red-500 sm:text-sm p-2">
                        </div>
                    </div>

                    <div class="flex justify-between mt-6">
                        <a href="own_vehicle_attendance.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition mr-3">Back</a>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition">Submit Record</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
        const empIdSelect = document.getElementById('emp_id_select');
        const vehicleNoInput = document.getElementById('vehicle_no'); 
        const form = document.getElementById('addVehicleForm');

        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<span class="mr-2 text-xl">${type==='success'?'✓':'✖'}</span><span>${message}</span>`;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => { toast.classList.remove('show'); toast.remove(); }, 4000); 
        }

        function resetAutoFields() {
            vehicleNoInput.value = ''; 
            vehicleNoInput.classList.remove('bg-yellow-100');
        }

        async function fetchDetailsByEmpId(empId) {
            if (!empId) { resetAutoFields(); return; }
            vehicleNoInput.value = '...';
            
            const formData = new FormData();
            formData.append('fetch_details', '1'); formData.append('emp_id', empId);

            try {
                const response = await fetch('add_own_vehicle_attendance.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.status === 'success') {
                    vehicleNoInput.value = result.details.vehicle_no;
                } else {
                    resetAutoFields(); showToast('Vehicle details not found', 'error');
                }
            } catch (e) { resetAutoFields(); }
        }

        if (empIdSelect) empIdSelect.addEventListener('change', (e) => fetchDetailsByEmpId(e.target.value));

        if (form) {
            form.addEventListener('submit', async function(event) {
                event.preventDefault();
                const formData = new FormData(this);
                formData.set('vehicle_no', vehicleNoInput.value.toUpperCase().trim());
                
                try {
                    const response = await fetch('add_own_vehicle_attendance.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.status === 'success') {
                        showToast(result.message, 'success');
                        setTimeout(() => window.location.href = 'own_vehicle_attendance.php', 2000);
                    } else {
                        showToast(result.message, 'error');
                    }
                } catch (e) { showToast('Server Error', 'error'); }
            });
        }

        document.getElementById('date').value = '<?php echo $today_date; ?>';
    </script>
</body>
</html>