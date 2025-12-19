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
include('../../includes/header.php');
include('../../includes/navbar.php');

if ($conn->connect_error) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Database connection failed: " . $conn->connect_error . "</div>");
}

// --- 1. Get Employee ID AND Vehicle No (UPDATED) ---
$target_emp_id = $_GET['emp_id'] ?? null;
$target_vehicle_no = $_GET['vehicle_no'] ?? null; // වාහන අංකයත් ගන්නවා

if (!$target_emp_id || !$target_vehicle_no) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Error: Employee ID or Vehicle Number not specified for editing.</div>");
}

// 🔑 Hardcoded Vehicle Types List
$vehicle_types = ['Car', 'Van', 'Motorbike', 'Other'];

// 2. Fetch Dropdown Data
$rate_query = "SELECT rate_id, type, rate FROM fuel_rate ORDER BY type ASC";
$rate_result = $conn->query($rate_query);
$fuel_rates = [];
if ($rate_result && $rate_result->num_rows > 0) {
    while ($row = $rate_result->fetch_assoc()) {
        $row['display'] = "{$row['type']} (Rs. " . number_format($row['rate'], 2) . ")";
        $fuel_rates[] = $row;
    }
}

// 3. Fetch Specific Vehicle Data (UPDATED SQL)
$vehicle_sql = "
    SELECT 
        ov.emp_id, 
        e.calling_name, 
        ov.vehicle_no, 
        ov.distance,
        ov.fuel_efficiency AS consumption_value, 
        ov.rate_id,
        ov.type, 
        ov.fixed_amount
    FROM 
        own_vehicle ov
    JOIN 
        employee e ON ov.emp_id = e.emp_id
    WHERE ov.emp_id = ? AND ov.vehicle_no = ?; 
";

$stmt = $conn->prepare($vehicle_sql);
// 'ss' means two strings (emp_id and vehicle_no)
$stmt->bind_param("ss", $target_emp_id, $target_vehicle_no);
$stmt->execute();
$vehicle_result = $stmt->get_result();
$current_vehicle_data = $vehicle_result->fetch_assoc();

