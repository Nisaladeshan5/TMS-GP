<?php
// extra_vehicle_register.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$current_session_user_id = $is_logged_in ? (int)($_SESSION['user_id'] ?? 0) : 0;

include('../../includes/db.php');

date_default_timezone_set('Asia/Colombo');

$user_role = $_SESSION['user_role'] ?? 'guest';
// $can_act logic can be used if you want to restrict general access, 
// but here we rely on specific record ownership for editing/deleting.
$can_act = in_array($user_role, ['super admin', 'admin', 'developer', 'manager']); 

$filterDate = date('Y-m-d');
if (isset($_GET['date'])) {
    $filterDate = $_GET['date'];
}

// =========================================================
// 1. AJAX: FETCH TRIP DETAILS (For Editing)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_trip_details'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    $trip_id = (int)$_POST['trip_id'];

    $stmt = $conn->prepare("SELECT op_code, route, distance, supplier_code, ac_status FROM extra_vehicle_register WHERE id = ?");
    $stmt->bind_param("i", $trip_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode(['success' => true, 'trip' => $result]);
    exit();
}

// =========================================================
// 2. AJAX: FETCH VIEW DETAILS
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_view_details'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    $trip_id = (int)$_POST['trip_id'];

    // 1. Get Trip Info
    $stmt_loc = $conn->prepare("SELECT from_location, to_location FROM extra_vehicle_register WHERE id = ?");
    $stmt_loc->bind_param("i", $trip_id);
    $stmt_loc->execute();
    $trip_info = $stmt_loc->get_result()->fetch_assoc();
    $stmt_loc->close();

    // 2. Get Passengers
    $passengers = [];
    $sql_pass = "
        SELECT 
            t.emp_id, 
            e.calling_name, 
            r.reason 
        FROM ev_trip_employee_reasons t
        LEFT JOIN reason r ON t.reason_code = r.reason_code
        LEFT JOIN employee e ON t.emp_id = e.emp_id
        WHERE t.trip_id = ?
    ";

    $stmt = $conn->prepare($sql_pass);
    if ($stmt) {
        $stmt->bind_param("i", $trip_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()){
            $row['reason'] = $row['reason'] ?? 'Unknown'; 
            $row['calling_name'] = $row['calling_name'] ?? '-'; 
            $passengers[] = $row;
        }
        $stmt->close();
    }

    echo json_encode(['success' => true, 'trip' => $trip_info, 'passengers' => $passengers]);
    exit();
}

// =========================================================
// 3. FETCH LISTS
// =========================================================
$op_codes_list = [];
$route_codes_list = [];
$supplier_list = [];

$check_op = $conn->query("SHOW TABLES LIKE 'op_services'");
if ($check_op && $check_op->num_rows > 0) {
    $result_op = $conn->query("SELECT op_code FROM op_services WHERE op_code LIKE 'EV%' ORDER BY op_code ASC");
    if($result_op) { while ($row = $result_op->fetch_assoc()) $op_codes_list[] = $row['op_code']; }
}

$result_sup = $conn->query("SELECT supplier_code, supplier FROM supplier ORDER BY supplier ASC");
if($result_sup) { while ($row = $result_sup->fetch_assoc()) $supplier_list[] = $row; }

$result_rt = $conn->query("SELECT route_code FROM route ORDER BY route_code ASC");
if($result_rt) { while ($row = $result_rt->fetch_assoc()) $route_codes_list[] = $row['route_code']; }

