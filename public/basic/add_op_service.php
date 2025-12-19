<?php
// add_op_service.php
require_once '../../includes/session_check.php';

// --- AUDIT LOGGING FUNCTION (START) ---
/**
 * Inserts a detailed entry into the audit_log table using prepared statements.
 *
 * @param mysqli $conn The database connection object.
 * @param string $tableName The table name affected (e.g., 'op_services').
 * @param string $recordId The composite key of the record (e.g., 'NE-001V/V-1234').
 * @param string $actionType The action (e.g., 'UPDATE', 'INSERT', 'DELETE').
 * @param int $userId The ID of the logged-in user.
 * @param string $fieldName The specific field that was modified.
 * @param string $oldValue The original value.
 * @param string $newValue The new value.
 */
function log_detailed_audit_entry($conn, $tableName, $recordId, $actionType, $userId, $fieldName, $oldValue, $newValue) {
    // Only log if the value has actually changed
    if ((string)$oldValue === (string)$newValue && $actionType === 'UPDATE') {
        return; 
    }

    $log_sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id, field_name, old_value, new_value, change_time) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

    $log_stmt = $conn->prepare($log_sql);

    if ($log_stmt === false) {
        error_log("Audit Log Preparation Error: " . $conn->error);
        return;
    }
    
    // Convert values to string for logging
    $oldValueStr = (string)$oldValue;
    $newValueStr = (string)$newValue;
    $recordIdKey = $recordId; 

    // Bind parameters: (string, string, string, integer, string, string, string)
    $log_stmt->bind_param(
        "sssisss", 
        $tableName, 
        $recordIdKey, 
        $actionType, 
        $userId, 
        $fieldName, 
        $oldValueStr, 
        $newValueStr
    );
    
    if (!$log_stmt->execute()) {
        error_log("Audit Log Execution Error: " . $log_stmt->error);
    }
    $log_stmt->close();
}
// --- AUDIT LOGGING FUNCTION (END) ---


if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Ensure you have a user ID in the session for auditing!
$logged_in_user_id = $_SESSION['user_id'] ?? 0; 
if ($logged_in_user_id === 0) {
    error_log("Audit Error: User ID is missing from session for user: " . ($_SESSION['username'] ?? 'Unknown'));
}


// 1. Database Connection 
include '../../includes/db.php'; 

// 2. Hardcoded Operational Codes 
$opcodes = [
    ['code' => 'DH', 'description' => 'Day Held-up Rate'],
    ['code' => 'NH', 'description' => 'Night Held-up Rate'],
    ['code' => 'NE', 'description' => 'Night Emergency Rate'],
    ['code' => 'EV', 'description' => 'Extra Vehicle Rate'],
];

// --- 2.5 Fetch ALL Vehicle Numbers for Datalist Suggestions ---
$all_vehicle_numbers = [];
$vehicles_sql = "SELECT vehicle_no FROM vehicle WHERE is_active = 1 ORDER BY vehicle_no ASC"; 
$vehicles_result = $conn->query($vehicles_sql);

if ($vehicles_result) {
    while ($row = $vehicles_result->fetch_assoc()) {
        $all_vehicle_numbers[] = $row['vehicle_no'];
    }
}
// -------------------------------------------------------------------

// --- NEW: Fetch ALL Supplier Codes/Names for Dropdown ---
$all_suppliers = [];
// NOTE: Now fetching 'supplier_code' instead of 'id'
$supplier_sql = "SELECT supplier_code, supplier FROM supplier WHERE is_active = 1 ORDER BY supplier ASC"; 
$supplier_result = $conn->query($supplier_sql);

if ($supplier_result) {
    while ($row = $supplier_result->fetch_assoc()) {
        // Store as supplier_code => name for easy lookup
        $all_suppliers[$row['supplier_code']] = $row['supplier']; 
    }
}
// -------------------------------------------------------------------


// Initialize form variables
$selected_op_code = $selected_vehicle_no = $selected_supplier_code = $slab_limit = $day_rate = $extra_rate = $extra_rate_ac = '';
$message = '';
$message_type = ''; 
$is_edit_mode = false; 

// Variables to store ORIGINAL values for auditing in POST logic
$original_rates = [
    'supplier_code' => '', // CHANGED: supplier_code (string)
    'slab_limit_distance' => 0,
    'day_rate' => 0,
    'extra_rate' => 0,
    'extra_rate_ac' => 0
];


