<?php
// edit_driver.php - FINAL VERSION with AUDIT LOG
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// Get the driver NIC from the GET request (for initial load) or POST request (for form submission)
$driver_nic = $_REQUEST['driver_NIC'] ?? null;
$driver_data = null;
$original_data = null; // Variable to store data before update for auditing
$message = null; // Variable to hold the status message for the toast

// --- Function to fetch driver data (including vehicle) ---
function fetch_driver_data($conn, $driver_nic) {
    $sql = "SELECT d.*, v.vehicle_no AS assigned_vehicle_no 
            FROM driver d 
            LEFT JOIN vehicle v ON d.driver_NIC = v.driver_NIC 
            WHERE d.driver_NIC = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $driver_nic);
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
        error_log("Insertion Failed for Driver {$record_id}: " . $stmt->error);
    }
    $stmt->close();
}


// --- Handle POST Request for Editing ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $driver_nic) {
    
    // 1. Fetch the ORIGINAL data before the transaction starts
    $original_data = fetch_driver_data($conn, $driver_nic);

    if (!$original_data) {
        $message = ['status' => 'error', 'text' => 'Original driver data not found. Cannot proceed with update.'];
        goto end_post_handling; // Skip to form display
    }
    
    // New submitted values
    $new_values = [
        'calling_name' => $_POST['calling_name'],
        'full_name' => $_POST['full_name'],
        'phone_no' => $_POST['phone_no'],
        'license_expiry_date' => $_POST['license_expiry_date'],
        'vehicle_no' => $_POST['vehicle_no'] ?? null,
    ];
    $user_id = $_SESSION['user_id'] ?? 0;

    try {
        $conn->begin_transaction();
        
        // A. DRIVER TABLE UPDATE
        $sql_driver = "UPDATE driver SET calling_name=?, full_name=?, phone_no=?, license_expiry_date=? WHERE driver_NIC=?";
        $stmt_driver = $conn->prepare($sql_driver);
        $stmt_driver->bind_param('sssss', $new_values['calling_name'], $new_values['full_name'], $new_values['phone_no'], $new_values['license_expiry_date'], $driver_nic);
        
        if (!$stmt_driver->execute()) {
            throw new Exception('Driver update error: ' . $stmt_driver->error);
        }

        // B. VEHICLE ASSIGNMENT UPDATE (Using the transaction logic you provided)
        
        // B1. Reset any vehicle currently assigned to this driver
        $sql_reset_vehicle = "UPDATE vehicle SET driver_NIC = NULL WHERE driver_NIC = ?";
        $stmt_reset_vehicle = $conn->prepare($sql_reset_vehicle);
        $stmt_reset_vehicle->bind_param('s', $driver_nic);
        if (!$stmt_reset_vehicle->execute()) {
             throw new Exception('Vehicle reset error: ' . $stmt_reset_vehicle->error);
        }
        
        // B2. Assign the selected vehicle (if one was selected)
        if (!empty($new_values['vehicle_no'])) {
            $sql_assign_vehicle = "UPDATE vehicle SET driver_NIC = ? WHERE vehicle_no = ?";
            $stmt_assign_vehicle = $conn->prepare($sql_assign_vehicle);
            $stmt_assign_vehicle->bind_param('ss', $driver_nic, $new_values['vehicle_no']);
            if (!$stmt_assign_vehicle->execute()) {
                throw new Exception('Vehicle assignment error: ' . $stmt_assign_vehicle->error);
            }
        }

        $conn->commit();
        
        // 3. LOG AUDIT CHANGES (After successful commit)
        
        // Log changes to the driver table fields
        $driver_fields = ['calling_name', 'full_name', 'phone_no', 'license_expiry_date'];
        foreach ($driver_fields as $field) {
            log_audit_change($conn, $user_id, 'driver', $driver_nic, $field, $original_data[$field], $new_values[$field]);
        }
        
        // Log change to vehicle assignment (which is effectively a change on the driver record's assignment)
        $old_vehicle_no = $original_data['assigned_vehicle_no'];
        $new_vehicle_no = $new_values['vehicle_no'];

        log_audit_change($conn, $user_id, 'driver', $driver_nic, 'assigned_vehicle', $old_vehicle_no, $new_vehicle_no);

        // SUCCESS ACTION: REDIRECT TO driver.php WITH SUCCESS MESSAGE
        $message_text = urlencode('driver details updated successfully!');
        header("Location: driver.php?status=success&message={$message_text}");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        // ERROR ACTION: Set error message to be displayed on the CURRENT page via toast
        $message = ['status' => 'error', 'text' => 'Failed to update driver: ' . $e->getMessage()];
        // Continue to the HTML section to re-display the form and the error toast
    }
}
end_post_handling: // Label for the goto