// =========================================================
// 4. HANDLE FORM SUBMISSION
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // --- CASE A: COMPLETE TRIP ---
    if (isset($_POST['complete_trip_simple'])) {
        $trip_id = (int)$_POST['trip_id'];
        $distance = (float)$_POST['distance'];
        
        $sql_update = "UPDATE extra_vehicle_register SET distance = ?, done = 1, user_id = ? WHERE id = ?";
        $stmt = $conn->prepare($sql_update);
        
        if ($stmt) {
            $stmt->bind_param('dii', $distance, $current_session_user_id, $trip_id);
            if ($stmt->execute()) {
                echo "<script>window.location.href='?date=$filterDate&status=success&message=" . urlencode("Trip Completed Successfully") . "';</script>";
            } else {
                echo "<script>alert('Error completing trip: " . addslashes($stmt->error) . "');</script>";
            }
            $stmt->close();
        }
    }

    // --- CASE B: EDIT TRIP ---
    if (isset($_POST['edit_trip_full'])) {
        $trip_id = (int)$_POST['trip_id'];
        $supplier_code = $_POST['supplier_code'];
        $code_type = $_POST['code_type'];
        $op_code = ($code_type === 'op') ? $_POST['op_code'] : NULL;
        $route = ($code_type === 'route') ? $_POST['route_code'] : NULL;
        $distance = (float)$_POST['distance'];
        $ac_status = (int)$_POST['ac_status']; 
        
        // Note: checking user_id in UPDATE ensures only owner can edit (if you want strict security here too)
        // For now, based on previous logic, we update user_id or keep it. Here I'll update it to current user or keep ownership logic consistent.
        // Assuming strict ownership:
        $sql_update = "UPDATE extra_vehicle_register SET supplier_code = ?, op_code = ?, route = ?, distance = ?, ac_status = ? WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql_update);
        
        if ($stmt) {
            $stmt->bind_param('sssdiii', $supplier_code, $op_code, $route, $distance, $ac_status, $trip_id, $current_session_user_id);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo "<script>window.location.href='?date=$filterDate&status=success&message=" . urlencode("Record Edited Successfully") . "';</script>";
                } else {
                    echo "<script>alert('Error: You can only edit records you completed.'); window.location.href='?date=$filterDate';</script>";
                }
            } else {
                echo "<script>alert('Error editing record: " . addslashes($stmt->error) . "');</script>";
            }
            $stmt->close();
        }
    }

    // --- CASE C: DELETE TRIP (NEW) ---
    if (isset($_POST['delete_trip'])) {
        $trip_id = (int)$_POST['trip_id'];

        // Strict Check: Delete only if ID matches AND user_id matches logged-in user
        $sql_delete = "DELETE FROM extra_vehicle_register WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql_delete);

        if ($stmt) {
            $stmt->bind_param('ii', $trip_id, $current_session_user_id);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo "<script>window.location.href='?date=$filterDate&status=success&message=" . urlencode("Trip Deleted Successfully") . "';</script>";
                } else {
                    // Affected rows 0 means either ID didn't exist OR user_id didn't match
                    echo "<script>alert('Error: You can only delete trips completed by yourself.'); window.location.href='?date=$filterDate';</script>";
                }
            } else {
                echo "<script>alert('Database Error: " . addslashes($stmt->error) . "');</script>";
            }
            $stmt->close();
        }
    }
}

// =========================================================
// 5. FETCH TABLE DATA
// =========================================================
$sql = "
    SELECT evr.*, 
           emp.calling_name as done_by_name,
           s.supplier
    FROM extra_vehicle_register evr
    LEFT JOIN admin a ON evr.user_id = a.user_id
    LEFT JOIN employee emp ON a.emp_id = emp.emp_id
    LEFT JOIN supplier s ON evr.supplier_code = s.supplier_code
    WHERE evr.date = ? 
    ORDER BY evr.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $filterDate);
