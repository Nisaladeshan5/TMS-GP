<?php
// Start the session at the very beginning to check login status
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Check if the user is currently logged in 
// Assume a session variable 'loggedin' is set upon successful login.
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

// Set timezone to Sri Lanka
date_default_timezone_set('Asia/Colombo');

// --- 1. Configuration & Includes (Database ONLY) ---
include('../../includes/db.php'); 

// Ensure $conn is available for all handlers
if (!isset($conn) || $conn->connect_error) {
    if (isset($_POST['action'])){
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed for AJAX handler.']);
        exit;
    }
}

// --- 2. AJAX Handlers (Report, Out, VIEW Employees, and NEW: CANCEL TRIP) ---

// Handler for REPORT and OUT actions (Existing)
if (isset($_POST['action']) && isset($_POST['trip_id']) && ($_POST['action'] == 'report' || $_POST['action'] == 'out')) {

    if (!isset($conn) || $conn->connect_error) { /* ... db check ... */ }
    
    $trip_id = (int)$_POST['trip_id'];
    $current_time = date('Y-m-d H:i:s'); 

    $update_field = ($_POST['action'] == 'report') ? 'report_time' : 'out_time';

    $update_sql = "UPDATE extra_vehicle_register SET {$update_field} = ? WHERE id = ?";
    $stmt_update = $conn->prepare($update_sql);
    
    if ($stmt_update === false) { 
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        exit;
    }
    
    $stmt_update->bind_param("si", $current_time, $trip_id);
    
    header('Content-Type: application/json');
    if ($stmt_update->execute()) {
        echo json_encode(['success' => true, 'message' => ucfirst($_POST['action']) . ' time recorded successfully.', 'time' => date('H:i', strtotime($current_time))]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt_update->error]);
    }
    $stmt_update->close();
    
    exit; 
} 

// Handler for VIEW EMPLOYEES action (Existing)
if (isset($_POST['action']) && $_POST['action'] == 'view_employees' && isset($_POST['trip_id'])) {
     // 🔒 GUARDRAIL: Only allow logged-in users to view details

    if (!isset($conn) || $conn->connect_error) { 
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed for AJAX.']);
        exit;
    }
    
    $trip_id = (int)$_POST['trip_id'];

    $employee_sql = "SELECT 
                        etr.emp_id, 
                        e.calling_name 
                    FROM ev_trip_employee_reasons AS etr
                    INNER JOIN employee AS e ON etr.emp_id = e.emp_id
                    WHERE etr.trip_id = ?";

    $stmt_emp = $conn->prepare($employee_sql);

    if ($stmt_emp === false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt_emp->bind_param("i", $trip_id);
    $stmt_emp->execute();
    $result_emp = $stmt_emp->get_result();

    $employees = [];
    while ($row_emp = $result_emp->fetch_assoc()) {
        $employees[] = [
            'emp_id' => $row_emp['emp_id'],
            'calling_name' => $row_emp['calling_name']
        ];
    }

    $stmt_emp->close();

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'employees' => $employees]);
    exit;
}

// 🆕 NEW Handler for CANCEL TRIP action
if (isset($_POST['action']) && $_POST['action'] == 'cancel_trip' && isset($_POST['trip_id'])) {
    // 🔒 GUARDRAIL: Only allow logged-in users to perform this sensitive action
    if (!$is_logged_in) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Authentication required for deletion.']);
        exit;
    }
    
    if (!isset($conn) || $conn->connect_error) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed for AJAX.']);
        exit;
    }
    
    $trip_id = (int)$_POST['trip_id'];
    $conn->autocommit(FALSE); // Start transaction

    $success = true;
    $message = "Trip cancelled successfully.";

    try {
        // 1. Delete records from ev_trip_employee_reasons (Child table)
        $sql_employees = "DELETE FROM ev_trip_employee_reasons WHERE trip_id = ?";
        $stmt_employees = $conn->prepare($sql_employees);
        if ($stmt_employees === false) {
            throw new Exception("Employee statement preparation failed: " . $conn->error);
        }
        $stmt_employees->bind_param("i", $trip_id);
        if (!$stmt_employees->execute()) {
            throw new Exception("Employee deletion failed: " . $stmt_employees->error);
        }
        $stmt_employees->close();

        // 2. Delete the trip record from extra_vehicle_register (Parent table)
        $sql_trip = "DELETE FROM extra_vehicle_register WHERE id = ?";
        $stmt_trip = $conn->prepare($sql_trip);
        if ($stmt_trip === false) {
            throw new Exception("Trip statement preparation failed: " . $conn->error);
        }
        $stmt_trip->bind_param("i", $trip_id);
        if (!$stmt_trip->execute()) {
            throw new Exception("Trip deletion failed: " . $stmt_trip->error);
        }
        $stmt_trip->close();

        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $success = false;
        $message = "Cancellation failed: " . $e->getMessage();
    }

    $conn->autocommit(TRUE); // End transaction

    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}


