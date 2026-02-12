<?php
// add_extra_vehicle.php (Updated with Auto-ID Formatting and Name Display)

require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

include('../../../../includes/db.php');
include('../../../../includes/header.php'); 
include('../../../../includes/navbar.php'); 

date_default_timezone_set('Asia/Colombo');

// --- DATA FETCH FOR DROPDOWNS ---
$suppliers_data = []; 
$route_codes_data = []; 
$op_codes = [];
$reasons_data = []; 

// 1. Fetch Suppliers
$result_suppliers = $conn->query("SELECT supplier AS supplier_name, supplier_code FROM supplier ORDER BY supplier_name ASC");
if ($result_suppliers) {
    while ($row = $result_suppliers->fetch_assoc()) {
        $suppliers_data[] = ['code' => $row['supplier_code'], 'name' => $row['supplier_name']];
    }
}

// 2. Fetch Route Codes
$result_routes = $conn->query("SELECT route, route_code FROM route WHERE is_active = 1 ORDER BY route_code ASC");
if ($result_routes) {
    while ($row = $result_routes->fetch_assoc()) {
        $route_codes_data[] = ['code' => $row['route_code'], 'name' => $row['route']];
    }
}

// 3. Fetch Op Codes
$result_ops = $conn->query("SELECT op_code FROM op_services WHERE is_active = 1 GROUP BY op_code ORDER BY op_code ASC");
if ($result_ops) {
    while ($row = $result_ops->fetch_assoc()) {
        $op_codes[] = $row['op_code'];
    }
}

