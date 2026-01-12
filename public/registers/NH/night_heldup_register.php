<?php
// nh_register_view.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$current_session_user_id = $is_logged_in ? (int)($_SESSION['user_id'] ?? 0) : 0;

include('../../../includes/db.php');

date_default_timezone_set('Asia/Colombo');

$user_role = $_SESSION['user_role'] ?? 'guest';
$can_act = in_array($user_role, ['super admin', 'admin', 'developer', 'manager']); 

$filterDate = date('Y-m-d');
if (isset($_GET['date'])) {
    $filterDate = $_GET['date'];
}

// ---------------------------------------------------------
// 1. AJAX: FETCH TRIP DETAILS FOR EDITING
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_trip_details'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    $trip_id = (int)$_POST['trip_id'];

    // Get Trip Data (Added schedule_qty here)
    $stmt = $conn->prepare("SELECT op_code, distance, direct_count, schedule_qty FROM nh_register WHERE id = ?");
    $stmt->bind_param("i", $trip_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Get Departments
    $depts = [];
    $stmt_d = $conn->prepare("SELECT department, count FROM nh_trip_departments WHERE trip_id = ?");
    $stmt_d->bind_param("i", $trip_id);
    $stmt_d->execute();
    $res_d = $stmt_d->get_result();
    while($row = $res_d->fetch_assoc()){
        $depts[] = $row;
    }
    $stmt_d->close();

    echo json_encode(['success' => true, 'trip' => $result, 'depts' => $depts]);
    exit();
}

// ---------------------------------------------------------
// 2. FETCH OP CODES & DEPARTMENTS
// ---------------------------------------------------------
$op_codes_list = [];
$check_op = $conn->query("SHOW TABLES LIKE 'op_services'");
if ($check_op && $check_op->num_rows > 0) {
    $result_op = $conn->query("SELECT op_code FROM op_services WHERE op_code LIKE 'NH%' OR op_code LIKE 'EV%' ORDER BY op_code ASC");
    if($result_op) {
        while ($row = $result_op->fetch_assoc()) $op_codes_list[] = $row['op_code'];
    }
}

$dept_list = [];
$result_dept = $conn->query("SELECT DISTINCT department FROM employee WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
if($result_dept) {
    while ($row = $result_dept->fetch_assoc()) $dept_list[] = $row['department'];
}

// ---------------------------------------------------------
// 3. HANDLE FORM SUBMISSION (COMPLETE / EDIT)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_trip'])) {
    $trip_id = (int)$_POST['trip_id'];
    $op_code = $_POST['op_code'];
    $distance = (float)$_POST['distance'];
    
    // Updated Logic: Use Schedule Qty for calculation
    $schedule_qty = (int)$_POST['schedule_qty'];
    $direct_count = (int)$_POST['direct_count'];
    
    // Calculation based on Schedule Qty
    $indirect_count = $schedule_qty - $direct_count; 
    
    // Update Main Register (Added schedule_qty to update)
    $sql_update = "UPDATE nh_register SET op_code = ?, distance = ?, schedule_qty = ?, direct_count = ?, indirect_count = ?, done = 1, user_id = ? WHERE id = ?";
    $stmt = $conn->prepare($sql_update);
    // types: s (op), d (dist), i (sch), i (dir), i (indir), i (user), i (id)
    $stmt->bind_param('sdiiiii', $op_code, $distance, $schedule_qty, $direct_count, $indirect_count, $current_session_user_id, $trip_id);
    
    if ($stmt->execute()) {
        // Save Departments
        $dept_data_json = $_POST['dept_data_json'];
        $dept_array = json_decode($dept_data_json, true);

        // Clear old departments
        $conn->query("DELETE FROM nh_trip_departments WHERE trip_id = $trip_id");
        
        // Insert new departments
        if (!empty($dept_array) && is_array($dept_array)) {
            $stmt_dept = $conn->prepare("INSERT INTO nh_trip_departments (trip_id, department, count) VALUES (?, ?, ?)");
            foreach ($dept_array as $deptItem) {
                $stmt_dept->bind_param('isi', $trip_id, $deptItem['dept'], $deptItem['count']);
                $stmt_dept->execute();
            }
            $stmt_dept->close();
        }
        echo "<script>window.location.href='?date=$filterDate&status=success&message=" . urlencode("Record Saved Successfully") . "';</script>";
    } else {
        echo "<script>alert('Error updating record');</script>";
    }
    $stmt->close();
}

