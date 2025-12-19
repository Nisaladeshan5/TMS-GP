<?php
// add_reduction.php (Add New Staff Payment Reduction - Multiple Route Version)
require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

include('../../../../includes/db.php');

// --- SETUP VARIABLES ---
$message = '';
$current_user_id = $_SESSION['user_id'] ?? null; 
$current_date = date('Y-m-d'); 

// --- 1. HANDLE FORM SUBMISSION (MULTIPLE ROUTE INSERTION) ---
// This PHP logic remains the same as it correctly handles the array of route_codes.
if (isset($_POST['add_reduction']) && $current_user_id) {
    $date = $_POST['date'];
    
    // CRITICAL CHANGE: route_codes is now an array of selected codes
    $route_codes = $_POST['route_code'] ?? []; 
    
    $amount = (float)$_POST['amount'];
    $reason = $_POST['reason'];
    $total_routes = count($route_codes);
    $successful_inserts = 0;
    
    // Basic validation
    if (empty($date) || $total_routes === 0 || $amount <= 0 || empty($reason)) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">Error: Date, Amount (>0), Reason, and at least one Route must be selected.</div>';
    } else {
        // We need the supplier mapping to get the supplier code for each route
        $route_supplier_map = [];
        $map_sql = "SELECT route_code, supplier_code FROM route WHERE purpose = 'factory'";
        $map_result = $conn->query($map_sql);
        if ($map_result) {
            while ($row = $map_result->fetch_assoc()) {
                $route_supplier_map[$row['route_code']] = $row['supplier_code'];
            }
        }
        
        // Start Transaction
        $conn->begin_transaction();
        $all_success = true;

        $insert_sql = "INSERT INTO reduction (date, route_code, supplier_code, amount, reason, user_id) VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);

        if ($insert_stmt === false) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">SQL Prepare Error: ' . htmlspecialchars($conn->error) . '</div>';
            $all_success = false;
        } else {
            // Loop through each selected route code
            foreach ($route_codes as $route_code) {
                $supplier_code = $route_supplier_map[$route_code] ?? null; 
                
                if (empty($supplier_code)) {
                    $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">Error: Could not find Supplier Code for route ' . htmlspecialchars($route_code) . '.</div>';
                    $all_success = false;
                    break;
                }

                // Bind parameters for the current record
                $insert_stmt->bind_param("sssdsi", $date, $route_code, $supplier_code, $amount, $reason, $current_user_id);

                if (!$insert_stmt->execute()) {
                    $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">Error adding record for ' . htmlspecialchars($route_code) . ': ' . htmlspecialchars($insert_stmt->error) . '</div>';
                    $all_success = false;
                    break;
                }
                $successful_inserts++;
            }
            $insert_stmt->close();
        }

        if ($all_success) {
            $conn->commit();
            header("Location: adjustment_factory.php?success=1&count=$successful_inserts");
            exit();
        } else {
            $conn->rollback();
        }
    }
}

// Check for success flag after redirect and set message for toast
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $count = $_GET['count'] ?? 1;
    $message = '<script>document.addEventListener("DOMContentLoaded", function() { showToast("' . htmlspecialchars($count) . ' Reduction record(s) added successfully.", "success"); });</script>';
}


// --- 2. FETCH DROPDOWN DATA (MODIFIED for Auto-Supplier) ---

// A. Fetch Staff Routes AND the default supplier code
$routes = [];
$route_supplier_map = [];
$routes_sql = "SELECT route_code, route, supplier_code FROM route WHERE purpose = 'factory' ORDER BY route_code";
$routes_result = $conn->query($routes_sql);
if ($routes_result) {
    while ($row = $routes_result->fetch_assoc()) {
        $routes[] = $row;
        $route_supplier_map[$row['route_code']] = $row['supplier_code'];
    }
}

