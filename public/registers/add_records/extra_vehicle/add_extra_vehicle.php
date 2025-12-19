<?php
require_once '../../../../includes/session_check.php';
// add_extra_vehicle_trip.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}


// --- Configuration & Includes ---
include('../../../../includes/db.php');
include('../../../../includes/header.php'); 
include('../../../../includes/navbar.php'); 

// Set timezone
date_default_timezone_set('Asia/Colombo');

// Define common reasons
$reasons = [
    "Machine Breakdown",
    "Emergency Transport",
    "Late Finish/Overtime",
    "Special Task Requirement"
];

// --- DATA FETCH FOR DROPDOWNS ---
$suppliers_data = []; 
$route_codes_data = []; 
$op_codes = [];

// 1. Fetch Suppliers
$sql_suppliers = "SELECT supplier AS supplier_name, supplier_code FROM supplier ORDER BY supplier_name ASC";
$result_suppliers = $conn->query($sql_suppliers);
if ($result_suppliers && $result_suppliers->num_rows > 0) {
    while ($row = $result_suppliers->fetch_assoc()) {
        $suppliers_data[] = ['code' => $row['supplier_code'], 'name' => $row['supplier_name']];
    }
}

// 2. Fetch Route Codes and Names
$sql_routes = "SELECT route, route_code FROM route ORDER BY route_code ASC";
$result_routes = $conn->query($sql_routes);
if ($result_routes && $result_routes->num_rows > 0) {
    while ($row = $result_routes->fetch_assoc()) {
        $route_codes_data[] = [
            'code' => $row['route_code'],
            'name' => $row['route']
        ];
    }
}

// 3. Fetch Op Codes
$sql_ops = "SELECT op_code FROM op_services GROUP BY op_code ORDER BY op_code ASC";
$result_ops = $conn->query($sql_ops);
if ($result_ops && $result_ops->num_rows > 0) {
    while ($row = $result_ops->fetch_assoc()) {
        $op_codes[] = $row['op_code'];
    }
}
// --- END DATA FETCH ---

