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

// Database include
include('../../../includes/db.php');

// Set timezone
date_default_timezone_set('Asia/Colombo');

// Error reporting settings
ini_set('display_errors', 1); 
ini_set('log_errors', 1);
error_reporting(E_ALL);

// --- Database Functions ---

function fetch_op_services_codes($conn) {
    $op_codes = [];
    $sql = "SELECT DISTINCT op_code FROM op_services WHERE op_code LIKE 'NE%' ORDER BY op_code ASC";
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) { $op_codes[] = $row['op_code']; }
        }
        if (isset($result)) $result->free();
    } catch (Exception $e) { return []; }
    return $op_codes;
}

function fetch_night_emergency_vehicles($conn) {
    $vehicles = [];
    $sql = "SELECT v.vehicle_no FROM vehicle v 
            INNER JOIN supplier s ON v.supplier_code = s.supplier_code 
            WHERE v.purpose = 'night_emergency' AND s.supplier_code LIKE 'NE%' 
            ORDER BY v.vehicle_no ASC";
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) { $vehicles[] = $row['vehicle_no']; }
        }
        if (isset($result)) $result->free();
    } catch (Exception $e) { return []; }
    return $vehicles;
}

function check_duplicate_entry($conn, $op_code, $date) {
    $sql = "SELECT COUNT(*) FROM night_emergency_attendance WHERE op_code = ? AND date = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $op_code, $date);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count > 0;
}

function fetch_vehicle_and_supplier_by_op_code($conn, $op_code) {
    $sql_op = "SELECT vehicle_no FROM op_services WHERE op_code = ? LIMIT 1"; 
    $stmt_op = $conn->prepare($sql_op);
    $stmt_op->bind_param('s', $op_code);
    $stmt_op->execute();
    $result_op = $stmt_op->get_result();
    $row_op = $result_op->fetch_assoc();
    $stmt_op->close();

    if (!$row_op) return null;

    $vehicle_no = $row_op['vehicle_no'];
    $sql_vehicle = "SELECT v.supplier_code, s.supplier, v.driver_NIC 
                    FROM vehicle v 
                    INNER JOIN supplier s ON v.supplier_code = s.supplier_code 
                    WHERE v.vehicle_no = ? LIMIT 1";
    $stmt_vehicle = $conn->prepare($sql_vehicle);
    $stmt_vehicle->bind_param('s', $vehicle_no);
    $stmt_vehicle->execute();
    $result_vehicle = $stmt_vehicle->get_result();
    $row_vehicle = $result_vehicle->fetch_assoc();
    $stmt_vehicle->close();
    
    return [
        'vehicle_no' => $vehicle_no,
        'supplier_code' => $row_vehicle['supplier_code'] ?? 'N/A',
        'supplier_name' => $row_vehicle['supplier'] ?? 'N/A',
        'driver_nic' => $row_vehicle['driver_NIC'] ?? ''
    ];
}

// --- AJAX POST HANDLERS ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_details'])) {
    header('Content-Type: application/json');
    $op_code = trim($_POST['op_code']);
    $details = fetch_vehicle_and_supplier_by_op_code($conn, $op_code);
    echo json_encode($details ? ['status' => 'success', 'details' => $details] : ['status' => 'error', 'message' => 'Details not found']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    header('Content-Type: application/json');
    $op_code = trim($_POST['op_code']); 
    $vehicle_no = strtoupper(trim($_POST['vehicle_no']));
    $driver_nic = trim($_POST['driver']);
    $date = trim($_POST['date']);
    $report_time = trim($_POST['report_time']); 

    if (empty($op_code) || empty($vehicle_no) || empty($driver_nic) || empty($date) || empty($report_time)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        exit();
    }
    
    if (check_duplicate_entry($conn, $op_code, $date)) {
        echo json_encode(['status' => 'error', 'message' => "A record already exists for this OP Code on this date."]);
        exit();
    }

    $attendance_sql = "INSERT INTO night_emergency_attendance (op_code, vehicle_no, date, driver_NIC, report_time, vehicle_status, driver_status) VALUES (?, ?, ?, ?, ?, 1, 1)";
    $stmt = $conn->prepare($attendance_sql);
    $stmt->bind_param('sssss', $op_code, $vehicle_no, $date, $driver_nic, $report_time);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Attendance record added successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save record.']);
    }
    $stmt->close();
    exit(); 
}

$today_date = date('Y-m-d');
$op_codes_list = fetch_op_services_codes($conn); 
$night_emergency_vehicles = fetch_night_emergency_vehicles($conn); 

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Night Emergency Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .auto-filled-display { background-color: #f3f4f6; color: #4b5563; }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            <h1 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add Night Emergency Attendance</h1>
            
            <form id="addVehicleForm" class="space-y-6">
                <input type="hidden" name="add_record" value="1">
                
                <div>
                    <label for="op_code_select" class="block text-sm font-medium text-gray-700">OP Code:</label>
                    <select id="op_code_select" name="op_code" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                        <option value="" disabled selected>Select an OP Code</option>
                        <?php foreach ($op_codes_list as $code): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($code); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No:</label>
                        <input list="night_emergency_vehicles_list" type="text" id="vehicle_no" name="vehicle_no" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                        <datalist id="night_emergency_vehicles_list">
                            <?php foreach ($night_emergency_vehicles as $vehicle): ?>
                                <option value="<?php echo htmlspecialchars($vehicle); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label for="supplier_code_display" class="block text-sm font-medium text-gray-700">Supplier Info:</label>
                        <input type="text" id="supplier_code_display" disabled class="mt-1 block w-full rounded-md p-2 auto-filled-display border">
                    </div>
                </div>

                <div>
                    <label for="driver" class="block text-sm font-medium text-gray-700">Driver NIC:</label>
                    <input type="text" id="driver" name="driver" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                        <input type="date" id="date" name="date" value="<?php echo $today_date; ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                    </div>
                    <div>
                        <label for="report_time" class="block text-sm font-medium text-gray-700">Report Time:</label>
                        <input type="time" id="report_time" name="report_time" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                    </div>
                </div>

                <div class="flex justify-between mt-6">
                    <a href="night_emergency_attendance.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow-md">Cancel</a>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md">Save Record</button>
                </div>
            </form>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
        const opCodeSelect = document.getElementById('op_code_select');
        const vehicleNoInput = document.getElementById('vehicle_no'); 
        const supplierCodeDisplay = document.getElementById('supplier_code_display'); 
        const driverNICInput = document.getElementById('driver'); 
        const form = document.getElementById('addVehicleForm');

        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<span>${message}</span>`;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 5000); 
        }

        opCodeSelect.addEventListener('change', async (e) => {
            const formData = new FormData();
            formData.append('fetch_details', '1');
            formData.append('op_code', e.target.value);
            const res = await fetch(window.location.href, { method: 'POST', body: formData });
            const result = await res.json();
            if (result.status === 'success') {
                vehicleNoInput.value = result.details.vehicle_no;
                driverNICInput.value = result.details.driver_nic;
                supplierCodeDisplay.value = result.details.supplier_code + ' (' + result.details.supplier_name + ')';
            }
        });

        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            const formData = new FormData(this);
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const result = await response.json();
            if (result.status === 'success') {
                showToast(result.message, 'success');
                setTimeout(() => window.location.href = 'night_emergency_attendance.php', 2000);
            } else {
                showToast(result.message, 'error');
            }
        });
    </script>
</body>
</html>