// --- 3. Handle Data Fetching for EDIT Mode ---
if (isset($_GET['op_code']) && isset($_GET['vehicle_no'])) {
    $is_edit_mode = true;
    $edit_op_code = $_GET['op_code'];
    $edit_vehicle_no = $_GET['vehicle_no']; // Original vehicle no

    // Fetch all current data (for form pre-fill AND for audit comparison later)
    // NOTE: 'supplier_code' is added here. Ensure this column exists in your op_services table.
    $sql = "SELECT op_code, vehicle_no, supplier_code, slab_limit_distance, day_rate, extra_rate, extra_rate_ac
            FROM op_services 
            WHERE op_code = ? AND vehicle_no = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $edit_op_code, $edit_vehicle_no);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $data = $result->fetch_assoc();

        // Load existing data into form variables for pre-filling
        $selected_op_code = $data['op_code'];
        $selected_vehicle_no = $data['vehicle_no'];
        $selected_supplier_code = $data['supplier_code'] ?? ''; // CHANGED
        
        $slab_limit = $data['slab_limit_distance'] ?? 0;
        $day_rate = $data['day_rate'] ?? 0;
        $extra_rate = $data['extra_rate'] ?? 0;
        $extra_rate_ac = $data['extra_rate_ac'] ?? 0;
        
        // Store original rates (crucial for audit logging later)
        $original_rates['supplier_code'] = (string)($selected_supplier_code ?? ''); // CHANGED
        $original_rates['slab_limit_distance'] = (float)$slab_limit;
        $original_rates['day_rate'] = (float)$day_rate;
        $original_rates['extra_rate'] = (float)$extra_rate;
        $original_rates['extra_rate_ac'] = (float)$extra_rate_ac;
        
        $message = "Editing existing service rate for " . htmlspecialchars($selected_op_code) . " (Vehicle: " . htmlspecialchars($edit_vehicle_no) . ")";
        $message_type = 'info';

    } else {
        $message = "Error: Service Rate not found for editing.";
        $message_type = 'error';
        $is_edit_mode = false;
    }
}


