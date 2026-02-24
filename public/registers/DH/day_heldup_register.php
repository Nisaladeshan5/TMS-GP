<?php
// day_heldup_register.php (Updated for Full Edit Capability & Monthly Lock)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- User Context ---
$user_role = $_SESSION['user_role'] ?? 'guest';
$can_act = in_array($user_role, ['super admin', 'admin', 'developer', 'manager']); 
$current_session_user_id = $is_logged_in ? (int)($_SESSION['user_id'] ?? 0) : 0; 

// Filter Date
$filterDate = $_GET['date'] ?? date('Y-m-d');

// --- 1. Get Monthly Lock Data ---
$max_period_sql = "SELECT MAX(year) as max_year, MAX(month) as max_month FROM monthly_payments_dh";
$max_res = $conn->query($max_period_sql);
$max_data = $max_res->fetch_assoc();
$max_year = (int)($max_data['max_year'] ?? 0);
$max_month = (int)($max_data['max_month'] ?? 0);

// --- AJAX Handler for Fetching Reasons ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_reasons']) && isset($_POST['trip_id'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    $trip_id = (int)$_POST['trip_id'];
    
    $trip_details_sql = "SELECT distance FROM day_heldup_register WHERE trip_id = ? LIMIT 1";
    $trip_stmt = $conn->prepare($trip_details_sql);
    $trip_stmt->bind_param('i', $trip_id);
    $trip_stmt->execute();
    $trip_details = $trip_stmt->get_result()->fetch_assoc();
    $trip_stmt->close();

    $reasons_sql = "SELECT dher.emp_id, e.calling_name, e.department, r.reason 
                    FROM dh_emp_reason dher 
                    JOIN reason r ON dher.reason_code = r.reason_code 
                    LEFT JOIN employee e ON dher.emp_id = e.emp_id 
                    WHERE dher.trip_id = ? ORDER BY dher.emp_id ASC";
    $stmt = $conn->prepare($reasons_sql);
    $stmt->bind_param('i', $trip_id);
    $stmt->execute();
    $reasons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode(['success' => true, 'reasons' => $reasons, 'distance' => number_format($trip_details['distance'] ?? 0, 2)]);
    if (isset($conn)) $conn->close();
    exit();
}

// 2. Fetch relevant Day Heldup Trip records
$sql = "SELECT dhr.trip_id, dhr.op_code, dhr.vehicle_no, dhr.date, dhr.out_time, dhr.in_time, dhr.distance, dhr.done AS heldup_done_status, dhr.user_id, 
        COUNT(dher.emp_id) AS employee_count, GROUP_CONCAT(DISTINCT r.reason SEPARATOR ' / ') AS reasons_summary, 
        CASE WHEN dhr.user_id IS NOT NULL THEN user_employee.calling_name ELSE NULL END AS done_by_user_display 
        FROM day_heldup_register dhr 
        LEFT JOIN dh_emp_reason dher ON dhr.trip_id = dher.trip_id 
        LEFT JOIN reason r ON dher.reason_code = r.reason_code 
        LEFT JOIN admin a ON dhr.user_id = a.user_id 
        LEFT JOIN employee AS user_employee ON a.emp_id = user_employee.emp_id 
        WHERE DATE(dhr.date) = ? 
        GROUP BY dhr.trip_id, dhr.op_code, dhr.vehicle_no, dhr.date, dhr.done, dhr.out_time, dhr.in_time, dhr.distance, dhr.user_id, user_employee.calling_name 
        ORDER BY dhr.trip_id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $filterDate);
$stmt->execute();
$result = $stmt->get_result();

$heldup_records = [];
while ($row = $result->fetch_assoc()) {
    $row['user_id'] = (int)($row['user_id'] ?? 0); 
    $heldup_records[] = $row;
}
$stmt->close();
$conn->close();

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Day Heldup Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; max-width: 400px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .reason-modal-overlay, .complete-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); display: none; justify-content: center; align-items: center; z-index: 3000; }
        .reason-modal-content, .complete-modal-content { background-color: white; padding: 2rem; border-radius: 0.5rem; width: 90%; max-width: 800px; box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2); }

        /* --- Sticky Header & Auto-Height Logic --- */
        .table-wrapper {
            overflow-y: auto;
            /* Screen එකේ උස අනුව auto adjust වේ (Header එකට ඉඩ තබා) */
            max-height: calc(100vh - 120px); 
            border-radius: 0.5rem;
        }
        thead th {
            position: sticky;
            top: 0;
            z-index: 20;
            background-color: #2563eb; /* bg-blue-600 */
        }
    </style>
