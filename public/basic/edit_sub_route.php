<?php
// edit_sub_route.php
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

// 1. Check if ID is provided
if (isset($_GET['code'])) {
    $sub_route_code = $_GET['code'];
} elseif (isset($_GET['sub_route_code'])) {
    $sub_route_code = $_GET['sub_route_code'];
} else {
    echo "<div class='p-10 text-red-600 font-bold'>Error: No Sub-Route Code provided.</div>";
    exit;
}

// 2. Fetch Current Sub-Route Details
$current_data = null;
$stmt = $conn->prepare("SELECT * FROM sub_route WHERE sub_route_code = ?");
$stmt->bind_param("s", $sub_route_code);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $current_data = $result->fetch_assoc();
} else {
    echo "<div class='p-10 text-red-600 font-bold'>Error: Sub-Route not found.</div>";
    exit;
}
$stmt->close();

// 3. Fetch Dropdown Data
$routes = [];
$routes_result = $conn->query("SELECT route_code, route FROM route WHERE is_active = 1 ORDER BY route");
if ($routes_result) while ($row = $routes_result->fetch_assoc()) $routes[] = $row;

$suppliers = [];
$supplier_result = $conn->query("SELECT supplier_code, supplier FROM supplier ORDER BY supplier");
if ($supplier_result) while ($row = $supplier_result->fetch_assoc()) $suppliers[] = $row;

