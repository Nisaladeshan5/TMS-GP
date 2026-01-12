<?php
// varification.php

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- 1. AJAX HANDLER (Update ID, Return Name) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_driver') {
    header('Content-Type: application/json');

    $record_id = $_POST['id'];
    $new_nic = trim($_POST['new_nic']);
    
    // Session එකෙන් User ID එක ගන්න
    $logged_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0; 

    if (empty($record_id) || empty($new_nic)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid data.']);
        exit;
    }

    // 1. Database එකට User ID එක Save කරන්න
    $update_sql = "UPDATE cross_check SET driver_NIC = ?, updated_by = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("sii", $new_nic, $logged_user_id, $record_id);

    if ($stmt->execute()) {
        
        // 2. Frontend එකට යවන්න Calling Name එක හොයාගන්න
        $name_sql = "SELECT e.calling_name 
                     FROM admin a 
                     JOIN employee e ON a.emp_id = e.emp_id 
                     WHERE a.user_id = ?";
                     
        $stmt_name = $conn->prepare($name_sql);
        $stmt_name->bind_param("i", $logged_user_id);
        $stmt_name->execute();
        $res_name = $stmt_name->get_result();
        
        $display_name = "Unknown";
        if ($row_name = $res_name->fetch_assoc()) {
            $display_name = $row_name['calling_name'];
        }

        echo json_encode([
            'status' => 'success', 
            'message' => 'Driver NIC updated.',
            'updated_by_name' => $display_name
        ]);
        
        $stmt_name->close();

    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database update failed.']);
    }
    $stmt->close();
    exit;
}

include('../../includes/header.php');
include('../../includes/navbar.php');

// Filters
$filterDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filterShift = isset($_GET['shift']) ? $_GET['shift'] : 'morning';