</head>
<body class="bg-gray-100 overflow-hidden"> <div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">Day Heldup Register</div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            <a href="?date=<?php echo date('Y-m-d', strtotime($filterDate . ' -1 day')); ?>" class="p-2 text-gray-400 hover:text-white rounded-md transition"><i class="fas fa-chevron-left"></i></a>
            <form method="GET" class="flex items-center mx-1">
                <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none w-32 cursor-pointer text-center">
            </form>
            <a href="?date=<?php echo date('Y-m-d', strtotime($filterDate . ' +1 day')); ?>" class="p-2 text-gray-400 hover:text-white rounded-md transition"><i class="fas fa-chevron-right"></i></a>
        </div>
        <?php if ($is_logged_in): ?>
            <a href="export_heldup_excel.php?month=<?php echo date('Y-m', strtotime($filterDate)); ?>" 
            class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs">
                <i class="fas fa-file-excel mr-1"></i> Export Excel
            </a>
        <?php endif; ?>
        <a href="dh_attendance.php" class="text-gray-300 hover:text-white transition">Attendance</a>
        <a href="<?php echo $can_act ? 'day_heldup_add.php' : 'day_heldup_add_trip.php'; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs">Add Trip</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-2">
    <div class="table-wrapper bg-white shadow-lg border border-gray-200">
        <table class="w-full table-auto text-sm text-left border-collapse">
            <thead class="text-white">
                <tr>
                    <th class="px-4 py-3">Trip ID</th>
                    <th class="px-4 py-3">Vehicle No</th>
                    <th class="px-4 py-3">Op Code</th>
                    <th class="px-4 py-3">Out Time</th>
                    <th class="px-4 py-3">In Time</th>
                     <?php if ($is_logged_in): ?>
                        <th class="px-4 py-3 text-right">Distance (km)</th>
                    <?php endif; ?>
                    <th class="px-4 py-3 text-center">Emps</th>
                     <?php if ($is_logged_in): ?>
                        <th class="px-4 py-3">Done By</th>
                    <?php endif; ?>
                    <th class="px-4 py-3 text-center">Details</th>
                     <?php if ($is_logged_in): ?>
                    <th class="px-4 py-3 text-center">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($heldup_records)): ?>
                    <tr><td colspan="10" class="px-6 py-4 text-center text-gray-500">No records available for <?php echo htmlspecialchars($filterDate); ?>.</td></tr>
                <?php else: ?>
                    <?php foreach ($heldup_records as $entry): 
                        $is_done = $entry['heldup_done_status'] == 1;
                        $trip_id = $entry['trip_id'];
                        $trip_user_id = $entry['user_id'];
                        $row_class = $is_done ? 'bg-green-50 hover:bg-green-100' : 'bg-red-50 hover:bg-red-100';
                        $is_owner = ($is_logged_in && $current_session_user_id === $trip_user_id);
                        
                        $t_year = (int)date('Y', strtotime($entry['date']));
                        $t_month = (int)date('m', strtotime($entry['date']));
                        $is_locked = ($t_year < $max_year || ($t_year == $max_year && $t_month <= $max_month));
                    ?>
                        <tr class="<?php echo $row_class; ?> border-b border-gray-100 transition duration-150">
                            <td class="px-4 py-3 font-medium"><?php echo $trip_id; ?></td>
                            <td class="px-4 py-3"><?php echo $entry['vehicle_no']; ?></td>
                            <td class="px-4 py-3"><?php echo $entry['op_code']; ?></td>
                            <td class="px-4 py-3"><?php echo htmlspecialchars($entry['out_time'] ?? '---'); ?></td>
                            <td class="px-4 py-3"><?php echo htmlspecialchars($entry['in_time'] ?? '---'); ?></td>
                             <?php if ($is_logged_in): ?>
                            <td class="px-4 py-3 text-right font-mono"><?php echo number_format($entry['distance'] ?? 0, 2); ?></td>
                            <?php endif; ?>
                            <td class="px-4 py-3 text-center"><span class="bg-gray-200 text-gray-700 py-0.5 px-2 rounded-full text-xs font-bold"><?php echo $entry['employee_count']; ?></span></td>
                            <?php if ($is_logged_in): ?>
                            <td class="px-4 py-3 text-xs"><?php echo htmlspecialchars($entry['done_by_user_display'] ?? '---'); ?></td>
                            <?php endif; ?>
                            <td class="px-4 py-3 text-center">
                                <button data-trip-id="<?php echo $trip_id; ?>" class="view-reasons-btn bg-indigo-100 hover:bg-indigo-200 text-indigo-700 p-1.5 rounded-full"><i class="fas fa-eye"></i></button>
                            </td>
                            <?php if ($is_logged_in): ?>   
                            <td class="px-4 py-3 text-center flex justify-center gap-1">
                                <?php if ($is_locked): ?>
                                    <span class="text-red-500 font-bold text-[10px] uppercase italic border border-red-200 px-1 rounded bg-red-50"><i class="fas fa-lock mr-1"></i>Locked</span>
                                <?php else: ?>
                                    <?php if ($is_done): ?>
                                        <?php if ($is_owner): ?>
                                            <a href="day_heldup_process.php?trip_id=<?php echo $trip_id; ?>&action=edit_reasons" class="bg-blue-600 hover:bg-blue-700 text-white p-1.5 rounded-md text-xs shadow-sm flex items-center gap-1" title="Full Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-[10px] italic border border-gray-200 px-1 rounded">Private</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php 
                                        $in_time_is_null = empty($entry['in_time']) || $entry['in_time'] === '---';
                                        if ($in_time_is_null && $trip_user_id === 0 && !$is_logged_in): ?>
                                            <button data-trip-id="<?php echo $trip_id; ?>" class="set-in-time-btn bg-yellow-600 hover:bg-yellow-700 text-white p-1.5 rounded-md text-xs shadow-sm"><i class="fas fa-clock"></i></button>
                                        <?php endif; ?>

                                        <?php if ($is_logged_in): ?>
                                            <button data-trip-id="<?php echo $trip_id; ?>" data-op-code="<?php echo $entry['op_code']; ?>" class="complete-trip-btn bg-green-500 hover:bg-green-600 text-white p-1.5 rounded-md text-xs shadow-sm"><i class="fas fa-check"></i></button>
                                            <?php if ($can_act): ?>
                                                <a href="day_heldup_process.php?trip_id=<?php echo $trip_id; ?>&action=edit_reasons" class="bg-yellow-500 hover:bg-yellow-600 text-white p-1.5 rounded-md text-xs shadow-sm"><i class="fas fa-edit"></i></a>
                                            <?php endif; ?>
                                            <?php 
                                            $show_delete = ($trip_user_id === 0 || $is_owner);
                                            $delete_security_type = ($trip_user_id === 0) ? 'PIN_REQUIRED' : 'OWNER';
                                            if ($show_delete): ?>
                                                <button data-trip-id="<?php echo $trip_id; ?>" data-security-type="<?php echo $delete_security_type; ?>" class="delete-trip-btn bg-red-500 hover:bg-red-600 text-white p-1.5 rounded-md text-xs shadow-sm"><i class="fas fa-trash-alt"></i></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
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