// B. Fetch Suppliers
$suppliers = [];
$suppliers_sql = "SELECT supplier_code, supplier FROM supplier ORDER BY supplier_code";
$suppliers_result = $conn->query($suppliers_sql);
if ($suppliers_result) {
    while ($row = $suppliers_result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}
$conn->close(); 

// --- 3. HTML TEMPLATE SETUP ---
$page_title = "Add New Reduction (Multi-Route)";

include('../../../../includes/header.php'); 
include('../../../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Styles for toast remain the same */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
        /* NEW: Style for the scrollable checkbox container */
        .checkbox-container {
            max-height: 200px; /* Limits the height */
            overflow-y: auto; /* Adds scrollbar */
            border: 1px solid #D1D5DB; /* light gray border */
            border-radius: 0.375rem; /* rounded corners */
            padding: 0.5rem;
            background-color: #ffffff;
        }
    </style>
</head>
<script>
    // Session timeout (same)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);

    // Toast Functionality (same)
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container'); 
        const toast = document.createElement('div'); 
        toast.className = `toast ${type}`; 
        
        let iconPath = (type === 'success') 
            ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />'
            : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />';

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
        }, 4000); 
    } 

    // --- FORM VALIDATION (MODIFIED for Checkboxes) ---
    function handleFormSubmit(event) {
        // Find all checked route checkboxes
        const selectedRoutes = document.querySelectorAll('input[name="route_code[]"]:checked');
        const amount = parseFloat(document.getElementById('amount').value);
        const reason = document.getElementById('reason').value.trim();
        const supplierSelect = document.getElementById('supplier_code');

        if (selectedRoutes.length === 0) {
            showToast("Please select at least one Route.", 'error');
            event.preventDefault();
            return false;
        }

        if (amount <= 0 || isNaN(amount)) {
            showToast("Amount must be a positive number.", 'error');
            event.preventDefault();
            return false;
        }
        
        if (reason === '') {
            showToast("Reason cannot be empty.", 'error');
            event.preventDefault();
            return false;
        }
        
        // IMPORTANT: Re-enable the supplier select just before submission
        supplierSelect.disabled = false;
        return true;
    }
    
    // --- JAVASCRIPT FOR DYNAMICALLY SETTING SUPPLIER FIELD ---
    const ROUTE_SUPPLIER_MAP = <?php echo json_encode($route_supplier_map); ?>;

    document.addEventListener('DOMContentLoaded', function() {
        const routeCheckboxes = document.querySelectorAll('input[name="route_code[]"]');
        const supplierSelect = document.getElementById('supplier_code');
        const supplierDisplay = document.getElementById('supplier_display');

        function updateSupplierDisplay() {
            // Find the currently CHECKED routes
            const selectedRoutes = document.querySelectorAll('input[name="route_code[]"]:checked');

            if (selectedRoutes.length > 0) {
                // Get the supplier code for the FIRST selected route
                const firstRouteCode = selectedRoutes[0].value;
                const defaultSupplier = ROUTE_SUPPLIER_MAP[firstRouteCode];
                
                if (defaultSupplier) {
                    supplierSelect.value = defaultSupplier;
                    // Find the full supplier name for display
                    const supplierOption = Array.from(supplierSelect.options).find(opt => opt.value === defaultSupplier);
                    const supplierName = supplierOption ? supplierOption.text : defaultSupplier;
                    
                    supplierDisplay.textContent = supplierName;
                    supplierDisplay.classList.remove('text-gray-500', 'text-red-500');
                    supplierDisplay.classList.add('text-gray-900', 'font-semibold');
                } else {
                    supplierSelect.value = ""; 
                    supplierDisplay.textContent = "Error: No default supplier found for this route.";
                    supplierDisplay.classList.add('text-red-500');
                }
            } else {
                supplierSelect.value = "";
                supplierDisplay.textContent = "-- Select Route(s) First --";
                supplierDisplay.classList.remove('text-gray-900', 'font-semibold', 'text-red-500');
                supplierDisplay.classList.add('text-gray-500');
            }
        }
        
        // Listen for ANY change in selected routes (checked/unchecked)
        routeCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSupplierDisplay);
        });
        
        // Initial call
        updateSupplierDisplay();
    });

</script>
<body class="bg-gray-100 font-sans">

<div id="toast-container"></div>

<div class="w-[85%] ml-[15%]">
    <div class="container max-w-3xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add New Reduction (Multi-Route)</h1>
        
        <?php echo $message; ?>

        <form id="reductionForm" method="POST" onsubmit="return handleFormSubmit(event)" class="space-y-6">
            <input type="hidden" name="add_reduction" value="1">
            
            <div class="grid md:grid-cols-3 gap-6">
                <div>
                    <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                    <input type="date" id="date" name="date" value="<?php echo $current_date; ?>" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2">
                </div>
                
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Route(s) Selection:</label>
                    
                    <div class="checkbox-container">
                        <?php foreach ($routes as $route_row): ?>
                            <div class="flex items-center space-x-2 p-1 border-b border-gray-100 last:border-b-0 hover:bg-gray-50">
                                <input 
                                    type="checkbox" 
                                    id="route_<?= htmlspecialchars($route_row["route_code"]) ?>" 
                                    name="route_code[]" 
                                    value="<?= htmlspecialchars($route_row["route_code"]) ?>" 
                                    class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                >
                                <label for="route_<?= htmlspecialchars($route_row["route_code"]) ?>" class="text-sm font-medium text-gray-700 cursor-pointer">
                                    <?= htmlspecialchars($route_row["route"]) ?> (<?= htmlspecialchars($route_row["route_code"]) ?>)
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- <div class="space-y-4">
                <label for="supplier_code" class="block text-sm font-medium text-gray-700">Supplier (Derived from Route):</label>
                <div id="supplier_display" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2 bg-gray-100 text-gray-500 border h-10 flex items-center">
                     -- Select Route(s) First --
                </div>
                <select id="supplier_code" name="supplier_code" class="hidden" required disabled>
                     <?php foreach ($suppliers as $supplier_row): ?>
                        <option value="<?= htmlspecialchars($supplier_row["supplier_code"]) ?>">
                            <?= htmlspecialchars($supplier_row["supplier"]) ?> (<?= htmlspecialchars($supplier_row["supplier_code"]) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div> -->
            
            <hr class="my-4">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Reduction Details (Applied to ALL selected Routes)</h3>
            
            <div class="grid md:grid-cols-2 gap-6">
                <div> 
                    <label for="amount" class="block text-sm font-medium text-gray-700">Amount (LKR):</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2">
                </div>
                <div>
                    <label for="reason" class="block text-sm font-medium text-gray-700">Reason for Reduction:</label>
                    <input type="text" id="reason" name="reason" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2">
                </div>
            </div>

            <div class="flex justify-between mt-8 pt-4 border-t border-gray-200">
                <a href="adjustment_factory.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" id="submitBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Submit All Reductions
                </button>
            </div>
        </form>
    </div>
</div>

</body>
</html>