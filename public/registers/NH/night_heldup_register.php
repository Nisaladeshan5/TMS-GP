<?php
// night_heldup_register.php

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

// FETCH DATA
$startRange = $filterDate . ' 12:00:00';
$endRange = date('Y-m-d', strtotime($filterDate . ' +1 day')) . ' 11:59:59';

$sql = "
    SELECT nh.*, 
           emp.calling_name as done_by_name,
           ROW_NUMBER() OVER (
               PARTITION BY DATE(DATE_SUB(CAST(CONCAT(nh.date, ' ', nh.time) AS DATETIME), INTERVAL 12 HOUR)) 
               ORDER BY nh.date ASC, nh.time ASC
           ) as daily_id
    FROM nh_register nh
    LEFT JOIN admin a ON nh.user_id = a.user_id
    LEFT JOIN employee emp ON a.emp_id = emp.emp_id
    WHERE CAST(CONCAT(nh.date, ' ', nh.time) AS DATETIME) BETWEEN ? AND ?
    ORDER BY nh.date DESC, nh.time DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $startRange, $endRange);
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
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s ease-in-out; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        
        /* Modal & Blur Fixes */
        .modal-active { overflow: hidden; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f8fafc; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Glassmorphism Backdrop */
        .glass-overlay {
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Night Heldup Register
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            <a href="?date=<?php echo date('Y-m-d', strtotime($filterDate . ' -1 day')); ?>" class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150">
                <i class="fas fa-chevron-left"></i>
            </a>
            <form method="GET" class="flex items-center mx-1">
                <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer text-center w-32 appearance-none">
            </form>
            <a href="?date=<?php echo date('Y-m-d', strtotime($filterDate . ' +1 day')); ?>" class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <?php if ($is_logged_in): ?>
            <a href="nh_export_excel.php?month=<?php echo $filterDate; ?>" 
            class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs">
                <i class="fas fa-file-excel mr-1"></i> Export Excel
            </a>
        <?php endif; ?>
        <span class="text-gray-600">|</span>
        <a href="<?php echo $is_logged_in ? 'nh_add.php' : 'nh_add_trip.php'; ?>" 
        class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs">
            Add Trip
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200">
        <table class="w-full table-auto">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-3 text-left">Daily ID</th>
                    <th class="px-4 py-3 text-left">Schedule</th> 
                    <th class="px-4 py-3 text-left">Time</th>
                    <th class="px-4 py-3 text-left">Vehicle No</th>
                    <th class="px-4 py-3 text-left">Op Code</th>
                    <th class="px-4 py-3 text-center">Qty</th>
                <?php if ($is_logged_in): ?>
                    <th class="px-4 py-3 text-center">Distance</th>
                    <th class="px-4 py-3 text-left">Done By</th>
                    <th class="px-4 py-3 text-center">Details</th>
                    <th class="px-4 py-3 text-center">Action</th>
                <?php endif; ?>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php if (empty($heldup_records)): ?>
                    <tr>
                        <td colspan="10" class="px-6 py-4 text-center text-gray-500">No records found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($heldup_records as $row): 
                        $is_completed = ($row['done'] == 1);
                        $row_class = $is_completed ? 'bg-green-50 hover:bg-green-100' : 'bg-red-50 hover:bg-red-100';
                        $can_edit = $is_completed && ((int)$row['user_id'] === $current_session_user_id);
                    ?>
                        <tr class="<?php echo $row_class; ?> border-b border-gray-100 transition duration-150">
                            <td class="px-4 py-3 font-mono text-blue-600 font-bold">#<?php echo $row['daily_id']; ?></td>
                            <td class="px-4 py-3 font-semibold text-indigo-600 uppercase"><?php echo htmlspecialchars($row['schedule_time'] ?: '-'); ?></td>
                            <td class="px-4 py-3"><?php echo date('H:i', strtotime($row['time'])); ?></td>
                            <td class="px-4 py-3 font-bold uppercase"><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                            <td class="px-4 py-3">
                                <?php if ($is_completed): ?>
                                    <span class="bg-purple-100 text-purple-700 px-2 py-1 rounded text-xs font-bold"><?php echo htmlspecialchars($row['op_code']); ?></span>
                                <?php else: ?>
                                    <span class="text-red-500 text-xs italic font-bold animate-pulse">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center"><?php echo $row['quantity']; ?></td>
                            <?php if ($is_logged_in): ?>
                                <td class="px-4 py-3 text-center font-mono"><?php echo ($row['distance'] > 0) ? number_format($row['distance'], 2) . ' km' : '-'; ?></td>
                                <td class="px-4 py-3 text-xs"><?php echo htmlspecialchars($row['done_by_name'] ?: '-'); ?></td>
                                
                                <td class="px-4 py-3 text-center">
                                    <button onclick="viewParticipants(<?php echo $row['id']; ?>)" class="text-blue-500 hover:text-blue-700 transition-all transform hover:scale-125 focus:outline-none">
                                        <i class="fas fa-eye fa-lg"></i>
                                    </button>
                                </td>

                                <td class="px-4 py-3 text-center">
                                    <?php if (!$is_completed): ?>
                                        <a href="nh_complete_trip.php?id=<?php echo $row['id']; ?>" class="bg-green-600 hover:bg-green-700 text-white text-xs px-2 py-1.5 rounded shadow inline-flex items-center">
                                            <i class="fas fa-check-circle"></i>
                                        </a>
                                    <?php elseif ($can_edit): ?>
                                        <a href="nh_complete_trip.php?id=<?php echo $row['id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white text-xs px-2 py-1.5 rounded shadow inline-flex items-center">
                                            <i class="fas fa-pencil-alt"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs italic"><i class="fas fa-lock"></i></span>
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

<div id="participantModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 glass-overlay transition-all duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-100">
        <div class="bg-gradient-to-r from-blue-700 to-indigo-900 text-white px-6 py-5 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <i class="fas fa-users text-xl text-yellow-400"></i>
                <div>
                    <h3 class="font-bold text-lg leading-tight">Trip Crew</h3>
                    <p class="text-[10px] text-blue-200 uppercase tracking-tighter">Night Heldup Register</p>
                </div>
            </div>
            <button onclick="closeModal()" class="w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center transition focus:outline-none text-2xl">&times;</button>
        </div>
        
        <div class="p-0">
            <div id="modalLoader" class="hidden py-16 text-center">
                <i class="fas fa-spinner fa-spin text-4xl text-blue-600"></i>
                <p class="mt-3 text-gray-500 text-sm">Getting details...</p>
            </div>
            <div id="modalContent" class="max-h-[60vh] overflow-y-auto custom-scrollbar">
                <table class="w-full text-sm text-left">
                    <thead class="sticky top-0 bg-gray-50/95 backdrop-blur-sm z-10 border-b">
                        <tr>
                            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase">Emp ID</th>
                            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase text-right">Dept</th>
                        </tr>
                    </thead>
                    <tbody id="participantListContent" class="divide-y divide-gray-50">
                        </tbody>
                </table>
            </div>
        </div>

        <div class="bg-gray-50/50 px-6 py-4 flex justify-end">
            <button onclick="closeModal()" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl shadow-lg transition transform hover:scale-105 active:scale-95">
                Got it
            </button>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script>
    function viewParticipants(tripId) {
        const modal = document.getElementById('participantModal');
        const contentBody = document.getElementById('participantListContent');
        const loader = document.getElementById('modalLoader');
        const contentTable = document.getElementById('modalContent');

        modal.classList.remove('hidden');
        document.body.classList.add('modal-active');
        loader.classList.remove('hidden');
        contentTable.classList.add('hidden');
        contentBody.innerHTML = ''; 

        fetch(`get_trip_participants.php?trip_id=${tripId}`)
            .then(response => response.json())
            .then(data => {
                loader.classList.add('hidden');
                contentTable.classList.remove('hidden');
                if (data.length === 0) {
                    contentBody.innerHTML = '<tr><td colspan="3" class="px-6 py-12 text-center text-gray-400 font-medium">No team members assigned.</td></tr>';
                } else {
                    data.forEach(emp => {
                        contentBody.innerHTML += `
                            <tr class="hover:bg-blue-50/50 transition">
                                <td class="px-6 py-4 font-mono text-blue-600 font-bold text-xs">${emp.emp_id}</td>
                                <td class="px-6 py-4 font-semibold text-gray-800">${emp.calling_name}</td>
                                <td class="px-6 py-4 text-right"><span class="px-2 py-1 bg-gray-100 text-gray-600 rounded text-[10px] font-bold uppercase tracking-tight">${emp.department}</span></td>
                            </tr>`;
                    });
                }
            })
            .catch(() => {
                loader.classList.add('hidden');
                contentTable.classList.remove('hidden');
                contentBody.innerHTML = '<tr><td colspan="3" class="px-6 py-12 text-center text-red-500 font-bold">Failed to connect to database.</td></tr>';
            });
    }

    function closeModal() {
        document.getElementById('participantModal').classList.add('hidden');
        document.body.classList.remove('modal-active');
    }

    window.onclick = function(event) {
        if (event.target == document.getElementById('participantModal')) { closeModal(); }
    }

    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type} show`;
        toast.innerHTML = `<span>${message}</span>`;
        toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
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