<div id="reasonsModal" class="reason-modal-overlay">
    <div class="reason-modal-content">
        <h2 class="text-xl font-bold mb-4 border-b pb-2 text-indigo-700">Reasons Breakdown: <span id="modalTripId"></span></h2>
        <div id="reasonsContent" class="overflow-y-auto max-h-80"><p>Loading...</p></div>
        <div class="flex justify-end mt-4"><button onclick="document.getElementById('reasonsModal').style.display='none'" class="bg-gray-500 text-white py-2 px-4 rounded hover:bg-gray-600">Close</button></div>
    </div>
</div>

<div id="completeTripModal" class="complete-modal-overlay">
    <div class="complete-modal-content">
        <h2 class="text-xl font-bold mb-4 text-green-700 border-b pb-2">Finalize Trip Completion</h2>
        <form id="completeTripForm">
            <input type="hidden" id="completeTripHiddenId" name="trip_id">
            <div class="mb-4">
                <label class="block text-sm font-bold mb-2">Total Distance Traveled (km):</label>
                <input type="number" step="0.01" id="distanceInput" name="distance" required class="w-full border p-2 rounded focus:ring-2 focus:ring-green-500 outline-none" placeholder="Enter KM">
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('completeTripModal').style.display='none'" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Cancel</button>
                <button type="submit" id="confirmCompleteBtn" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 shadow">Save & Complete</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteTripModal" class="complete-modal-overlay">
    <div class="complete-modal-content border-2 border-red-200">
        <h2 class="text-xl font-bold mb-4 text-red-600 border-b pb-2">Confirm Deletion</h2>
        <form id="deleteTripForm">
            <input type="hidden" id="deleteTripHiddenId" name="trip_id">
            <div id="pinGroup" class="mb-4">
                <label class="block text-sm font-bold mb-2">Enter Security PIN:</label>
                <input type="password" id="deletePinInput" name="pin" class="w-full border p-2 rounded focus:ring-2 focus:ring-red-500 outline-none">
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('deleteTripModal').style.display='none'" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Cancel</button>
                <button type="submit" id="confirmDeleteBtn" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 shadow">Delete Record</button>
            </div>
        </form>
    </div>
