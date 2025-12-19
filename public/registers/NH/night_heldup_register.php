<?php
// nh_register_view.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php'); 

date_default_timezone_set('Asia/Colombo');

$filterDate = date('Y-m-d');
if (isset($_GET['date'])) {
    $filterDate = $_GET['date'];
}

// ---------------------------------------------------------
// 1. FETCH OP CODES (From op_services table: NH% or EV%)
// ---------------------------------------------------------
$op_codes_list = [];
$sql_op = "SELECT op_code FROM op_services WHERE op_code LIKE 'NH%' OR op_code LIKE 'EV%' ORDER BY op_code ASC";
$result_op = $conn->query($sql_op);
if ($result_op) {
    while ($row_op = $result_op->fetch_assoc()) {
        $op_codes_list[] = $row_op['op_code'];
    }
}

// ---------------------------------------------------------
// 2. FETCH DEPARTMENTS (From employee table for dropdown)
// ---------------------------------------------------------
$dept_list = [];
$sql_dept = "SELECT DISTINCT department FROM employee WHERE department IS NOT NULL AND department != '' ORDER BY department ASC";
$result_dept = $conn->query($sql_dept);
if ($result_dept) {
    while ($row_d = $result_dept->fetch_assoc()) {
        $dept_list[] = $row_d['department'];
    }
}

// ---------------------------------------------------------
// 3. HANDLE COMPLETE FORM SUBMISSION
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_trip'])) {
    $trip_id = (int)$_POST['trip_id'];
    $op_code = $_POST['op_code'];
    $distance = (float)$_POST['distance'];
    $direct_count = (int)$_POST['direct_count'];
    
    // Calculate Indirect Count
    $qty_sql = "SELECT quantity FROM nh_register WHERE id = $trip_id";
    $qty_res = $conn->query($qty_sql);
    $qty_row = $qty_res->fetch_assoc();
    $total_qty = $qty_row['quantity'];
    
    $indirect_count = $total_qty - $direct_count; 
    
    // Update Main Register
    $sql_update = "UPDATE nh_register SET op_code = ?, distance = ?, direct_count = ?, indirect_count = ?, done = 1 WHERE id = ?";
    $stmt = $conn->prepare($sql_update);
    $stmt->bind_param('sdiii', $op_code, $distance, $direct_count, $indirect_count, $trip_id);
    
    if ($stmt->execute()) {
        
        // Save Department Breakdown
        $dept_data_json = $_POST['dept_data_json'];
        $dept_array = json_decode($dept_data_json, true);

        if (!empty($dept_array)) {
            $conn->query("DELETE FROM nh_trip_departments WHERE trip_id = $trip_id");
            $stmt_dept = $conn->prepare("INSERT INTO nh_trip_departments (trip_id, department, count) VALUES (?, ?, ?)");
            foreach ($dept_array as $deptItem) {
                $d_name = $deptItem['dept'];
                $d_count = (int)$deptItem['count'];
                $stmt_dept->bind_param('isi', $trip_id, $d_name, $d_count);
                $stmt_dept->execute();
            }
            $stmt_dept->close();
        }

        echo "<script>window.location.href='?date=$filterDate&status=success&message=" . urlencode("Trip Completed Successfully") . "';</script>";
    } else {
        echo "<script>alert('Error updating record');</script>";
    }
    $stmt->close();
}

// ---------------------------------------------------------
// 4. FETCH DATA FOR TABLE
// ---------------------------------------------------------
$sql = "SELECT * FROM nh_register WHERE date = ? ORDER BY id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $filterDate);
$stmt->execute();
$result = $stmt->get_result();
$heldup_records = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
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
    #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; display: flex; flex-direction: column; align-items: flex-end; }
    .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; max-width: 400px; }
    .toast.show { transform: translateY(0); opacity: 1; }
    .toast.success { background-color: #4CAF50; } .toast.error { background-color: #F44336; }
    
    .heldup-pending { background-color: #fca5a5; }
    .heldup-done { background-color: #d1fae5; }

    /* Modal Styling */
    .reason-modal-overlay, .complete-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); display: none; justify-content: center; align-items: center; z-index: 3000; }
    .reason-modal-content, .complete-modal-content { background-color: white; padding: 2rem; border-radius: 0.5rem; width: 90%; max-width: 800px; box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2); }
