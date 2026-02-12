<?php
// Note: Assuming '../../includes/db.php' connects to the 'transport' database
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php'); 

// --- LOGIN CHECK LOGIC ---
if (session_status() == PHP_SESSION_NONE) { session_start(); }
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
if (!isset($user_role) && isset($_SESSION['role'])) { $user_role = $_SESSION['role']; }

// ---------------------------------------------------------
// 1. FETCH SUPPLIERS FOR DROPDOWN (NEW CODE)
// ---------------------------------------------------------
$suppliers_list = [];
$sql_sup = "SELECT supplier_code, supplier FROM supplier WHERE is_active = 1 ORDER BY supplier ASC";
$res_sup = $conn->query($sql_sup);
if ($res_sup) {
    while ($row_s = $res_sup->fetch_assoc()) {
        $suppliers_list[] = $row_s;
    }
}

// --- HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // A. HANDLE STATUS TOGGLE
    if (isset($_POST['toggle_status'])) {
        if ($is_logged_in && isset($user_role) && in_array($user_role, ['super admin', 'admin', 'developer'])) {
            $record_id = $_POST['record_id'];
            $current_status = $_POST['current_status'];
            $new_status = ($current_status == 1) ? 0 : 1; 
            $redirect_date = $_POST['redirect_date'];

            $update_sql = "UPDATE factory_transport_vehicle_register SET is_active = ? WHERE id = ?";
            $stmt_update = $conn->prepare($update_sql);
            $stmt_update->bind_param('ii', $new_status, $record_id);

            if ($stmt_update->execute()) {
                $msg = ($new_status == 1) ? "Shift Enabled Successfully" : "Shift Disabled Successfully";
                echo "<script>window.location.href='?status=success&message=" . urlencode($msg) . "&date=" . $redirect_date . "';</script>";
                exit();
            } else {
                echo "<script>window.location.href='?status=error&message=" . urlencode("Update Failed") . "&date=" . $redirect_date . "';</script>";
                exit();
            }
        } else {
            echo "<script>window.location.href='?status=error&message=" . urlencode("Permission Denied") . "';</script>";
            exit();
        }
    }

    // B. HANDLE SUPPLIER UPDATE (NEW LOGIC)
    if (isset($_POST['update_supplier_action'])) {
        if ($is_logged_in && isset($user_role) && in_array($user_role, ['super admin', 'admin', 'developer'])) {
            $record_id     = $_POST['record_id'];
            $new_sup_code  = $_POST['supplier_code'];
            $redirect_date = $_POST['redirect_date'];

            // Note: Updating 'factory_transport_vehicle_register'
            $upd_sup_sql = "UPDATE factory_transport_vehicle_register SET supplier_code = ? WHERE id = ?";
            $stmt_sup    = $conn->prepare($upd_sup_sql);
            $stmt_sup->bind_param('si', $new_sup_code, $record_id);

            if ($stmt_sup->execute()) {
                echo "<script>window.location.href='?status=success&message=" . urlencode("Supplier Updated Successfully") . "&date=" . $redirect_date . "';</script>";
                exit();
            } else {
                echo "<script>window.location.href='?status=error&message=" . urlencode("Supplier Update Failed") . "&date=" . $redirect_date . "';</script>";
                exit();
            }
        }
    }
}

// --- DATA FETCHING ---
$filterDate = date('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['date'])) { $filterDate = $_POST['date']; } 
elseif (isset($_GET['date'])) { $filterDate = $_GET['date']; }

// ---------------------------------------------------------
// NEW LOGIC: FETCH MAX PAYMENT MONTH/YEAR FOR FACTORY
// ---------------------------------------------------------
$max_payment_val = 0; 
$sql_pay = "SELECT year, month FROM monthly_payments_f ORDER BY year DESC, month DESC LIMIT 1";
$result_pay = $conn->query($sql_pay);

if ($result_pay && $result_pay->num_rows > 0) {
    $row_pay = $result_pay->fetch_assoc();
    $max_payment_val = ($row_pay['year'] * 100) + $row_pay['month'];
}

// 1. Fetch Running Chart details
// ADDED: f.supplier_code to the SELECT list
$sql = "SELECT f.id, f.vehicle_no, f.actual_vehicle_no, f.vehicle_status, f.shift, f.driver_NIC, f.driver_status, 
        f.supplier_code, -- <--- NEW COLUMN
        r.route AS route_name, r.route_code, f.in_time, f.date, f.is_active
        FROM factory_transport_vehicle_register f
        LEFT JOIN route r ON f.route = r.route_code
        WHERE DATE(f.date) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $filterDate);