$stmt->execute();
$result = $stmt->get_result();
$records = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include('../../includes/header.php');
include('../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Extra Vehicle Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s ease-in-out; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        
        .ev-pending { background-color: #fca5a5; }
        .ev-done { background-color: #d1fae5; }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); display: none; justify-content: center; align-items: center; z-index: 3000; }
        .modal-content { background-color: white; padding: 2rem; border-radius: 0.5rem; width: 90%; max-width: 600px; box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2); }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Extra Vehicle Register
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

            <form method="GET" class="flex items-center mx-1">
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
        <span class="text-gray-600">|</span>

        <a href="add_records/extra_vehicle/add_extra_vehicle.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Extra
        </a>

    </div>
</div>

<div class="w-[85%] ml-[15%] flex flex-col items-center p-2 mt-1">
    
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-w-8xl">
        <table class="w-full table-auto p-2">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-3 text-left">Time</th>
                    <th class="px-4 py-3 text-left">Vehicle No</th>
                    <th class="px-4 py-3 text-left">Supplier</th>
                    <th class="px-4 py-3 text-left">Op/Route</th>
                    <th class="px-4 py-3 text-center">A/C</th>
                    <th class="px-4 py-3 text-center">Distance</th>
                    <th class="px-4 py-3 text-left">Done By</th>
                    <th class="px-4 py-3 text-center" style="min-width: 180px;">Action</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                            No records found for <?php echo htmlspecialchars($filterDate); ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $row): 
                        $is_completed = ($row['done'] == 1);
                        $row_class = $is_completed ? 'bg-green-50 hover:bg-green-100' : 'bg-red-50 hover:bg-red-100';
                        
                        $record_user_id = (int)$row['user_id'];
                        // Check if the current user is the one who did the record
                        $can_edit = ($is_completed && ($record_user_id === $current_session_user_id) && $current_session_user_id !== 0);
                        
                        $display_code = '';
                        $code_class = '';
                        if (!empty($row['route'])) {
                            $display_code = "RT: " . htmlspecialchars($row['route']);
                            $code_class = "bg-blue-100 text-blue-800";
                        } elseif (!empty($row['op_code'])) {
                            $display_code = "OP: " . htmlspecialchars($row['op_code']);
                            $code_class = "bg-purple-100 text-purple-800";
                        } else {
                            $display_code = "Pending";
                            $code_class = "text-gray-400 italic";
                        }

                        $ac_status = ($row['ac_status'] == 1) ? "<span class='px-2 py-0.5 rounded text-xs font-bold bg-green-200 text-green-800'>Yes</span>" : "<span class='text-gray-400 text-xs'>No</span>";
                    ?>
                        <tr class="<?php echo $row_class; ?> border-b border-gray-100 transition duration-150">
                            <td class="px-4 py-3"><?php echo date('H:i', strtotime($row['time'])); ?></td>
                            <td class="px-4 py-3 font-bold uppercase"><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                            <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($row['supplier']); ?></td>
                            
                            <td class="px-4 py-3 font-mono text-sm">
                                <span class="px-2 py-1 rounded text-xs font-bold <?php echo $code_class; ?>">
                                    <?php echo $display_code; ?>
                                </span>
                            </td>

                            <td class="px-4 py-3 text-center"><?php echo $ac_status; ?></td>
                            <td class="px-4 py-3 text-center font-mono"><?php echo ($row['distance'] > 0) ? number_format($row['distance'], 2) . ' km' : '-'; ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($row['done_by_name'] ?? '-'); ?></td>

                            <td class="px-4 py-3 text-center">
                                <div class="flex justify-center gap-2">
                                    <button onclick="viewDetails(<?php echo $row['id']; ?>)" 
                                            class="bg-yellow-500 hover:bg-yellow-600 text-white text-xs px-2 py-1.5 rounded shadow transition" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>

                                    <?php if (!$is_completed): ?>
                                        <button onclick="openCompleteModal(<?php echo $row['id']; ?>)" 
                                                class="bg-green-600 hover:bg-green-700 text-white text-xs px-2 py-1.5 rounded shadow transition" title="Complete Trip">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php elseif ($can_edit): ?>
                                        <a href="edit_extra_vehicle.php?id=<?php echo $row['id']; ?>&date=<?php echo $filterDate; ?>" 
                                        class="bg-blue-600 hover:bg-blue-700 text-white text-xs px-2 py-1.5 rounded shadow transition" title="Edit Trip">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <button onclick="confirmDelete(<?php echo $row['id']; ?>)" 
                                                class="bg-red-600 hover:bg-red-700 text-white text-xs px-2 py-1.5 rounded shadow transition" title="Delete Trip">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>

                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs py-1.5 flex items-center gap-1 cursor-help" title="Locked by <?php echo htmlspecialchars($row['done_by_name'] ?? ''); ?>">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="detailsModal" class="modal-overlay">
    <div class="modal-content flex flex-col" style="max-width: 600px;">
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h2 class="text-xl font-bold text-gray-800"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Trip Details</h2>
            <button onclick="closeModal('detailsModal')" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <div id="detailsLoading" class="hidden flex-col justify-center items-center py-6">
            <i class="fas fa-circle-notch fa-spin text-3xl text-blue-500 mb-2"></i>
            <span class="text-sm text-gray-500">Fetching data...</span>
        </div>
        <div id="detailsContent" class="overflow-y-auto max-h-[60vh] p-1"></div>
        <div class="flex justify-end mt-4 border-t pt-2">
            <button onclick="closeModal('detailsModal')" class="bg-gray-600 hover:bg-gray-700 text-white text-sm px-4 py-2 rounded">Close</button>
        </div>
    </div>
</div>

<div id="completeModal" class="modal-overlay">
    <div class="modal-content flex flex-col" style="max-width: 400px;">
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h2 class="text-xl font-bold text-gray-800">Complete Trip</h2>
            <button onclick="closeModal('completeModal')" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <form method="POST" action="?date=<?php echo htmlspecialchars($filterDate); ?>">
            <input type="hidden" name="trip_id" id="completeTripId">
            <input type="hidden" name="complete_trip_simple" value="1">
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Enter Distance (Km)</label>
                <input type="number" step="0.01" name="distance" required class="w-full border border-gray-300 rounded p-2 text-lg focus:border-green-500 focus:ring-green-500" placeholder="0.00" autofocus>
            </div>
            <div class="flex justify-end space-x-2 pt-2">
                <button type="button" onclick="closeModal('completeModal')" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded">Cancel</button>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded shadow">Complete</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="modal-overlay">
    <div class="modal-content flex flex-col">
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h2 class="text-xl font-bold text-gray-800">Edit Trip Details</h2>
            <button onclick="closeModal('editModal')" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        
        <div id="editLoading" class="hidden flex-1 flex flex-col justify-center items-center py-10">
            <i class="fas fa-spinner fa-spin text-4xl text-blue-500 mb-3"></i>
            <p class="text-gray-500 font-medium">Loading...</p>
        </div>

        <form method="POST" action="?date=<?php echo htmlspecialchars($filterDate); ?>" id="editForm">
            <input type="hidden" name="trip_id" id="editTripId">
            <input type="hidden" name="edit_trip_full" value="1">

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                    <select name="supplier_code" id="editSupplierSelect" required class="block w-full border border-gray-300 rounded-md p-2 shadow-sm">
                        <option value="">Select Supplier</option>
                        <?php foreach ($supplier_list as $sup): ?>
                            <option value="<?php echo htmlspecialchars($sup['supplier_code']); ?>">
                                <?php echo htmlspecialchars($sup['supplier']) . " (" . htmlspecialchars($sup['supplier_code']) . ")"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="bg-gray-50 p-3 rounded border border-gray-200">
                    <label class="block text-sm font-bold text-gray-700 mb-2">Identify By:</label>
                    <div class="flex gap-6">
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="radio" name="code_type" value="op" onclick="toggleEditCode('op')" class="form-radio text-indigo-600">
                            <span class="font-medium text-sm">Op Code</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="radio" name="code_type" value="route" onclick="toggleEditCode('route')" class="form-radio text-indigo-600">
                            <span class="font-medium text-sm">Route Code</span>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div id="editOpDiv">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Op Code</label>
                        <select name="op_code" id="editOpCodeSelect" class="block w-full border border-gray-300 rounded-md p-2 shadow-sm">
                            <option value="">-- Select --</option>
                            <?php foreach ($op_codes_list as $code) echo "<option value='$code'>$code</option>"; ?>
                        </select>
                    </div>
                    <div id="editRouteDiv" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Route</label>
                        <select name="route_code" id="editRouteCodeSelect" class="block w-full border border-gray-300 rounded-md p-2 shadow-sm">
                            <option value="">-- Select --</option>
                            <?php foreach ($route_codes_list as $rt) echo "<option value='$rt'>$rt</option>"; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Distance (Km)</label>
                        <input type="number" step="0.01" name="distance" id="editDistanceInput" required class="block w-full border border-gray-300 rounded-md p-2 shadow-sm">
                    </div>
                </div>

                <div class="border-t pt-3 mt-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">A/C Status</label>
                    <div class="flex items-center space-x-6">
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="radio" name="ac_status" value="1" class="form-radio text-green-600 h-4 w-4">
                            <span class="ml-2 text-sm font-bold text-green-700">Yes</span>
                        </label>
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="radio" name="ac_status" value="0" class="form-radio text-gray-600 h-4 w-4">
                            <span class="ml-2 text-sm font-bold text-gray-600">No</span>
                        </label>
                    </div>
                </div>

            </div>

            <div class="flex justify-end space-x-3 mt-6 pt-4 border-t">
                <button type="button" onclick="closeModal('editModal')" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">Cancel</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded transition shadow">Update Record</button>
            </div>
        </form>
    </div>
</div>

<div id="toast-container"></div>

<script>
    // --- View Details ---
    function viewDetails(id) {
        const modal = document.getElementById('detailsModal');
        const loader = document.getElementById('detailsLoading');
        const content = document.getElementById('detailsContent');
        
        modal.style.display = 'flex';
        loader.style.display = 'flex';
        content.innerHTML = '';

        const formData = new FormData();
        formData.append('fetch_view_details', '1');
        formData.append('trip_id', id);

        fetch('', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            loader.style.display = 'none';
            if(data.success) {
                let html = `
                    <div class="bg-blue-50 p-4 rounded-lg mb-4 border border-blue-200">
                        <div class="flex items-center justify-between text-sm">
                            <div class="text-left"><span class="text-xs uppercase text-gray-500 font-bold">From</span><div class="text-lg font-bold text-gray-800">${data.trip.from_location}</div></div>
                            <div class="text-blue-400 px-4"><i class="fas fa-arrow-right text-xl"></i></div>
                            <div class="text-right"><span class="text-xs uppercase text-gray-500 font-bold">To</span><div class="text-lg font-bold text-gray-800">${data.trip.to_location}</div></div>
                        </div>
                    </div>`;
                
                if(data.passengers.length > 0) {
                    html += `<table class="w-full text-sm text-left text-gray-500 border rounded"><thead class="text-xs text-gray-700 uppercase bg-gray-100"><tr><th class="px-4 py-2 border-b">ID</th><th class="px-4 py-2 border-b">Name</th><th class="px-4 py-2 border-b">Reason</th></tr></thead><tbody>`;
                    data.passengers.forEach(p => {
                        html += `<tr class="bg-white border-b"><td class="px-4 py-2 font-mono font-medium text-gray-900 border-r">${p.emp_id}</td><td class="px-4 py-2 font-medium border-r">${p.calling_name}</td><td class="px-4 py-2">${p.reason}</td></tr>`;
                    });
                    html += `</tbody></table>`;
                } else {
                    html += `<div class="text-center py-4 text-gray-500 italic bg-gray-50 rounded border border-dashed">No passenger details.</div>`;
                }
                content.innerHTML = html;
            }
        })
        .catch(err => { console.error(err); loader.style.display = 'none'; content.innerHTML = 'Error loading details.'; });
    }

    // --- Complete Modal ---
    function openCompleteModal(id) {
        document.getElementById('completeTripId').value = id;
        document.getElementById('completeModal').style.display = 'flex';
    }

    // --- Edit Modal ---
    function openEditModal(id) {
        const modal = document.getElementById('editModal');
        const form = document.getElementById('editForm');
        const loading = document.getElementById('editLoading');
        
        document.getElementById('editTripId').value = id;
        modal.style.display = 'flex';
        form.style.display = 'none';
        loading.style.display = 'flex';

        const formData = new FormData();
        formData.append('fetch_trip_details', '1');
        formData.append('trip_id', id);

        fetch('', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            loading.style.display = 'none';
            if(data.success) {
                // Populate Fields
                document.getElementById('editSupplierSelect').value = data.trip.supplier_code;
                document.getElementById('editDistanceInput').value = data.trip.distance;

                // Set Op/Route
                if(data.trip.route) {
                    document.querySelector('input[name="code_type"][value="route"]').checked = true;
                    toggleEditCode('route');
                    document.getElementById('editRouteCodeSelect').value = data.trip.route;
                } else {
                    document.querySelector('input[name="code_type"][value="op"]').checked = true;
                    toggleEditCode('op');
                    document.getElementById('editOpCodeSelect').value = data.trip.op_code;
                }

                // Set AC Status
                if (data.trip.ac_status == 1) {
                    document.querySelector('input[name="ac_status"][value="1"]').checked = true;
                } else {
                    document.querySelector('input[name="ac_status"][value="0"]').checked = true;
                }

                form.style.display = 'block';
            }
        });
    }

    function toggleEditCode(type) {
        const opDiv = document.getElementById('editOpDiv');
        const rtDiv = document.getElementById('editRouteDiv');
        const opSel = document.getElementById('editOpCodeSelect');
        const rtSel = document.getElementById('editRouteCodeSelect');

        if(type === 'op') {
            opDiv.classList.remove('hidden');
            rtDiv.classList.add('hidden');
            opSel.setAttribute('required', 'required');
            rtSel.removeAttribute('required');
            rtSel.value = "";
        } else {
            opDiv.classList.add('hidden');
            rtDiv.classList.remove('hidden');
            rtSel.setAttribute('required', 'required');
            opSel.removeAttribute('required');
            opSel.value = "";
        }
    }

    // --- Delete Confirmation (NEW) ---
    function confirmDelete(id) {
        if (confirm("Are you sure you want to delete this trip permanently?")) {
            // Create a temporary form to submit the delete request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '?date=<?php echo $filterDate; ?>';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'delete_trip';
            actionInput.value = '1';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'trip_id';
            idInput.value = id;
            
            form.appendChild(actionInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    // --- Toast ---
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status')) {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.classList.add('toast', urlParams.get('status'));
        let icon = urlParams.get('status') === 'success' ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-triangle mr-2"></i>';
        toast.innerHTML = `${icon}<span>${decodeURIComponent(urlParams.get('message'))}</span>`;
        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => toast.classList.remove('show'), 3000);
        window.history.replaceState(null, null, window.location.pathname + "?date=<?php echo $filterDate; ?>");
    }
</script>

</body>
</html>