</div>

<div id="toast-container"></div>

<script>
    const CURRENT_SESSION_USER_ID = <?php echo json_encode($current_session_user_id); ?>;

    function showToast(message, type) {
        const container = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast ${type}`;
        t.innerHTML = `<span>${message}</span>`;
        container.appendChild(t);
        setTimeout(() => t.classList.add('show'), 10);
        setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3000);
    }

    document.querySelectorAll('.view-reasons-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.tripId;
            document.getElementById('modalTripId').textContent = id;
            document.getElementById('reasonsModal').style.display = 'flex';
            const content = document.getElementById('reasonsContent');
            content.innerHTML = '<div class="flex justify-center p-4"><i class="fas fa-spinner fa-spin fa-2x text-indigo-500"></i></div>';
            
            const fd = new FormData();
            fd.append('fetch_reasons', '1');
            fd.append('trip_id', id);
            
            try {
                const res = await fetch('day_heldup_register.php', { method: 'POST', body: fd });
                const data = await res.json();
                if(data.success && data.reasons.length > 0) {
                    let h = '<table class="w-full text-left text-xs border-collapse"><thead><tr class="bg-indigo-50">';
                    h += '<th class="p-2 border-b">Emp ID</th><th class="p-2 border-b">Name</th><th class="p-2 border-b">Dept</th><th class="p-2 border-b">Reason</th>';
                    h += '</tr></thead><tbody>';
                    data.reasons.forEach(r => {
                        h += `<tr class="hover:bg-gray-50"><td class="p-2 border-b font-bold">${r.emp_id}</td><td class="p-2 border-b">${r.calling_name || '---'}</td><td class="p-2 border-b uppercase text-[9px] text-gray-500">${r.department || '---'}</td><td class="p-2 border-b italic">${r.reason}</td></tr>`;
                    });
                    h += '</tbody></table>';
                    content.innerHTML = h;
                } else {
                    content.innerHTML = '<p class="text-center py-4 text-gray-500">No data found.</p>';
                }
            } catch (err) {
                content.innerHTML = '<p class="text-center py-4 text-red-500">Error loading details.</p>';
            }
        });
    });

    document.querySelectorAll('.complete-trip-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('completeTripHiddenId').value = this.dataset.tripId;
            document.getElementById('completeTripModal').style.display = 'flex';
            document.getElementById('distanceInput').value = '';
            document.getElementById('distanceInput').focus();
        });
    });

    document.getElementById('completeTripForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('confirmCompleteBtn');
        btn.disabled = true;
        btn.innerText = 'Processing...';

        const fd = new FormData(this);
        fd.append('action', 'complete');
        const res = await fetch('day_heldup_process.php', { method: 'POST', body: new URLSearchParams(fd) });
        const data = await res.json();
        
        if(data.success) { 
            showToast(data.message, 'success'); 
            setTimeout(() => location.reload(), 800); 
        } else { 
            showToast(data.message, 'error'); 
            btn.disabled = false;
            btn.innerText = 'Save & Complete';
        }
    });

    document.querySelectorAll('.delete-trip-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.tripId;
            const type = this.dataset.securityType;
            document.getElementById('deleteTripHiddenId').value = id;
            document.getElementById('pinGroup').style.display = (type === 'PIN_REQUIRED') ? 'block' : 'none';
            document.getElementById('deletePinInput').value = '';
            document.getElementById('deleteTripModal').style.display = 'flex';
            document.getElementById('deleteTripForm').dataset.securityType = type;
        });
    });

    document.getElementById('deleteTripForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        if(!confirm('This action cannot be undone. Delete?')) return;

        const fd = new FormData(this);
        fd.append('action', 'delete');
        fd.append('security_type', this.dataset.securityType);
        
        const res = await fetch('day_heldup_process.php', { method: 'POST', body: new URLSearchParams(fd) });
        const data = await res.json();
        if(data.success) { showToast(data.message, 'success'); setTimeout(() => location.reload(), 800); }
        else showToast(data.message, 'error');
    });

    document.querySelectorAll('.set-in-time-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            if(!confirm("Record current time as 'In Time' for this trip?")) return;
            const fd = new FormData();
            fd.append('trip_id', this.dataset.tripId);
            fd.append('action', 'set_in_time');
            const res = await fetch('day_heldup_process.php', { method: 'POST', body: new URLSearchParams(fd) });
            const data = await res.json();
            if(data.success) { showToast(data.message, 'success'); setTimeout(() => location.reload(), 800); }
        });
    });
</script>
</body>
</html>