$stmt->execute();
$result = $stmt->get_result();


$cross_check_data = [];
$sql_tms = "SELECT actual_vehicle_no, driver_NIC, route, shift 
            FROM cross_check 
            WHERE DATE(date) = ?";
$stmt_tms = $conn->prepare($sql_tms);
$stmt_tms->bind_param('s', $filterDate);
$stmt_tms->execute();
$result_tms = $stmt_tms->get_result();

while ($row_tms = $result_tms->fetch_assoc()) {
    $route_tms = $row_tms['route'] ?? null; 
    $shift_tms = $row_tms['shift'] ?? null;
    if ($route_tms === null || $shift_tms === null) continue;
    
    $key_tms = $filterDate . '-' . $route_tms . '-' . $shift_tms;
    $cross_check_data[$key_tms] = [
        'actual_vehicle_no' => $row_tms['actual_vehicle_no'] ?? null,
        'driver_NIC' => $row_tms['driver_NIC'] ?? null
    ];
}

// Group and merge logic
$grouped = [];
while ($row = $result->fetch_assoc()) {
    $routeName = $row['route_name'] ?? 'ROUTE_N/A (' . $row['route_code'] . ')';
    $group_key = $row['date'] . '-' . $routeName; 
    $cross_check_key = $row['date'] . '-' . $row['route_code'] . '-' . $row['shift'];

    // Check against the cross-check data
    $is_match = true;
    if (isset($cross_check_data[$cross_check_key])) {
        $tms_record = $cross_check_data[$cross_check_key];
        if ($row['actual_vehicle_no'] !== $tms_record['actual_vehicle_no'] || $row['driver_NIC'] !== $tms_record['driver_NIC']) {
            $is_match = false; 
        }
    } else {
        $is_match = false; 
    }

    if (!isset($grouped[$group_key])) {
        $grouped[$group_key] = [
            'date' => $row['date'],
            'route_name' => $routeName,
            'route_code' => $row['route_code'],
            'morning_id' => null, 'morning_active' => null, 
            'morning_vehicle' => null, 'morning_actual_vehicle' => null, 
            'morning_vehicle_status' => null, 'morning_driver' => null, 
            'morning_driver_status' => null, 'morning_in' => null,
            'morning_supplier' => null, // <--- New Key
            'evening_id' => null, 'evening_active' => null,
            'evening_vehicle' => null, 'evening_actual_vehicle' => null, 
            'evening_vehicle_status' => null, 'evening_driver' => null, 
            'evening_driver_status' => null, 'evening_in' => null,
            'evening_supplier' => null, // <--- New Key
            'morning_match' => true, 
            'evening_match' => true 
        ];
    }
    
    // Assign shift data
    $shift_prefix = $row['shift'] . '_'; // 'morning_' or 'evening_'
    
    if ($row['shift'] === 'morning' || $row['shift'] === 'evening') {
        $grouped[$group_key][$shift_prefix . 'id'] = $row['id'];
        $grouped[$group_key][$shift_prefix . 'active'] = $row['is_active'];
        $grouped[$group_key][$shift_prefix . 'vehicle'] = $row['vehicle_no'];
        $grouped[$group_key][$shift_prefix . 'actual_vehicle'] = $row['actual_vehicle_no'];
        $grouped[$group_key][$shift_prefix . 'supplier'] = $row['supplier_code']; // <--- Store Supplier
        $grouped[$group_key][$shift_prefix . 'vehicle_status'] = $row['vehicle_status'];
        $grouped[$group_key][$shift_prefix . 'driver'] = $row['driver_NIC'];
        $grouped[$group_key][$shift_prefix . 'driver_status'] = $row['driver_status'];
        $grouped[$group_key][$shift_prefix . 'in'] = $row['in_time'];
        $grouped[$group_key][$shift_prefix . 'match'] = $is_match;
    }
}

