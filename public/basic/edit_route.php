<?php
// edit_route.php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- DYNAMIC FUEL PRICE & CONSUMPTION SETUP (Helper Functions) ---

// 1. Fetch LATEST Fuel Price for a specific rate_id (consumption ID)
// මෙම ශ්‍රිතය fuel_rate වගුවෙන් නිශ්චිත rate_id එකට අදාළ නවතම මිල ලබා ගනී.
function get_latest_fuel_price_by_rate_id($conn, $rate_id)
{
    $sql = "SELECT rate FROM fuel_rate WHERE rate_id = ? ORDER BY date DESC, id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0; 
    }
    $stmt->bind_param("i", $rate_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['rate'] ?? 0;
}
// ගෝලීය වශයෙන් නවතම මිල ලබා ගැනීම අවශ්‍ය නොවේ
// $current_fuel_price_per_liter = get_current_fuel_price($conn); // <<-- මෙම පේළිය ඉවත් කර ඇත

// 2. Fetch Consumption Rates (km per Liter) indexed by c_id
$consumption_rates = [];
$consumption_sql = "SELECT c_id, distance FROM consumption";
$consumption_result = $conn->query($consumption_sql);
if ($consumption_result) {
    while ($row = $consumption_result->fetch_assoc()) {
        $consumption_rates[$row['c_id']] = $row['distance'];
    }
}
$default_km_per_liter = 1.00;

// 3. Helper Function to calculate the Fuel Cost per KM (නවීකරණය කරන ලදී)
// $fuel_price_per_liter පරාමිතිය ඉවත් කර ඇත
function calculate_fuel_cost_per_km($conn, $vehicle_no, $consumption_rates) {
    global $default_km_per_liter;

    if (empty($vehicle_no)) {
        return 0;
    }

    $vehicle_stmt = $conn->prepare("SELECT fuel_efficiency, rate_id FROM vehicle WHERE vehicle_no = ?");
    $vehicle_stmt->bind_param("s", $vehicle_no);
    $vehicle_stmt->execute();
    $vehicle_result = $vehicle_stmt->get_result();
    $vehicle_row = $vehicle_result->fetch_assoc();
    $vehicle_stmt->close();

    // fuel_efficiency යනු rate_id යැයි උපකල්පනය කරමු
    $consumption_id = $vehicle_row['fuel_efficiency'] ?? null;
    $rate_id = $vehicle_row['rate_id'] ?? null; 
    
    if ($consumption_id === null) {
        return 0;
    }

    if ($rate_id === null) {
        return 0;
    }

    // නිශ්චිත rate_id එකට අදාළ නවතම ඉන්ධන මිල ලබා ගැනීම
    $current_fuel_price_per_liter = get_latest_fuel_price_by_rate_id($conn, $rate_id);

    if ($current_fuel_price_per_liter <= 0) {
         return 0;
    }
    
    $km_per_liter = $consumption_rates[$consumption_id] ?? 0;
    if ($km_per_liter <= 0) $km_per_liter = $default_km_per_liter;

    // නවතම මිල භාවිතයෙන් ගණනය කිරීම
    return $current_fuel_price_per_liter / $km_per_liter;
}

// --- END DYNAMIC FUEL SETUP ---

$route_data = null;
$route_code = $_GET['code'] ?? '';

// 🎯 Filter Persistence Setup
$prev_purpose_filter = $_GET['purpose_filter'] ?? 'staff';
$prev_status_filter = $_GET['status_filter'] ?? 'active';
$back_url_params = "purpose_filter=" . urlencode($prev_purpose_filter) . "&status_filter=" . urlencode($prev_status_filter);

