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

// ---------------------------------------------------------
// 1. FETCH DATA (Logic: Night 6PM to Next Day 8AM = Same Day)
// ---------------------------------------------------------
// මෙහිදී අප භාවිතා කරන්නේ TIMESTAMP(nh.date, nh.time) එකෙන් පැය 8ක් අඩු කිරීමේ උපක්‍රමයයි.
// එවිට පසුදා උදේ 8 ට පෙර දත්ත, පෙර දිනටම ගොනු වී අංකනය වේ.
$sql = "
    SELECT nh.*, 
           emp.calling_name as done_by_name,
           ROW_NUMBER() OVER (
               PARTITION BY DATE_FORMAT(DATE_SUB(CAST(CONCAT(nh.date, ' ', nh.time) AS DATETIME), INTERVAL 8 HOUR), '%Y-%m') 
               ORDER BY nh.date ASC, nh.time ASC
           ) as monthly_id
    FROM nh_register nh
    LEFT JOIN admin a ON nh.user_id = a.user_id
    LEFT JOIN employee emp ON a.emp_id = emp.emp_id
    WHERE nh.date = ? 
    ORDER BY nh.time DESC
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
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s ease-in-out; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
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
        <span class="text-gray-600">|</span>
        <a href="nh_add<?php echo $can_act ? '.php' : '_trip.php'; ?>" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs">
            Add Trip
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200">
        <table class="w-full table-auto">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-3 text-left">Monthly ID</th>
                    <th class="px-4 py-3 text-left">Time</th>
                    <th class="px-4 py-3 text-left">Vehicle No</th>
                    <th class="px-4 py-3 text-left">Op Code</th>
                    <th class="px-4 py-3 text-center">Qty</th>
                    <th class="px-4 py-3 text-center">Distance</th>
                    <th class="px-4 py-3 text-left">Done By</th>
                    <?php if ($is_logged_in): ?>
                        <th class="px-4 py-3 text-center">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php if (empty($heldup_records)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-4 text-center text-gray-500">No records found for <?php echo htmlspecialchars($filterDate); ?>.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($heldup_records as $row): 
                        $is_completed = ($row['done'] == 1);
                        $row_class = $is_completed ? 'bg-green-50 hover:bg-green-100' : 'bg-red-50 hover:bg-red-100';
                        $can_edit = $is_completed && ((int)$row['user_id'] === $current_session_user_id);
                    ?>
                        <tr class="<?php echo $row_class; ?> border-b border-gray-100 transition duration-150">
                            <td class="px-4 py-3 font-mono text-blue-600 font-bold">
                                #<?php echo $row['monthly_id']; ?>
                            </td>
                            <td class="px-4 py-3"><?php echo date('H:i', strtotime($row['time'])); ?></td>
                            <td class="px-4 py-3 font-bold uppercase"><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                            <td class="px-4 py-3">
                                <?php if ($is_completed): ?>
                                    <span class="bg-purple-100 text-purple-700 px-2 py-1 rounded text-xs font-bold"><?php echo htmlspecialchars($row['op_code']); ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs italic">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center"><?php echo $row['quantity']; ?></td>
                            <td class="px-4 py-3 text-center font-mono"><?php echo ($row['distance'] > 0) ? number_format($row['distance'], 2) . ' km' : '-'; ?></td>
                            <td class="px-4 py-3 text-xs"><?php echo htmlspecialchars($row['done_by_name'] ?: '-'); ?></td>

                            <?php if ($is_logged_in): ?>
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

<div id="toast-container"></div>

<script>
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