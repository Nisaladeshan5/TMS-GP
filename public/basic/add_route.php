<?php
require_once '../../includes/session_check.php';
// Includes
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php'); // DB connection
include('../../includes/header.php');
include('../../includes/navbar.php');

// Query for suppliers
$supplier_sql = "SELECT supplier_code, supplier FROM supplier ORDER BY supplier";
$supplier_result = $conn->query($supplier_sql);
$suppliers = [];
if ($supplier_result && $supplier_result->num_rows > 0) {
    while ($row = $supplier_result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// Query for vehicles
$vehicle_sql = "SELECT vehicle_no FROM vehicle ORDER BY vehicle_no";
$vehicle_result = $conn->query($vehicle_sql);
$vehicles = [];
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
    <title>Add New Route</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Toast Notifications CSS - Adopted Driver file style */
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
            background-color: #4CAF50; /* Green for success */
        }
        .toast.error {
            background-color: #F44336; /* Red for errors */
        }
        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
        .readonly-field {
            background-color: #e5e7eb;
            cursor: not-allowed;
        }
    </style>
</head>
<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100 font-sans">

<div id="toast-container"></div>

<div class="w-[85%] ml-[15%]">
    <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add New Route</h1>
        
        <form id="routeForm" onsubmit="handleFormSubmit(event)" class="space-y-6">
            <input type="hidden" name="action" id="action" value="add">
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="route_code" class="block text-sm font-medium text-gray-700">Route Code:</label>
                    <input type="text" id="route_code" name="route_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
                <div> 
                    <label for="route" class="block text-sm font-medium text-gray-700">Route Name:</label>
                    <input type="text" id="route" name="route" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
            </div>
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="distance" class="block text-sm font-medium text-gray-700">Distance (km):</label>
                    <input type="number" id="distance" name="distance" step="0.01" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
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
                    <select id="vehicle_no" name="vehicle_no" required 
                    class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        <option value="">-- Select Vehicle No --</option>
                        <?php foreach ($vehicles as $vehicle_row): ?>
                            <option value="<?= htmlspecialchars($vehicle_row["vehicle_no"]) ?>">
                                <?= htmlspecialchars($vehicle_row["vehicle_no"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="purpose" class="block text-sm font-medium text-gray-700">Purpose:</label>
                    <select id="purpose" name="purpose" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        <option value="staff" selected>Staff</option>
                        <option value="factory">Factory</option>
                    </select>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="fixed_amount" class="block text-sm font-medium text-gray-700">Fixed Amount (Rs./km):</label>
                    <input type="number" id="fixed_amount" name="fixed_amount" step="0.01" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Fuel Calculation:</label>
                    <div class="flex items-center space-x-4 mt-2 p-2 border border-gray-300 rounded-md bg-gray-50">
                        <label class="flex items-center">
                            <input type="radio" name="fuel_option" value="with_fuel" checked class="mr-1 text-indigo-600 focus:ring-indigo-500">
                            With Fuel
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="fuel_option" value="without_fuel" class="mr-1 text-indigo-600 focus:ring-indigo-500">
                            Without Fuel
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="fuel_amount" class="block text-sm font-medium text-gray-700">Fuel Amount (Rs./km):</label>
                    <input type="number" id="fuel_amount" name="fuel_amount" step="0.01" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2 readonly-field" readonly value="0.00">
                </div>
                <div>
                    <label for="assigned_person" class="block text-sm font-medium text-gray-700">Assigned Person:</label>
                    <input type="text" id="assigned_person" name="assigned_person" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
            </div>
            
            <input type="hidden" name="fuel_option_value" id="fuel_option_value" value="1">
            
            <div class="flex justify-between mt-6 pt-4 border-t border-gray-200">
                <a href="routes_staff2.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" id="submitBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Add Route
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const form = document.getElementById("routeForm");
    const toastContainer = document.getElementById("toast-container");
    const vehicleNoSelect = document.getElementById('vehicle_no');
    const fuelAmountInput = document.getElementById('fuel_amount');
    const fuelRadioOptions = document.querySelectorAll('input[name="fuel_option"]');
    const fuelOptionValueInput = document.getElementById('fuel_option_value');

    // Toast Function - Adopted from Driver file style
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container'); 
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

        // Show the toast with a slight delay for the transition effect 
        setTimeout(() => toast.classList.add('show'), 10); 

        // Automatically hide and remove the toast after 1.3 seconds 
        setTimeout(() => { 
            toast.classList.remove('show'); 
            // Wait for transition to finish before removing
            toast.addEventListener('transitionend', () => toast.remove(), { once: true }); 
        }, 1300); 
    } 

    
    // Fuel calculation logic 
    async function calculateFuelAmount() {
        const selectedOption = document.querySelector('input[name="fuel_option"]:checked').value;
        const vehicleNo = vehicleNoSelect.value;
        
        // Ensure the input remains read-only
        fuelAmountInput.classList.add('readonly-field');
        fuelAmountInput.readOnly = true;

        if (selectedOption === 'without_fuel') {
            fuelAmountInput.value = 0.00; // Set to 0.00 if fuel is not included
            return;
        }

        if (selectedOption === 'with_fuel' && vehicleNo) {
            try {
                // Fetch fuel rates from the backend
                const response = await fetch(`routes_backend2.php?action=get_fuel_rates&vehicle_no=${encodeURIComponent(vehicleNo)}`);
                const data = await response.json();

                if (data.success) {
                    const fuelCostPerLiter = parseFloat(data.fuel_cost_per_liter);
                    const kmPerLiter = parseFloat(data.km_per_liter);

                    if (!isNaN(fuelCostPerLiter) && !isNaN(kmPerLiter) && kmPerLiter > 0) {
                        // Fuel cost per 1 km = Fuel Cost per Liter / Km per Liter
                        const calculatedAmount = (fuelCostPerLiter / kmPerLiter);
                        fuelAmountInput.value = calculatedAmount.toFixed(2);
                    } else {
                        showToast("Fuel data incomplete or invalid for this vehicle.", 'error');
                        fuelAmountInput.value = '0.00';
                    }
                } else {
                    showToast(data.message || "Failed to fetch fuel rates. Check vehicle/fuel data.", 'error');
                    fuelAmountInput.value = '0.00';
                }
            } catch (error) {
                console.error('Error fetching fuel data:', error);
                showToast("An error occurred during calculation.", 'error');
                fuelAmountInput.value = '0.00';
            }
        } else {
            fuelAmountInput.value = '0.00';
        }
    }

    // Attach event listeners
    vehicleNoSelect.addEventListener('change', calculateFuelAmount);
    fuelRadioOptions.forEach(radio => radio.addEventListener('change', function() {
        // Update hidden field for PHP backend logic
        fuelOptionValueInput.value = (this.value === 'with_fuel') ? 1 : 0;
        calculateFuelAmount();
    }));
    
    // Initial calculation on load
    document.addEventListener('DOMContentLoaded', calculateFuelAmount);


    function handleFormSubmit(event) {
        event.preventDefault();
        const formData = new FormData(form);
        
        // Extract supplier_code from the selected option's data-code attribute
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

        // Final check for fuel_amount
        if (parseFloat(formData.get('fixed_amount')) <= 0) {
            showToast("Fixed Amount must be greater than zero.", 'error');
            return;
        }
        if (formData.get('fuel_amount') === '' || isNaN(parseFloat(formData.get('fuel_amount')))) {
            showToast("Fuel Amount is invalid. Please check vehicle selection.", 'error');
            return;
        }
        
        // Disable button during submission
        document.getElementById('submitBtn').disabled = true;

        fetch('routes_backend2.php', { // Submitting to the existing backend script
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            document.getElementById('submitBtn').disabled = false;
            
            if (data.trim() === "Success") {
                showToast("Route added successfully!", 'success');
                setTimeout(() => {
                    // Redirect back to the main route details page
                    window.location.href = 'routes_staff2.php'; 
                }, 1300); // Wait for toast to display
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