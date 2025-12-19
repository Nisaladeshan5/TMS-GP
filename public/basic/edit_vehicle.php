<?php
// edit_vehicle.php - FINAL VERSION with AUDIT LOG
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// 1. Initial connection and function definitions
include('../../includes/db.php');

$vehicle_no = $_GET['vehicle_no'] ?? null;
$vehicle_data = null;
$original_data = null; // Variable to store data before update for auditing
$message = null; // Variable to hold the status message for the toast

// --- Function to fetch vehicle data ---
function fetch_vehicle_data($conn, $vehicle_no) {
    $sql = "SELECT * FROM vehicle WHERE vehicle_no = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $vehicle_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data;
}

// --- Function to log changes (Requires 'audit_log' table) ---
function log_audit_change($conn, $user_id, $table, $record_id, $field, $old, $new) {
    // Only log if the value actually changed
    if (trim((string)$old) === trim((string)$new)) return; 

    // SQL has 6 placeholders
    $sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id, field_name, old_value, new_value, change_time) 
             VALUES (?, ?, 'UPDATE', ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    
    $old_str = (string)$old;
    $new_str = (string)$new;
    
    // Type definition: s (table_name), s (record_id), i (user_id), s (field_name), s (old_value), s (new_value)
    $stmt->bind_param('ssisss', $table, $record_id, $user_id, $field, $old_str, $new_str);
    
    if (!$stmt->execute()) {
        error_log("Audit Log Insertion Failed for Vehicle {$record_id}: " . $stmt->error);
    }
    $stmt->close();
}


// --- Handle POST Request for Editing (MODIFIED for Audit Log) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_no = $_POST['vehicle_no'];

    // 2. Fetch the ORIGINAL data BEFORE the update
    $original_data = fetch_vehicle_data($conn, $vehicle_no);

    if (!$original_data) {
        $message = ['status' => 'error', 'text' => 'Vehicle not found for update.'];
    } else {
        try {
            // New submitted values
            $new_values = [
                'supplier_code' => $_POST['supplier_code'],
                'capacity' => (int)$_POST['capacity'],
                'fuel_efficiency' => $_POST['fuel_efficiency'], // This is c_id
                'type' => $_POST['type'],
                'purpose' => $_POST['purpose'],
                'license_expiry_date' => $_POST['license_expiry_date'],
                'insurance_expiry_date' => $_POST['insurance_expiry_date'],
                'rate_id' => $_POST['rate_id'],
            ];

            // Prepare and execute the UPDATE query
            $sql = "UPDATE vehicle 
                    SET supplier_code=?, capacity=?, fuel_efficiency=?, type=?, purpose=?, 
                        license_expiry_date=?, insurance_expiry_date=?, rate_id=?
                    WHERE vehicle_no=?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sisssssss', 
                $new_values['supplier_code'], $new_values['capacity'], $new_values['fuel_efficiency'], $new_values['type'], $new_values['purpose'],
                $new_values['license_expiry_date'], $new_values['insurance_expiry_date'], $new_values['rate_id'], $vehicle_no
            );

            if ($stmt->execute()) {
                // 3. Log the changes
                $user_id = $_SESSION['user_id'] ?? 0; // Use a session user ID
                
                foreach ($new_values as $field => $new_value) {
                    $old_value = $original_data[$field];
                    log_audit_change($conn, $user_id, 'vehicle', $vehicle_no, $field, $old_value, $new_value);
                }
                
                // SUCCESS: Set the message variable
                $message_text = urlencode('vehicle details updated successfully!');
                header("Location: vehicle.php?status=success&message={$message_text}");
                exit();
                
            } else {
                $message = ['status' => 'error', 'text' => 'Database error: ' . $stmt->error];
            }
            $stmt->close();
            
        } catch (Exception $e) {
            $message = ['status' => 'error', 'text' => 'An unexpected error occurred: ' . $e->getMessage()];
        }
    }
}

// --- Fetch Vehicle Data for Form (Modified to use the function) ---
if ($vehicle_no) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($message['status']) && $message['status'] === 'success') {
        // If successful POST, use POST data for form to show updated values immediately
        $vehicle_data = [
            'vehicle_no' => $vehicle_no,
            'supplier_code' => $_POST['supplier_code'],
            'capacity' => (int)$_POST['capacity'],
            'fuel_efficiency' => $_POST['fuel_efficiency'],
            'type' => $_POST['type'],
            'purpose' => $_POST['purpose'],
            'license_expiry_date' => $_POST['license_expiry_date'],
            'insurance_expiry_date' => $_POST['insurance_expiry_date'],
            'rate_id' => $_POST['rate_id'],
        ];
    } else {
        // Normal GET request or failed POST: Fetch from DB
        $vehicle_data = fetch_vehicle_data($conn, $vehicle_no);
        
        if (!$vehicle_data) {
            header("Location: vehicles.php?status=error&message=" . urlencode("Vehicle not found."));
            exit();
        }
    }
} else {
    header("Location: vehicles.php?status=error&message=" . urlencode("Vehicle number missing."));
    exit();
}

// Fetch all dropdown data (suppliers, consumption types, fuel rates)
$suppliers_sql = "SELECT supplier_code, supplier FROM supplier ORDER BY supplier";
$suppliers_result = $conn->query($suppliers_sql);
$suppliers = $suppliers_result ? $suppliers_result->fetch_all(MYSQLI_ASSOC) : [];

