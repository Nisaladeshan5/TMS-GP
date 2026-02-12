<?php
require_once '../../includes/session_check.php';
// Start the session (ensure it's started before accessing session variables)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// Get the logged-in user's ID
$logged_in_user_id = $_SESSION['user_id'] ?? 0;

// Disable error display in AJAX responses (log errors instead)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Ensure this path is writable
// ini_set('error_log', __DIR__ . '/error.log'); 

// Define a flag for AJAX requests
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// --- AUDIT LOG FUNCTION ---
// 🎯 AUDIT LOG FUNCTION: Uses 7 columns
function log_general_audit_entry(
    $conn, 
    $tableName, 
    $recordId, 
    $actionType, 
    $userId, 
    $fieldName = null,  
    $oldValue = null,   
    $newValue = null    
) {
    // Reopen connection if it was closed or not available (crucial for AJAX block where $conn is closed later)
    if (!isset($conn) || $conn->connect_error) {
         // Attempt a basic reconnection or rely on the caller to manage connection state
         // For simplicity here, we assume the connection passed in is valid.
         // If a reconnection mechanism is needed, it should be implemented here or in db.php.
         error_log("Audit Log: Database connection is not valid.");
         return;
    }

    $log_sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id, field_name, old_value, new_value, change_time) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

    $log_stmt = $conn->prepare($log_sql);

    if ($log_stmt === false) {
        error_log("General Audit Log Preparation Error: " . $conn->error);
        return;
    }
    
    // Binding parameters: 7 string/text fields
    $log_stmt->bind_param(
        "sssssss", // Using 's' for all for compatibility with string/text fields
        $tableName, 
        $recordId, 
        $actionType,
        $userId, 
        $fieldName, 
        $oldValue, 
        $newValue
    );
    
    if (!$log_stmt->execute()) {
        error_log("General Audit Log Execution Error: " . $log_stmt->error);
    }
    $log_stmt->close();
}
// --- END AUDIT LOG FUNCTION ---


