<?php
// add_reduction.php (Add New Staff Payment Reduction)
require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

// NOTE: Ensure the path to db.php is correct relative to the location of this script.
include('../../../includes/db.php');

// --- SETUP VARIABLES ---
$message = '';
// CRITICAL: Get the user_id from the session to store as the creator
$current_user_id = $_SESSION['user_id'] ?? null; 
$current_date = date('Y-m-d'); // Default date for the form

// --- 1. HANDLE FORM SUBMISSION (INSERT OPERATION) ---
if (isset($_POST['add_reduction']) && $current_user_id) {
    $date = $_POST['date'];
    $route_code = $_POST['route_code'];
    $supplier_code = $_POST['supplier_code'];
    $amount = (float)$_POST['amount'];
    $reason = $_POST['reason'];

    // Input validation (basic check)
    if (empty($date) || empty($route_code) || empty($supplier_code) || $amount <= 0 || empty($reason)) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">Error: All fields are required, and Amount must be greater than zero.</div>';
    } else {
        // SQL to insert the new reduction record, INCLUDING user_id
        $insert_sql = "INSERT INTO reduction (date, route_code, supplier_code, amount, reason, user_id) VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        
        if ($insert_stmt === false) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">SQL Error: ' . htmlspecialchars($conn->error) . '</div>';
        } else {
            // Bind parameters: sssdsi (string, string, string, double, string, integer)
            $insert_stmt->bind_param("sssdsi", $date, $route_code, $supplier_code, $amount, $reason, $current_user_id);

            if ($insert_stmt->execute()) {
                // Redirect after successful POST (best practice)
                header("Location: add_reduction.php?success=1");
                exit();
            } else {
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">Error adding record: ' . htmlspecialchars($insert_stmt->error) . '</div>';
            }
            $insert_stmt->close();
        }
    }
}

// Check for success flag after redirect and set message for toast
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = '<script>document.addEventListener("DOMContentLoaded", function() { showToast("Reduction record added successfully.", "success"); });</script>';
}


// --- 2. FETCH DROPDOWN DATA ---

// A. Fetch Staff Routes
$routes = [];
$routes_sql = "SELECT route_code, route FROM route WHERE purpose = 'staff' ORDER BY route_code";
$routes_result = $conn->query($routes_sql);
if ($routes_result) {
    while ($row = $routes_result->fetch_assoc()) {
        $routes[] = $row;
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
$page_title = "Add New Reduction";

// Reusing Includes
include('../../../includes/header.php'); 
include('../../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Reusing custom styles from add_route.php for consistency */
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
            background-color: #4CAF50;
        }
        .toast.error {
            background-color: #F44336;
        }
        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
<script>
    // Session timeout script placeholder (as provided in the original file)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);

    // Replicating the Toast Functionality from the original script
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

        setTimeout(() => toast.classList.add('show'), 10); 

        setTimeout(() => { 
            toast.classList.remove('show'); 
            toast.addEventListener('transitionend', () => toast.remove(), { once: true }); 
        }, 3000); // Increased duration slightly for better visibility
    } 

    // Function to handle form submission (standard POST)
    function handleFormSubmit(event) {
        const amount = parseFloat(document.getElementById('amount').value);
        
        if (amount <= 0 || isNaN(amount)) {
            event.preventDefault();
            showToast("Amount must be a positive number.", 'error');
            return;
        }

        // The form will now submit normally via POST
    }
    
    // --- JAVASCRIPT FOR AUTOFULL ---
    document.addEventListener('DOMContentLoaded', function() {
        const routeSelect = document.getElementById('route_code');
        const supplierSelect = document.getElementById('supplier_code');

        routeSelect.addEventListener('change', function() {
            const selectedRouteCode = this.value;
            if (selectedRouteCode) {
                fetchDefaultSupplier(selectedRouteCode);
            } else {
                supplierSelect.value = ""; 
            }
        });

        async function fetchDefaultSupplier(routeCode) {
            // Correct path for AJAX request relative to add_reduction.php
            try {
                const response = await fetch(`get_default_supplier.php?route_code=${encodeURIComponent(routeCode)}`);
                
                // CRITICAL: Check if response is successful (HTTP 200) and if content is empty
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const text = await response.text();
                if (!text) {
                    throw new Error("Empty response received from server.");
                }
                
                const data = JSON.parse(text);

                if (data.success && data.supplier_code) {
                    supplierSelect.value = data.supplier_code;
                } else {
                    supplierSelect.value = ""; 
                }
            } catch (error) {
                console.error('Error fetching default supplier:', error);
                // Displaying a generic error message after catching the specific console error
                showToast("Server lookup failed. Check console for details.", 'error');
            }
        }
        
        // Trigger autofill on initial page load if a route is already selected (e.g., if page reloaded due to error)
        if(routeSelect.value) {
            fetchDefaultSupplier(routeSelect.value);
        }
    });

</script>
<body class="bg-gray-100 font-sans">

<div id="toast-container"></div>

<div class="w-[85%] ml-[15%]">
    <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add New Reduction</h1>
        
        <?php echo $message; ?>

        <form id="reductionForm" method="POST" onsubmit="handleFormSubmit(event)" class="space-y-6">
            <input type="hidden" name="add_reduction" value="1">
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                    <input type="date" id="date" name="date" value="<?php echo $current_date; ?>" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2">
                </div>
                
                <div> 
                    <label for="amount" class="block text-sm font-medium text-gray-700">Amount (LKR):</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2">
                </div>
            </div>
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="route_code" class="block text-sm font-medium text-gray-700">Route:</label>
                    <select id="route_code" name="route_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2">
                        <option value="">-- Select Route --</option>
                        <?php foreach ($routes as $route_row): ?>
                            <option value="<?= htmlspecialchars($route_row["route_code"]) ?>">
                                <?= htmlspecialchars($route_row["route"]) ?> (<?= htmlspecialchars($route_row["route_code"]) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="supplier_code" class="block text-sm font-medium text-gray-700">Supplier:</label>
                    <select id="supplier_code" name="supplier_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2">
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($suppliers as $supplier_row): ?>
                            <option value="<?= htmlspecialchars($supplier_row["supplier_code"]) ?>">
                                <?= htmlspecialchars($supplier_row["supplier"]) ?> (<?= htmlspecialchars($supplier_row["supplier_code"]) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="space-y-6">
                <div>
                    <label for="reason" class="block text-sm font-medium text-gray-700">Reason for Reduction:</label>
                    <input type="text" id="reason" name="reason" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2">
                </div>
            </div>

            <div class="flex justify-between mt-6 pt-4 border-t border-gray-200">
                <a href="adjustment_staff.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" id="submitBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Add Reduction
                </button>
            </div>
        </form>
    </div>
</div>

</body>
</html>