// --- Fetch Data for Form (after GET or failed POST) ---

if ($driver_nic) {
    // If the POST failed, we need to re-fetch the data to show the user the original values 
    // (since the transaction was rolled back)
    $driver_data = fetch_driver_data($conn, $driver_nic);
    
    if (!$driver_data) {
        header("Location: driver.php?status=error&message=" . urlencode("Driver not found."));
        exit();
    }
} else {
    header("Location: driver.php?status=error&message=" . urlencode("Driver NIC missing."));
    exit();
}

// Fetch all available/assigned vehicles for the dropdown
// We fetch ALL vehicles to correctly identify which are assigned to others, 
// and to pre-select the currently assigned one.
$sql_vehicles = "SELECT vehicle_no, driver_NIC FROM vehicle";
$vehicles_result = $conn->query($sql_vehicles);
$all_vehicles = $vehicles_result ? $vehicles_result->fetch_all(MYSQLI_ASSOC) : [];


include('../../includes/header.php');
include('../../includes/navbar.php');
// Close the connection only after all database operations are complete
if (isset($conn)) {
    $conn->close(); 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Driver: <?php echo htmlspecialchars($driver_data['driver_NIC']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Toast Notification Styles */
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
            <h1 class="text-3xl font-extrabold text-gray-800">Edit Driver Details</h1>
            <p class="text-lg text-gray-600 mt-1">License ID: <span class="font-semibold"><?= htmlspecialchars($driver_data['driver_NIC']) ?></span></p>
        </div>

        <form method="POST" action="edit_driver.php" class="space-y-4">
            <input type="hidden" name="driver_NIC" value="<?= htmlspecialchars($driver_data['driver_NIC']) ?>">
            
            <div class="bg-gray-50 p-4 rounded-lg border">
                <h4 class="text-xl font-bold mb-4 text-blue-600 border-b pb-1">Driver Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <div>
                        <label for="calling_name" class="block text-sm font-semibold text-gray-700">Calling Name:</label>
                        <input type="text" id="calling_name" name="calling_name" value="<?= htmlspecialchars($driver_data['calling_name']) ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label for="full_name" class="block text-sm font-semibold text-gray-700">Full Name:</label>
                        <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($driver_data['full_name']) ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label for="phone_no" class="block text-sm font-semibold text-gray-700">Phone No:</label>
                        <input type="text" id="phone_no" name="phone_no" value="<?= htmlspecialchars($driver_data['phone_no']) ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label for="license_expiry_date" class="block text-sm font-semibold text-gray-700">License Expiry Date:</label>
                        <input type="date" id="license_expiry_date" name="license_expiry_date" value="<?= htmlspecialchars($driver_data['license_expiry_date']) ?>" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label for="vehicle_no" class="block text-sm font-semibold text-gray-700">Assign Vehicle:</label>
                        <select id="vehicle_no" name="vehicle_no" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">-- Unassign --</option>
                            <?php foreach ($all_vehicles as $v): 
                                $is_assigned_to_other = !empty($v['driver_NIC']) && $v['driver_NIC'] !== $driver_data['driver_NIC'];
                                $display_text = htmlspecialchars($v['vehicle_no']);
                                
                                // Skip vehicles assigned to other drivers
                                if ($is_assigned_to_other) {
                                    continue; 
                                } 
                                
                                // Check if the current vehicle is the one assigned to this driver
                                $is_selected = ($v['vehicle_no'] === $driver_data['assigned_vehicle_no']);

                                // Mark the currently assigned vehicle
                                if ($is_selected) {
                                    $display_text .= ' (Currently Assigned)';
                                }
                            ?>
                                <option 
                                    value="<?php echo htmlspecialchars($v['vehicle_no']); ?>"
                                    <?php echo $is_selected ? 'selected' : ''; ?>>
                                    <?php echo $display_text; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Note: Only unassigned vehicles or the vehicle currently assigned to this driver are shown.</p>
                    </div>

                </div>
            </div>
            
            <div class="flex justify-between pt-4 space-x-4">
                <a href="driver.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-lg shadow-md transition duration-300 transform hover:scale-105">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- Toast Function (Copied to edit_driver.php for error handling) ---
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

        // --- Message Check for POST Errors (only errors on this page) ---
        const phpMessage = <?php echo json_encode($message); ?>;

        if (phpMessage && phpMessage.status === 'error' && phpMessage.text) {
            showToast(phpMessage.text, phpMessage.status);
        }
    });
</script>

</body>
</html>