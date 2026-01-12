<?php
// add_own_vehicle.php

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

// Fuel Rates List (rate_id, type, rate)
$rate_query = "SELECT 
        fr.rate_id, 
        fr.type, 
        fr.rate 
    FROM 
        fuel_rate fr
    WHERE
        fr.id = (
            SELECT id 
            FROM fuel_rate 
            WHERE rate_id = fr.rate_id 
            AND date <= CURDATE() 
            ORDER BY date DESC, id DESC 
            LIMIT 1
        )
    ORDER BY 
        fr.rate_id DESC;"; // Group by rate_id for stability
$rate_result = $conn->query($rate_query);
$fuel_rates = [];
if ($rate_result && $rate_result->num_rows > 0) {
    while ($row = $rate_result->fetch_assoc()) {
        $row['display'] = "{$row['type']} (Rs. " . number_format($row['rate'], 2) . ")";
        $fuel_rates[] = $row;
    }
}

$conn->close();

// 🔑 Hardcoded Vehicle Types List
$vehicle_types = ['Car', 'Van', 'Motorbike', 'Other'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Own Vehicle</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        /* CSS for toast and spinner */
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
    
    <div class="w-[85%] ml-[15%] flex justify-center"> 
        <div class="container max-w-3xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            
            <div class="flex justify-between items-center mb-6 border-b pb-2">
                <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 tracking-tight">
                    Add New Employee Vehicle
                </h1>
            </div>
            
            <form id="add-vehicle-form" onsubmit="handleFormSubmit(event)" action="process_vehicle.php" method="POST" class="space-y-6" novalidate>
                <input type="hidden" name="action" value="add">

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="emp_id" class="block text-sm font-medium text-gray-700">Employee ID</label>
                        <input type="text" id="emp_id" name="emp_id" required 
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                            placeholder="Enter Employee ID (e.g., GP000000)">
                        <p id="emp-id-status" class="mt-1 text-sm text-gray-500">Enter Employee ID to validate.</p>
                    </div>
                    <div>
                        <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No</label>
                        <input type="text" id="vehicle_no" name="vehicle_no" required 
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                            placeholder="e.g., ABC-1234">
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700">Vehicle Model</label>
                        <select id="type" name="type" required 
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 appearance-none">
                            <option value="" disabled selected>Select Vehicle Model</option>
                            <?php foreach ($vehicle_types as $type_name): ?>
                                <option value="<?php echo htmlspecialchars($type_name); ?>">
                                    <?php echo htmlspecialchars($type_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="consumption" class="block text-sm font-medium text-gray-700">Fuel Efficiency (L/100km)</label>
                        <input type="number" step="0.01" min="0" id="consumption" name="consumption" required 
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                            placeholder="e.g., 8.5 (Liters per 100km)">
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="rate_id" class="block text-sm font-medium text-gray-700">Fuel Rate (Type & Price)</label>
                        <select id="rate_id" name="rate_id" required 
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 appearance-none">
                            <option value="" disabled selected>Select Fuel Rate</option>
                            <?php foreach ($fuel_rates as $rate): ?>
                                <option value="<?php echo htmlspecialchars($rate['rate_id']); ?>">
                                    <?php echo htmlspecialchars($rate['display']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="distance" class="block text-sm font-medium text-gray-700">Daily Base Distance (km)</label>
                        <input type="number" step="0.01" min="0" id="distance" name="distance" required 
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                            placeholder="e.g., 50.5 (Daily base travel)">
                    </div>
                </div>
                
                <div>
                    <label for="fixed_amount" class="block text-sm font-medium text-gray-700">Fixed Allowance (LKR)</label>
                    <input type="number" step="0.01" id="fixed_amount" name="fixed_amount" required 
                        class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" 
                        placeholder="e.g., 5000.00 (Monthly Fixed Amount)">
                </div>

                <div class="flex justify-between mt-6 pt-4 border-t border-gray-200">
                    <a href="own_vehicle.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Back
                    </a>
                    <button type="submit" id="submit-btn" class="bg-blue-400 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300">
                        Add Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div> 

    <script>
        const form = document.getElementById("add-vehicle-form");
        const empIdInput = document.getElementById('emp_id');
        const empIdStatus = document.getElementById('emp-id-status');
        const submitBtn = document.getElementById('submit-btn');
        let isEmpIdValid = false;
        let debounceTimer;
        
        // --- Toast Notification Function (IMPLEMENTATION) ---
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

        // --- Check Form Validity ---
        function checkFormValidity() {
            const formElements = form.querySelectorAll('input, select');
            let allFieldsFilled = true;
            
            formElements.forEach(element => {
                // Check if the element has 'required' attribute AND its value is empty
                if (element.hasAttribute('required')) {
                    if (element.type === 'number') {
                        // Check for empty string for all required number fields
                        if (element.value.trim() === '') {
                            allFieldsFilled = false;
                        } 
                        
                        // Distance validation: Can be 0, but cannot be negative (< 0)
                        if (element.id === 'distance' && parseFloat(element.value) < 0) {
                            allFieldsFilled = false;
                        }

                        // Consumption validation: Can be 0, but cannot be negative (< 0)
                        if (element.id === 'consumption' && parseFloat(element.value) < 0) {
                            allFieldsFilled = false;
                        }

                    } else if (element.value.trim() === '') {
                        allFieldsFilled = false;
                    }
                }
            });

            if (allFieldsFilled && isEmpIdValid) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('bg-blue-400', 'cursor-not-allowed');
                submitBtn.classList.add('bg-blue-600', 'hover:bg-blue-700', 'transform', 'hover:scale-105');
            } else {
                submitBtn.disabled = true;
                submitBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700', 'transform', 'hover:scale-105');
                submitBtn.classList.add('bg-blue-400', 'cursor-not-allowed');
            }
        }
        
        // --- Employee ID Validation (Debounced AJAX - MODIFIED IMPLEMENTATION) ---
        empIdInput.addEventListener('keyup', function() {
            clearTimeout(debounceTimer);
            const empId = this.value.trim();

            if (empId.length < 5) {
                empIdStatus.textContent = 'Employee ID is too short.';
                empIdStatus.classList.remove('text-green-600', 'text-red-600');
                empIdStatus.classList.add('text-gray-500');
                isEmpIdValid = false;
                checkFormValidity();
                return;
            }

            empIdStatus.innerHTML = '<span class="spinner"></span> Checking ID...';
            
            debounceTimer = setTimeout(() => {
                // Sending a flag 'check_vehicle=false' to explicitly tell the server
                // NOT to check if the employee already has a vehicle registered.
                fetch('check_emp_id.php?emp_id=' + empId + '&check_vehicle=false') 
                    .then(response => {
                        const contentType = response.headers.get("content-type");
                        if (contentType && contentType.indexOf("application/json") !== -1) {
                            return response.json();
                        } else {
                            // If the server sends an HTML error page instead of JSON
                            throw new Error("Invalid response from server. Check console for details.");
                        }
                    })
                    .then(data => {
                        if (data.isValid) {
                            // Only confirming that the Employee ID is valid/exists
                            empIdStatus.textContent = `Valid Employee: ${data.name}`;
                            empIdStatus.classList.remove('text-red-600', 'text-gray-500');
                            empIdStatus.classList.add('text-green-600');
                            isEmpIdValid = true;
                        } else {
                            // Only showing that the Employee ID was not found
                            empIdStatus.textContent = data.message || 'Employee ID not found.';
                            empIdStatus.classList.remove('text-green-600', 'text-gray-500');
                            empIdStatus.classList.add('text-red-600');
                            isEmpIdValid = false;
                        }
                        checkFormValidity();
                    })
                    .catch(error => {
                        console.error("Validation AJAX Error:", error);
                        empIdStatus.textContent = 'Error checking ID. Try again.';
                        empIdStatus.classList.remove('text-green-600', 'text-gray-500');
                        empIdStatus.classList.add('text-red-600');
                        isEmpIdValid = false;
                        checkFormValidity();
                    });
            }, 500); // 500ms debounce delay
        });

        // Event listeners for other fields
        document.querySelectorAll('#add-vehicle-form input:not(#emp_id), #add-vehicle-form select').forEach(element => {
            element.addEventListener('change', checkFormValidity);
            element.addEventListener('keyup', checkFormValidity);
        });

        // --- Form Submission (AJAX) ---
        function handleFormSubmit(event) {
            event.preventDefault();
            
            if (!isEmpIdValid) {
                showToast('Please enter a valid Employee ID before proceeding.', 'error');
                return;
            }
            
            // Check HTML5 validity for other fields
            if (!form.checkValidity()) {
                form.reportValidity(); // Display default browser validation messages
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
                    return response.json().then(error => { throw new Error(error.message || `HTTP error! Status: ${response.status}`); });
                }
                return response.json();
            })
            .then(response => {
                if (response.success) {
                    showToast(response.message || "Vehicle added successfully!", 'success');
                    // Reset form to allow entry of another vehicle for the same employee
                    form.reset(); 
                    empIdStatus.textContent = 'Enter Employee ID to validate.';
                    empIdStatus.classList.remove('text-green-600', 'text-red-600');
                    empIdStatus.classList.add('text-gray-500');
                    isEmpIdValid = false;
                    checkFormValidity();
                    
                    // You might want to redirect after success, but keeping it on the page for multiple entries:
                    // setTimeout(() => window.location.href = 'own_vehicle.php', 1500); 
                } else {
                    throw new Error(response.message || "An unknown error occurred on the server.");
                }
            })
            .catch(error => {
                showToast(`Operation Failed: ${error.message}`, 'error', 5000);
            })
            .finally(() => {
                // Re-enable the button after a delay
                setTimeout(() => {
                    submitBtn.innerHTML = 'Add Vehicle';
                    checkFormValidity(); 
                }, 1000); 
            });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            checkFormValidity();
            if (empIdInput.value.trim() !== '') {
                // Trigger validation if the field somehow has content on load
                empIdInput.dispatchEvent(new Event('keyup'));
            }
        });
    </script>
</body>
</html>