$message = ""; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Sanitize and Determine the single code for insertion
    $vehicle_no = trim($_POST['vehicle_no'] ?? '');
    
    // *** FIX FOR MISSING SUPPLIER CODE: Check both hidden field (auto-filled) and select box (manual) ***
    $auto_filled_supplier = trim($_POST['supplier_code_hidden'] ?? ''); 
    $manually_selected_supplier = trim($_POST['supplier_code'] ?? '');     
    
    if (!empty($auto_filled_supplier)) {
        $supplier_code = $auto_filled_supplier;
    } else {
        $supplier_code = $manually_selected_supplier;
    }
    // *** END FIX ***

    $from_location = trim($_POST['from_location'] ?? '');
    $to_location = trim($_POST['to_location'] ?? '');
    $trip_date = trim($_POST['date'] ?? '');
    $trip_time = trim($_POST['time'] ?? '');
    $distance = floatval($_POST['distance'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0); 

    // Get values from the dropdowns (only the selected/non-disabled one will be in $_POST)
    $selected_route_code = trim($_POST['route_code'] ?? '');
    $selected_op_code = trim($_POST['op_code'] ?? '');
    
    // *** A/C STATUS LOGIC: Get ac_status and ensure it's an integer (1 or 0) or null ***
    $ac_status_post = $_POST['ac_status'] ?? null;
    $ac_status = ($ac_status_post !== null && $ac_status_post !== '') ? (int)$ac_status_post : null;

    // --- PHP SAFETY LOGIC: Amount must be 0 if distance is 0 for rate-based trip ---
    if ($distance == 0) {
        $amount = 0; 
    }
    // --- END PHP SAFETY LOGIC ---

    // Determine the final value for the 'route' column (the one code that was selected)
    if (!empty($selected_route_code)) {
        $trip_code = $selected_route_code;
    } elseif (!empty($selected_op_code)) {
        $trip_code = $selected_op_code;
    } else {
        $trip_code = ''; // No code selected
    }
    
    // 2. Set done_flag
    $done_flag = ($distance > 0) ? 1 : 0;
    
    // 3. Get Employee/Reason Data
    $reason_groups = $_POST['reason_group'] ?? []; 
    $emp_id_groups = $_POST['emp_id_group'] ?? [];

    $skip_execution = false;
    $missing = [];
    
    // *** ROBUST VALIDATION CHECK ***
    if (empty($vehicle_no)) $missing[] = 'Vehicle No';
    if (empty($supplier_code)) $missing[] = 'Supplier Code';
    if (empty($from_location)) $missing[] = 'From Location';
    if (empty($to_location)) $missing[] = 'To Location';
    if (empty($trip_date)) $missing[] = 'Date';
    if (empty($trip_time)) $missing[] = 'Time';
    if (empty($trip_code)) $missing[] = 'Route/Op Code';
    if ($ac_status === null) $missing[] = 'A/C Status'; 
    if (empty($reason_groups)) $missing[] = 'Reason Groups';
    if (empty($emp_id_groups)) $missing[] = 'Employee ID Groups';

    if (!empty($missing)) {
        $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative text-sm font-bold' role='alert'>
                        🚨 VALIDATION FAILED! The following required fields are missing data: <br>
                        <span class='font-normal'>" . implode(', ', $missing) . "</span>
                    </div>";
        $skip_execution = true; 
    } 
    // *** END VALIDATION CHECK ***
    
    if (!$skip_execution) {
        // --- Start Transaction for Atomicity ---
        $conn->begin_transaction();
        
        try {
            // A. Insert into extra_vehicle_register (TRIP DATA)
            $sql_main = "INSERT INTO extra_vehicle_register 
                         (vehicle_no, supplier_code, from_location, to_location, date, time, distance, amount, done, route, ac_status) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"; 
            
            $stmt_main = $conn->prepare($sql_main);
            
            // Binding parameters: s(v_no), s(sup_code), s(from), s(to), s(date), s(time), d(distance), d(amount), i(done), s(route), i(ac_status)
            $stmt_main->bind_param("ssssssddisi", 
                $vehicle_no, $supplier_code, $from_location, $to_location, $trip_date, $trip_time, 
                $distance, $amount, $done_flag, 
                $trip_code, 
                $ac_status // This is now guaranteed to be 1 or 0 (int)
            );

            if (!$stmt_main->execute()) {
                throw new Exception("Error inserting trip details: " . $stmt_main->error);
            }

            // Get the ID of the newly inserted trip
            $trip_id = $conn->insert_id;
            $stmt_main->close();

            // B. Insert into ev_trip_employee_reasons (LOOPING THROUGH ALL REASON GROUPS)
            $sql_details = "INSERT INTO ev_trip_employee_reasons (trip_id, reason, emp_id) VALUES (?, ?, ?)";
            $stmt_details = $conn->prepare($sql_details);

            foreach ($reason_groups as $group_index => $reason) {
                $reason = trim($reason);

                if (!empty($reason) && isset($emp_id_groups[$group_index]) && is_array($emp_id_groups[$group_index])) {
                    
                    $found_employee = false;
                    foreach ($emp_id_groups[$group_index] as $emp_id) {
                        $clean_emp_id = trim($emp_id);
                        if (!empty($clean_emp_id)) {
                            $stmt_details->bind_param("iss", $trip_id, $reason, $clean_emp_id);
                            if (!$stmt_details->execute()) {
                                throw new Exception("Error inserting employee details for ID: {$clean_emp_id} with reason: {$reason}");
                            }
                            $found_employee = true;
                        }
                    }
                    if (!$found_employee) {
                         throw new Exception("Reason Group " . ($group_index + 1) . " requires at least one Employee ID.");
                    }
                } else {
                     if (!empty($reason)) {
                         throw new Exception("Reason Group " . ($group_index + 1) . " has a reason but no Employee ID.");
                     }
                     // Ignore if both reason and employee list are empty (in case of blank cloned groups)
                }
            }
            $stmt_details->close();

            $conn->commit();
            $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative text-sm' role='alert'>Vehicle trip and employee details added successfully!</div>";
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative text-sm' role='alert'>Error: " . $e->getMessage() . " (Transaction rolled back)</div>";
            error_log($e->getMessage()); 
        }
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
<body class="bg-gray-50 min-h-screen">

<div class="w-[85%] ml-[15%]">
    <div class="container pt-8 pb-6 mx-auto px-4 md:px-8">
        
        <h1 class="text-3xl font-extrabold text-gray-800 mb-2 text-center">🚗 New Extra Vehicle Trip Registration</h1>
        <p class="text-center text-gray-500 text-sm mb-6">Select a Route Name or an Operation Code.</p>
        
        <div class="mb-4">
            <?php echo $message; ?>
        </div>

        <form method="POST" action="" class="bg-white p-6 rounded-xl shadow-xl space-y-6 max-w-5xl mx-auto border border-gray-100">
            
            <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                <h3 class="text-xl font-bold border-b pb-2 mb-4 text-blue-800 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                    Trip Information
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    
                    <?php 
                        $input_class = "mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 text-sm focus:ring-blue-500 focus:border-blue-500 transition duration-150"; 
                        $label_class = "block text-xs font-medium text-gray-700"; 
                        $required_span = "<span class='text-red-500'>*</span>";
                    ?>
                    
                    <div class="md:col-span-2">
                        <label for="route_code" class="<?php echo $label_class; ?>">Route Name (Select ONLY one) <?php echo $required_span; ?></label>
                        <select id="route_code" name="route_code" class="<?php echo $input_class; ?>">
                            <option value="">-- Select Route Name --</option>
                            <?php foreach ($route_codes_data as $route): ?>
                                <option value="<?php echo htmlspecialchars($route['code']); ?>">
                                    <?php echo htmlspecialchars($route['name']); ?> (<?php echo htmlspecialchars($route['code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label for="op_code" class="<?php echo $label_class; ?>">Operation Code (Select ONLY one) <?php echo $required_span; ?></label>
                        <select id="op_code" name="op_code" class="<?php echo $input_class; ?>">
                            <option value="">-- Select Operation Code --</option>
                            <?php foreach ($op_codes as $code): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="vehicle_no" class="<?php echo $label_class; ?>">Vehicle No <?php echo $required_span; ?></label>
                        <input type="text" id="vehicle_no" name="vehicle_no" required class="<?php echo $input_class; ?>">
                    </div>
                    
                    <div>
                        <label for="supplier_code" class="<?php echo $label_class; ?>">Supplier <?php echo $required_span; ?></label>
                        <select id="supplier_code" name="supplier_code" required class="<?php echo $input_class; ?>">
                            <option value="">-- Select Supplier --</option>
                            <?php 
                            foreach ($suppliers_data as $supplier): ?>
                                <option value="<?php echo htmlspecialchars($supplier['code']); ?>">
                                    <?php echo htmlspecialchars($supplier['name']); ?> (<?php echo htmlspecialchars($supplier['code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span id="supplier_loading_status" class="text-xs text-red-500 italic hidden">Searching...</span>
                        <input type="hidden" id="hidden_supplier_code" name="supplier_code_hidden">
                    </div>

                    <div>
                        <label for="date" class="<?php echo $label_class; ?>">Date <?php echo $required_span; ?></label>
                        <input type="date" id="date" name="date" required value="<?php echo date('Y-m-d'); ?>" class="<?php echo $input_class; ?>">
                    </div>
                    
                    <div>
                        <label for="time" class="<?php echo $label_class; ?>">Time <?php echo $required_span; ?></label>
                        <input type="time" id="time" name="time" required value="<?php echo date('H:i'); ?>" class="<?php echo $input_class; ?>">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label for="from_location" class="<?php echo $label_class; ?>">From Location <?php echo $required_span; ?></label>
                        <input type="text" id="from_location" name="from_location" required class="<?php echo $input_class; ?>">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label for="to_location" class="<?php echo $label_class; ?>">To Location <?php echo $required_span; ?></label>
                        <input type="text" id="to_location" name="to_location" required class="<?php echo $input_class; ?>">
                    </div>
                    
                    <div>
                        <label for="ac_status" class="<?php echo $label_class; ?>">A/C Status <?php echo $required_span; ?></label>
                        <select id="ac_status" name="ac_status" required class="<?php echo $input_class; ?>">
                            <option value="">-- Select A/C Status --</option>
                            <option value="1">A/C</option>
                            <option value="0">Non A/C</option>
                        </select>
                    </div>

                    <div>
                        <label for="distance" class="<?php echo $label_class; ?>">Distance (Km)</label>
                        <input type="number" step="0.01" id="distance" name="distance" placeholder="0.00 (Optional, 0 for pending)" class="<?php echo $input_class; ?>">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label for="amount" class="<?php echo $label_class; ?>">Amount (LKR)</label>
                        <input 
                            type="number" 
                            step="0.01" 
                            id="amount" 
                            name="amount" 
                            placeholder="0.00 (Auto-calculated)" 
                            readonly 
                            class="<?php echo $input_class; ?> bg-gray-100" 
                            value="0.00"
                        >
                        <span id="amount_status" class="text-xs italic mt-1 block"></span>
                    </div>
                </div>
            </div>
            
            <div class="p-4 bg-indigo-50 rounded-lg border border-indigo-200">
                <h3 class="text-xl font-bold border-b pb-2 mb-4 text-indigo-800 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857m0 0a5.002 5.002 0 019.288 0M7 15v-2m4 2v-2m4 2v-2m-4-2H9v-2h6v2h-2zm-4-2V9m0-2h4m-4 2h4"></path></svg>
                    Employee & Reason Details
                </h3>

                <div id="reason-group-container" class="space-y-4">
                    <div class="reason-group p-4 border border-indigo-300 rounded-lg bg-white shadow-sm">
                        <div class="flex justify-between items-center mb-3">
                            <h4 class="text-md font-bold text-indigo-700">Reason Group 1</h4>
                            <button type="button" class="remove-group-btn text-red-500 hover:text-red-700 text-2xl font-light leading-none disabled:opacity-50 transition duration-150" disabled>&times;</button>
                        </div>
                        <div class="mb-3">
                            <label class="<?php echo $label_class; ?>">Reason for Trip <?php echo $required_span; ?></label>
                            <select name="reason_group[]" required class="reason-select <?php echo $input_class; ?>">
                                <option value="">-- Select Reason --</option>
                                <?php foreach ($reasons as $reason): ?>
                                    <option value="<?php echo htmlspecialchars($reason); ?>"><?php echo htmlspecialchars($reason); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="employee-list-container space-y-2 p-3 border-t border-gray-100 mt-3 pt-3">
                            <label class="block text-xs font-bold text-gray-700">Employee IDs for this Reason <?php echo $required_span; ?></label>
                            <div class="employee-input flex items-center space-x-2">
                                <input type="text" name="emp_id_group[0][]" placeholder="Employee ID 1 (e.g., SL001)" required class="emp-id-input flex-grow border border-gray-300 rounded-md shadow-sm p-2 text-sm focus:ring-green-500 focus:border-green-500">
                                <button type="button" class="remove-employee-btn text-gray-400 hover:text-red-600 text-xl font-light leading-none disabled:opacity-50 transition duration-150" disabled>&times;</button>
                            </div>
                        </div>
                        <button 
                            type="button" 
                            class="add-employee-btn-group bg-green-500 text-white px-3 py-1.5 rounded-lg hover:bg-green-600 transition duration-150 ease-in-out font-medium mt-3 text-xs shadow-md flex items-center ml-auto"
                        >
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            Add Another Employee
                        </button>
                    </div>
                </div>
                
                <button 
                    type="button" 
                    id="add-reason-group-btn" 
                    class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition duration-150 ease-in-out font-semibold text-sm mt-4 shadow-lg flex items-center ml-auto"
                >
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    Add Another Reason Group
                </button>
            </div>


            <div class="pt-4 border-t border-gray-200 mt-6 flex justify-between items-center">
                <button 
                    type="button" 
                    onclick="window.location.href='../../extra_vehicle.php'" 
                    class="bg-gray-400 text-white px-4 py-2 rounded-lg hover:bg-gray-500 transition duration-150 ease-in-out font-semibold text-md shadow-md flex items-center"
                >
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    Back
                </button>
                <button type="submit" class="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700 transition duration-150 ease-in-out font-semibold text-md shadow-lg transform hover:scale-[1.02] active:scale-[0.98]">
                    💾 Save Trip Record
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script src="add_extra_vehicle.js"></script>
</body>
</html>