$prevDate = date('Y-m-d', strtotime($filterDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($filterDate . ' +1 day'));

$records = [];
$connection_error = null;

if (isset($conn) && $conn instanceof mysqli && $conn->connect_error === null) {
    
    // --- MAIN QUERY ---
    $sql = "SELECT 
                r.id, 
                r.route AS route_code, 
                rm.route, 
                r.actual_vehicle_no, 
                r.driver_NIC, 
                r.time, 
                r.shift, 
                r.updated_by,
                e.calling_name AS updater_name 
            FROM cross_check r
            LEFT JOIN route rm ON r.route = rm.route_code
            LEFT JOIN admin a ON r.updated_by = a.user_id
            LEFT JOIN employee e ON a.emp_id = e.emp_id
            WHERE DATE(r.date) = ?";
            
    if ($filterShift !== 'all') { 
        $sql .= " AND r.shift = ?";
    }
    
    $sql .= " ORDER BY CAST(SUBSTR(r.route, 7, 3) AS UNSIGNED) ASC, r.time ASC";
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $param_types = 's';
        $param_values = [&$filterDate];
        if ($filterShift !== 'all') {
            $param_types .= 's';
            $param_values[] = &$filterShift;
        }
        call_user_func_array([$stmt, 'bind_param'], array_merge([$param_types], $param_values));
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    $connection_error = "FATAL: Database connection failed.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Vehicle Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; max-width: 400px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        
        .route-F { background-color: #dafcf3ff !important; }
        .route-S { background-color: #fff3da !important; }
        
        /* Updated Cell Style */
        .updated-cell { background-color: #fef08a !important; } /* yellow-200 */
        .editable-cell { cursor: pointer; transition: background-color 0.2s; }
        .editable-cell:hover { background-color: #f3f4f6; }

        /* Custom Scrollbar for the table container */
        .custom-scrollbar::-webkit-scrollbar { width: 8px; height: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
    </style>
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 
        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>

<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Varification
            </a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">Records</span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <form method="GET" class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            <a href="?date=<?php echo $prevDate; ?>&shift=<?php echo $filterShift; ?>" class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150"><i class="fas fa-chevron-left"></i></a>
            <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer px-2 h-8">
            <a href="?date=<?php echo $nextDate; ?>&shift=<?php echo $filterShift; ?>" class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150"><i class="fas fa-chevron-right"></i></a>
            <span class="text-gray-400 mx-1">|</span>
            <div class="relative">
                <select name="shift" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-1 pr-2">
                    <?php
                    $shifts = ['morning', 'evening']; 
                    foreach ($shifts as $shift) {
                        $selected = ($filterShift === $shift) ? 'selected' : '';
                        echo "<option value='{$shift}' {$selected} class='text-gray-900 bg-white'>" . ucfirst($shift) . "</option>";
                    }
                    ?>
                </select>
            </div>
        </form>
        <span class="text-gray-600">|</span>
        <a href="missing_routes.php" class="text-gray-300 hover:text-white transition">Missing Routes</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    <?php if ($connection_error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 w-full mx-auto" role="alert">
            <strong class="font-bold">Database Error!</strong> <?php echo htmlspecialchars($connection_error); ?>
        </div>
    <?php endif; ?>

    <div class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full mx-auto custom-scrollbar" style="max-height: 88vh;">
        <table class="w-full table-auto border-collapse">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Route Code</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Route</th> 
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Vehicle No</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Driver LID (Edit)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Time</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Shift</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php
                if (count($records) > 0) {
                    $row_counter = 0;
                    foreach ($records as $row) {
                        $row_counter++;
                        $time = ($row['time'] !== null) ? date('H:i', strtotime($row['time'])) : '-';
                        
                        $route_code = $row['route_code'];
                        $highlight_class = '';
                        if (strlen($route_code) >= 5) {
                            $fifth_char = strtoupper($route_code[4]);
                            if ($fifth_char === 'F') $highlight_class = 'route-F';
                            elseif ($fifth_char === 'S') $highlight_class = 'route-S';
                        }
                        
                        $base_row_class = ($row_counter % 2 == 0) ? 'bg-gray-50' : '';
                        $route_name = !empty($row['route']) ? htmlspecialchars($row['route']) : 'N/A';
                        
                        // Check if updated
                        $is_updated = !empty($row['updated_by']);
                        $cell_bg = $is_updated ? 'updated-cell' : 'editable-cell';
                        
                        // Updater name
                        $updater_name_display = !empty($row['updater_name']) ? $row['updater_name'] : 'Unknown';
                        $updated_by_text = $is_updated ? "<br><span class='text-[10px] text-gray-500 font-semibold italic'>Updated by: " . htmlspecialchars($updater_name_display) . "</span>" : "";

                        echo "<tr class='border-b {$base_row_class} {$highlight_class} transition duration-150'>
                            <td class='border-r px-6 py-2 font-mono font-medium text-blue-600'>{$route_code}</td>
                            <td class='border-r px-6 py-2 font-semibold text-gray-800'>{$route_name}</td> 
                            <td class='border-r px-6 py-2 font-bold uppercase'>{$row['actual_vehicle_no']}</td>
                            
                            <td class='border-r px-6 py-2 {$cell_bg}' 
                                ondblclick=\"makeEditable(this, '{$row['id']}')\" 
                                title='Double click to edit'>
                                <span class='nic-text'>{$row['driver_NIC']}</span>
                                <span class='updater-info'>{$updated_by_text}</span>
                            </td>

                            <td class='border-r px-6 py-2'>{$time}</td>
                            <td class='px-6 py-2'>" . ucfirst($row['shift']) . "</td>
                        </tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' class='text-center py-4 text-gray-500'>No records found.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

<script>
    // Toast Function
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let iconHtml = '';
        if(type === 'success') iconHtml = '<i class="fas fa-check-circle mr-2"></i>';
        else if(type === 'error') iconHtml = '<i class="fas fa-exclamation-circle mr-2"></i>';

        toast.innerHTML = `${iconHtml}<span>${message}</span>`;
        toastContainer.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Double Click to Edit
    function makeEditable(td, id) {
        if (td.querySelector('input')) return;

        const nicSpan = td.querySelector('.nic-text');
        const updaterSpan = td.querySelector('.updater-info');
        const currentText = nicSpan.innerText.trim();
        
        const input = document.createElement('input');
        input.type = 'text';
        input.value = currentText;
        input.className = 'w-full p-1 border rounded focus:ring-2 focus:ring-blue-500 text-sm';
        
        td.innerHTML = '';
        td.appendChild(input);
        input.focus();

        const saveEdit = () => {
            const newText = input.value.trim();

            if (newText === currentText) {
                td.innerHTML = '';
                td.appendChild(nicSpan);
                td.appendChild(updaterSpan);
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_driver');
            formData.append('id', id);
            formData.append('new_nic', newText);

            fetch('varification.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    
                    td.classList.remove('editable-cell');
                    td.classList.add('updated-cell');
                    
                    const newUpdaterHtml = `<br><span class='text-[10px] text-gray-500 font-semibold italic'>Updated by: ${data.updated_by_name}</span>`;
                    
                    td.innerHTML = `<span class='nic-text'>${newText}</span><span class='updater-info'>${newUpdaterHtml}</span>`;
                } else {
                    showToast(data.message, 'error');
                    td.innerHTML = '';
                    td.appendChild(nicSpan);
                    td.appendChild(updaterSpan);
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Something went wrong', 'error');
                td.innerHTML = '';
                td.appendChild(nicSpan);
                td.appendChild(updaterSpan);
            });
        };

        input.addEventListener('blur', saveEdit);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                input.blur();
            }
        });
    }
</script>

</body>
</html>