// ---------------------------------------------------------
// 4. FETCH DATA
// ---------------------------------------------------------
$sql = "
    SELECT nh.*, 
           emp.calling_name as done_by_name
    FROM nh_register nh
    LEFT JOIN admin a ON nh.user_id = a.user_id
    LEFT JOIN employee emp ON a.emp_id = emp.emp_id
    WHERE nh.date = ? 
    ORDER BY nh.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $filterDate);
$stmt->execute();
$result = $stmt->get_result();
$heldup_records = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include('../../../includes/header.php');
include('../../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Night Heldup Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<style>
    /* CSS for toast */
    #toast-container { 
        position: fixed; top: 1rem; right: 1rem; z-index: 4000; 
    }
    .toast { 
        display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; 
        border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
        color: white; opacity: 0; transition: opacity 0.3s ease-in-out; 
    }
    .toast.show { opacity: 1; }
    .toast.success { background-color: #4CAF50; }
    .toast.error { background-color: #F44336; }
    
    /* Row Status Colors */
    .heldup-pending { background-color: #fca5a5; /* Red-400 */ }
    .heldup-done { background-color: #d1fae5; /* Green-100 */ }

    /* Modal Styling */
    .reason-modal-overlay, .complete-modal-overlay { 
        position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
        background-color: rgba(0, 0, 0, 0.6); 
        display: none; justify-content: center; align-items: center; z-index: 3000; 
    }
    .reason-modal-content, .complete-modal-content { 
        background-color: white; padding: 2rem; border-radius: 0.5rem; width: 90%; max-width: 800px; box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2); 
    }
    .modal-scroll::-webkit-scrollbar { width: 8px; }
    .modal-scroll::-webkit-scrollbar-track { background: #f1f1f1; }
    .modal-scroll::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 4px; }
    
    /* Scrollbar for table */
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #f1f1f1; }
    ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: #555; }
</style>
<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Night Heldup Register
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

        <?php if ($can_act): ?>
            <a href="nh_schedule.php" class="text-gray-300 hover:text-white transition">Schedule</a>
            
            <a href="nh_add.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs  tracking-wide">
                Add Trip
            </a>
        <?php endif; ?>
        
        <?php if (!$can_act): ?>
            <a href="nh_add_trip.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
                Add Trip
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">

    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200">
        <table class="w-full table-auto">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-3 text-left">ID</th>
                    <th class="px-4 py-3 text-left">Time</th>
                    <th class="px-4 py-3 text-left">Vehicle No</th>
                    <th class="px-4 py-3 text-left">Op Code</th>
                    <th class="px-4 py-3 text-center" title="Requested Quantity">Qty</th>
                    <th class="px-4 py-3 text-center" title="Scheduled Quantity">Sch Qty</th>
                    <th class="px-4 py-3 text-center">Distance</th>
                    <th class="px-4 py-3 text-center">Direct</th>
                    <th class="px-4 py-3 text-center">Indirect</th>
                    <th class="px-4 py-3 text-left">Done By</th>
                    
                    <?php if ($is_logged_in): ?>
                        <th class="px-4 py-3 text-center" style="min-width: 150px;">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php if (empty($heldup_records)): ?>
                    <tr>
                        <td colspan="<?php echo $is_logged_in ? 11 : 10; ?>" class="px-6 py-4 text-center text-gray-500">
                            No records found for <?php echo htmlspecialchars($filterDate); ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($heldup_records as $row): 
                        $is_completed = ($row['done'] == 1);
                        // Status Colors (Matching other pages)
                        $row_class = $is_completed ? 'bg-green-50 hover:bg-green-100' : 'bg-red-50 hover:bg-red-100';
                        
                        $record_user_id = (int)$row['user_id'];
                        $can_edit = $is_completed && ($record_user_id === $current_session_user_id) && ($current_session_user_id !== 0);
                        $done_by_name = $row['done_by_name'] ? $row['done_by_name'] : '-';
                        
                        // Handle display of Schedule Qty
                        $display_sch_qty = $is_completed ? $row['schedule_qty'] : '-';
                    ?>
                        <tr class="<?php echo $row_class; ?> border-b border-gray-100 transition duration-150">
                            <td class="px-4 py-3 font-mono text-blue-600 font-bold">#<?php echo $row['id']; ?></td>
                            <td class="px-4 py-3"><?php echo date('H:i', strtotime($row['time'])); ?></td>
                            <td class="px-4 py-3 font-bold uppercase"><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                            
                            <td class="px-4 py-3">
                                <?php if (!empty($row['op_code'])): ?>
                                    <span class="bg-purple-100 text-purple-700 px-2 py-1 rounded text-xs font-bold"><?php echo htmlspecialchars($row['op_code']); ?></span>
                                <?php else: ?>
                                    <span class="text-gray-500 text-xs italic">Pending</span>
                                <?php endif; ?>
                            </td>

                            <td class="px-4 py-3 text-center text-gray-500"><?php echo $row['quantity']; ?></td>
                            <td class="px-4 py-3 text-center font-bold text-black"><?php echo $display_sch_qty; ?></td>
                            <td class="px-4 py-3 text-center font-mono"><?php echo ($row['distance'] > 0) ? number_format($row['distance'], 2) . ' km' : '-'; ?></td>
                            <td class="px-4 py-3 text-center text-green-600 font-bold"><?php echo ($row['direct_count'] > 0) ? $row['direct_count'] : '-'; ?></td>
                            <td class="px-4 py-3 text-center text-yellow-600 font-bold"><?php echo ($row['indirect_count'] > 0) ? $row['indirect_count'] : '-'; ?></td>
                            <td class="px-4 py-3 text-xs text-gray-700"><?php echo htmlspecialchars($done_by_name); ?></td>

                            <?php if ($is_logged_in): ?>
                                <td class="px-4 py-3 text-center">
                                    <?php if (!$is_completed): ?>
                                        <button onclick="openModal('complete', <?php echo $row['id']; ?>, <?php echo $row['quantity']; ?>)" 
                                                class="bg-green-600 hover:bg-green-700 text-white text-xs px-3 py-1.5 rounded shadow flex items-center justify-center mx-auto transition">
                                            <i class="fas fa-check-circle"></i>
                                        </button>
                                    <?php elseif ($can_edit): ?>
                                        <button onclick="openModal('edit', <?php echo $row['id']; ?>, <?php echo $row['quantity']; ?>)" 
                                                class="bg-blue-600 hover:bg-blue-700 text-white text-xs px-3 py-1.5 rounded shadow flex items-center justify-center mx-auto transition">
                                            <i class="fas fa-pencil-alt"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs italic cursor-not-allowed" title="Completed by <?php echo htmlspecialchars($done_by_name); ?>">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="completeModal" class="complete-modal-overlay">
    <div class="complete-modal-content flex flex-col" style="max-height: 90vh;">
        
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h2 class="text-xl font-bold text-gray-800"><span id="modalActionTitle">Complete</span> Trip #<span id="displayTripId" class="text-indigo-600"></span></h2>
            <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        
        <div id="modalLoading" class="hidden flex-1 flex flex-col justify-center items-center py-10">
            <i class="fas fa-spinner fa-spin text-4xl text-blue-500 mb-3"></i>
            <p class="text-gray-500 font-medium">Loading details...</p>
        </div>

        <form method="POST" action="" onsubmit="return prepareSubmission()" id="tripForm" class="overflow-y-auto modal-scroll p-1 flex-1">
            <input type="hidden" name="trip_id" id="modalTripId">
            <input type="hidden" name="complete_trip" value="1">
            <input type="hidden" name="dept_data_json" id="deptDataJson">

            <div class="space-y-6">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Op Code (Service)</label>
                        <select name="op_code" id="opCodeSelect" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="" disabled selected>Select Code</option>
                            <?php foreach ($op_codes_list as $code): ?>
                                <option value="<?php echo $code; ?>"><?php echo $code; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Total Distance (Km)</label>
                        <input type="number" step="0.01" name="distance" id="distanceInput" required placeholder="0.00" 
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>

                <hr class="border-gray-200">

                <div>
                    <h4 class="text-sm font-bold text-gray-800 mb-2">Department Breakdown</h4>
                    <div class="flex gap-2 mb-2">
                        <select id="deptSelect" class="flex-1 border border-gray-300 rounded-md p-2 text-sm">
                            <option value="">Select Dept</option>
                            <?php foreach ($dept_list as $d): ?>
                                <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" id="deptCount" placeholder="Qty" class="w-20 border border-gray-300 rounded-md p-2 text-sm">
                        <button type="button" onclick="addDepartment()" class="bg-blue-600 text-white px-3 rounded hover:bg-blue-700 text-sm font-bold">Add</button>
                    </div>
                    
                    <div id="deptListContainer" class="bg-gray-50 border border-gray-200 rounded p-2 text-sm min-h-[50px] max-h-[150px] overflow-y-auto">
                        <p class="text-gray-400 text-xs italic text-center mt-2">No departments added yet.</p>
                    </div>
                </div>

                <hr class="border-gray-200">

                <div class="bg-yellow-50 p-4 rounded border border-yellow-200">
                    <div class="mb-2 text-center">
                        <span class="text-xs text-gray-500">Quantity (Ref): <span id="displayReqQty" class="font-bold text-gray-800">0</span></span>
                    </div>
                    
                    <div class="grid grid-cols-3 gap-4 text-center items-end">
                        <div>
                            <label class="block text-xs font-bold text-black uppercase mb-1">Schedule Qty</label>
                            <input type="number" name="schedule_qty" id="scheduleQtyInput" oninput="calcIndirect()" required 
                                   class="w-full text-center p-2 border border-gray-300 rounded font-bold text-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-blue-600 uppercase mb-1">Direct</label>
                            <input type="number" name="direct_count" id="directCount" oninput="calcIndirect()" required placeholder="0" 
                                   class="w-full text-center p-2 border-b-2 border-blue-400 bg-white font-bold text-lg focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Indirect</label>
                            <input type="text" id="indirectQty" readonly class="w-full text-center bg-transparent font-bold text-lg text-gray-600 border-none outline-none" value="0">
                        </div>
                    </div>
                    <p class="text-center text-xs text-gray-400 mt-2 italic">(Indirect = Schedule Qty - Direct)</p>
                </div>
            </div>

            <div class="flex justify-between space-x-3 mt-6 pt-2 border-t">
                <button type="button" onclick="closeModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">Cancel</button>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded transition">Save Record</button>
            </div>
        </form>
    </div>
</div>

<div id="toast-container"></div>

<script>
    let addedDepartments = [];
    const modal = document.querySelector('.complete-modal-overlay');
    const tripForm = document.getElementById('tripForm');
    const loadingDiv = document.getElementById('modalLoading');
    const deptListContainer = document.getElementById('deptListContainer');

    function openModal(mode, id, reqQty) {
        document.getElementById('modalTripId').value = id;
        document.getElementById('displayTripId').innerText = id;
        
        // Show Requested Qty just for display
        document.getElementById('displayReqQty').innerText = reqQty;
        
        // Reset Inputs
        document.getElementById('directCount').value = '';
        document.getElementById('indirectQty').value = reqQty; // Default view
        document.getElementById('opCodeSelect').value = "";
        document.getElementById('distanceInput').value = "";
        
        // For new completion, default Schedule Qty to Request Qty
        document.getElementById('scheduleQtyInput').value = reqQty;

        addedDepartments = [];
        renderDeptList();

        if (mode === 'edit') {
            document.getElementById('modalActionTitle').innerText = "Edit";
            fetchTripDetails(id);
        } else {
            document.getElementById('modalActionTitle').innerText = "Complete";
            tripForm.style.display = 'block';
            loadingDiv.style.display = 'none';
            // Trigger calc for initial values
            calcIndirect();
        }
        
        modal.style.display = 'flex';
    }

    function fetchTripDetails(id) {
        tripForm.style.display = 'none';
        loadingDiv.style.display = 'flex';

        const formData = new FormData();
        formData.append('fetch_trip_details', '1');
        formData.append('trip_id', id);

        fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                document.getElementById('opCodeSelect').value = data.trip.op_code;
                document.getElementById('distanceInput').value = data.trip.distance;
                document.getElementById('directCount').value = data.trip.direct_count;
                
                // Set the stored Schedule Qty
                if(data.trip.schedule_qty !== undefined && data.trip.schedule_qty !== null) {
                    document.getElementById('scheduleQtyInput').value = data.trip.schedule_qty;
                }

                calcIndirect(); 

                if(data.depts.length > 0) {
                    addedDepartments = data.depts.map(d => ({ dept: d.department, count: d.count }));
                    renderDeptList();
                }
                loadingDiv.style.display = 'none';
                tripForm.style.display = 'block';
            } else {
                alert("Error fetching data: " + data.message);
                closeModal();
            }
        })
        .catch(err => { console.error(err); alert("Network error"); closeModal(); });
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    function addDepartment() {
        const deptSelect = document.getElementById('deptSelect');
        const countInput = document.getElementById('deptCount');
        const dept = deptSelect.value;
        const count = countInput.value;

        if(dept && count > 0) {
            addedDepartments.push({ dept: dept, count: count });
            deptSelect.value = "";
            countInput.value = "";
            renderDeptList();
        } else {
            alert("Please select a department and enter a valid count.");
        }
    }

    function removeDept(index) {
        addedDepartments.splice(index, 1);
        renderDeptList();
    }

    function renderDeptList() {
        if (addedDepartments.length === 0) {
            deptListContainer.innerHTML = '<p class="text-gray-400 text-xs italic text-center mt-4">No departments added yet.</p>';
            return;
        }
        let html = '<ul class="space-y-2">';
        addedDepartments.forEach((item, index) => {
            html += `
                <li class="flex justify-between items-center bg-white px-3 py-2 rounded-md border border-gray-200 shadow-sm">
                    <span class="font-medium text-gray-700">${item.dept}</span>
                    <div class="flex items-center">
                        <span class="bg-blue-100 text-blue-800 text-xs font-bold px-2 py-1 rounded mr-3">${item.count}</span>
                        <button type="button" onclick="removeDept(${index})" class="text-red-400 hover:text-red-600 transition">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </li>
            `;
        });
        html += '</ul>';
        deptListContainer.innerHTML = html;
    }

    function calcIndirect() {
        // NEW LOGIC: Based on Schedule Qty
        const scheduleQty = parseInt(document.getElementById('scheduleQtyInput').value) || 0;
        const direct = parseInt(document.getElementById('directCount').value) || 0;
        
        let indirect = scheduleQty - direct;
        
        const indirectInput = document.getElementById('indirectQty');
        indirectInput.value = indirect;
        
        if (indirect < 0) {
            indirectInput.classList.add('text-red-600');
        } else {
            indirectInput.classList.remove('text-red-600');
        }
    }

    function prepareSubmission() {
        const indirect = parseInt(document.getElementById('indirectQty').value);
        if (indirect < 0) {
            alert("Direct count cannot exceed Schedule Quantity!");
            return false;
        }
        document.getElementById('deptDataJson').value = JSON.stringify(addedDepartments);
        return true;
    }

    // --- TOAST LOGIC ---
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.classList.add('toast', type);
        let icon = type === 'success' ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-triangle mr-2"></i>';
        toast.innerHTML = `${icon}<span>${message}</span>`;
        toastContainer.appendChild(toast);
        requestAnimationFrame(() => { toast.classList.add('show'); });
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 3000); 
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status')) {
        showToast(decodeURIComponent(urlParams.get('message')), urlParams.get('status'));
        window.history.replaceState(null, null, window.location.pathname + "?date=<?php echo $filterDate; ?>");
    }
</script>

</body>
</html>