// --- 4. Handle Form Submission (Insertion/Update Logic) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 4a. Retrieve submitted data
    $is_post_edit = isset($_POST['is_edit_mode_flag']); 
    
    $selected_op_code = $_POST['op_code'] ?? ''; 
    $new_vehicle_no = $_POST['vehicle_no'] ?? '';
    $original_vehicle_no = $_POST['original_vehicle_no'] ?? $new_vehicle_no; 
    $original_op_code = $_POST['original_op_code'] ?? $selected_op_code; 
    $selected_supplier_code = $_POST['supplier_code'] ?? ''; // CHANGED

    // Retrieve original rates that were stored in a hidden field during edit mode
    $original_rates_post = [
        'supplier_code' => (string)($_POST['original_supplier_code'] ?? ''), // CHANGED
        'slab_limit_distance' => (float)($_POST['original_slab_limit'] ?? 0),
        'day_rate' => (float)($_POST['original_day_rate'] ?? 0),
        'extra_rate' => (float)($_POST['original_extra_rate'] ?? 0),
        'extra_rate_ac' => (float)($_POST['original_extra_rate_ac'] ?? 0)
    ];

    // Slab/Rate fields are optional (default to 0 if empty)
    $slab_limit = $_POST['slab_limit_distance'] ?? 0;
    $day_rate = $_POST['day_rate'] ?? 0;
    $extra_rate = $_POST['extra_rate'] ?? 0;
    $extra_rate_ac = $_POST['extra_rate_ac'] ?? 0; 

    // 4b. Validation
    if (empty($selected_op_code) || empty($new_vehicle_no) || empty($selected_supplier_code)) { // Supplier Code added to required checks
        $message = "Service Type (Full Rate Code), Vehicle Number, and Supplier Code are required.";
        $message_type = 'error';
    } elseif (strlen($selected_op_code) < 4 || substr($selected_op_code, 2, 1) !== '-') {
        $message = "Full Rate Code must include a valid 2-letter prefix followed by a dash (e.g., NE-001V).";
        $message_type = 'error';
    } elseif (!in_array($new_vehicle_no, $all_vehicle_numbers) && !$is_post_edit) {
        // Enforce server-side check that the vehicle number exists in the main vehicles table (for new records)
          $message = "The entered vehicle number does not exist in the master vehicle list. It cannot be added.";
          $message_type = 'error';
    } elseif (!array_key_exists($selected_supplier_code, $all_suppliers)) { // CHANGED: Validate Supplier Code exists
        $message = "The selected Supplier Code is invalid.";
        $message_type = 'error';
    } else {
        // --- START CONDITIONAL VALIDATION (Based on user requirement) ---
        $op_prefix = substr($selected_op_code, 0, 2);
        
        // DH, NH need slab_limit_distance
        $required_slab = in_array($op_prefix, ['DH', 'NH']); 
        // ONLY NE needs day_rate
        $required_day_rate = ($op_prefix === 'NE'); 
        
        if ($required_slab && (empty($slab_limit) || (float)$slab_limit <= 0)) {
            $message = "Slab Limit (km) is required for Service Type **" . htmlspecialchars($op_prefix) . "**, and must be greater than zero.";
            $message_type = 'error';
        } elseif ($required_day_rate && (empty($day_rate) || (float)$day_rate <= 0)) {
            $message = "Day Rate (Rs.) is required for Service Type **" . htmlspecialchars($op_prefix) . "**, and must be greater than zero.";
            $message_type = 'error';
        } else {
        // --- END CONDITIONAL VALIDATION ---
            try {
                // Prepare data types
                $selected_supplier_code = (string)$selected_supplier_code; // CHANGED
                $slab_limit = (float)$slab_limit;
                $day_rate = (float)$day_rate;
                $extra_rate = (float)$extra_rate;
                $extra_rate_ac = (float)$extra_rate_ac;
                $is_active = 1;

                $vehicle_changed = $is_post_edit && ($new_vehicle_no !== $original_vehicle_no);

                // Start Transaction 
                $conn->begin_transaction(); 

                // --- SCENARIO 1: Vehicle Number was changed (Treat as delete old + insert new) ---
                if ($vehicle_changed) {
                    // Log the DELETE action for the OLD record
                    $delete_record_id = $original_op_code . '/' . $original_vehicle_no;
                    // Log the DELETE action with a summary entry
                    log_detailed_audit_entry($conn, 'op_services', $delete_record_id, 'DELETE', $logged_in_user_id, '**RECORD REPLACED**', $original_vehicle_no, $new_vehicle_no);

                    // Delete the old record based on the original key
                    $delete_sql = "DELETE FROM op_services WHERE op_code = ? AND vehicle_no = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("ss", $original_op_code, $original_vehicle_no);
                    $delete_stmt->execute();
                    
                    // Then proceed to insert the new one
                }
                
                // --- SCENARIO 2: Standard UPSERT (Insert new record or Update existing record) ---
                // NOTE: 'supplier_code' is used in the INSERT/UPDATE query.
                $sql = "INSERT INTO op_services (op_code, vehicle_no, supplier_code, slab_limit_distance, day_rate, extra_rate, extra_rate_ac, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                            supplier_code = VALUES(supplier_code),
                            slab_limit_distance = VALUES(slab_limit_distance),
                            day_rate = VALUES(day_rate),
                            extra_rate = VALUES(extra_rate),
                            extra_rate_ac = VALUES(extra_rate_ac),
                            is_active = VALUES(is_active)"; 

                $stmt = $conn->prepare($sql);
                // The types string is updated to reflect the string supplier_code: "sssddddi"
                $types = "sssddddi"; 
                // $params is updated to include $selected_supplier_code
                $params = [$selected_op_code, $new_vehicle_no, $selected_supplier_code, $slab_limit, $day_rate, $extra_rate, $extra_rate_ac, $is_active];

                if ($stmt->bind_param($types, ...$params) && $stmt->execute()) {
                    $status_message = '';
                    $action_type = '';
                    $current_record_id = $selected_op_code . '/' . $new_vehicle_no;
                    
                    if ($is_post_edit) {
                        $status_message = "Service Rate for $new_vehicle_no ($selected_op_code) updated successfully!";
                        $action_type = 'UPDATE';
                        
                        // AUDIT LOGGING FOR SUPPLIER_CODE
                        log_detailed_audit_entry($conn, 'op_services', $current_record_id, $action_type, $logged_in_user_id, 'supplier_code', $original_rates_post['supplier_code'], $selected_supplier_code);
                        
                        log_detailed_audit_entry($conn, 'op_services', $current_record_id, $action_type, $logged_in_user_id, 'slab_limit_distance', $original_rates_post['slab_limit_distance'], $slab_limit);
                        log_detailed_audit_entry($conn, 'op_services', $current_record_id, $action_type, $logged_in_user_id, 'day_rate', $original_rates_post['day_rate'], $day_rate);
                        log_detailed_audit_entry($conn, 'op_services', $current_record_id, $action_type, $logged_in_user_id, 'extra_rate', $original_rates_post['extra_rate'], $extra_rate);
                        log_detailed_audit_entry($conn, 'op_services', $current_record_id, $action_type, $logged_in_user_id, 'extra_rate_ac', $original_rates_post['extra_rate_ac'], $extra_rate_ac);
                        
                    } else {
                        $status_message = "Service Rate for $new_vehicle_no ($selected_op_code) added successfully!";
                        $action_type = 'INSERT';
                        
                        // AUDIT LOGGING FOR INSERT: Log only a single summary entry
                        $insert_record_id = $selected_op_code . '/' . $new_vehicle_no;
                        log_detailed_audit_entry(
                            $conn, 
                            'op_services', 
                            $insert_record_id, 
                            $action_type, 
                            $logged_in_user_id, 
                            '**NEW RECORD**', // New field_name for summary
                            'N/A', 
                            $insert_record_id // new_value is the composite key
                        );
                    }

                    $conn->commit(); // Commit the transaction
                    
                    // Redirect to op_services.php with success message (PRG pattern)
                    header("Location: op_services.php?status=success&message=" . urlencode($status_message)); 
                    exit();
                } else {
                    $conn->rollback(); // Rollback on failed statement execution
                    throw new Exception('Database error: ' . $stmt->error); 
                }
            } catch (Exception $e) {
                // Rollback is implicitly handled if not committed, but better to be explicit
                if ($conn->in_transaction) $conn->rollback(); 
                
                $message = "Error: " . $e->getMessage();
                $message_type = 'error';
                // Set the vehicle number and supplier back for form persistence
                $selected_vehicle_no = $new_vehicle_no; 
                $selected_supplier_code = $_POST['supplier_code'] ?? ''; // CHANGED
                // Also set other rates back for form persistence
                $extra_rate = $_POST['extra_rate'] ?? 0;
                $extra_rate_ac = $_POST['extra_rate_ac'] ?? 0;
            }
        }
    }
} else {
    // If not a POST request, ensure $selected_vehicle_no and $selected_supplier_code holds the value loaded in edit mode
    $selected_vehicle_no = $selected_vehicle_no; 
    $selected_supplier_code = $selected_supplier_code; // CHANGED
}