uksort($grouped, function($key1, $key2) use ($grouped) {
    $route_code1 = $grouped[$key1]['route_code'] ?? '';
    $route_code2 = $grouped[$key2]['route_code'] ?? '';
    $num1_str = substr($route_code1, 6, 3);
    $num2_str = substr($route_code2, 6, 3);
    $num1 = is_numeric($num1_str) ? (int)$num1_str : 0;
    $num2 = is_numeric($num2_str) ? (int)$num2_str : 0;
    if ($num1 == $num2) { return 0; }
    return ($num1 < $num2) ? -1 : 1;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Factory Vehicle Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* CSS for toast */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); color: white; transform: translateY(-20px); opacity: 0; transition: all 0.3s; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; } .toast.warning { background-color: #ff9800; } .toast.error { background-color: #F44336; }
        
        .mismatch-row { background-color: #eed7d7ff !important; color: black !important; }
        .mismatch-row td { border-color: #fca5a5; }

        /* Modal Animation */
        .modal { transition: opacity 0.25s ease; }
        body.modal-active { overflow-x: hidden; overflow-y: visible !important; }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-50 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Factory Transport Vehicle Registers
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium"> 
        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
        <a href="?date=<?php echo date('Y-m-d', strtotime($filterDate . ' -1 day')); ?>" 
           class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150" 
           title="Previous Day">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
        </a>

        <form method="POST" class="flex items-center mx-1">
            <input type="date" name="date" 
                   value="<?php echo htmlspecialchars($filterDate); ?>" 
                   onchange="this.form.submit()" 
                   class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer text-center w-32 appearance-none">
        </form>

        <a href="?date=<?php echo date('Y-m-d', strtotime($filterDate . ' +1 day')); ?>" 
           class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150" 
           title="Next Day">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
        </a>
    </div>
        <?php if ($is_logged_in): ?>
            <a href="unmark_factory_route_attendace.php" class="text-gray-300 hover:text-red-400 transition" title="Unmark Routes">
                Unmark Routes
            </a>
            
            <a href="factory_route_attendace.php" class="text-gray-300 hover:text-white transition flex items-center gap-1">
                <span>Attendance</span>
            </a>
            
            <?php if (isset($user_role) && in_array($user_role, ['super admin', 'admin', 'developer'])): ?>
                <a href="add_records/factory/adjustment_factory.php" class="text-gray-300 hover:text-yellow-400 transition">
                    Adjustments
                </a>

                <a href="add_records/factory/add_factory_record.php" 
                   class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md shadow-md transition transform hover:scale-105">
                    <span>Add Record</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="flex flex-col items-center mt-2 w-[85%] ml-[15%] p-2">

    <div class="overflow-x-auto overflow-y-auto bg-white shadow-md rounded-md mb-6 w-full max-h-[87vh] mx-auto">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-2 py-2 text-center w-12 shadow-md">St</th>
                    
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-2 text-left shadow-md">Date</th>
                    
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-2 text-left shadow-md">Route</th>
                    
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-2 text-left shadow-md">Assigned Vehicle No</th>
                    
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-2 text-left shadow-md">Vehicle No</th>
                    
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-2 text-left shadow-md">Driver</th>
                    
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-2 text-left shadow-md">Morning Time</th> 
                    
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-2 text-left shadow-md">Evening Time</th>
                    
                    <?php if ($is_logged_in && isset($user_role) && in_array($user_role, ['super admin', 'admin', 'developer'])): ?>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-2 text-center shadow-md">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($grouped)) {
                    $colspan = ($is_logged_in && isset($user_role) && in_array($user_role, ['super admin', 'admin', 'developer'])) ? 9 : 8;
                    echo "<tr><td colspan='{$colspan}' class='border px-4 py-2 text-center text-gray-500'>No factory transport vehicle record available for today.</td></tr>";
                } else {
                    foreach ($grouped as $entry) {
                        
                        // Prepare time strings
                        $morning_time = ($entry['morning_in'] !== null) ? date('H:i', strtotime($entry['morning_in'])) : '-';
                        $evening_time = ($entry['evening_in'] !== null) ? date('H:i', strtotime($entry['evening_in'])) : '-';

                        // Variables for JS Modal (Added Suppliers)
                        $js_route = htmlspecialchars($entry['route_name'], ENT_QUOTES);
                        $js_date = $entry['date'];
                        $js_m_id = $entry['morning_id'] ?? 'null';
                        $js_m_stat = $entry['morning_active'] ?? 'null';
                        $js_m_sup  = $entry['morning_supplier'] ?? ''; // New

                        $js_e_id = $entry['evening_id'] ?? 'null';
                        $js_e_stat = $entry['evening_active'] ?? 'null';
                        $js_e_sup  = $entry['evening_supplier'] ?? ''; // New

                        // --- VISUAL LOGIC FOR DISABLED STATE ---
                        $is_m_disabled = ($entry['morning_id'] !== null && $entry['morning_active'] == 0);
                        $is_e_disabled = ($entry['evening_id'] !== null && $entry['evening_active'] == 0);
                        
                        // Generate Status Badge (A / M / E)
                        $status_badge = "";
                        if ($is_m_disabled && $is_e_disabled) {
                            $status_badge = "<span class='inline-block w-5 h-5 leading-5 text-center text-xs font-bold bg-white text-red-600 rounded-full border border-red-200' title='All Shifts Disabled'>A</span>";
                        } elseif ($is_m_disabled) {
                            $status_badge = "<span class='inline-block w-5 h-5 leading-5 text-center text-xs font-bold bg-white text-red-600 rounded-full border border-red-200' title='Morning Disabled'>M</span>";
                        } elseif ($is_e_disabled) {
                            $status_badge = "<span class='inline-block w-5 h-5 leading-5 text-center text-xs font-bold bg-white text-red-600 rounded-full border border-red-200' title='Evening Disabled'>E</span>";
                        }

                        // Dim Text Style Logic
                        $m_text_style = ($is_m_disabled) ? 'text-gray-400 italic' : '';
                        $e_text_style = ($is_e_disabled) ? 'text-gray-400 italic' : '';

                        $has_morning = $entry['morning_vehicle'] !== null;
                        $has_evening = $entry['evening_vehicle'] !== null || $entry['evening_driver'] !== null;

                        $is_full_match = $has_morning && $has_evening && 
                                         ($entry['morning_actual_vehicle'] === $entry['evening_actual_vehicle']) && 
                                         ($entry['morning_driver'] === $entry['evening_driver']);

                        $morning_vehicle_cell_class = ($entry['morning_vehicle_status'] == 0) ? 'bg-red-200' : '';
                        $morning_driver_cell_class = ($entry['morning_driver_status'] == 0) ? 'bg-red-200' : '';
                        $evening_vehicle_cell_class = ($entry['evening_vehicle_status'] == 0) ? 'bg-red-200' : '';
                        $evening_driver_cell_class = ($entry['evening_driver_status'] == 0) ? 'bg-red-200' : '';

                        // --- BUTTON GENERATION (Updated to pass Suppliers) ---
                        $action_btn = "";
                        $show_action_column = ($is_logged_in && isset($user_role) && in_array($user_role, ['super admin', 'admin', 'developer']));
                        
                        if ($show_action_column) {
                            // Check Max Payment Month Validation
                            $record_ts = strtotime($entry['date']);
                            $record_val = (date('Y', $record_ts) * 100) + date('m', $record_ts);
                            $is_editable = ($record_val > $max_payment_val);

                            if ($is_editable) {
                                // Updated onClick with supplier vars
                                $action_btn = "<td class='border px-2 py-2 text-center align-middle'>
                                    <button onclick=\"openModal('$js_date', '$js_route', $js_m_id, $js_m_stat, '$js_m_sup', $js_e_id, $js_e_stat, '$js_e_sup')\" 
                                    class='bg-blue-100 hover:bg-blue-200 text-blue-800 border border-blue-300 font-bold py-1 px-3 rounded shadow-sm text-xs transition duration-150 ease-in-out'>
                                            Manage
                                    </button>
                                </td>";
                            } else {
                                $action_btn = "<td class='border px-2 py-2 text-center align-middle'>
                                    <span class='text-gray-400 text-xs italic' title='Payment finalized'>Locked</span>
                                </td>";
                            }
                        }

                        // --- RENDER ROWS ---
                        if ($is_full_match) {
                            $combined_row_class = (!$entry['morning_match'] || !$entry['evening_match']) ? 'mismatch-row' : 'bg-white hover:bg-gray-50';
                            $vehicle_cell_class = ($entry['morning_vehicle_status'] == 0 || $entry['evening_vehicle_status'] == 0) ? 'bg-red-200' : '';
                            $driver_cell_class = ($entry['morning_driver_status'] == 0 || $entry['evening_driver_status'] == 0) ? 'bg-red-200' : '';
                            $assigned_vehicle_display = $entry['morning_vehicle'] ?? $entry['evening_vehicle']; 

                            echo "<tr class='{$combined_row_class}'>
                                <td class='border px-2 py-2 text-center'>{$status_badge}</td>
                                <td class='border px-4 py-2'>{$entry['date']}</td>
                                <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                <td class='border px-4 py-2'>{$assigned_vehicle_display}</td>
                                <td class='border px-4 py-2 {$vehicle_cell_class}'>
                                    <span class='{$m_text_style}'>{$entry['morning_actual_vehicle']}</span>
                                </td>
                                <td class='border px-4 py-2 {$driver_cell_class}'>
                                    <span class='{$m_text_style}'>{$entry['morning_driver']}</span>
                                </td>
                                <td class='border px-4 py-2 {$m_text_style}'>{$morning_time}</td>
                                <td class='border px-4 py-2 {$e_text_style}'>{$evening_time}</td>
                                {$action_btn}
                            </tr>";
                            
                        } else {
                            // Split Rows
                            if ($has_morning) {
                                $morning_row_class = (!$entry['morning_match']) ? 'mismatch-row' : 'bg-white hover:bg-gray-50';
                                
                                $m_btn_only = "";
                                if ($action_btn) {
                                    if (strpos($action_btn, 'Manage') !== false) {
                                         // Updated onClick
                                         $m_btn_only = "<td class='border px-2 py-2 text-center align-middle'>
                                            <button onclick=\"openModal('$js_date', '$js_route', $js_m_id, $js_m_stat, '$js_m_sup', null, null, null)\" 
                                            class='bg-blue-100 hover:bg-blue-200 text-blue-800 border border-blue-300 font-bold py-1 px-3 rounded shadow-sm text-xs'>Manage</button>
                                        </td>";
                                    } else {
                                        $m_btn_only = $action_btn;
                                    }
                                }

                                echo "<tr class='{$morning_row_class}'>
                                    <td class='border px-2 py-2 text-center'>{$status_badge}</td>
                                    <td class='border px-4 py-2'>{$entry['date']}</td>
                                    <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                    <td class='border px-4 py-2'>{$entry['morning_vehicle']}</td>
                                    <td class='border px-4 py-2 {$morning_vehicle_cell_class} {$m_text_style}'>{$entry['morning_actual_vehicle']}</td>
                                    <td class='border px-4 py-2 {$morning_driver_cell_class} {$m_text_style}'>{$entry['morning_driver']}</td>
                                    <td class='border px-4 py-2 {$m_text_style}'>{$morning_time}</td>
                                    <td class='border px-4 py-2'>-</td> 
                                    {$m_btn_only}
                                </tr>";
                            } 

                            if ($has_evening) {
                                $evening_row_class = (!$entry['evening_match']) ? 'mismatch-row' : 'bg-white hover:bg-gray-50';
                                
                                $e_btn_only = "";
                                if ($action_btn) {
                                    if (strpos($action_btn, 'Manage') !== false) {
                                         // Updated onClick
                                         $e_btn_only = "<td class='border px-2 py-2 text-center align-middle'>
                                            <button onclick=\"openModal('$js_date', '$js_route', null, null, null, $js_e_id, $js_e_stat, '$js_e_sup')\" 
                                            class='bg-blue-100 hover:bg-blue-200 text-blue-800 border border-blue-300 font-bold py-1 px-3 rounded shadow-sm text-xs'>Manage</button>
                                        </td>";
                                    } else {
                                        $e_btn_only = $action_btn;
                                    }
                                }

                                echo "<tr class='{$evening_row_class}'>
                                    <td class='border px-2 py-2 text-center'>{$status_badge}</td>
                                    <td class='border px-4 py-2'>{$entry['date']}</td>
                                    <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                    <td class='border px-4 py-2'>{$entry['evening_vehicle']}</td>
                                    <td class='border px-4 py-2 {$evening_vehicle_cell_class} {$e_text_style}'>{$entry['evening_actual_vehicle']}</td>
                                    <td class='border px-4 py-2 {$evening_driver_cell_class} {$e_text_style}'>{$entry['evening_driver']}</td>
                                    <td class='border px-4 py-2'>-</td> 
                                    <td class='border px-4 py-2 {$e_text_style}'>{$evening_time}</td>
                                    {$e_btn_only}
                                </tr>";
                            }
                        } 
                    } 
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="statusModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
    <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50" onclick="closeModal()"></div>
    
    <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded shadow-lg z-50 overflow-y-auto">
        <div class="modal-content py-4 text-left px-6">
            <div class="flex justify-between items-center pb-3">
                <div>
                    <p class="text-2xl font-bold text-gray-800" id="modalRouteName">Route Name</p>
                    <p class="text-sm text-gray-500" id="modalDate">Date</p>
                </div>
                <div class="cursor-pointer z-50" onclick="closeModal()">
                    <svg class="fill-current text-black" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18">
                        <path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path>
                    </svg>
                </div>
            </div>
            <hr class="mb-4">
            <div class="space-y-4">
                
                <div id="morningSection" class="bg-gray-50 p-4 rounded-lg border border-gray-200 hidden">
                    <div class="flex justify-between items-center mb-2">
                        <span class="font-semibold text-gray-700">☀️ Morning Shift</span>
                        <span id="morningStatusText" class="text-xs font-bold px-2 py-1 rounded"></span>
                    </div>

                    <form method="POST" class="mb-3 border-b pb-3">
                        <input type="hidden" name="record_id" id="morningSupId">
                        <input type="hidden" name="update_supplier_action" value="1">
                        <input type="hidden" name="redirect_date" value="<?php echo htmlspecialchars($filterDate); ?>">
                        <label class="block text-xs text-gray-500 mb-1">Supplier</label>
                        <div class="flex gap-2">
                            <select name="supplier_code" id="morningSupplierSelect" class="w-full border rounded text-sm p-1">
                                <option value="">Select Supplier</option>
                                <?php foreach($suppliers_list as $sup): ?>
                                    <option value="<?php echo $sup['supplier_code']; ?>"><?php echo $sup['supplier']; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="bg-indigo-600 text-white text-xs px-2 rounded hover:bg-indigo-700">Save</button>
                        </div>
                    </form>

                    <form method="POST">
                        <input type="hidden" name="record_id" id="morningId">
                        <input type="hidden" name="current_status" id="morningStatus">
                        <input type="hidden" name="redirect_date" value="<?php echo htmlspecialchars($filterDate); ?>">
                        <input type="hidden" name="toggle_status" value="1">
                        <button type="submit" id="morningBtn" class="w-full py-2 px-4 rounded text-white font-bold transition duration-200"></button>
                    </form>
                </div>

                <div id="eveningSection" class="bg-gray-50 p-4 rounded-lg border border-gray-200 hidden">
                    <div class="flex justify-between items-center mb-2">
                        <span class="font-semibold text-gray-700">🌙 Evening Shift</span>
                        <span id="eveningStatusText" class="text-xs font-bold px-2 py-1 rounded"></span>
                    </div>

                    <form method="POST" class="mb-3 border-b pb-3">
                        <input type="hidden" name="record_id" id="eveningSupId">
                        <input type="hidden" name="update_supplier_action" value="1">
                        <input type="hidden" name="redirect_date" value="<?php echo htmlspecialchars($filterDate); ?>">
                        <label class="block text-xs text-gray-500 mb-1">Supplier</label>
                        <div class="flex gap-2">
                            <select name="supplier_code" id="eveningSupplierSelect" class="w-full border rounded text-sm p-1">
                                <option value="">Select Supplier</option>
                                <?php foreach($suppliers_list as $sup): ?>
                                    <option value="<?php echo $sup['supplier_code']; ?>"><?php echo $sup['supplier']; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="bg-indigo-600 text-white text-xs px-2 rounded hover:bg-indigo-700">Save</button>
                        </div>
                    </form>

                    <form method="POST">
                        <input type="hidden" name="record_id" id="eveningId">
                        <input type="hidden" name="current_status" id="eveningStatus">
                        <input type="hidden" name="redirect_date" value="<?php echo htmlspecialchars($filterDate); ?>">
                        <input type="hidden" name="toggle_status" value="1">
                        <button type="submit" id="eveningBtn" class="w-full py-2 px-4 rounded text-white font-bold transition duration-200"></button>
                    </form>
                </div>

                <div id="noDataMessage" class="hidden text-center text-gray-500 py-4">No actionable records found.</div>
            </div>
            <div class="flex justify-end pt-2 mt-4">
                <button onclick="closeModal()" class="px-4 py-2 bg-gray-300 rounded-lg text-gray-700 hover:bg-gray-400">Close</button>
            </div>
        </div>
    </div>
</div>

<div id="toast-container"></div>
</body>
<script>
    // Toast Function
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        let iconPath = type === 'success' ? '<path d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />' : '<path d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126z" />';
        toast.innerHTML = `<svg class="w-6 h-6 mr-2" stroke="currentColor" fill="none" viewBox="0 0 24 24" stroke-width="1.5">${iconPath}</svg><span>${message}</span>`;
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => { toast.classList.remove('show'); toast.remove(); }, 5000); 
    }
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') && urlParams.get('message')) {
        showToast(decodeURIComponent(urlParams.get('message')), urlParams.get('status'));
        window.history.replaceState(null, null, window.location.pathname + (urlParams.get('date') ? '?date=' + urlParams.get('date') : ''));
    }

    // Modal Functions (UPDATED FOR SUPPLIER)
    function openModal(date, route, mId, mStat, mSup, eId, eStat, eSup) {
        document.getElementById('modalRouteName').textContent = route;
        document.getElementById('modalDate').textContent = date;
        const mSection = document.getElementById('morningSection');
        const eSection = document.getElementById('eveningSection');
        const noData = document.getElementById('noDataMessage');
        mSection.classList.add('hidden');
        eSection.classList.add('hidden');
        noData.classList.add('hidden');
        let hasAction = false;

        if (mId !== null) {
            hasAction = true;
            mSection.classList.remove('hidden');
            document.getElementById('morningId').value = mId;
            document.getElementById('morningSupId').value = mId; // For Supplier Update
            document.getElementById('morningStatus').value = mStat;
            
            // Set Dropdown Value
            document.getElementById('morningSupplierSelect').value = mSup;

            const btn = document.getElementById('morningBtn');
            const txt = document.getElementById('morningStatusText');
            if (mStat == 1) {
                btn.textContent = 'Disable Morning Shift';
                btn.className = 'w-full py-2 px-4 rounded text-white font-bold transition duration-200 bg-red-500 hover:bg-red-600 shadow-lg';
                txt.textContent = 'ACTIVE';
                txt.className = 'text-xs font-bold px-2 py-1 rounded bg-green-100 text-green-800';
            } else {
                btn.textContent = 'Enable Morning Shift';
                btn.className = 'w-full py-2 px-4 rounded text-white font-bold transition duration-200 bg-green-500 hover:bg-green-600 shadow-lg';
                txt.textContent = 'DISABLED';
                txt.className = 'text-xs font-bold px-2 py-1 rounded bg-red-100 text-red-800';
            }
        }
        if (eId !== null) {
            hasAction = true;
            eSection.classList.remove('hidden');
            document.getElementById('eveningId').value = eId;
            document.getElementById('eveningSupId').value = eId; // For Supplier Update
            document.getElementById('eveningStatus').value = eStat;
            
            // Set Dropdown Value
            document.getElementById('eveningSupplierSelect').value = eSup;

            const btn = document.getElementById('eveningBtn');
            const txt = document.getElementById('eveningStatusText');
            if (eStat == 1) {
                btn.textContent = 'Disable Evening Shift';
                btn.className = 'w-full py-2 px-4 rounded text-white font-bold transition duration-200 bg-red-500 hover:bg-red-600 shadow-lg';
                txt.textContent = 'ACTIVE';
                txt.className = 'text-xs font-bold px-2 py-1 rounded bg-green-100 text-green-800';
            } else {
                btn.textContent = 'Enable Evening Shift';
                btn.className = 'w-full py-2 px-4 rounded text-white font-bold transition duration-200 bg-green-500 hover:bg-green-600 shadow-lg';
                txt.textContent = 'DISABLED';
                txt.className = 'text-xs font-bold px-2 py-1 rounded bg-red-100 text-red-800';
            }
        }
        if (!hasAction) noData.classList.remove('hidden');
        const modal = document.getElementById('statusModal');
        modal.classList.remove('opacity-0', 'pointer-events-none');
        document.body.classList.add('modal-active');
    }

    function closeModal() {
        const modal = document.getElementById('statusModal');
        modal.classList.add('opacity-0', 'pointer-events-none');
        document.body.classList.remove('modal-active');
    }
    document.onkeydown = function(evt) {
        evt = evt || window.event;
        if (evt.keyCode == 27) closeModal();
    };
</script>
</html>