// ---------------------------
// PHP Logic: Handle AJAX Form Submission
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vehicle'])) {
    if (!$is_ajax) {
        // Prevent direct POST access
        header("Location: add_vehicle.php");
        exit();
    }

    // Clean any accidental output before sending JSON
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $vehicle_no = trim($_POST['vehicle_no']);
    $supplier = trim($_POST['supplier']); // This is the supplier_code
    $capacity = (int)$_POST['capacity'];
    $km_per_liter = trim($_POST['km_per_liter']); // This is c_id (consumption ID)
    $type = trim($_POST['type']);
    $purpose = trim($_POST['purpose']);
    $license_expiry_date = trim($_POST['license_expiry_date']);
    $insurance_expiry_date = trim($_POST['insurance_expiry_date']);
    $fuel_rate_id = (int)$_POST['fuel_rate_id']; // New input

    // Input validation
    if (empty($vehicle_no) || empty($supplier) || empty($capacity) || empty($km_per_liter) || empty($type) || empty($purpose) || empty($license_expiry_date) || empty($insurance_expiry_date) || empty($fuel_rate_id)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        $conn->close();
        exit();
    }

    // Check for duplicate vehicle number
    $stmt = $conn->prepare("SELECT vehicle_no FROM vehicle WHERE vehicle_no = ?");
    if ($stmt === false) {
        echo json_encode(['status' => 'error', 'message' => 'Database prepare error (Check Duplicates): ' . $conn->error]);
        $conn->close();
        exit();
    }
    $stmt->bind_param("s", $vehicle_no);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Vehicle with this number already exists.']);
        $stmt->close();
        $conn->close();
        exit();
    }
    $stmt->close();

    // Insert new vehicle
    $sql = "INSERT INTO vehicle (vehicle_no, supplier_code, capacity, fuel_efficiency, type, rate_id, purpose, license_expiry_date, insurance_expiry_date) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    try {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('ssississs', $vehicle_no, $supplier, $capacity, $km_per_liter, $type, $fuel_rate_id, $purpose, $license_expiry_date, $insurance_expiry_date);

        if ($stmt->execute()) {
            
            // --- AUDIT LOG: Vehicle Insertion ---
            // Log the vehicle creation. The primary key for the record is the vehicle_no.
            log_general_audit_entry(
                $conn, 
                'vehicle', 
                $vehicle_no, 
                'INSERT', 
                $logged_in_user_id,
                'vehicle_no',   // Field name
                'NULL',         // Old Value
                $vehicle_no     // New Value (The primary key itself)
            );
            
            echo json_encode(['status' => 'success', 'message' => 'Vehicle added successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

    // Ensure the statement and connection are closed in the AJAX block
    if (isset($stmt) && is_object($stmt)) {
        $stmt->close();
    }
    $conn->close();
    exit(); // important to prevent HTML leaking into JSON
}

// ---------------------------
// PHP Logic: Fetch data for page load
// ---------------------------
// NOTE: Re-include db.php if connection was closed in the AJAX block above and we are now executing the page load logic.
if (!isset($conn) || $conn->connect_error) {
    include('../../includes/db.php');
}

// Fetch suppliers for dropdown
$suppliers = [];
$supplier_sql = "SELECT DISTINCT supplier_code, supplier FROM supplier ORDER BY supplier";
$supplier_result = $conn->query($supplier_sql);
if ($supplier_result) {
    while ($row = $supplier_result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// Fetch fuel consumption types for dropdown (km_per_liter)
$fuel_efficiencies = [];
$fuel_efficiency_sql = "SELECT c_id, c_type FROM consumption ORDER BY c_id";
$fuel_efficiency_result = $conn->query($fuel_efficiency_sql);
if ($fuel_efficiency_result) {
    while ($row = $fuel_efficiency_result->fetch_assoc()) {
        $fuel_efficiencies[] = $row;
    }
}

// Fetch fuel types and IDs for dropdown (fuel_rate_id)
$fuel_types = [];
$fuel_sql = "SELECT rate_id, type FROM fuel_rate GROUP BY type ORDER BY type";
$fuel_result = $conn->query($fuel_sql);
if ($fuel_result) {
    while ($row = $fuel_result->fetch_assoc()) {
        $fuel_types[] = $row;
    }
}

// Close connection before HTML output
$conn->close();

// Standard page load HTML starts here
include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Vehicle</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
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
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add New Vehicle</h1>
            
            <form id="addVehicleForm" class="space-y-6">
                <input type="hidden" name="add_vehicle" value="1">
                
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No:</label>
                        <input type="text" id="vehicle_no" name="vehicle_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                    </div>
                    <div>
                        <label for="supplier" class="block text-sm font-medium text-gray-700">Supplier:</label>
                        <select id="supplier" name="supplier" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>">
                                    <?php echo htmlspecialchars($supplier['supplier']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="capacity" class="block text-sm font-medium text-gray-700">Capacity:</label>
                        <input type="number" id="capacity" name="capacity" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                    </div>
                    <div>
                        <label for="km_per_liter" class="block text-sm font-medium text-gray-700">Fuel Efficiency:</label>
                        <select id="km_per_liter" name="km_per_liter" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">Select Fuel Efficiency Type</option>
                            <?php foreach ($fuel_efficiencies as $fuel_efficiency): ?>
                                <option value="<?php echo htmlspecialchars($fuel_efficiency['c_id']); ?>">
                                    <?php echo htmlspecialchars($fuel_efficiency['c_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700">Type:</label>
                        <select id="type" name="type" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">Select Type</option>
                            <option value="van">Van</option>
                            <option value="bus">Bus</option>
                            <option value="car">Car</option>
                            <option value="wheel">Wheel</option>
                            <option value="motor bike">Motor Bike</option>
                        </select>
                    </div>
                    <div>
                        <label for="fuel_type" class="block text-sm font-medium text-gray-700">Fuel Type:</label>
                        <select id="fuel_type" name="fuel_rate_id" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">Select Fuel Type</option>
                            <?php foreach ($fuel_types as $fuel): ?>
                                <option value="<?php echo htmlspecialchars($fuel['rate_id']); ?>">
                                    <?php echo htmlspecialchars($fuel['type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="purpose" class="block text-sm font-medium text-gray-700">Purpose:</label>
                        <select id="purpose" name="purpose" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="staff">Staff</option>
                            <option value="factory">Factory</option>
                            <option value="sub_route">Sub Route</option>
                            <option value="held_up">Held Up</option>
                            <option value="extra">Extra</option>
                            <option value="night_emergency">Night Emergency</option>
                        </select>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="license_expiry_date" class="block text-sm font-medium text-gray-700">License Expiry Date:</label>
                        <input type="date" id="license_expiry_date" name="license_expiry_date" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                    </div>
                    <div>
                        <label for="insurance_expiry_date" class="block text-sm font-medium text-gray-700">Insurance Expiry Date:</label>
                        <input type="date" id="insurance_expiry_date" name="insurance_expiry_date" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                    </div>
                </div>

                <div class="flex justify-between mt-6 space-x-4">
                    <a href="vehicle.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Cancel
                    </a>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Add Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
        // Show toast notification
        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                    ${type === 'success'
                        ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />'
                        : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />'
                    }
                </svg>
                <span>${message}</span>
            `;
            
            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);

            // Hide and remove
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 2000);
        }

        // Handle form submit via AJAX
        const form = document.getElementById('addVehicleForm');
        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            const formData = new FormData(this);

            try {
                const response = await fetch('add_vehicle.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                if (result.status === 'success') {
                    showToast(result.message, 'success');
                    // Redirect to vehicle.php after a 2-second delay
                    setTimeout(() => window.location.href = 'vehicle.php', 2000);
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                console.error('Submission error:', error);
                showToast('An unexpected error occurred. Check the console for details.', 'error');
            }
        });
    </script>
</body>
</html>