// --- 1. Fetch Existing Route Data ---
if (!empty($route_code)) {
    $sql_route = "SELECT 
                        r.*,
                        s.supplier AS supplier_name,
                        r.vehicle_no
                FROM route r
                JOIN supplier s ON r.supplier_code = s.supplier_code
                WHERE r.route_code = ?";
    $stmt_route = $conn->prepare($sql_route);
    $stmt_route->bind_param("s", $route_code);
    $stmt_route->execute();
    $result_route = $stmt_route->get_result();

    if ($result_route->num_rows === 1) {
        $route_data = $result_route->fetch_assoc();

        $fixed_amount_float = (float)$route_data['fixed_amount'];
        $with_fuel = (int)$route_data['with_fuel'];
        $vehicle_no = htmlspecialchars($route_data['vehicle_no']);

        $calculated_fuel_amount_per_km = 0;
        if ($with_fuel === 1) {
            // නවීකරණය කරන ලද ශ්‍රිතය ඇමතීම ($current_fuel_price_per_liter ඉවත් කර ඇත)
            $calculated_fuel_amount_per_km = calculate_fuel_cost_per_km(
                $conn,
                $vehicle_no,
                $consumption_rates
            );
        }

        $route_data['fuel_amount2'] = number_format($calculated_fuel_amount_per_km, 2, '.', '');
        $total_amount_per_km = $fixed_amount_float + $calculated_fuel_amount_per_km;
    } else {
        header("Location: routes_staff2.php?{$back_url_params}&status=error&message=" . urlencode("Route not found."));
        exit();
    }
    $stmt_route->close();
} else {
    header("Location: routes_staff2.php?{$back_url_params}&status=error&message=" . urlencode("Route code missing."));
    exit();
}

// --- 2. Fetch Suppliers & Vehicles ---
$suppliers = $conn->query("SELECT supplier_code, supplier FROM supplier ORDER BY supplier")->fetch_all(MYSQLI_ASSOC);
$vehicles = $conn->query("SELECT vehicle_no FROM vehicle ORDER BY vehicle_no")->fetch_all(MYSQLI_ASSOC);