if (!$current_vehicle_data) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Error: Vehicle data not found for Employee ID: " . htmlspecialchars($target_emp_id) . " and Vehicle No: " . htmlspecialchars($target_vehicle_no) . "</div>");
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Own Vehicle</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; width: 300px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; flex-shrink: 0; }
        .spinner { border: 4px solid rgba(0, 0, 0, 0.1); border-left-color: #4f46e5; border-radius: 50%; width: 1rem; height: 1rem; animation: spin 1s linear infinite; display: inline-block; margin-right: 0.5rem; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<script>
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 
    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100 font-sans">
    
    <div id="toast-container"></div> 
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            
            <div class="flex justify-between items-center mb-6 border-b pb-2">
                <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 tracking-tight">
                    Edit Vehicle for <?php echo htmlspecialchars($current_vehicle_data['calling_name']); ?>
                </h1>
            </div>
            
            <form id="edit-vehicle-form" onsubmit="handleFormSubmit(event)" action="process_vehicle.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="edit">
                
                <input type="hidden" name="original_emp_id" value="<?php echo htmlspecialchars($current_vehicle_data['emp_id']); ?>">
                <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($current_vehicle_data['emp_id']); ?>">

                <input type="hidden" name="original_vehicle_no" value="<?php echo htmlspecialchars($current_vehicle_data['vehicle_no']); ?>">

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="emp_id_display" class="block text-sm font-medium text-gray-700">Employee ID</label>
                        <input type="text" id="emp_id_display" value="<?php echo htmlspecialchars($current_vehicle_data['emp_id']); ?>" disabled
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2 bg-gray-100 cursor-not-allowed">
                    </div>
                    <div>
                        <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No</label>
                        <input type="text" id="vehicle_no" name="vehicle_no" required 
                            value="<?php echo htmlspecialchars($current_vehicle_data['vehicle_no']); ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                            placeholder="e.g., ABC-1234">
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700">Vehicle Model</label>
                        <select id="type" name="type" required 
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 appearance-none">
                            <option value="" disabled>Select Vehicle Model</option>
                            <?php foreach ($vehicle_types as $type_name): ?>
                                <option value="<?php echo htmlspecialchars($type_name); ?>"
                                    <?php echo ($type_name == $current_vehicle_data['type']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="consumption_value" class="block text-sm font-medium text-gray-700">Fuel Efficiency (L/100km)</label>
                        <input type="number" step="0.01" id="consumption_value" name="consumption_value" required 
                            value="<?php echo htmlspecialchars($current_vehicle_data['consumption_value']); ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                            placeholder="Per L/100km">
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="rate_id" class="block text-sm font-medium text-gray-700">Fuel Rate (Type & Price)</label>
                        <select id="rate_id" name="rate_id" required 
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 appearance-none">
                            <option value="" disabled>Select Fuel Rate</option>
                            <?php foreach ($fuel_rates as $rate): ?>
                                <option value="<?php echo htmlspecialchars($rate['rate_id']); ?>"
                                    <?php echo ($rate['rate_id'] == $current_vehicle_data['rate_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($rate['display']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="distance" class="block text-sm font-medium text-gray-700">Daily Base Distance (km)</label>
                        <input type="number" step="0.01" id="distance" name="distance" required 
                            value="<?php echo htmlspecialchars($current_vehicle_data['distance']); ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                            placeholder="e.g., 50.5">
                    </div>
                </div>

                <div>
                    <label for="fixed_amount" class="block text-sm font-medium text-gray-700">Fixed Allowance (LKR)</label>
                    <input type="number" step="0.01" id="fixed_amount" name="fixed_amount" required 
                        value="<?php echo htmlspecialchars($current_vehicle_data['fixed_amount'] ?? 0.00); ?>"
                        class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                        placeholder="e.g., 5000.00">
                </div>
                
                <div class="flex justify-between mt-6 pt-4 border-t border-gray-200">
                    
                <a href="own_vehicle.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Back
                </a>
                    <button type="submit" id="submit-btn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div> 

    <script>
        const form = document.getElementById("edit-vehicle-form");
        const submitBtn = document.getElementById('submit-btn');

        // --- Toast Notification Function ---
        var toastContainer = document.getElementById("toast-container");

        function showToast(message, type = 'success', duration = 3000) {
            const toast = document.createElement('div');
            toast.classList.add('toast', type);
            const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
            
            toast.innerHTML = `
                <i class="fas ${iconClass} toast-icon"></i>
                <span class="text-sm font-medium">${message}</span>
            `;
            
            toastContainer.appendChild(toast);
            setTimeout(() => { toast.classList.add('show'); }, 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => { toast.remove(); }, 300);
            }, duration);
        }

        // --- Form Submission (AJAX) ---
        function handleFormSubmit(event) {
            event.preventDefault();
            
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Saving...';

            const formData = new FormData(form);
            const actionUrl = 'process_vehicle.php';

            fetch(actionUrl, {
                method: 'POST',
                body: new URLSearchParams(formData).toString(),
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json' 
                }
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(error => { throw new Error(error.message || 'Server error occurred.'); });
                }
                return response.json();
            })
            .then(response => {
                if (response.success) {
                    showToast(response.message || "Vehicle updated successfully!", 'success');
                    
                    // Redirect to the main list after success
                    setTimeout(() => window.location.href = 'own_vehicle.php', 1500); 
                } else {
                    throw new Error(response.message || "An unknown error occurred on the server.");
                }
            })
            .catch(error => {
                showToast(`Operation Failed: ${error.message}`, 'error', 5000);
            })
            .finally(() => {
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Save Changes';
                }, 1000);
            });
        }
    </script>
</body>
</html>