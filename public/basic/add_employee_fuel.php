<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// Session එකෙන් user_id එක ලබා ගැනීම
$logged_in_user_id = $_SESSION['user_id'] ?? 0;

ini_set('display_errors', 0);
ini_set('log_errors', 1);

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// --- AJAX Name Fetching Logic ---
if (isset($_GET['fetch_name'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $search_id = trim($_GET['emp_id']);
    
    $stmt = $conn->prepare("SELECT calling_name FROM employee WHERE emp_id = ? LIMIT 1");
    $stmt->bind_param("s", $search_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    echo json_encode(['name' => $res['calling_name'] ?? 'Not Found']);
    exit();
}

// --- Data Insert Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_emp_fuel'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $emp_id = strtoupper(trim($_POST['emp_id']));
    $reason = trim($_POST['reason']);
    $issued_qty = (float)$_POST['issued_qty'];
    $issue_date = trim($_POST['issue_date']);

    // Final Backend Validation
    if (empty($emp_id) || strlen($emp_id) !== 8 || empty($reason) || $issued_qty <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Validation failed. Ensure ID is 8 characters and Qty > 0.']);
        exit();
    }

    // SQL update කළා user_id එකත් සමඟ
    $sql = "INSERT INTO employee_fuel_issues (emp_id, reason, issued_qty, issue_date, user_id) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssdsi', $emp_id, $reason, $issued_qty, $issue_date, $logged_in_user_id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Employee fuel issue recorded successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }
    exit();
}

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Employee Fuel Issue</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; }
        .toast { padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; color: white; transition: all 0.3s; opacity: 0; transform: translateY(-20px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10 mx-auto border border-gray-200">
            <h1 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">Employee Fuel Issue</h1>
            
            <form id="empFuelForm" class="space-y-6">
                <input type="hidden" name="add_emp_fuel" value="1">
                
                <div class="grid grid-cols-1 gap-6">
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 font-bold">Employee ID:</label>
                            <input type="text" id="emp_id" name="emp_id" onchange="handleEmployeeId(this.value)" required 
                                   placeholder="e.g. ST8, 7135, D187" 
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 sm:text-sm p-2.5 border font-mono uppercase">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 font-bold">Calling Name:</label>
                            <input type="text" id="calling_name" readonly placeholder="Auto-loaded name" 
                                   class="mt-1 block w-full rounded-md bg-gray-50 border-gray-300 sm:text-sm p-2.5 border text-blue-700 font-bold">
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 font-bold">Quantity (L):</label>
                            <input type="number" step="0.01" name="issued_qty" required placeholder="0.00" 
                                   class="mt-1 block w-full rounded-md border-gray-300 p-2.5 border font-mono shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 font-bold">Issue Date:</label>
                            <input type="date" name="issue_date" required value="<?php echo date('Y-m-d'); ?>" 
                                   class="mt-1 block w-full rounded-md border-gray-300 p-2.5 border shadow-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 font-bold">Reason / Purpose:</label>
                        <textarea name="reason" rows="3" required placeholder="Enter the reason for fuel issue..." 
                                  class="mt-1 block w-full rounded-md border-gray-300 p-2.5 border shadow-sm"></textarea>
                    </div>
                </div>
                
                <div class="flex justify-between mt-6">
                    <a href="fuel_issue_history.php?view_filter=employees" class="bg-gray-400 hover:bg-gray-500 text-white py-2 px-6 rounded-md shadow-md transition">Cancel</a>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-8 rounded-md shadow-md transition transform hover:scale-105">
                        Save Record <i class="fas fa-save ml-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="toast-container"></div>

    <script>
        async function handleEmployeeId(val) {
            const idField = document.getElementById('emp_id');
            const nameField = document.getElementById('calling_name');
            
            if (val.trim().length === 0) {
                nameField.value = '';
                return;
            }

            let inputId = val.toUpperCase().trim();
            let formattedId = inputId;

            if (/^ST\d+$/.test(inputId)) {
                let digits = inputId.replace('ST', '');
                formattedId = 'ST' + digits.padStart(6, '0');
            }
            else if (/^D\d+$/.test(inputId)) {
                let digits = inputId.replace('D', '');
                formattedId = 'GPD' + digits.padStart(5, '0');
            }
            else if (/^\d+$/.test(inputId)) {
                formattedId = 'GP' + inputId.padStart(6, '0');
            }

            idField.value = formattedId;

            if (formattedId.length === 8) {
                nameField.value = 'Searching...';
                try {
                    const response = await fetch(`add_employee_fuel.php?fetch_name=1&emp_id=${formattedId}`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await response.json();
                    
                    if (data.name && data.name !== 'Not Found') {
                        nameField.value = data.name;
                        nameField.style.color = 'blue';
                    } else {
                        nameField.value = 'Employee Not Found';
                        nameField.style.color = 'red';
                    }
                } catch (e) {
                    nameField.value = 'Error fetching name';
                }
            } else {
                nameField.value = 'ID must be 8 characters';
                nameField.style.color = 'orange';
            }
        }

        document.getElementById('empFuelForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const empIdVal = document.getElementById('emp_id').value;
            const callingNameVal = document.getElementById('calling_name').value;

            if(empIdVal.length !== 8 || callingNameVal === 'Employee Not Found' || callingNameVal === 'ID must be 8 characters') {
                showToast("Please enter a valid 8-character Employee ID", "error");
                return;
            }

            const formData = new FormData(this);
            try {
                const response = await fetch('add_employee_fuel.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await response.json();
                
                if(result.status === 'success') {
                    showToast(result.message, 'success');
                    setTimeout(() => window.location.href='fuel_issue_history.php?view_filter=employees', 1500);
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast("An unexpected error occurred.", "error");
            }
        });

        function showToast(msg, type) {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type} show px-6 py-3 font-bold`;
            toast.innerText = msg;
            container.appendChild(toast);
            setTimeout(() => { 
                toast.classList.remove('show'); 
                setTimeout(() => toast.remove(), 300); 
            }, 3000);
        }
    </script>
</body>
</html>