// 4. Fetch Reasons
$result_reasons = $conn->query("SELECT reason_code, reason FROM reason ORDER BY reason ASC");
if ($result_reasons) {
    while ($row = $result_reasons->fetch_assoc()) {
        $reasons_data[] = ['code' => $row['reason_code'], 'text' => $row['reason']];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Extra Vehicle Trip</title>
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
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
        
        .readonly-field { background-color: #f3f4f6; cursor: not-allowed; }
        /* Style for employee name display */
        .emp-name-badge { font-size: 0.65rem; font-weight: 700; color: #4f46e5; font-style: italic; margin-left: 0.25rem; display: block; line-height: 1; margin-top: 2px; }
    </style>
</head>
<body class="bg-gray-100 font-sans">

<div id="toast-container"></div>

<div class="w-[85%] ml-[15%]">
    <div class="container max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10 mx-auto">
        
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-4">
            New Extra Vehicle Trip
        </h1>
        
        <p class="text-gray-500 text-sm mb-6">Enter trip details. You must select either a Route Name or an Operation Code.</p>

        <form id="tripForm" class="space-y-8">
            
            <div class="bg-blue-50 p-6 rounded-lg border border-blue-200">
                <h3 class="text-lg font-bold text-blue-800 mb-4 border-b border-blue-200 pb-2">Trip Identification</h3>
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="route_code" class="block text-sm font-medium text-gray-700">Route Name:</label>
                        <select id="route_code" name="route_code" onchange="toggleCodeSelection('route')" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                            <option value="">-- Select Route --</option>
                            <?php foreach ($route_codes_data as $route): ?>
                                <option value="<?php echo htmlspecialchars($route['code']); ?>">
                                    <?php echo htmlspecialchars($route['name']); ?> (<?php echo htmlspecialchars($route['code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="op_code" class="block text-sm font-medium text-gray-700">Operation Code:</label>
                        <select id="op_code" name="op_code" onchange="toggleCodeSelection('op')" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                            <option value="">-- Select Op Code --</option>
                            <?php foreach ($op_codes as $code): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No:</label>
                    <input type="text" id="vehicle_no" name="vehicle_no" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                </div>
                <div>
                    <label for="supplier_code" class="block text-sm font-medium text-gray-700">Supplier:</label>
                    <select id="supplier_code" name="supplier_code" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($suppliers_data as $supplier): ?>
                            <option value="<?php echo htmlspecialchars($supplier['code']); ?>">
                                <?php echo htmlspecialchars($supplier['name']); ?> (<?php echo htmlspecialchars($supplier['code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" id="hidden_supplier_code" name="supplier_code_hidden">
                    <span id="supplier_loading_status" class="text-xs italic hidden mt-1"></span>
                </div>

                <div>
                    <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                    <input type="date" id="date" name="date" required value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                </div>
                <div>
                    <label for="time" class="block text-sm font-medium text-gray-700">Time:</label>
                    <input type="time" id="time" name="time" required value="<?php echo date('H:i'); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                </div>

                <div>
                    <label for="from_location" class="block text-sm font-medium text-gray-700">From Location:</label>
                    <input type="text" id="from_location" name="from_location" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                </div>
                <div>
                    <label for="to_location" class="block text-sm font-medium text-gray-700">To Location:</label>
                    <input type="text" id="to_location" name="to_location" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                </div>

                <div>
                    <label for="ac_status" class="block text-sm font-medium text-gray-700">A/C Status:</label>
                    <div class="mt-2 flex space-x-6">
                        <label class="inline-flex items-center">
                            <input type="radio" class="form-radio text-indigo-600" name="ac_status" value="0" checked>
                            <span class="ml-2 text-gray-700">Non A/C</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" class="form-radio text-indigo-600" name="ac_status" value="1">
                            <span class="ml-2 text-gray-700">A/C</span>
                        </label>
                    </div>
                </div>
                <div>
                    <label for="distance" class="block text-sm font-medium text-gray-700">Distance (Km):</label>
                    <input type="number" step="0.01" id="distance" name="distance" placeholder="0.00 (Leave 0 if pending)" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                </div>
            </div>

            <div class="bg-gray-50 p-6 rounded-lg border border-gray-200 mt-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 border-b border-gray-200 pb-2 flex justify-between items-center">
                    <span>Employee Details</span>
                    <div class="flex space-x-2">
                        <button type="button" id="fetch-route-emps-btn" class="bg-green-600 text-white px-3 py-1.5 rounded-md hover:bg-green-700 text-xs font-medium shadow-sm transition">
                            Get Route Employees
                        </button>
                        <button type="button" id="add-reason-group-btn" class="bg-indigo-600 text-white px-3 py-1.5 rounded-md hover:bg-indigo-700 text-xs font-medium shadow-sm transition">
                            + Add Group
                        </button>
                    </div>
                </h3>

                <div id="reason-group-container" class="space-y-4">
                    <div class="reason-group bg-white p-4 rounded-md border border-gray-300 shadow-sm relative">
                        <div class="flex justify-between items-start mb-3">
                            <h4 class="text-sm font-semibold text-indigo-700 uppercase tracking-wide group-title">Group 1</h4>
                            <button type="button" class="remove-group-btn text-red-400 hover:text-red-600 transition" title="Remove Group" disabled>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </div>
                        
                        <div class="grid md:grid-cols-3 gap-4 mb-3">
                            <div class="md:col-span-1">
                                <label class="block text-xs font-medium text-gray-500 mb-1">Reason</label>
                                <select name="reason_group[]" required class="reason-select block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-xs p-2 border">
                                    <option value="">Select Reason</option>
                                    <?php foreach ($reasons_data as $reason): ?>
                                        <option value="<?php echo htmlspecialchars($reason['code']); ?>"><?php echo htmlspecialchars($reason['text']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-medium text-gray-500 mb-1">Employee IDs</label>
                                <div class="employee-list-container space-y-2">
                                    <div class="employee-input flex flex-col space-y-1">
                                        <div class="flex items-center space-x-2">
                                            <input type="text" name="emp_id_group[0][]" placeholder="Emp ID" required class="emp-id-input flex-grow rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-xs p-2 border uppercase">
                                            <button type="button" class="remove-employee-btn text-gray-400 hover:text-red-500 disabled:opacity-30" disabled>&times;</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-2 text-right">
                                    <button type="button" class="add-employee-btn-group text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                        + Add Employee
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-between space-x-4 pt-4 border-t border-gray-200">
                <a href="../../extra_vehicle.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-md shadow-md transition transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" id="submitBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition transform hover:scale-105">
                    Save Record
                </button>
            </div>

        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="add_extra_vehicle.js"></script> 

<script>
    // --- ID FORMATTING LOGIC ---
    function formatEmpID(val) {
        val = val.trim().toUpperCase();
        if (!val) return "";
        let letters = val.match(/[A-Z]+/g) ? val.match(/[A-Z]+/g).join('') : "";
        let numbers = val.match(/\d+/g) ? val.match(/\d+/g).join('') : "";
        
        // Logical formatting: D -> GPD, empty -> GP
        if (letters === "D") { letters = "GPD"; } 
        else if (letters === "") { letters = "GP"; }
        
        let currentLen = letters.length + numbers.length;
        let zeros = "";
        if (currentLen < 8) {
            zeros = "0".repeat(8 - currentLen);
        }
        return letters + zeros + numbers;
    }

    // --- Toast Notification Logic ---
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
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
        }, 3000);
    }

    // --- Toggle Route/Op Code ---
    function toggleCodeSelection(type) {
        if (type === 'route') { $('#op_code').val(""); } 
        else { $('#route_code').val(""); }
    }

    // --- Employee ID Validation & Name Display (On Blur) ---
    $(document).on('blur', '.emp-id-input', function() {
        var inputField = $(this);
        var rawId = inputField.val().trim();
        if (rawId === "") return;

        // Auto format the ID
        var formattedId = formatEmpID(rawId);
        inputField.val(formattedId);

        inputField.removeClass('border-red-500 border-green-500 bg-red-50 bg-green-50');
        
        // Find or create name display span
        var nameSpan = inputField.closest('.employee-input').find('.emp-name-badge');
        if (nameSpan.length === 0) {
            inputField.after('<span class="emp-name-badge"></span>');
            nameSpan = inputField.closest('.employee-input').find('.emp-name-badge');
        }

        $.ajax({
            url: 'check_employee.php',
            type: 'POST',
            data: { emp_id: formattedId },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') { 
                    inputField.addClass('border-green-500 bg-green-50');
                    nameSpan.text(response.name).css('color', '#4f46e5');
                } 
                else {
                    inputField.addClass('border-red-500 bg-red-50');
                    nameSpan.text("Invalid ID!").css('color', '#ef4444');
                    showToast('Invalid Employee ID: ' + formattedId, 'error');
                }
            },
            error: function() {
                nameSpan.text("Error checking ID");
            }
        });
    });

    // --- Fetch Route Employees Logic ---
    $('#fetch-route-emps-btn').on('click', function() {
        const routeCode = $('#route_code').val();
        const reasonCode = $('.reason-select').first().val();

        if (!routeCode) { showToast("Please select a Route first.", 'error'); return; }
        if (!reasonCode) { showToast("Please select a Reason first.", 'error'); return; }

        const btn = $(this);
        btn.prop('disabled', true).text('Fetching...');

        $.ajax({
            url: 'process_fetch_route_employees.php',
            type: 'POST',
            data: { route_code: routeCode },
            dataType: 'json',
            success: function(employees) {
                btn.prop('disabled', false).text('Get Route Employees');
                if (employees.length === 0) {
                    showToast("No active employees found for this route pattern.", 'error');
                    return;
                }
                const container = $('.reason-group').first().find('.employee-list-container');
                container.empty();
                employees.forEach((empId) => {
                    const html = `
                        <div class="employee-input flex flex-col space-y-1">
                            <div class="flex items-center space-x-2">
                                <input type="text" name="emp_id_group[0][]" value="${empId}" placeholder="Emp ID" required 
                                       class="emp-id-input flex-grow rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-xs p-2 border uppercase">
                                <button type="button" class="remove-employee-btn text-gray-400 hover:text-red-500">&times;</button>
                            </div>
                        </div>`;
                    container.append(html);
                    // Manually trigger blur to load names
                    container.find('.emp-id-input').last().trigger('blur');
                });
                showToast(employees.length + " employees added.", 'success');
            },
            error: function() {
                btn.prop('disabled', false).text('Get Route Employees');
                showToast("Error fetching employees.", 'error');
            }
        });
    });

    // --- Form Submission Logic ---
    $('#tripForm').on('submit', function(e) {
        e.preventDefault();
        if (!$('#route_code').val() && !$('#op_code').val()) {
            showToast("Please select either a Route Name or an Operation Code.", 'error');
            return;
        }
        if ($('.emp-id-input.border-red-500').length > 0) {
            showToast("Please correct invalid Employee IDs before saving.", 'error');
            return;
        }

        const formData = new FormData(this);
        const submitBtn = $('#submitBtn');
        submitBtn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: 'process_add_extra_vehicle.php', 
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(response) {
                submitBtn.prop('disabled', false).text('Save Record');
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => { window.location.href = '../../extra_vehicle.php'; }, 1500);
                } else { showToast(response.message, 'error'); }
            },
            error: function() {
                submitBtn.prop('disabled', false).text('Save Record');
                showToast("An unexpected error occurred.", 'error');
            }
        });
    });

    // --- Dynamic Remove Row Logic ---
    $(document).on('click', '.remove-employee-btn', function() {
        $(this).closest('.employee-input').remove();
    });
</script>

</body>
</html>