include('../../includes/header.php');
include('../../includes/navbar.php');
?>
<style>
#toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
.toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); color: white; transition: transform .3s, opacity .3s; transform: translateY(-20px); opacity: 0; }
.toast.show { transform: translateY(0); opacity: 1; }
.toast.success { background: #4CAF50; }
.toast.error { background: #F44336; }
.toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
.readonly-field { background-color: #e5e7eb; cursor: not-allowed; }
</style>

<script>
const SESSION_TIMEOUT_MS = 32400000;
setTimeout(() => {
    alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
    window.location.href = "/TMS/includes/client_logout.php";
}, SESSION_TIMEOUT_MS);
</script>

<body class="bg-gray-100 font-sans">
<div id="toast-container"></div>
<div class="w-[85%] ml-[15%]">
    <div class="max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10 mx-auto">
        <div class="mb-3 pb-1 border-b border-gray-200">
            <h1 class="text-3xl font-extrabold text-gray-800">Edit Route Details</h1>
            <p class="text-lg text-gray-600 mt-1">Route Code: 
                <span class="font-semibold"><?= htmlspecialchars($route_data['route_code']) ?></span>
            </p>
        </div>

        <form id="routeForm" onsubmit="handleFormSubmit(event)" class="space-y-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="supplier_code" id="supplier_code" value="<?= htmlspecialchars($route_data['supplier_code']) ?>"> 
            <input type="hidden" id="filter_params" value="<?= htmlspecialchars($back_url_params) ?>">

            <div class="bg-gray-50 p-3 border border-gray-100 rounded-lg space-y-2">
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="route_code" class="block text-sm font-semibold text-gray-700">Route Code:</label>
                        <input type="text" id="route_code" name="route_code" required readonly
                            value="<?= htmlspecialchars($route_data['route_code']) ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2 readonly-field">
                    </div>
                    <div> 
                        <label for="route" class="block text-sm font-semibold text-gray-700">Route Name:</label>
                        <input type="text" id="route" name="route" required 
                            value="<?= htmlspecialchars($route_data['route']) ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="distance" class="block text-sm font-semibold text-gray-700">Distance (km):</label>
                        <input type="number" id="distance" name="distance" step="0.01" required 
                            value="<?= htmlspecialchars($route_data['distance']) ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                    </div>
                    <div>
                        <label for="supplier" class="block text-sm font-semibold text-gray-700">Supplier:</label>
                        <select id="supplier" name="supplier" required 
                            onchange="document.getElementById('supplier_code').value = this.options[this.selectedIndex].value;"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">-- Select Supplier --</option>
                            <?php foreach ($suppliers as $row): ?>
                                <?php 
                                    $selected = ($row['supplier_code'] === $route_data['supplier_code']) ? 'selected' : '';
                                ?>
                                <option value="<?= htmlspecialchars($row['supplier_code']) ?>" <?= $selected ?>>
                                    <?= htmlspecialchars($row['supplier']) ?> (<?= htmlspecialchars($row['supplier_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="vehicle_no" class="block text-sm font-semibold text-gray-700">Vehicle No:</label>
                        <select id="vehicle_no" name="vehicle_no" required 
                            onchange="calculateFuelAmount()"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">-- Select Vehicle No --</option>
                            <?php foreach ($vehicles as $row): ?>
                                <?php $selected = ($row['vehicle_no'] === $route_data['vehicle_no']) ? 'selected' : ''; ?>
                                <option value="<?= htmlspecialchars($row['vehicle_no']) ?>" <?= $selected ?>>
                                    <?= htmlspecialchars($row['vehicle_no']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="purpose" class="block text-sm font-semibold text-gray-700">Purpose:</label>
                        <select id="purpose" name="purpose" required 
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="staff" <?= ($route_data['purpose'] === 'staff') ? 'selected' : '' ?>>Staff</option>
                            <option value="factory" <?= ($route_data['purpose'] === 'factory') ? 'selected' : '' ?>>Factory</option>
                        </select>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="fixed_amount" class="block text-sm font-semibold text-gray-700">Fixed Amount (Rs./km):</label>
                        <input type="number" id="fixed_amount" name="fixed_amount" step="0.01" required 
                            value="<?= htmlspecialchars($route_data['fixed_amount']) ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Fuel Calculation:</label>
                        <div class="flex items-center space-x-4 mt-2 p-2 border border-gray-300 rounded-md bg-gray-50">
                            <label class="flex items-center">
                                <input type="radio" name="fuel_option" value="with_fuel" 
                                    <?= ($route_data['with_fuel'] == 1) ? 'checked' : '' ?>
                                    onchange="calculateFuelAmount()"
                                    class="mr-1 text-indigo-600 focus:ring-indigo-500">
                                With Fuel
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="fuel_option" value="without_fuel" 
                                    <?= ($route_data['with_fuel'] == 0) ? 'checked' : '' ?>
                                    onchange="calculateFuelAmount()"
                                    class="mr-1 text-indigo-600 focus:ring-indigo-500">
                                Without Fuel
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="fuel_amount" class="block text-sm font-semibold text-gray-700">Fuel Amount (Rs./km):</label>
                        <input type="number" id="fuel_amount" name="fuel_amount" step="0.01" required 
                            value="<?= number_format($route_data['fuel_amount2'], 2, '.', '') ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2 readonly-field" readonly>
                    </div>
                    <div>
                        <label for="assigned_person" class="block text-sm font-semibold text-gray-700">Assigned Person:</label>
                        <input type="text" id="assigned_person" name="assigned_person" required 
                            value="<?= htmlspecialchars($route_data['assigned_person']) ?>"
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                    </div>
                </div>
            </div>

            <input type="hidden" name="fuel_option_value" id="fuel_option_value" value="<?= htmlspecialchars($route_data['with_fuel']) ?>">

            <div class="flex justify-between mt-6">
                <a href="routes_staff2.php?<?= htmlspecialchars($back_url_params) ?>" 
                    class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" id="submitBtn" 
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const form = document.getElementById("routeForm");
const vehicleNoSelect = document.getElementById('vehicle_no');
const fuelAmountInput = document.getElementById('fuel_amount');
const fuelRadioOptions = document.querySelectorAll('input[name="fuel_option"]');
const fuelOptionValueInput = document.getElementById('fuel_option_value');
const filterParams = document.getElementById('filter_params').value;

function showToast(message, type) {
    const toastContainer = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        ${type === 'success'
            ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />'
            : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />'}
    </svg><span>${message}</span>`;
    toastContainer.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        toast.classList.remove('show');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }, 1300);
}

// NOTE: The AJAX call below relies on routes_backend2.php being updated to correctly use
// the vehicle's fuel_efficiency (rate_id) to fetch the LATEST fuel price.
async function calculateFuelAmount() {
    const selectedOption = document.querySelector('input[name="fuel_option"]:checked').value;
    const vehicleNo = vehicleNoSelect.value;
    fuelAmountInput.readOnly = true;
    fuelOptionValueInput.value = (selectedOption === 'with_fuel') ? 1 : 0;

    if (selectedOption === 'without_fuel') {
        fuelAmountInput.value = (0.00).toFixed(2);
        return;
    }

    if (selectedOption === 'with_fuel' && vehicleNo) {
        try {
            // routes_backend2.php now needs to fetch the latest rate specific to the vehicle's rate_id
            const response = await fetch(`routes_backend2.php?action=get_fuel_rates&vehicle_no=${encodeURIComponent(vehicleNo)}`);
            const data = await response.json();

            if (data.success) {
                const fuelCostPerLiter = parseFloat(data.fuel_cost_per_liter); // This should be the latest rate for the vehicle's rate_id
                const kmPerLiter = parseFloat(data.km_per_liter);
                if (!isNaN(fuelCostPerLiter) && !isNaN(kmPerLiter) && kmPerLiter > 0) {
                    fuelAmountInput.value = (fuelCostPerLiter / kmPerLiter).toFixed(2);
                } else {
                    showToast("Fuel data invalid or missing for this vehicle.", 'error');
                    fuelAmountInput.value = (0.00).toFixed(2);
                }
            } else {
                showToast(data.message || "Failed to fetch fuel rates.", 'error');
                fuelAmountInput.value = (0.00).toFixed(2);
            }
        } catch (error) {
            console.error(error);
            showToast("Error fetching fuel data.", 'error');
            fuelAmountInput.value = (0.00).toFixed(2);
        }
    } else {
        showToast("Please select a Vehicle Number.", 'error');
        fuelAmountInput.value = (0.00).toFixed(2);
    }
}

function handleFormSubmit(event) {
    event.preventDefault();
    calculateFuelAmount().then(() => {
        const formData = new FormData(form);
        const supplierCode = formData.get('supplier');
        if (!supplierCode) { showToast('Please select a valid Supplier.', 'error'); return; }
        formData.set('supplier_code', supplierCode);
        formData.delete('supplier');

        if (parseFloat(formData.get('fixed_amount')) <= 0) {
            showToast("Fixed Amount must be greater than zero.", 'error');
            return;
        }
        if (formData.get('fuel_amount') === '' || isNaN(parseFloat(formData.get('fuel_amount')))) {
            showToast("Fuel Amount is invalid.", 'error');
            return;
        }

        document.getElementById('submitBtn').disabled = true;
        fetch('routes_backend2.php', { method: 'POST', body: formData })
            .then(res => res.text())
            .then(result => {
                document.getElementById('submitBtn').disabled = false;
                if (result.trim() === 'Success') {
                    showToast('Route updated successfully!', 'success');
                    setTimeout(() => {
                        window.location.href = 'routes_staff2.php?status=success&message=' + 
                            encodeURIComponent('Route ' + formData.get('route_code') + ' updated successfully!') + '&' + filterParams;
                    }, 1300);
                } else {
                    showToast('Update Error: ' + result, 'error');
                }
            })
            .catch(error => {
                document.getElementById('submitBtn').disabled = false;
                showToast('Network Error: ' + error.message, 'error');
            });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    calculateFuelAmount();
    vehicleNoSelect.addEventListener('change', calculateFuelAmount);
    fuelRadioOptions.forEach(radio => radio.addEventListener('change', calculateFuelAmount));
});
</script>
</body>
</html>
<?php $conn->close(); ?>