// Check for incoming status messages from the redirect
if (isset($_GET['status'])) { 
    $message = $_GET['message'] ?? ''; 
    $message_type = $_GET['status'] ?? ''; 
} 

// Include header/navbar after handling form submission
include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit_mode ? 'Edit Service Rate' : 'Add New Service Rate'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* CSS for the toast notification */
        #toast-container { 
            position: fixed; top: 1rem; right: 1rem; z-index: 2000; 
            display: flex; flex-direction: column; align-items: flex-end; 
        } 
        .toast { 
            display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; 
            border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
            color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; 
            transform: translateY(-20px); opacity: 0; 
        } 
        .toast.show { transform: translateY(0); opacity: 1; } 
        .toast.success { background-color: #4CAF50; } 
        .toast.error { background-color: #F44336; } 
        .toast.info { background-color: #2196F3; } 
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; } 
        /* Added class for required fields */
        .conditionally-required { color: red; } 
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
    <div class="container max-w-3xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">
            <?php echo $is_edit_mode ? 'Edit Service' : 'Add New Service'; ?>
        </h1>
        
        <form action="add_op_service.php" method="POST" class="space-y-6"> 
            
            <?php if ($is_edit_mode): ?>
                <input type="hidden" name="is_edit_mode_flag" value="1">
                <input type="hidden" name="original_vehicle_no" value="<?php echo htmlspecialchars($selected_vehicle_no); ?>">
                <input type="hidden" name="original_op_code" value="<?php echo htmlspecialchars($selected_op_code); ?>">
                
                <input type="hidden" name="original_supplier_code" value="<?php echo htmlspecialchars($original_rates['supplier_code']); ?>"> <input type="hidden" name="original_slab_limit" value="<?php echo htmlspecialchars($original_rates['slab_limit_distance']); ?>">
                <input type="hidden" name="original_day_rate" value="<?php echo htmlspecialchars($original_rates['day_rate']); ?>">
                <input type="hidden" name="original_extra_rate" value="<?php echo htmlspecialchars($original_rates['extra_rate']); ?>">
                <input type="hidden" name="original_extra_rate_ac" value="<?php echo htmlspecialchars($original_rates['extra_rate_ac']); ?>">
            <?php endif; ?>

            <div> 
                <label for="op_code_base" class="block text-sm font-medium text-gray-700">Service Type (Prefix) <span class="text-red-500">*</span></label> 
                <select id="op_code_base" name="op_code_base" required onchange="filterVehicles(this.value); updateRequiredFields(this.value);" 
                    class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2 
                    <?php echo $is_edit_mode ? 'bg-gray-200 cursor-not-allowed' : 'focus:border-indigo-500 focus:ring-indigo-500'; ?>"
                    <?php echo $is_edit_mode ? 'disabled' : ''; // Disable Prefix selection in EDIT mode ?>> 
                    <option value="">-- Select Code --</option> 
                    <?php foreach ($opcodes as $op_data): ?> 
                        <option value="<?php echo htmlspecialchars($op_data['code']); ?>" <?php echo (substr($selected_op_code, 0, 2) === $op_data['code']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($op_data['code']) . ' - ' . htmlspecialchars($op_data['description']); ?>
                        </option> 
                    <?php endforeach; ?> 
                </select> 
            </div> 
            
            <div> 
                <label for="supplier_code" class="block text-sm font-medium text-gray-700">Supplier <span class="text-red-500">*</span></label> 
                <select id="supplier_code" name="supplier_code" required 
                    class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2 focus:border-indigo-500 focus:ring-indigo-500"> 
                    <option value="">-- Select Supplier --</option> 
                    <?php 
                    foreach ($all_suppliers as $code => $supplier): // CHANGED: $id to $code
                        $selected = ((string)$selected_supplier_code === (string)$code) ? 'selected' : ''; // CHANGED: $selected_supplier_id to $selected_supplier_code
                    ?> 
                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $selected; ?>>
                            <?php echo htmlspecialchars($supplier) . ' (' . htmlspecialchars($code) . ')'; ?>
                        </option> 
                    <?php endforeach; ?> 
                </select> 
            </div> 
            <div class="grid md:grid-cols-2 gap-6">
                <div> 
                    <label for="op_code" class="block text-sm font-medium text-gray-700">Full Rate Code (e.g., NE-001V) <span class="text-red-500">*</span></label> 
                    <input 
                        type="text" 
                        id="op_code" 
                        name="op_code" 
                        value="<?php echo htmlspecialchars($selected_op_code); ?>" 
                        required 
                        maxlength="10" 
                        <?php echo $is_edit_mode ? 'readonly' : ''; // Rate Code must remain Readonly ?>
                        class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2 
                        <?php echo $is_edit_mode ? 'bg-gray-200 cursor-not-allowed' : 'focus:border-indigo-500 focus:ring-indigo-500'; ?>" 
                        placeholder="Select Service Type to get prefix...">
                    <p id="op_code_help" class="mt-1 text-xs text-gray-500">
                        <?php echo $is_edit_mode ? 'Rate Code cannot be edited.' : ''; ?>
                    </p> 
                </div> 

                <div> 
                    <label for="vehicle_no_input" class="block text-sm font-medium text-gray-700">Vehicle Number <span class="text-red-500">*</span></label> 
                    
                    <input
                        type="text"
                        id="vehicle_no_input"
                        name="vehicle_no"
                        required
                        list="vehicle_options"
                        value="<?php echo htmlspecialchars($selected_vehicle_no); ?>"
                        class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm sm:text-sm p-2 focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="Type or select a vehicle number"
                        maxlength="15"
                    >

                    <datalist id="vehicle_options">
                        <?php
                        // Populate Datalist with all valid vehicle numbers
                        foreach ($all_vehicle_numbers as $vehicle_no) {
                            echo '<option value="' . htmlspecialchars($vehicle_no) . '">';
                        }
                        ?>
                    </datalist>

                    <p id="vehicle_no_help" class="mt-1 text-xs text-gray-500">
                        <?php echo $is_edit_mode ? 'You can change the vehicle, which replaces the old record.' : ''; ?>
                    </p>
                    <p id="validation_vehicle_error" class="mt-1 text-xs text-red-600 hidden">
                        The entered vehicle number must be a valid, existing vehicle.
                    </p>
                </div> 
                </div>

            <div class="grid md:grid-cols-4 gap-6">
                <div> 
                    <label for="slab_limit_distance" id="label_slab_limit" class="block text-sm font-medium text-gray-700">
                        Slab Limit (km) <span id="req_slab" class="hidden conditionally-required">*</span>
                    </label> 
                    <input type="number" step="0.01" id="slab_limit_distance" name="slab_limit_distance" value="<?php echo htmlspecialchars($slab_limit); ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" placeholder="e.g., 100.00"> 
                </div> 

                <div> 
                    <label for="day_rate" id="label_day_rate" class="block text-sm font-medium text-gray-700">
                        Day Rate (Rs.) <span id="req_day_rate" class="hidden conditionally-required">*</span>
                    </label> 
                    <input type="number" step="0.01" id="day_rate" name="day_rate" value="<?php echo htmlspecialchars($day_rate); ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" placeholder="e.g., 5000.00"> 
                </div> 

                <div> 
                    <label for="extra_rate" class="block text-sm font-medium text-gray-700">Extra Rate (Non-AC)</label> 
                    <input type="number" step="0.01" id="extra_rate" name="extra_rate" value="<?php echo htmlspecialchars($extra_rate); ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" placeholder="e.g., 50.00" required> 
                </div> 

                <div> 
                    <label for="extra_rate_ac" class="block text-sm font-medium text-gray-700">Extra Rate (AC)</label> 
                    <input type="number" step="0.01" id="extra_rate_ac" name="extra_rate_ac" value="<?php echo htmlspecialchars($extra_rate_ac); ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" placeholder="e.g., 60.00" required> 
                </div> 
                </div>
            
            <div class="flex justify-between mt-6"> 
                <a href="op_services.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300">
                    Go Back
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    <?php echo $is_edit_mode ? 'Update Rate' : 'Save Rate'; ?>
                </button> 
            </div> 
        </form> 
    </div>
</div>

<script>
    // Toast Function (Unchanged)
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
        setTimeout(() => { 
            toast.classList.remove('show'); 
            toast.addEventListener('transitionend', () => toast.remove(), { once: true }); 
        }, 3000); 
    } 

    // Function to enforce the prefix (Unchanged)
    const opCodeInput = document.getElementById('op_code');
    const opCodeBaseSelect = document.getElementById('op_code_base');
    const isEditMode = opCodeInput.readOnly; 

    function enforcePrefix(inputElement) {
        if (isEditMode) return; 

        const opCodeBase = opCodeBaseSelect.value;
        const requiredPrefix = opCodeBase ? opCodeBase + '-' : '';
        const currentValue = inputElement.value;

        if (!requiredPrefix) return; 

        if (!currentValue.startsWith(requiredPrefix)) {
            let correctedValue = requiredPrefix;
            
            if (currentValue.length > requiredPrefix.length) {
                const newSuffix = currentValue.substring(currentValue.indexOf('-') > -1 ? currentValue.indexOf('-') + 1 : requiredPrefix.length);
                correctedValue = requiredPrefix + newSuffix;
            }

            inputElement.value = correctedValue;
        }
        
        inputElement.setSelectionRange(inputElement.value.length, inputElement.value.length);
    }


    // Dynamic Vehicle Filtering Function (Unchanged logic)
    function filterVehicles(opCodeBase) {
        const fullOpCodeInput = document.getElementById('op_code');
        const opCodeHelp = document.getElementById('op_code_help');
        
        // 1. Handle Prefix and listener (Only in ADD mode, or if base code changes)
        if (!isEditMode) {
            const prefix = opCodeBase ? opCodeBase + '-' : '';
            fullOpCodeInput.value = prefix;
            opCodeHelp.textContent = prefix ? `Prefix is set to ${prefix}. You can only edit the characters after the dash.` : '';
            
            if (opCodeBase) {
                fullOpCodeInput.focus();
                fullOpCodeInput.setSelectionRange(fullOpCodeInput.value.length, fullOpCodeInput.value.length);
                fullOpCodeInput.oninput = () => enforcePrefix(fullOpCodeInput);
            } else {
                opCodeHelp.textContent = '';
                fullOpCodeInput.oninput = null; 
            }
        }
    }

    // --- CLIENT-SIDE CONDITIONAL REQUIRED LOGIC (Unchanged, based on final user requirement) ---
    function updateRequiredFields(opCodeBase) {
        const slabInput = document.getElementById('slab_limit_distance');
        const dayRateInput = document.getElementById('day_rate');
        const reqSlab = document.getElementById('req_slab');
        const reqDayRate = document.getElementById('req_day_rate');

        // Reset all
        slabInput.removeAttribute('required');
        dayRateInput.removeAttribute('required');
        reqSlab.classList.add('hidden');
        reqDayRate.classList.add('hidden');
        
        // Also remove minimum constraint when not required
        slabInput.removeAttribute('min');
        dayRateInput.removeAttribute('min');

        // DH and NH need Slab Limit (min 0.01)
        if (opCodeBase === 'DH' || opCodeBase === 'NH') {
            slabInput.setAttribute('required', 'required');
            reqSlab.classList.remove('hidden');
            slabInput.setAttribute('min', '0.01');
        }
        
        // ONLY NE needs Day Rate (min 0.01)
        if (opCodeBase === 'NE') {
            dayRateInput.setAttribute('required', 'required');
            reqDayRate.classList.remove('hidden');
            dayRateInput.setAttribute('min', '0.01');
        }
    }
    // ----------------------------------------------------


    // --- CLIENT-SIDE VALIDATION FOR DATALIST INPUT (Unchanged) ---
    const form = document.querySelector('form');
    const vehicleInput = document.getElementById('vehicle_no_input');
    const datalist = document.getElementById('vehicle_options');
    const vehicleErrorMsg = document.getElementById('validation_vehicle_error');


    form.addEventListener('submit', function(event) {
        const inputValue = vehicleInput.value.trim();
        let isValid = false;

        // Check if the input value matches any option in the datalist
        for (const option of datalist.options) {
            if (option.value === inputValue) {
                isValid = true;
                break;
            }
        }
        
        // Also allow the currently selected vehicle in edit mode to pass if it was the original value
        if (inputValue === '<?php echo $selected_vehicle_no; ?>') {
            isValid = true;
        }


        // If the value is NOT valid (not in the list)
        if (!isValid) {
            event.preventDefault(); // Stop the form from submitting
            vehicleErrorMsg.style.display = 'block'; // Show the error message
            vehicleInput.focus(); 
            vehicleInput.classList.add('border-red-500', 'ring-red-500'); // Highlight red
        } else {
            // If valid, ensure the error message is hidden and style is reset
            vehicleErrorMsg.style.display = 'none';
            vehicleInput.classList.remove('border-red-500', 'ring-red-500'); 
        }
    });

    // Remove error message when the user starts typing or selecting again
    vehicleInput.addEventListener('input', function() {
        vehicleErrorMsg.style.display = 'none';
        vehicleInput.classList.remove('border-red-500', 'ring-red-500'); 
    });
    // ----------------------------------------------------


    // Call the functions on load to set the initial state
    document.addEventListener('DOMContentLoaded', () => {
        // Set the correct prefix code on load (needed for AJAX call and conditional fields)
        const initialOpCodeBase = '<?php echo substr($selected_op_code, 0, 2); ?>';
        opCodeBaseSelect.value = initialOpCodeBase;
        
        if (initialOpCodeBase) {
            filterVehicles(initialOpCodeBase);
            updateRequiredFields(initialOpCodeBase); // <--- Call new function on load
        }
    });

</script>

<?php if (isset($message) && $message_type): ?> 
<script> 
    showToast('<?php echo htmlspecialchars($message); ?>', '<?php echo htmlspecialchars($message_type); ?>'); 
</script> 
<?php endif; ?> 

</body> 
</html>