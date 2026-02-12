<?php
// add_sub_route.php
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .uppercase-input { text-transform: uppercase; }
        .readonly-field { background-color: #f3f4f6; cursor: not-allowed; }
    </style>
</head>
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
                    <select id="route_code" name="route_code" required class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm p-2">
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
                    <input type="text" id="sub_route_code" name="sub_route_code" placeholder="e.g. 1WED-V" maxlength="7" required class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm p-2 uppercase-input">
                </div>
            </div>
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="sub_route" class="block text-sm font-medium text-gray-700">Sub-Route Name:</label>
                    <input type="text" id="sub_route" name="sub_route" required class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm p-2">
                </div>
                <div>
                    <label for="supplier" class="block text-sm font-medium text-gray-700">Supplier:</label>
                    <select id="supplier" name="supplier" required class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm p-2">
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
                    <select id="vehicle_no" name="vehicle_no" required class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm p-2">
                        <option value="">-- Select Vehicle --</option>
                        <?php foreach ($vehicles as $vehicle_row): ?>
                            <option value="<?= htmlspecialchars($vehicle_row["vehicle_no"]) ?>">
                                <?= htmlspecialchars($vehicle_row["vehicle_no"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="distance" class="block text-sm font-medium text-gray-700">Distance (km):</label>
                    <input type="number" id="distance" name="distance" step="0.01" required class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm p-2">
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="fixed_rate" class="block text-sm font-medium text-gray-700">Fixed Rate (Rs./km):</label>
                    <input type="number" id="fixed_rate" name="fixed_rate" step="0.01" required class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm p-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Fuel Option:</label>
                    <div class="flex items-center space-x-4 mt-2 p-2 border border-gray-300 rounded-md bg-gray-50">
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="with_fuel" value="1" checked class="mr-1 h-4 w-4 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-700">With Fuel</span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="with_fuel" value="0" class="mr-1 h-4 w-4 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-700">Without Fuel</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="fuel_amount_display" class="block text-sm font-medium text-gray-700">Fuel Amount (Auto):</label>
                    <input type="text" id="fuel_amount_display" readonly class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm p-2" value="0.00">
                </div>
            </div>
            
            <div class="flex justify-between mt-6 pt-4 border-t border-gray-200">
                <a href="sub_routes.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md transition duration-300 transform hover:scale-105 shadow-md text-sm">Cancel</a>
                <button type="submit" id="submitBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md transition duration-300 transform hover:scale-105 shadow-md text-sm">Add Sub-Route</button>
            </div>
        </form>
    </div>
</div>

<script>
    const form = document.getElementById("subRouteForm");
    const vehicleNoSelect = document.getElementById('vehicle_no');
    const fuelDisplay = document.getElementById('fuel_amount_display');
    const fuelRadios = document.querySelectorAll('input[name="with_fuel"]');

    // Toast Function
    function showToast(message, type) {
        const container = document.getElementById("toast-container");
        const toast = document.createElement('div');
        toast.className = `toast ${type} show`;
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
        toast.innerHTML = `<i class="fas ${icon} mr-2"></i><span>${message}</span>`;
        container.appendChild(toast);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // --- Dynamic Fuel Calculation ---
    async function updateFuelRate() {
        const vehicleNo = vehicleNoSelect.value;
        const withFuel = document.querySelector('input[name="with_fuel"]:checked').value;

        // "Without Fuel" select karala nam ho vehicle select karala nethnam 0.00 pennanawa
        if (withFuel == "0" || !vehicleNo) {
            fuelDisplay.value = "0.00";
            return;
        }

        try {
            // Fuel calculation action eka backend file ekatama call karanawa
            const response = await fetch(`sub_routes_backend.php?action=get_fuel_rates&vehicle_no=${encodeURIComponent(vehicleNo)}`);
            const data = await response.json();
            
            if (data.success) {
                const fuelPrice = parseFloat(data.fuel_cost_per_liter);
                const kmPerLiter = parseFloat(data.km_per_liter);

                if (kmPerLiter > 0) {
                    const rate = fuelPrice / kmPerLiter;
                    fuelDisplay.value = rate.toFixed(2);
                } else {
                    fuelDisplay.value = "0.00";
                    showToast("Consumption data missing for this vehicle", "error");
                }
            } else {
                fuelDisplay.value = "0.00";
            }
        } catch (e) {
            console.error("Fuel fetch error:", e);
            fuelDisplay.value = "0.00";
        }
    }

    // Event Listeners for calculation
    vehicleNoSelect.addEventListener('change', updateFuelRate);
    fuelRadios.forEach(r => r.addEventListener('change', updateFuelRate));

    // Handle Form Submit
    function handleFormSubmit(event) {
        event.preventDefault();
        const formData = new FormData(form);
        
        // 1. Combine Route Code + Suffix (Parent + Suffix)
        const routeCode = formData.get('route_code');
        const suffix = formData.get('sub_route_code').toUpperCase();
        if (routeCode && suffix) {
            formData.set('sub_route_code', `${routeCode}-${suffix}`);
        } else {
            showToast("Missing code details", "error");
            return;
        }

        // 2. Extract Supplier Code
        const supplierSelect = document.getElementById('supplier');
        const selectedOpt = supplierSelect.options[supplierSelect.selectedIndex];
        if (selectedOpt && selectedOpt.value) {
            formData.set('supplier_code', selectedOpt.getAttribute('data-code'));
        } else {
            showToast("Please select a supplier", "error");
            return;
        }

        document.getElementById('submitBtn').disabled = true;

        fetch('sub_routes_backend.php', { method: 'POST', body: formData })
        .then(res => res.text())
        .then(data => {
            if (data.trim() === "Success") {
                showToast("Sub-Route added successfully!", 'success');
                setTimeout(() => window.location.href = 'sub_routes.php', 1300);
            } else {
                showToast("Error: " + data, 'error');
                document.getElementById('submitBtn').disabled = false;
            }
        })
        .catch(err => {
            console.error("Submit error:", err);
            showToast("An error occurred. Please try again.", "error");
            document.getElementById('submitBtn').disabled = false;
        });
    }
</script>
</body>
</html>