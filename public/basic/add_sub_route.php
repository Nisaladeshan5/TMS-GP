<?php
// add_sub_route.php
require_once '../../includes/session_check.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// 1. Fetch Routes for Dropdown
$routes = [];
$routes_sql = "SELECT route_code, route FROM route ORDER BY SUBSTRING(route_code, 7, 3)";
$routes_result = $conn->query($routes_sql);
if ($routes_result && $routes_result->num_rows > 0) {
    while ($row = $routes_result->fetch_assoc()) {
        $routes[] = $row;
    }
}

// 2. Fetch Suppliers for Dropdown
$suppliers = [];
$supplier_sql = "SELECT supplier_code, supplier FROM supplier ORDER BY supplier";
$supplier_result = $conn->query($supplier_sql);
if ($supplier_result && $supplier_result->num_rows > 0) {
    while ($row = $supplier_result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// 3. Fetch Vehicles for Dropdown
$vehicles = [];
$vehicle_sql = "SELECT vehicle_no FROM vehicle WHERE purpose='sub_route' ORDER BY vehicle_no";
$vehicle_result = $conn->query($vehicle_sql);
if ($vehicle_result && $vehicle_result->num_rows > 0) {
    while ($row = $vehicle_result->fetch_assoc()) {
        $vehicles[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Sub-Route</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Toast Notifications CSS */
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
        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        .toast.success {
            background-color: #4CAF50; /* Green */
        }
        .toast.error {
            background-color: #F44336; /* Red */
        }
        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
        /* Style to force uppercase visually */
        .uppercase-input {
            text-transform: uppercase;
        }
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
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add New Sub-Route</h1>
        
        <form id="subRouteForm" onsubmit="handleFormSubmit(event)" class="space-y-6">
            <input type="hidden" name="action" id="action" value="add">
            
            <div class="grid md:grid-cols-2 gap-6">
                <div> 
                    <label for="route_code" class="block text-sm font-medium text-gray-700">Route Name (Parent):</label>
                    <select id="route_code" name="route_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        <option value="">-- Select Route --</option>
                        <?php foreach ($routes as $route): ?>
                            <option value="<?= htmlspecialchars($route['route_code']) ?>">
                                <?= htmlspecialchars($route['route']) ?> (<?= htmlspecialchars($route['route_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="sub_route_code" class="block text-sm font-medium text-gray-700">Sub-Route Code Suffix:</label>
                    <input type="text" id="sub_route_code" name="sub_route_code" 
                           placeholder="e.g. 1WED-V" maxlength="6" required 
                           class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 uppercase-input">
                    <p class="text-xs text-gray-500 mt-1">Format: Digit + 3 Letters + Hyphen + Letter (e.g. 1WED-V)</p>
                </div>
            </div>
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="sub_route" class="block text-sm font-medium text-gray-700">Sub-Route Name:</label>
                    <input type="text" id="sub_route" name="sub_route" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
                <div>
                    <label for="supplier" class="block text-sm font-medium text-gray-700">Supplier:</label>
                    <select id="supplier" name="supplier" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($suppliers as $supplier_row): ?>
                            <option value="<?= htmlspecialchars($supplier_row["supplier"]) ?>" data-code="<?= htmlspecialchars($supplier_row["supplier_code"]) ?>">
                                <?= htmlspecialchars($supplier_row["supplier"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No:</label>
                    <select id="vehicle_no" name="vehicle_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        <option value="">-- Select Vehicle No --</option>
                        <?php foreach ($vehicles as $vehicle_row): ?>
                            <option value="<?= htmlspecialchars($vehicle_row["vehicle_no"]) ?>">
                                <?= htmlspecialchars($vehicle_row["vehicle_no"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="distance" class="block text-sm font-medium text-gray-700">Distance (km):</label>
                    <input type="number" id="distance" name="distance" step="0.01" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="per_day_rate" class="block text-sm font-medium text-gray-700">Per Day Rate (Rs.):</label>
                    <input type="number" id="per_day_rate" name="per_day_rate" step="0.01" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
            </div>
            
            <div class="flex justify-between mt-6 pt-4 border-t border-gray-200">
                <a href="sub_routes.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" id="submitBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Add Sub-Route
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const form = document.getElementById("subRouteForm");
    const toastContainer = document.getElementById("toast-container");
    const subRouteInput = document.getElementById('sub_route_code');

    // 1. Auto Capitalize Input Listener
    subRouteInput.addEventListener('input', function (e) {
        this.value = this.value.toUpperCase();
    });

    // Toast Function
    function showToast(message, type) {
        const toast = document.createElement('div'); 
        toast.className = `toast ${type}`; 
        
        let iconPath = '';
        if (type === 'success') {
             iconPath = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />';
        } else {
             iconPath = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />';
        }

        toast.innerHTML = ` 
            <svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                ${iconPath}
            </svg>
            <span>${message}</span> 
        `; 
        
        toastContainer.appendChild(toast); 

        setTimeout(() => toast.classList.add('show'), 10); 

        setTimeout(() => { 
            toast.classList.remove('show'); 
            toast.addEventListener('transitionend', () => toast.remove(), { once: true }); 
        }, 3000); 
    } 

    function handleFormSubmit(event) {
        event.preventDefault();
        const formData = new FormData(form);
        
        // --- VALIDATION & CONCATENATION START ---
        
        const routeCode = formData.get('route_code');
        // Ensure user input is uppercase for regex check
        const userTypedCode = formData.get('sub_route_code').toUpperCase(); 

        // 2. Updated Regex Pattern for "1WED-V" format
        // ^ = Start, \d = One Digit, [A-Z]{3} = 3 Letters, - = Hyphen, [A-Z] = 1 Letter, $ = End
        const codePattern = /^\d[A-Z]{3}-[A-Z]$/; 

        if (!codePattern.test(userTypedCode)) {
            showToast("Invalid format! Use: Number + 3 Letters + Hyphen + Letter (e.g. 1WED-V)", 'error');
            return; // Stop execution
        }

        // 3. Combine Route Code + Suffix (Parent Code + Suffix)
        if (routeCode && userTypedCode) {
            const finalSubCode = `${routeCode.trim()}-${userTypedCode.trim()}`;
            formData.set('sub_route_code', finalSubCode);
        } else {
            showToast("Please select a Route and enter a Sub-Route Code.", 'error');
            return;
        }
        
        // --- VALIDATION & CONCATENATION END ---

        // Handle Supplier Code Extraction
        const selectedSupplierName = formData.get('supplier');
        const supplierSelect = document.getElementById('supplier');
        const selectedOption = supplierSelect.querySelector(`option[value="${selectedSupplierName}"]`);
        
        if (selectedOption) {
            formData.set('supplier_code', selectedOption.getAttribute('data-code'));
        } else {
             showToast("Please select a valid supplier.", 'error');
             return;
        }
        formData.delete('supplier'); // Remove supplier name, keep supplier_code

        // Disable button during submission
        document.getElementById('submitBtn').disabled = true;

        fetch('sub_routes_backend.php', { 
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            document.getElementById('submitBtn').disabled = false;
            
            if (data.trim() === "Success") {
                showToast("Sub-Route added successfully!", 'success');
                setTimeout(() => {
                    window.location.href = 'sub_routes.php'; 
                }, 1300); 
            } else {
                showToast("Error: " + data, 'error');
            }
        })
        .catch(error => {
            document.getElementById('submitBtn').disabled = false;
            console.error('Error:', error);
            showToast("An error occurred. Please try again.", 'error');
        });
    }
</script>

</body>
</html>
<?php $conn->close(); ?>