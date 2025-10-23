<?php
// Includes
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

session_start();

// Handle purpose filter
if (isset($_GET['purpose_filter']) && in_array($_GET['purpose_filter'], ['staff', 'worker'])) {
    $_SESSION['purpose_filter'] = $_GET['purpose_filter'];
}
$purpose_filter = isset($_SESSION['purpose_filter']) ? $_SESSION['purpose_filter'] : 'staff';

// Handle status filter
$status_filter = isset($_GET['status_filter']) && in_array($_GET['status_filter'], ['active', 'inactive']) ? $_GET['status_filter'] : 'active';

// Build the SQL query with WHERE clauses
$sql = "SELECT r.route_code, r.supplier_code, s.supplier, r.route, r.purpose, r.distance, r.vehicle_no, r.fixed_amount, r.fuel_amount, r.assigned_person, r.with_fuel, r.is_active
        FROM route r
        JOIN supplier s ON r.supplier_code = s.supplier_code";

// Sanitize the input and add WHERE clauses
$safe_purpose_filter = $conn->real_escape_string($purpose_filter);
$sql .= " WHERE r.purpose = '" . $safe_purpose_filter . "'";

if ($status_filter === 'active') {
    $sql .= " AND r.is_active = 1";
} else {
    $sql .= " AND r.is_active = 0";
}

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Modal CSS */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 500px;
            position: relative;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
        }
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        /* Toast Notifications CSS */
        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
        }
        .toast {
            display: none;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }
        .toast.show {
            display: flex;
            align-items: center;
            transform: translateY(0);
            opacity: 1;
        }
        .toast.success {
            background-color: #4CAF50; /* Green for success */
            color: white;
        }
        .toast.error {
            background-color: #F44336; /* Red for errors */
            color: white;
        }
        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
        .readonly-field {
            background-color: #e5e7eb; /* A light gray background to indicate it's not editable */
            cursor: not-allowed;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="containerl flex justify-center">
    <div class="w-[85%] ml-[15%]">
        <div class="p-3">
            <h1 class="text-4xl mx-auto font-bold text-gray-800 mt-3 mb-3 text-center">Route Details</h1>
            <div class="w-full flex justify-between items-center mb-6">
                <div class="flex space-x-4">
                    <button onclick="openModal()" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300">
                        Add New Route
                    </button>
                    <button onclick="generateRouteQrPdf()" class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300">
                        Generate Route QR PDF
                    </button>
                    </div>
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-1">
                        <label for="purpose-filter" class="text-gray-700 font-semibold">Filter by Purpose:</label>
                        <select id="purpose-filter" onchange="filterRoutes()" class="p-2 border rounded-md">
                            <option value="staff" <?php echo ($purpose_filter === 'staff') ? 'selected' : ''; ?>>Staff</option>
                            <option value="worker" <?php echo ($purpose_filter === 'worker') ? 'selected' : ''; ?>>Workers</option>
                        </select>
                    </div>
                    <div class="flex items-center space-x-1">
                        <label for="status-filter" class="text-gray-700 font-semibold">Filter by Status:</label>
                        <select id="status-filter" onchange="filterRoutes()" class="p-2 border rounded-md">
                            <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto bg-white shadow-md rounded-md w-full">
                <table class="min-w-full table-auto">
                    <thead class="bg-blue-600 text-white">
                        <tr>
                            <th class="px-2 py-2 text-center w-10">
                                <input type="checkbox" id="select-all" onclick="toggleAllCheckboxes()">
                            </th>
                            <th class="px-2 py-2 text-left">Route Code</th>
                            <th class="px-2 py-2 text-left">Supplier</th>
                            <th class="px-2 py-2 text-left">Route</th>
                            <th class="px-2 py-2 text-left">Fixed Price(1km)</th>
                            <th class="px-2 py-2 text-left">Fuel Price(1km)</th>
                            <th class="px-2 py-2 text-left">Total Price(1km)</th>
                            <th class="px-2 py-2 text-left">Distance (km)</th>
                            <th class="px-2 py-2 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($result && $result->num_rows > 0) {
                            while($row = $result->fetch_assoc()) {
                                $route_code = htmlspecialchars($row["route_code"]);
                                $supplier_name = htmlspecialchars($row["supplier"]);
                                $route_name = htmlspecialchars($row["route"]);
                                $fixed_amount = htmlspecialchars($row["fixed_amount"]);
                                $fuel_amount = htmlspecialchars($row["fuel_amount"]);
                                $distance = htmlspecialchars($row["distance"]);
                                $vehicle_no = htmlspecialchars($row["vehicle_no"]);
                                $purpose = htmlspecialchars($row["purpose"]); 
                                $assigned_person = htmlspecialchars($row["assigned_person"]);
                                $with_fuel = htmlspecialchars($row["with_fuel"]);
                                $is_active = htmlspecialchars($row["is_active"]);
                                
                                $status_text = ($is_active == 1) ? 'Active' : 'Disabled';
                                $status_color = ($is_active == 1) ? 'text-green-600' : 'text-red-600';
                                $toggle_button_text = ($is_active == 1) ? 'Disable' : 'Enable';
                                $toggle_button_color = ($is_active == 1) ? 'bg-red-500 hover:bg-red-600' : 'bg-green-500 hover:bg-green-600';

                                echo "<tr>";
                                // NEW CHECKBOX CELL
                                echo "<td class='border px-2 py-2 text-center'>
                                        <input type='checkbox' name='selected_routes[]' value='" . $route_code . "' class='route-checkbox'>
                                      </td>";
                                // END NEW CHECKBOX CELL
                                echo "<td class='border px-2 py-2'>" . $route_code . "</td>";
                                echo "<td class='border px-2 py-2'>" . $supplier_name . "</td>";
                                echo "<td class='border px-2 py-2'>" . $route_name . "</td>";
                                echo "<td class='border px-2 py-2'>" . $fixed_amount . "</td>";
                                echo "<td class='border px-2 py-2'>" . $fuel_amount . "</td>";
                                echo "<td class='border px-2 py-2'>" . number_format($fixed_amount + $fuel_amount, 2) . "</td>";
                                echo "<td class='border px-2 py-2'>" . $distance . "</td>";
                                echo "<td class='border px-2 py-2'>
                                            <button onclick='openViewModal(\"$route_code\", \"$route_name\", \"$fixed_amount\", \"$fuel_amount\", \"$distance\", \"$supplier_name\", \"$vehicle_no\", \"$assigned_person\", \"$purpose\", \"$with_fuel\")' class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>View</button>
                                            <button onclick='openEditModal(\"$route_code\", \"$route_name\", \"$fixed_amount\", \"$fuel_amount\", \"$distance\", \"$supplier_name\", \"$vehicle_no\", \"$assigned_person\", \"$purpose\", \"$with_fuel\")' class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>Edit</button>
                                            <button onclick='toggleRouteStatus(\"$route_code\", $is_active)' class='" . $toggle_button_color . " text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>$toggle_button_text</button>
                                        </td>";
                                echo "</tr>";
                            }
                        } else {
                            $message = ($status_filter === 'active') ? "No active routes found for this purpose." : "No inactive routes found for this purpose.";
                            // Changed colspan from 8 to 9 to account for the new checkbox column
                            echo "<tr><td colspan='9' class='border px-4 py-2 text-center'>{$message}</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="myModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3 class="text-2xl font-semibold mb-1" id="modalTitle">Add New Route</h3>
        <form id="routeForm" onsubmit="handleFormSubmit(event)" class="space-y-4">
            <input type="hidden" name="action" id="action">
            <div>
                <label for="route_code" class="block text-gray-700">Route Code:</label>
                <input type="text" id="route_code" name="route_code" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
            </div>
            <div class="flex">
                <div class="w-[63%] mr-4"> 
                    <label for="route" class="block text-gray-700">Route:</label>
                    <input type="text" id="route" name="route" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                </div>
                <div class="w-[33%]">
                    <label for="purpose" class="block text-gray-700">Purpose:</label>
                    <select id="purpose" name="purpose" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <option value="">-- Select Purpose --</option>
                        <option value="staff">Staff</option>
                        <option value="worker">Worker</option>
                    </select>
                </div>
            </div>
            <div class="flex">
                <div class="w-[48%] mr-4">
                    <label for="distance" class="block text-gray-700">Distance (km):</label>
                    <input type="number" id="distance" name="distance" step="0.01" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                </div>
                <div class="w-[48%]">
                    <label for="supplier" class="block text-gray-700">Supplier:</label>
                    <select id="supplier" name="supplier" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <option value="">-- Select Supplier --</option>
                        <?php
                        // Reset the pointer for supplier data if needed, or run the query again
                        $supplier_sql = "SELECT supplier_code, supplier FROM supplier ORDER BY supplier_code";
                        $supplier_result = $conn->query($supplier_sql);
                        if ($supplier_result && $supplier_result->num_rows > 0) {
                            while ($supplier_row = $supplier_result->fetch_assoc()) {
                                $supplier_name = htmlspecialchars($supplier_row["supplier"]);
                                $supplier_code_val = htmlspecialchars($supplier_row["supplier_code"]);
                                echo "<option value='{$supplier_name}' data-code='{$supplier_code_val}'>{$supplier_name}</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="flex">
                <div class="w-[48%] mr-4">
                    <label for="vehicle_no" class="block text-gray-700">Vehicle No:</label>
                    <select id="vehicle_no" name="vehicle_no" required 
                    class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <option value="">-- Select Vehicle No --</option>
                        <?php
                        // Reset the pointer for vehicle data if needed, or run the query again
                        $vehicle_sql = "SELECT vehicle_no FROM vehicle ORDER BY vehicle_no";
                        $vehicle_result = $conn->query($vehicle_sql);
                        if ($vehicle_result && $vehicle_result->num_rows > 0) {
                            while ($vehicle_row = $vehicle_result->fetch_assoc()) {
                                $vehicle_no = htmlspecialchars($vehicle_row["vehicle_no"]);
                                echo "<option value='{$vehicle_no}'>{$vehicle_no}</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
                <div class="w-[48%]">
                    <label class="block text-gray-700">Fuel Calculation:</label>
                    <div class="flex items-center space-x-4 mt-2">
                        <label>
                            <input type="radio" name="fuel_option" value="with_fuel" checked class="mr-1">
                            With Fuel
                        </label>
                        <label>
                            <input type="radio" name="fuel_option" value="without_fuel" class="mr-1">
                            Without Fuel
                        </label>
                    </div>
                </div>
            </div>
            <div>
                <label for="fixed_amount" class="block text-gray-700">Fixed Amount:</label>
                <input type="number" id="fixed_amount" name="fixed_amount" step="0.01" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
            </div>
            <div>
                <label for="fuel_amount" class="block text-gray-700">Fuel Amount:</label>
                <input type="number" id="fuel_amount" name="fuel_amount" step="0.01" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 readonly-field" readonly>
            </div>
            <div>
                <label for="assigned_person" class="block text-gray-700">Assigned Person:</label>
                <input type="text" id="assigned_person" name="assigned_person" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
            </div>
            <input type="hidden" name="fuel_option_value" id="fuel_option_value" value="1">
            <div class="flex justify-end">
                <input type="submit" id="submitBtn" value="Add" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md cursor-pointer transition duration-300">
            </div>
        </form>
    </div>
</div>

<div id="toast-container"></div>

<script>
    var modal = document.getElementById("myModal");
    var form = document.getElementById("routeForm");
    var submitBtn = document.getElementById("submitBtn");
    var modalTitle = document.getElementById("modalTitle");
    var toastContainer = document.getElementById("toast-container");
    const routeCodeInput = document.getElementById('route_code');
    const vehicleNoSelect = document.getElementById('vehicle_no');
    const fuelAmountInput = document.getElementById('fuel_amount');
    const fuelRadioOptions = document.querySelectorAll('input[name="fuel_option"]');
    const fuelOptionValueInput = document.getElementById('fuel_option_value');
    
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.classList.add('toast', type);
        toast.innerHTML = `
            <svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                ${type === 'success' ? `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                ` : `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                `}
            </svg>
            <span>${message}</span>
        `;
        toastContainer.appendChild(toast);
        setTimeout(() => { toast.classList.add('show'); }, 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => { toast.remove(); }, 300);
        }, 3000);
    }

    function setFormState(isReadOnly) {
        const formFields = form.querySelectorAll('input:not([type="hidden"]), select');
        formFields.forEach(field => {
            if (field.id !== 'action' && field.id !== 'fuel_option_value') {
                field.readOnly = isReadOnly;
                field.disabled = isReadOnly;
                if (isReadOnly) {
                    field.classList.add('readonly-field');
                } else {
                    field.classList.remove('readonly-field');
                }
            }
        });
        // Disable radio buttons separately
        fuelRadioOptions.forEach(radio => radio.disabled = isReadOnly);
        submitBtn.style.display = isReadOnly ? 'none' : 'block';
    }

    // New function to handle fuel calculation
    async function calculateFuelAmount() {
        const selectedOption = document.querySelector('input[name="fuel_option"]:checked').value;
        const vehicleNo = vehicleNoSelect.value;
        
        fuelAmountInput.classList.add('readonly-field');
        fuelAmountInput.readOnly = true;

        if (selectedOption === 'without_fuel') {
            fuelAmountInput.value = 0;
            return;
        }

        if (selectedOption === 'with_fuel' && vehicleNo) {
            try {
                const response = await fetch(`routes_backend2.php?action=get_fuel_rates&vehicle_no=${encodeURIComponent(vehicleNo)}`);
                const data = await response.json();

                if (data.success) {
                    const fuelCostPerLiter = parseFloat(data.fuel_cost_per_liter);
                    const kmPerLiter = parseFloat(data.km_per_liter);
                    const distance = parseFloat(document.getElementById('distance').value);

                    if (!isNaN(fuelCostPerLiter) && !isNaN(kmPerLiter) && kmPerLiter > 0) {
                        const calculatedAmount = (fuelCostPerLiter / kmPerLiter);
                        fuelAmountInput.value = calculatedAmount.toFixed(2);
                    } else {
                        showToast("Fuel data incomplete or invalid for this vehicle.", 'error');
                        fuelAmountInput.value = '';
                    }
                } else {
                    showToast(data.message || "Failed to fetch fuel rates.", 'error');
                    fuelAmountInput.value = '';
                }
            } catch (error) {
                console.error('Error fetching fuel data:', error);
                showToast("An error occurred during calculation.", 'error');
                fuelAmountInput.value = '';
            }
        } else {
            fuelAmountInput.value = '';
        }
    }

    // Attach event listeners
    vehicleNoSelect.addEventListener('change', calculateFuelAmount);
    document.getElementById('distance').addEventListener('input', calculateFuelAmount);
    fuelRadioOptions.forEach(radio => radio.addEventListener('change', function() {
        fuelOptionValueInput.value = (this.value === 'with_fuel') ? 1 : 0;
        calculateFuelAmount();
    }));

    // --- NEW QR PDF GENERATION LOGIC ---

    function toggleAllCheckboxes() {
        const selectAll = document.getElementById('select-all');
        const checkboxes = document.querySelectorAll('.route-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
    }

    function generateRouteQrPdf() {
        const selectedRoutes = Array.from(document.querySelectorAll('.route-checkbox:checked'))
                                    .map(checkbox => checkbox.value);

        if (selectedRoutes.length === 0) {
            showToast("Please select at least one route to generate the PDF.", 'error');
            return;
        }

        const routeCodesString = selectedRoutes.join(',');

        // Route Codes string එක POST කරමින් නව PDF generator script එකට යැවීමට Form එකක් තාවකාලිකව සාදා යවයි.
        const form = document.createElement('form');
        form.method = 'POST';
        // 💡 ගොනුවේ නම නිවැරදිව යොදන්න. Route QR PDF එක ජනනය කරන ගොනුව මෙයයි.
        form.action = 'generate_qr_route_pdf.php'; 

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_route_codes';
        input.value = routeCodesString;

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    
    // --- END NEW QR PDF GENERATION LOGIC ---


    function openModal() {
        form.reset();
        setFormState(false);
        document.getElementById('action').value = 'add';
        modalTitle.textContent = "Add New Route";
        modal.style.display = "flex";
        calculateFuelAmount();
    }
    
    function openEditModal(code, route, fixed_amount, fuel_amount, distance, supplier, vehicle_no, assigned_person, purpose, with_fuel) {
        setFormState(false);
        document.getElementById('action').value = 'edit';
        document.getElementById('route_code').value = code;
        document.getElementById('route_code').disabled = true;
        document.getElementById('route_code').readOnly = true;
        document.getElementById('route_code').style.backgroundColor = '#e5e7eb';
        
        document.getElementById('route').value = route;
        document.getElementById('fixed_amount').value = fixed_amount;
        document.getElementById('fuel_amount').value = fuel_amount;
        document.getElementById('distance').value = distance;
        document.getElementById('supplier').value = supplier; 
        document.getElementById('vehicle_no').value = vehicle_no;
        document.getElementById('assigned_person').value = assigned_person;
        document.getElementById('purpose').value = purpose;
        
        if (with_fuel == 1) {
            document.querySelector('input[name="fuel_option"][value="with_fuel"]').checked = true;
            fuelOptionValueInput.value = 1;
        } else {
            document.querySelector('input[name="fuel_option"][value="without_fuel"]').checked = true;
            fuelOptionValueInput.value = 0;
        }
        
        submitBtn.value = "Save Changes";
        modalTitle.textContent = "Edit Route";
        modal.style.display = "flex";
        calculateFuelAmount();
    }
    
    function openViewModal(code, route, fixed_amount, fuel_amount, distance, supplier, vehicle_no, assigned_person, purpose, with_fuel) {
        setFormState(true);
        document.getElementById('route_code').value = code;
        document.getElementById('route').value = route;
        document.getElementById('fixed_amount').value = fixed_amount;
        document.getElementById('fuel_amount').value = fuel_amount;
        document.getElementById('distance').value = distance;
        document.getElementById('supplier').value = supplier; 
        document.getElementById('vehicle_no').value = vehicle_no;
        document.getElementById('assigned_person').value = assigned_person;
        document.getElementById('purpose').value = purpose;
        
        if (with_fuel == 1) {
            document.querySelector('input[name="fuel_option"][value="with_fuel"]').checked = true;
        } else {
            document.querySelector('input[name="fuel_option"][value="without_fuel"]').checked = true;
        }

        modalTitle.textContent = "View Route Details";
        modal.style.display = "flex";
    }

    function closeModal() {
        modal.style.display = "none";
        setFormState(false);
        document.getElementById('route_code').disabled = false;
        document.getElementById('route_code').readOnly = false;
        document.getElementById('route_code').style.backgroundColor = 'white';
    }

    function handleFormSubmit(event) {
        event.preventDefault();
        const formData = new FormData(form);
        const action = formData.get('action');

        if (action === 'edit') {
            formData.append('route_code', routeCodeInput.value);
        }

        const selectedSupplierName = formData.get('supplier');
        const supplierSelect = document.getElementById('supplier');
        const selectedOption = supplierSelect.querySelector(`option[value="${selectedSupplierName}"]`);
        if (selectedOption) {
            formData.set('supplier_code', selectedOption.getAttribute('data-code'));
        }
        formData.delete('supplier');

        fetch('routes_backend2.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            if (data.trim() === "Success") {
                const message = action === 'add' ? "Route added successfully!" : "Route updated successfully!";
                showToast(message, 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast("Error: " + data, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast("An error occurred. Please try again.", 'error');
        });
    }

    function toggleRouteStatus(routeCode, currentStatus) {
        const newStatus = currentStatus === 1 ? 0 : 1;
        const actionText = newStatus === 1 ? 'enable' : 'disable';
        if (confirm(`Are you sure you want to ${actionText} this route?`)) {
            fetch(`routes_backend2.php?toggle_status=true&route_code=${encodeURIComponent(routeCode)}&new_status=${newStatus}`)
            .then(response => response.text())
            .then(data => {
                if (data.trim() === "Success") {
                    showToast(`Route ${actionText}d successfully!`, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 2300);
                } else {
                    showToast("Error: " + data, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast("An error occurred. Please try again.", 'error');
            });
        }
    }
    
    function filterRoutes() {
        const purpose = document.getElementById('purpose-filter').value;
        const status = document.getElementById('status-filter').value;
        window.location.href = `?purpose_filter=${purpose}&status_filter=${status}`;
    }
</script>

</body>
</html>

<?php $conn->close(); ?>