$fuel_efficiency_sql = "SELECT c_id, c_type FROM consumption ORDER BY c_id";
$fuel_efficiency_result = $conn->query($fuel_efficiency_sql);
$fuel_efficiencies = $fuel_efficiency_result ? $fuel_efficiency_result->fetch_all(MYSQLI_ASSOC) : [];

$fuel_rates_sql = "SELECT rate_id, type FROM fuel_rate GROUP BY type ORDER BY type";
$fuel_rates_result = $conn->query($fuel_rates_sql);
$fuel_rates = $fuel_rates_result ? $fuel_rates_result->fetch_all(MYSQLI_ASSOC) : [];


include('../../includes/header.php');
include('../../includes/navbar.php');
// Close DB connection
if (isset($conn)) {
    closeDbConnection($conn);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Vehicle: <?php echo htmlspecialchars($vehicle_no); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        #toast-container {
            position: fixed; top: 1rem; right: 1rem; z-index: 2000;
            display: flex; flex-direction: column; align-items: flex-end;
        }
        .toast {
            display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem;
            border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; 
            transform: translateY(-20px); opacity: 0; max-width: 350px;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; flex-shrink: 0; }
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
    <div class="w-2xl p-8 bg-white shadow-lg rounded-lg mt-10 mx-auto max-w-4xl">
        
        <div class="mb-3 pb-1 border-b border-gray-200">
            <h1 class="text-3xl font-extrabold text-gray-800">Edit Vehicle Details</h1>
            <p class="text-lg text-gray-600 mt-1">Vehicle No: <span class="font-semibold"><?= htmlspecialchars($vehicle_data['vehicle_no']) ?></span></p>
        </div>

        <form id="editForm" method="POST" action="edit_vehicle.php" class="space-y-4">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="vehicle_no" value="<?= htmlspecialchars($vehicle_data['vehicle_no']) ?>">
            
            <div class="bg-gray-50 p-3 border border-gray-100 rounded-lg">
                <h4 class="text-xl font-bold mb-4 text-blue-600 border-b pb-1">Basic Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <div>
                        <label for="supplier_code" class="block text-sm font-semibold text-gray-700">Supplier:</label>
                        <select id="supplier_code" name="supplier_code" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>"
                                    <?php echo ($supplier['supplier_code'] == $vehicle_data['supplier_code']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['supplier']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="capacity" class="block text-sm font-semibold text-gray-700">Capacity:</label>
                        <input type="number" id="capacity" name="capacity" value="<?= htmlspecialchars($vehicle_data['capacity']) ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label for="fuel_efficiency" class="block text-sm font-semibold text-gray-700">Fuel Efficiency:</label>
                        <select id="fuel_efficiency" name="fuel_efficiency" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php foreach ($fuel_efficiencies as $fe): ?>
                                <option value="<?php echo htmlspecialchars($fe['c_id']); ?>"
                                    <?php echo ($fe['c_id'] == $vehicle_data['fuel_efficiency']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($fe['c_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="type" class="block text-sm font-semibold text-gray-700">Type:</label>
                        <select id="type" name="type" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php $types = ['van', 'bus', 'car', 'wheel', 'motor bike']; ?>
                            <?php foreach ($types as $type): ?>
                                <option value="<?php echo $type; ?>"
                                    <?php echo ($type == $vehicle_data['type']) ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="rate_id" class="block text-sm font-semibold text-gray-700">Fuel Type:</label>
                        <select id="rate_id" name="rate_id" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php foreach ($fuel_rates as $rate): ?>
                                <option value="<?php echo htmlspecialchars($rate['rate_id']); ?>"
                                    <?php echo ($rate['rate_id'] == $vehicle_data['rate_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($rate['type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="purpose" class="block text-sm font-semibold text-gray-700">Purpose:</label>
                        <select id="purpose" name="purpose" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php $purposes = ['staff', 'factory', 'night_emergency' , 'sub_route']; ?>
                            <?php foreach ($purposes as $purpose): ?>
                                <option value="<?php echo $purpose; ?>"
                                    <?php echo ($purpose == $vehicle_data['purpose']) ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(str_replace('_', ' ', $purpose)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-50 p-3 border border-gray-100 rounded-lg">
                <h4 class="text-xl font-bold mb-4 text-blue-600 border-b pb-1">Document Expiry Dates</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="license_expiry_date" class="block text-sm font-semibold text-gray-700">License Expiry Date:</label>
                        <input type="date" id="license_expiry_date" name="license_expiry_date" value="<?= htmlspecialchars($vehicle_data['license_expiry_date']) ?>" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="insurance_expiry_date" class="block text-sm font-semibold text-gray-700">Insurance Expiry Date:</label>
                        <input type="date" id="insurance_expiry_date" name="insurance_expiry_date" value="<?= htmlspecialchars($vehicle_data['insurance_expiry_date']) ?>" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                </div>
            </div>

            <div class="flex justify-between pt-4 border-t border-gray-200 space-x-4">
                <a href="vehicle.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-lg shadow-md transition duration-300 transform hover:scale-105">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- Toast Function ---
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

        // --- Message Check ---
        const phpMessage = <?php echo json_encode($message); ?>;

        if (phpMessage && phpMessage.status && phpMessage.text) {
            showToast(phpMessage.text, phpMessage.status);
        }
    });
</script>

</body>
</html>