$vehicles = [];
$vehicle_result = $conn->query("SELECT vehicle_no FROM vehicle WHERE purpose='sub_route' ORDER BY vehicle_no");
if ($vehicle_result) while ($row = $vehicle_result->fetch_assoc()) $vehicles[] = $row;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Sub-Route</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Screen එකට හරියන පරිදි උස සකස් කිරීම */
        html, body { height: 100vh; overflow: hidden; }
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .readonly-field { background-color: #f3f4f6; cursor: not-allowed; }
        
        /* Form container එක scroll නොවී මැදට ගැනීමට */
        .main-wrapper { 
            height: calc(100vh - 20px); /* Navbar එකක් තිබේ නම් ඒ සඳහා ඉඩ තබන්න */
            display: flex; 
            align-items: center; 
            justify-content: center;
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">

<div id="toast-container"></div>

<div class="w-[85%] ml-[15%] main-wrapper">
    <div class="w-full max-w-3xl p-4 bg-white shadow-xl rounded-lg">
        <h1 class="text-2xl font-extrabold text-gray-900 mb-2 border-b pb-1">Edit Sub-Route</h1>
        
        <div class="mb-3 pb-2 border-b border-gray-100">
            <h3 class="text-xl font-semibold text-gray-700"><?php echo htmlspecialchars($current_data['sub_route']); ?></h3>
            <p class="text-xs text-gray-500">Code: <span class="font-mono font-bold"><?= htmlspecialchars($current_data['sub_route_code']) ?></span></p>
        </div>
        
        <form id="editSubRouteForm" onsubmit="handleFormSubmit(event)" class="space-y-3">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="sub_route_code" value="<?= htmlspecialchars($current_data['sub_route_code']) ?>">
            
            <div class="grid md:grid-cols-2 gap-3 bg-gray-50 p-3 rounded-lg border">
                <div class="md:col-span-2"><h4 class="font-bold text-sm text-blue-600 border-b">Route Details</h4></div>
                
                <div>
                    <label class="block text-xs font-semibold">Parent Route:</label>
                    <select name="route_code" required class="mt-1 p-1.5 w-full rounded border shadow-sm text-sm">
                        <?php foreach ($routes as $route): ?>
                            <option value="<?= htmlspecialchars($route['route_code']) ?>" <?= ($route['route_code'] == $current_data['route_code']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($route['route']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold">Sub-Route Name:</label>
                    <input type="text" name="sub_route" value="<?= htmlspecialchars($current_data['sub_route']) ?>" required class="mt-1 p-1.5 w-full rounded border shadow-sm text-sm">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold">Supplier:</label>
                    <select id="supplier" name="supplier" required class="mt-1 p-1.5 w-full rounded border shadow-sm text-sm">
                        <?php foreach ($suppliers as $supplier_row): ?>
                            <option value="<?= htmlspecialchars($supplier_row["supplier"]) ?>" 
                                    data-code="<?= htmlspecialchars($supplier_row["supplier_code"]) ?>"
                                    <?= ($supplier_row['supplier_code'] == $current_data['supplier_code']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($supplier_row["supplier"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="grid md:grid-cols-2 gap-3 bg-gray-50 p-3 rounded-lg border">
                <div class="md:col-span-2"><h4 class="font-bold text-sm text-blue-600 border-b">Costs & Vehicle</h4></div>

                <div>
                    <label class="block text-xs font-semibold">Vehicle No:</label>
                    <select id="vehicle_no" name="vehicle_no" required class="mt-1 p-1.5 w-full rounded border shadow-sm text-sm">
                        <?php foreach ($vehicles as $vehicle_row): ?>
                            <option value="<?= htmlspecialchars($vehicle_row["vehicle_no"]) ?>"
                                <?= ($vehicle_row['vehicle_no'] == $current_data['vehicle_no']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vehicle_row["vehicle_no"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold">Distance (km):</label>
                    <input type="number" name="distance" step="0.01" value="<?= htmlspecialchars($current_data['distance']) ?>" required class="mt-1 p-1.5 w-full rounded border shadow-sm text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold">Fixed Rate (Rs/km):</label>
                    <input type="number" name="fixed_rate" step="0.01" value="<?= htmlspecialchars($current_data['fixed_rate']) ?>" required class="mt-1 p-1.5 w-full rounded border shadow-sm text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700">Fuel Option:</label>
                    <div class="flex items-center space-x-4 mt-1">
                        <label class="inline-flex items-center">
                            <input type="radio" name="with_fuel" value="1" <?= ($current_data['with_fuel'] == 1) ? 'checked' : '' ?> class="h-3 w-3 text-blue-600">
                            <span class="ml-2 text-xs">With Fuel</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="with_fuel" value="0" <?= ($current_data['with_fuel'] == 0) ? 'checked' : '' ?> class="h-3 w-3 text-blue-600">
                            <span class="ml-2 text-xs">No Fuel</span>
                        </label>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-blue-600">Calculated Fuel Rate (Auto):</label>
                    <input type="text" id="fuel_display" readonly class="mt-1 p-1.5 w-full rounded border shadow-sm text-sm readonly-field">
                </div>
            </div>
            
            <div class="flex justify-between mt-4">
                <a href="sub_routes.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded shadow text-sm transition transform hover:scale-105">Cancel</a>
                <button type="submit" id="submitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded shadow text-sm transition transform hover:scale-105">Update Sub-Route</button>
            </div>
        </form>
    </div>
</div>

<script>
    const vehicleSelect = document.getElementById('vehicle_no');
    const fuelDisplay = document.getElementById('fuel_display');
    const fuelRadios = document.querySelectorAll('input[name="with_fuel"]');

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

    async function updateFuelRate() {
        const vNo = vehicleSelect.value;
        const withFuel = document.querySelector('input[name="with_fuel"]:checked').value;

        if (withFuel == "0" || !vNo) {
            fuelDisplay.value = "0.00";
            return;
        }

        try {
            const response = await fetch(`sub_routes_backend.php?action=get_fuel_rates&vehicle_no=${encodeURIComponent(vNo)}`);
            const data = await response.json();
            if (data.success) {
                const rate = (parseFloat(data.fuel_cost_per_liter) / parseFloat(data.km_per_liter));
                fuelDisplay.value = rate.toFixed(2);
            } else {
                fuelDisplay.value = "0.00";
            }
        } catch (e) {
            fuelDisplay.value = "0.00";
        }
    }

    vehicleSelect.addEventListener('change', updateFuelRate);
    fuelRadios.forEach(r => r.addEventListener('change', updateFuelRate));
    document.addEventListener('DOMContentLoaded', updateFuelRate);

    function handleFormSubmit(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const supplierSelect = document.getElementById('supplier');
        const selectedOption = supplierSelect.options[supplierSelect.selectedIndex];
        if (selectedOption) {
            formData.set('supplier_code', selectedOption.getAttribute('data-code'));
        }

        document.getElementById('submitBtn').disabled = true;

        fetch('sub_routes_backend.php', { method: 'POST', body: formData })
        .then(res => res.text())
        .then(data => {
            if (data.trim() === "Success") {
                showToast("Sub-Route updated successfully!", 'success');
                setTimeout(() => window.location.href = 'sub_routes.php', 1300);
            } else {
                showToast(data, 'error');
                document.getElementById('submitBtn').disabled = false;
            }
        });
    }
</script>

</body>
</html>