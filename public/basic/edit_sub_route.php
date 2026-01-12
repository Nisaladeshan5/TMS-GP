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

// --- Navbar සහ Header මෙතැනින් load වෙයි ---
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
// Routes
$routes = [];
$routes_result = $conn->query("SELECT route_code, route FROM route WHERE is_active = 1 ORDER BY route");
if ($routes_result) while ($row = $routes_result->fetch_assoc()) $routes[] = $row;

// Suppliers
$suppliers = [];
$supplier_result = $conn->query("SELECT supplier_code, supplier FROM supplier ORDER BY supplier");
if ($supplier_result) while ($row = $supplier_result->fetch_assoc()) $suppliers[] = $row;

// Vehicles
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
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; max-width: 350px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; flex-shrink: 0; }
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
    <div class="w-full max-w-3xl p-4 bg-white shadow-lg rounded-lg mt-6 mx-auto">
        
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-2 border-b pb-2">Edit Sub-Route</h1>
        
        <div class="mb-3 pb-2 border-b border-gray-200">
            <h3 class="text-2xl font-semibold text-gray-700"><?php echo htmlspecialchars($current_data['sub_route']); ?></h3>
            <p class="text-sm text-gray-500 mt-1">Sub-Route Code: <span class="font-medium"><?php echo htmlspecialchars($current_data['sub_route_code']); ?></span></p>
        </div>
        
        <form id="editSubRouteForm" onsubmit="handleFormSubmit(event)" class="space-y-3">
            <input type="hidden" name="action" id="action" value="edit">
            <input type="hidden" name="sub_route_code" value="<?= htmlspecialchars($current_data['sub_route_code']) ?>">
            
            <div class="grid md:grid-cols-2 gap-4 bg-gray-100 p-3 border border-gray-100 rounded-lg shadow-sm">
                <div class="md:col-span-2">
                    <h4 class="text-xl font-bold text-blue-600 border-b pb-1">Route Information</h4>
                </div>
                
                <div class="col-span-1"> 
                    <label for="route_code" class="block text-sm font-semibold text-gray-700">Parent Route:</label>
                    <select id="route_code" name="route_code" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <option value="">-- Select Route --</option>
                        <?php foreach ($routes as $route): ?>
                            <option value="<?= htmlspecialchars($route['route_code']) ?>" 
                                <?= ($route['route_code'] == $current_data['route_code']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($route['route']) ?> (<?= htmlspecialchars($route['route_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-span-1">
                    <label for="sub_route" class="block text-sm font-semibold text-gray-700">Sub-Route Name:</label>
                    <input type="text" id="sub_route" name="sub_route" value="<?= htmlspecialchars($current_data['sub_route']) ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <div class="col-span-1 md:col-span-2">
                    <label for="supplier" class="block text-sm font-semibold text-gray-700">Supplier:</label>
                    <select id="supplier" name="supplier" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <option value="">-- Select Supplier --</option>
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
            
            <div class="grid md:grid-cols-2 gap-4 bg-gray-100 p-3 border border-gray-100 rounded-lg shadow-sm">
                <div class="md:col-span-2">
                    <h4 class="text-xl font-bold text-blue-600 border-b pb-1">Logistics & Costs</h4>
                </div>

                <div class="col-span-1">
                    <label for="vehicle_no" class="block text-sm font-semibold text-gray-700">Vehicle No:</label>
                    <select id="vehicle_no" name="vehicle_no" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <option value="">-- Select Vehicle No --</option>
                        <?php foreach ($vehicles as $vehicle_row): ?>
                            <option value="<?= htmlspecialchars($vehicle_row["vehicle_no"]) ?>"
                                <?= ($vehicle_row['vehicle_no'] == $current_data['vehicle_no']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vehicle_row["vehicle_no"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-span-1">
                    <label for="distance" class="block text-sm font-semibold text-gray-700">Distance (km):</label>
                    <input type="number" id="distance" name="distance" step="0.01" value="<?= htmlspecialchars($current_data['distance']) ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <div class="col-span-1">
                    <label for="per_day_rate" class="block text-sm font-semibold text-gray-700">Per Day Rate (Rs.):</label>
                    <input type="number" id="per_day_rate" name="per_day_rate" step="0.01" value="<?= htmlspecialchars($current_data['per_day_rate']) ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
            </div>
            
            <div class="flex justify-between mt-6 pt-2">
                <a href="sub_routes.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" id="submitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Update Sub-Route
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const form = document.getElementById("editSubRouteForm");
    const toastContainer = document.getElementById("toast-container");

    function showToast(message, type) {
        const toast = document.createElement('div'); 
        toast.className = `toast ${type}`; 
        
        let iconPath = type === 'success' 
            ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />'
            : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />';

        toast.innerHTML = `<svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">${iconPath}</svg><span>${message}</span>`; 
        
        toastContainer.appendChild(toast); 
        setTimeout(() => toast.classList.add('show'), 10); 
        setTimeout(() => { 
            toast.classList.remove('show'); 
            toast.addEventListener('transitionend', () => toast.remove(), { once: true }); 
        }, 1300); 
    } 

    function handleFormSubmit(event) {
        event.preventDefault();
        const formData = new FormData(form);
        
        // Extract Supplier Code using data-code attribute
        const selectedSupplierName = formData.get('supplier');
        const supplierSelect = document.getElementById('supplier');
        const selectedOption = supplierSelect.querySelector(`option[value="${selectedSupplierName}"]`);
        
        if (selectedOption) {
            formData.set('supplier_code', selectedOption.getAttribute('data-code'));
        } else {
             showToast("Please select a valid supplier.", 'error');
             return;
        }

        document.getElementById('submitBtn').disabled = true;

        fetch('sub_routes_backend.php', { 
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            document.getElementById('submitBtn').disabled = false;
            
            if (data.trim() === "Success") {
                showToast("Sub-Route updated successfully!", 'success');
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