</style>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="nh_schedule.php" class="hover:text-yellow-600">Schedule</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] flex flex-col items-center pb-10 mt-2">
    <p class="text-[32px] font-bold text-gray-800 mt-2">Night Heldup Register</p>

    <form method="GET" class="mb-6 flex justify-center">
        <div class="flex items-center">
            <label for="date" class="text-lg font-medium mr-2">Filter by Date:</label>
            <input type="date" id="date" name="date" class="border border-gray-300 p-2 rounded-md" value="<?php echo htmlspecialchars($filterDate); ?>" required>
            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md ml-2 hover:bg-blue-600">Filter</button>
        </div>
    </form>

    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6">
        <table class="w-full table-auto p-2">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-2 text-left">ID</th>
                    <th class="px-4 py-2 text-left">Time</th>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Op Code</th>
                    <th class="px-4 py-2 text-center">Qty</th>
                    <th class="px-4 py-2 text-center">Distance</th>
                    <th class="px-4 py-2 text-center">Direct</th>
                    <th class="px-4 py-2 text-center">Indirect</th>
                    <th class="px-4 py-2 text-center" style="min-width: 150px;">Action</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200">
                <?php if (empty($heldup_records)): ?>
                    <tr><td colspan="9" class="border px-4 py-2 text-center text-gray-500">No records found for <?php echo htmlspecialchars($filterDate); ?>.</td></tr>
                <?php else: ?>
                    <?php foreach ($heldup_records as $row): 
                        $is_completed = ($row['done'] == 1);
                        // Using same row class logic as day register
                        $row_class = $is_completed ? 'heldup-done' : 'heldup-pending';
                    ?>
                        <tr class="<?php echo $row_class; ?> hover:bg-gray-50 transition duration-150">
                            <td class="border px-4 py-2 font-mono text-blue-600"><?php echo $row['id']; ?></td>
                            <td class="border px-4 py-2"><?php echo date('H:i', strtotime($row['time'])); ?></td>
                            <td class="border px-4 py-2 font-bold uppercase"><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                            
                            <td class="border px-4 py-2">
                                <?php if (!empty($row['op_code'])): ?>
                                    <span class="bg-purple-100 text-purple-700 px-2 py-1 rounded text-xs font-bold"><?php echo htmlspecialchars($row['op_code']); ?></span>
                                <?php else: ?>
                                    <span class="text-gray-500 text-xs">Pending</span>
                                <?php endif; ?>
                            </td>

                            <td class="border px-4 py-2 text-center font-bold"><?php echo $row['quantity']; ?></td>
                            <td class="border px-4 py-2 text-center"><?php echo ($row['distance'] > 0) ? $row['distance'] . ' km' : '-'; ?></td>
                            <td class="border px-4 py-2 text-center text-green-600 font-bold"><?php echo ($row['direct_count'] > 0) ? $row['direct_count'] : '-'; ?></td>
                            <td class="border px-4 py-2 text-center text-yellow-600 font-bold"><?php echo ($row['indirect_count'] > 0) ? $row['indirect_count'] : '-'; ?></td>

                            <td class="border px-4 py-2 text-center">
                                <?php if (!$is_completed): ?>
                                    <button onclick="openCompleteModal(<?php echo $row['id']; ?>, <?php echo $row['quantity']; ?>)" 
                                            class="bg-green-600 hover:bg-green-700 text-white font-bold py-1 px-2 rounded text-xs">
                                        <i class="fas fa-check-circle mr-1"></i> Complete
                                    </button>
                                <?php else: ?>
                                    <span class="text-green-600 font-bold text-xs"><i class="fas fa-check"></i> Done</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="completeTripModal" class="complete-modal-overlay">
    <div class="complete-modal-content">
        <h2 class="text-xl font-bold mb-4 text-gray-800">Complete Trip #<span id="displayTripId" class="text-indigo-600"></span></h2>
        
        <form method="POST" action="" onsubmit="return prepareSubmission()">
            <input type="hidden" name="trip_id" id="modalTripId">
            <input type="hidden" name="complete_trip" value="1">
            <input type="hidden" name="dept_data_json" id="deptDataJson">

            <div class="p-2 space-y-6">
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Op Code (Service)</label>
                        <select name="op_code" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="" disabled selected>Select Code</option>
                            <?php foreach ($op_codes_list as $code): ?>
                                <option value="<?php echo $code; ?>"><?php echo $code; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Distance (Km)</label>
                        <input type="number" step="0.01" name="distance" required placeholder="0.00" 
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
                    
                    <div id="deptListContainer" class="bg-gray-50 border border-gray-200 rounded p-2 text-sm min-h-[50px]">
                        <p class="text-gray-400 text-xs italic">No departments added yet.</p>
                    </div>
                </div>

                <hr class="border-gray-200">

                <div class="bg-yellow-50 p-4 rounded border border-yellow-200">
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase">Total Qty</label>
                            <input type="text" id="totalQty" readonly class="w-full text-center bg-transparent font-bold text-lg text-gray-800 border-none outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-blue-600 uppercase">Direct</label>
                            <input type="number" name="direct_count" id="directCount" oninput="calcIndirect()" required placeholder="0" 
                                   class="w-full text-center p-1 border-b-2 border-blue-400 bg-white font-bold text-lg focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase">Indirect</label>
                            <input type="text" id="indirectQty" readonly class="w-full text-center bg-transparent font-bold text-lg text-gray-600 border-none outline-none" value="0">
                        </div>
                    </div>
                </div>

            </div>

            <div class="flex justify-end space-x-3 mt-4">
                <button type="button" onclick="closeModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">Cancel</button>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded transition">Save Complete</button>
            </div>
        </form>
    </div>