// Fallback for any unknown POST request
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($_POST['action'])]);
    exit;
}

// --- 3. HTML Includes (Only runs if it's NOT an AJAX request) ---
include('../../includes/header.php'); 
include('../../includes/navbar.php'); 

// --- 4. Input & Filter Initialization ---
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// --- 5. Database Query Preparation (for Display) ---
$sql = "SELECT 
            evr.id, evr.supplier_code, s.supplier, evr.vehicle_no, evr.date, evr.shift, evr.from_location, evr.to_location, 
            evr.time AS schedule_time, evr.report_time, evr.out_time, evr.in_time, evr.ac_status, evr.route
        FROM extra_vehicle_register AS evr
        INNER JOIN supplier AS s ON evr.supplier_code = s.supplier_code";

$conditions = ["evr.done = 0"]; // KEY: Only show trips that are not completed (done=0)
$params = [];
$types = "";

if (!empty($filter_month) && !empty($filter_year)) {
    $conditions[] = "MONTH(date) = ? AND YEAR(date) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= "ii";
}

if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY date ASC, schedule_time ASC"; 

// --- 6. Database Execution ---
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Prepare failed: " . $conn->error);
}

if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extra Vehicle Schedule</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* Custom styles for modal transition */
        .modal { transition: opacity 0.25s ease; }
        .modal-active { opacity: 1; pointer-events: auto; }
        .modal-inactive { opacity: 0; pointer-events: none; }
        .btn-green { background-color: #10B981; }
        .btn-green:hover { background-color: #059669; }
        .btn-yellow { background-color: #FBBF24; }
        .btn-yellow:hover { background-color: #F59E0B; }
        .btn-red { background-color: #EF4444; }
        .btn-red:hover { background-color: #DC2626; }
    </style>
</head>
<body class="bg-gray-100">
<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] fixed top-0 z-10">
    <div class="text-lg font-semibold ml-3">Extra Vehicle Schedule</div>
    <div class="flex gap-4">
        <a href="extra_vehicle.php" class="hover:text-yellow-400 font-medium transition duration-150">View Register</a>
    </div>
</div>

<div class="container pt-16 pb-4" style="width: 82%; margin-left: 17%; margin-right: 1%;">
    <p class="text-4xl font-extrabold text-gray-800 mb-3 text-center">Extra Vehicle Schedule</p>

    <form method="GET" action="" class="mb-4 flex justify-center w-full">
        <div class="flex items-center space-x-6">
            <select id="month" name="month" class="border border-gray-300 p-2 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <?php
                $months = [
                    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June',
                    '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                ];
                foreach ($months as $num => $name) {
                    $selected = ($num == $filter_month) ? 'selected' : '';
                    echo "<option value='{$num}' {$selected}>{$name}</option>";
                }
                ?>
            </select>
            
            <select id="year" name="year" class="border border-gray-300 p-2 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <?php
                $current_year = date('Y');
                for ($y = $current_year; $y >= 2020; $y--) {
                    $selected = ($y == $filter_year) ? 'selected' : '';
                    echo "<option value='{$y}' {$selected}>{$y}</option>";
                }
                ?>
            </select>

            <button type="submit" class="bg-blue-600 text-white px-3 py-2 rounded-lg hover:bg-blue-700 transition duration-150 ease-in-out font-medium">Apply Filter</button>
        </div>
    </form>
    
    <div class="overflow-x-auto bg-white shadow-xl rounded-lg w-full">
        <table class="min-w-full table-auto divide-y divide-gray-200">
            <thead class="bg-blue-700 text-white">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">Date</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">Scheduled Time</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">Vehicle No</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">Route</th>
                    <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider">A/C</th>
                    
                        <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider">Employees</th>
                        <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider">Report</th>
                        <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider">Out</th>
                        <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider">In</th>
                    <?php if ($is_logged_in): // Show Action headers ONLY if logged in ?>
                        <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $trip_id = $row['id'];
                        
                        $ac_status_display = ($row['ac_status'] == 1) ? 
                            "<span class='font-bold text-green-600'>Yes</span>" : 
                            "<span class='text-gray-500'>No</span>";

                        $report_time = $row['report_time'] ? date('H:i', strtotime($row['report_time'])) : null;
                        $out_time = $row['out_time'] ? date('H:i', strtotime($row['out_time'])) : null;
                        
                        $report_disabled = $report_time ? 'disabled' : '';
                        $out_disabled = $out_time || !$report_time ? 'disabled' : '';
                        $in_disabled = !$out_time ? 'disabled' : '';

                        echo "<tr class='hover:bg-red-50 transition duration-100' id='row-{$trip_id}' data-route='{$row['route']}' data-ac-status='{$row['ac_status']}'>";
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm'>{$row['date']}</td>";
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm font-semibold text-red-700'>" . date('H:i', strtotime($row['schedule_time'])) . "</td>"; 
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm font-medium'>{$row['vehicle_no']}</td>";
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm'>{$row['route']}</td>";
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm text-center'>{$ac_status_display}</td>";

                        
                            // Employees Button
                            echo "<td class='px-3 py-2 whitespace-nowrap text-center'>";
                            echo "<button data-action='view' data-trip-id='{$trip_id}' class='action-view-btn px-3 py-1 rounded text-xs font-medium transition duration-150 bg-gray-500 text-white hover:bg-gray-600'>";
                            echo "View</button>";
                            echo "</td>";

                            // Report Button
                            echo "<td class='px-3 py-2 whitespace-nowrap text-center'>";
                            echo "<button data-action='report' data-trip-id='{$trip_id}' class='action-btn px-3 py-1 rounded text-xs font-medium transition duration-150 w-24 {$report_disabled} ";
                            echo $report_time ? "bg-gray-300 text-gray-600 cursor-not-allowed" : "btn-yellow text-white hover:bg-yellow-600";
                            echo "'><span class='time-display'>{$report_time}</span><span class='btn-text'>" . ($report_time ? '' : 'Report') . "</span></button>";
                            echo "</td>";
                            
                            // Out Button
                            echo "<td class='px-3 py-2 whitespace-nowrap text-center'>";
                            echo "<button data-action='out' data-trip-id='{$trip_id}' class='action-btn px-3 py-1 rounded text-xs font-medium transition duration-150 w-24 {$out_disabled} ";
                            echo $out_time ? "bg-gray-300 text-gray-600 cursor-not-allowed" : "bg-blue-600 text-white hover:bg-blue-700";
                            echo "'><span class='time-display'>{$out_time}</span><span class='btn-text'>" . ($out_time ? '' : 'Out') . "</span></button>";
                            echo "</td>";
                            
                            // In Button
                            echo "<td class='px-3 py-2 whitespace-nowrap text-center'>";
                            echo "<button data-action='in' data-trip-id='{$trip_id}' class='action-in-btn px-3 py-1 rounded text-xs font-medium transition duration-150 {$in_disabled} ";
                            echo $in_disabled ? "bg-gray-300 text-gray-600 cursor-not-allowed" : "btn-green text-white hover:bg-green-700";
                            echo "'>In</button>";
                            echo "</td>";
                            
                        if ($is_logged_in): // Show Action buttons ONLY if logged in
                            // CANCEL/Delete Button
                            echo "<td class='px-3 py-2 whitespace-nowrap text-center'>";
                            echo "<button data-action='cancel' data-trip-id='{$trip_id}' class='action-cancel-btn px-3 py-1 rounded text-xs font-medium transition duration-150 btn-red text-white hover:bg-red-700'>";
                            echo "Delete</button>";
                            echo "</td>";
                        
                        endif; // End Logged In Check for Action Buttons
                        
                        echo "</tr>";
                    }
                } else {
                    $colspan = $is_logged_in ? 10 : 9; // Adjust colspan based on login status
                    echo "<tr><td colspan='{$colspan}' class='px-3 py-4 text-center text-gray-500'>No extra vehicle trips scheduled for this period.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="inModal" class="modal modal-inactive fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
    <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
    <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded-lg shadow-2xl z-50 overflow-y-auto">
        <div class="py-4 text-left px-6">
            <div class="flex justify-between items-center pb-4 border-b border-gray-200">
                <p class="text-2xl font-bold text-gray-800">Trip Arrival (IN)</p>
                <div class="modal-close cursor-pointer z-50 p-1 rounded-full hover:bg-gray-100 transition">
                    <svg class="fill-current text-gray-600" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path></svg>
                </div>
            </div>

            <form id="in-form" class="my-4 space-y-4">
                <input type="hidden" id="modal-trip-id" name="trip_id">
                <input type="hidden" id="modal-route-code" name="route_code">
                
                <div>
                    <label for="distance" class="block text-sm font-medium text-gray-700">Distance (Km)</label>
                    <input type="number" step="0.01" min="0" id="distance" name="distance" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div id="ac-status-group">
                    <label class="block text-sm font-medium text-gray-700">A/C Status</label>
                    <div class="mt-1 flex space-x-4">
                        <label class="inline-flex items-center">
                            <input type="radio" name="ac_status" value="1" required class="form-radio text-green-600">
                            <span class="ml-2">A/C</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="ac_status" value="0" required class="form-radio text-red-600">
                            <span class="ml-2">Non-A/C</span>
                        </label>
                    </div>
                </div>

                <div id="in-message" class="text-center font-medium"></div>

                <div class="flex justify-between pt-2 border-t border-gray-200">
                    <button type="button" class="modal-close-in px-6 bg-gray-500 p-2 rounded-lg text-white font-medium hover:bg-gray-600 transition duration-150">Cancel</button>
                    <button type="submit" id="in-submit-btn" class="px-6 btn-green p-2 rounded-lg text-white font-medium transition duration-150">Confirm Arrival</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="employeeModal" class="modal modal-inactive fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
    <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
    <div class="modal-container bg-white w-11/12 md:max-w-sm mx-auto rounded-lg shadow-2xl z-50 overflow-y-auto">
        <div class="py-4 text-left px-6">
            <div class="flex justify-between items-center pb-4 border-b border-gray-200">
                <p class="text-xl font-bold text-gray-800">Trip Employees</p>
                <div class="modal-close-emp cursor-pointer z-50 p-1 rounded-full hover:bg-gray-100 transition">
                    <svg class="fill-current text-gray-600" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path></svg>
                </div>
            </div>

            <div id="employee-list-content" class="my-4 space-y-2">
                <p class="text-gray-500 text-center">Loading employee data...</p>
            </div>

            <div class="flex justify-end pt-2 border-t border-gray-200">
                <button type="button" class="modal-close-emp px-6 bg-gray-500 p-2 rounded-lg text-white font-medium hover:bg-gray-600 transition duration-150">Close</button>
            </div>
        </div>
    </div>
</div>

<div id="cancelModal" class="modal modal-inactive fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
    <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
    <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded-lg shadow-2xl z-50 overflow-y-auto transform transition-all duration-300 scale-95 opacity-0">
        <div class="py-6 text-left px-6">
            <div class="flex items-center pb-3 border-b border-red-200">
                <svg class="w-8 h-8 text-red-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <p class="text-xl font-bold text-red-700">Warning!</p>
            </div>
            
            <input type="hidden" id="cancel-trip-id-holder">

            <div class="my-4 text-gray-700">
                <p class="mb-3 font-semibold text-lg">This action is irreversible. Deleting this record will permanently remove the main trip details and all associated data (including employee names and reasons) from the database. This data cannot be restored.</p>
            </div>

            <div class="flex justify-end pt-4 border-t border-gray-100">
                <button type="button" id="cancel-no-btn" class="px-6 bg-gray-500 p-2 rounded-lg text-white font-medium hover:bg-gray-600 transition duration-150 mr-3">Cancel</button>
                <button type="button" id="cancel-yes-btn" class="px-6 btn-red p-2 rounded-lg text-white font-medium transition duration-150">Yes, Delete</button>
            </div>
            <div id="cancel-message" class="text-center mt-3 font-medium hidden"></div>
        </div>
    </div>
</div>
<script src="schedule_extra_vehicle.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const cancelModal = document.getElementById('cancelModal');
    const modalContainer = cancelModal ? cancelModal.querySelector('.modal-container') : null;
    
    if (cancelModal && modalContainer) {
        // Event listener for opening the modal (triggered by jQuery)
        cancelModal.addEventListener('modal:open', () => {
            modalContainer.classList.remove('scale-95', 'opacity-0');
            modalContainer.classList.add('scale-100', 'opacity-100');
        });

        // Event listener for closing the modal (triggered by jQuery)
        cancelModal.addEventListener('modal:close', () => {
            modalContainer.classList.remove('scale-100', 'opacity-100');
            modalContainer.classList.add('scale-95', 'opacity-0');
        });
    }
});
</script>

</body>
</html>