</div>

<div id="toast-container"></div>

<script>
    // --- VARIABLES ---
    let addedDepartments = [];
    const modal = document.getElementById('completeTripModal');
    const deptListContainer = document.getElementById('deptListContainer');

    // --- OPEN MODAL ---
    function openCompleteModal(id, qty) {
        document.getElementById('modalTripId').value = id;
        document.getElementById('displayTripId').innerText = id;
        document.getElementById('totalQty').value = qty;
        
        // Reset Fields
        document.getElementById('directCount').value = '';
        document.getElementById('indirectQty').value = qty;
        addedDepartments = [];
        renderDeptList();
        
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    // --- DEPARTMENT LOGIC ---
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
            deptListContainer.innerHTML = '<p class="text-gray-400 text-xs italic">No departments added yet.</p>';
            return;
        }
        let html = '<ul class="space-y-1">';
        addedDepartments.forEach((item, index) => {
            html += `
                <li class="flex justify-between items-center bg-white px-2 py-1 rounded border border-gray-100 shadow-sm">
                    <span class="font-medium text-gray-700">${item.dept}</span>
                    <div class="flex items-center">
                        <span class="bg-blue-100 text-blue-800 text-xs font-bold px-2 py-0.5 rounded mr-2">${item.count}</span>
                        <button type="button" onclick="removeDept(${index})" class="text-red-400 hover:text-red-600 font-bold">&times;</button>
                    </div>
                </li>
            `;
        });
        html += '</ul>';
        deptListContainer.innerHTML = html;
    }

    // --- CALCULATION LOGIC ---
    function calcIndirect() {
        const total = parseInt(document.getElementById('totalQty').value) || 0;
        const direct = parseInt(document.getElementById('directCount').value) || 0;
        let indirect = total - direct;
        
        const indirectInput = document.getElementById('indirectQty');
        indirectInput.value = indirect;
        
        if (indirect < 0) {
            indirectInput.classList.add('text-red-600');
        } else {
            indirectInput.classList.remove('text-red-600');
        }
    }

    // --- PREPARE SUBMISSION ---
    function prepareSubmission() {
        const indirect = parseInt(document.getElementById('indirectQty').value);
        if (indirect < 0) {
            alert("Direct count cannot exceed Total Quantity!");
            return false;
        }
        document.getElementById('deptDataJson').value = JSON.stringify(addedDepartments);
        return true;
    }

    // --- TOAST LOGIC ---
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let icon = type === 'success' ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-circle mr-2"></i>';
        
        toast.innerHTML = `${icon} <span>${message}</span>`;
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => { toast.remove(); }, 4000); 
    }

    // Check URL Parameters for Messages
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    const message = urlParams.get('message');
    if (status && message) {
        showToast(decodeURIComponent(message), status);
        // Clean URL
        const newUrl = window.location.pathname + "?date=<?php echo $filterDate; ?>";
        window.history.replaceState(null, null, newUrl);
    }
</script>

</body>
</html>