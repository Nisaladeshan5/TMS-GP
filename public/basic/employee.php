<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- 0. PRE-FETCH ALL SUB ROUTES FOR DROPDOWN (ONLY ACTIVE ONES) ---
$all_sub_routes = [];
$sub_route_sql = "SELECT sub_route_code, sub_route FROM sub_route WHERE is_active = 1 ORDER BY sub_route";
$sr_result = $conn->query($sub_route_sql);
if ($sr_result) {
    while ($row = $sr_result->fetch_assoc()) {
        $all_sub_routes[] = $row;
    }
}

// --- 1. AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $emp_id = $_POST['emp_id'];

    if (empty($emp_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Employee ID.']);
        exit;
    }

    // UPDATE PHONE
    if ($_POST['action'] === 'update_phone') {
        $new_phone = trim($_POST['phone_no']);
        $stmt = $conn->prepare("UPDATE employee SET phone_no = ? WHERE emp_id = ?");
        $stmt->bind_param("ss", $new_phone, $emp_id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Phone updated.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Update failed.']);
        }
        $stmt->close();
        exit;
    }

    // UPDATE SUB ROUTE (UPDATED FOR MULTIPLE ROUTES)
    if ($_POST['action'] === 'update_sub_route') {
        $new_sub_route_codes = trim($_POST['sub_route_code']); // Example: "SR001,SR002"
        
        if ($new_sub_route_codes === "") {
             $stmt = $conn->prepare("UPDATE employee SET sub_route_code = NULL WHERE emp_id = ?");
             $stmt->bind_param("s", $emp_id);
        } else {
             $stmt = $conn->prepare("UPDATE employee SET sub_route_code = ? WHERE emp_id = ?");
             $stmt->bind_param("ss", $new_sub_route_codes, $emp_id);
        }

        if ($stmt->execute()) {
            if ($new_sub_route_codes === "") {
                $new_name = '-';
            } else {
                // Fetch all names for the assigned comma-separated codes
                $codes_array = explode(',', $new_sub_route_codes);
                $placeholders = implode(',', array_fill(0, count($codes_array), '?'));
                $name_stmt = $conn->prepare("SELECT GROUP_CONCAT(sub_route SEPARATOR ', ') as names FROM sub_route WHERE sub_route_code IN ($placeholders)");
                $name_stmt->bind_param(str_repeat('s', count($codes_array)), ...$codes_array);
                $name_stmt->execute();
                $res = $name_stmt->get_result();
                $new_name = ($res->num_rows > 0) ? $res->fetch_assoc()['names'] : '-';
                $name_stmt->close();
            }
            
            echo json_encode(['status' => 'success', 'message' => 'Sub route updated.', 'new_name' => $new_name]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Update failed.']);
        }
        $stmt->close();
        exit;
    }
}

// --- HELPER FUNCTION: GET DATA (UPDATED FOR MULTIPLE ROUTES) ---
function getEmployeeData($conn, $filters) {
    // Note: GROUP_CONCAT and FIND_IN_SET added to support multiple comma-separated route codes
    $sql = "SELECT 
                e.emp_id, 
                e.calling_name, 
                e.department, 
                e.gender, 
                e.route, 
                e.near_bus_stop,
                e.phone_no, 
                e.sub_route_code, 
                SUBSTRING(e.route, 1, 10) AS route_code, 
                SUBSTRING(e.route, 12, LENGTH(e.route) - 12) AS route_name,
                (SELECT GROUP_CONCAT(sub_route SEPARATOR ', ') 
                 FROM sub_route 
                 WHERE FIND_IN_SET(sub_route_code, e.sub_route_code) > 0) AS sub_route_name 
            FROM employee e
            WHERE e.is_active = 1"; 

    $params = [];
    $param_types = '';

    if (!empty($filters['emp_id'])) {
        $sql .= " AND (e.emp_id LIKE ? OR e.calling_name LIKE ?)";
        $searchTerm = "%" . $filters['emp_id'] . "%";
        $params[] = $searchTerm; 
        $params[] = $searchTerm; 
        $param_types .= 'ss';
    }
    if (!empty($filters['department'])) {
        $sql .= " AND e.department = ?";
        $params[] = $filters['department'];
        $param_types .= 's';
    }
    if (!empty($filters['route_code'])) {
        $sql .= " AND SUBSTRING(e.route, 1, 10) = ?";
        $params[] = $filters['route_code'];
        $param_types .= 's';
    }
    
    // --- Sub Route Filter Logic with FIND_IN_SET handling ---
    if (!empty($filters['sub_route_code'])) {
        if ($filters['sub_route_code'] === 'NONE') {
            $sql .= " AND (e.sub_route_code IS NULL OR e.sub_route_code = '')";
        } else {
            $sql .= " AND FIND_IN_SET(?, e.sub_route_code) > 0";
            $params[] = $filters['sub_route_code'];
            $param_types .= 's';
        }
    }

    if (!empty($filters['staff_type'])) {
        $char = strtoupper(substr($filters['staff_type'], 0, 1)); 
        if ($char === 'S' || $char === 'F') {
            $sql .= " AND SUBSTRING(e.route, 5, 1) = ?";
            $params[] = $char;
            $param_types .= 's';
        }
    }

    $sql .= " ORDER BY e.emp_id";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

// --- HELPER FUNCTION: RENDER ROWS ---
function renderTableRows($result) {
    if ($result && $result->num_rows > 0) {
        while ($employee = $result->fetch_assoc()) {
            
            $raw_bus_stop = $employee['near_bus_stop'] ?? '';
            $display_bus_stop = ucwords(strtolower($raw_bus_stop));
            
            $phone_no = htmlspecialchars($employee['phone_no'] ?? '');

            // Sub Route Logic
            $sub_route_name = !empty($employee['sub_route_name']) ? htmlspecialchars($employee['sub_route_name']) : '';
            $current_sub_code = !empty($employee['sub_route_code']) ? htmlspecialchars($employee['sub_route_code']) : '';

            $sub_route_display = "";
            if ($sub_route_name) {
                 $sub_route_display = "
                    <div class='mt-1'>
                        <span class='text-xs font-semibold text-purple-700 bg-purple-50 px-2 py-1 rounded border border-purple-100 whitespace-normal leading-tight block text-left' title='$sub_route_name'>
                            $sub_route_name
                        </span>
                    </div>";
            } else {
                $sub_route_display = "
                    <div class='mt-1'>
                        <span class='text-gray-400 text-[10px] font-medium border border-dashed border-gray-300 px-2 py-1 rounded hover:border-gray-500 hover:text-gray-600 transition block text-center bg-gray-50'>
                            + Assign
                        </span>
                    </div>";
            }

            $gender_badge = ($employee['gender'] === 'MALE') 
                ? '<span class="text-blue-600 bg-blue-100 px-2 py-0.5 rounded text-[10px] font-bold uppercase">Male</span>' 
                : '<span class="text-pink-600 bg-pink-100 px-2 py-0.5 rounded text-[10px] font-bold uppercase">Female</span>';

            echo "<tr class='hover:bg-blue-50 transition duration-150 group border-b border-gray-200'>
                    
                    <td class='px-4 py-3 align-top'>
                        <div class='flex flex-col'>
                            <span class='font-mono text-blue-600 font-bold text-sm'>{$employee['emp_id']}</span>
                            <span class='font-medium text-gray-800 text-sm capitalize truncate max-w-[150px]' title='{$employee['calling_name']}'>" 
                                . strtolower(htmlspecialchars($employee['calling_name'])) . 
                            "</span>
                        </div>
                    </td>

                    <td class='px-4 py-3 align-top' 
                        ondblclick=\"makeEditable(this, '{$employee['emp_id']}')\">
                        <div class='flex items-center pt-1'>
                             <span class='text-gray-700 font-mono text-sm cursor-pointer hover:text-blue-600 transition'>
                                " . ($phone_no ? $phone_no : '<span class="text-gray-400 text-xs border border-dashed border-gray-300 px-2 py-0.5 rounded hover:border-gray-400">+ Add</span>') . "
                            </span>
                        </div>
                    </td>

                    <td class='px-4 py-3 align-top'>
                        <div class='flex flex-col'>
                             <span class='font-mono text-gray-500 text-xs'>{$employee['route_code']}</span>
                             <span class='text-gray-700 text-sm font-medium capitalize truncate max-w-[180px]' title='{$employee['route_name']}'>" 
                                . strtolower(htmlspecialchars($employee['route_name'])) . 
                            "</span>
                        </div>
                    </td>

                    <td class='px-4 py-3 align-top'>
                        <div class='flex flex-col gap-1'>
                             <span class='text-gray-800 text-sm font-semibold whitespace-normal leading-snug'>" 
                                . htmlspecialchars($display_bus_stop) . 
                            "</span>
                            
                            <div class='cursor-pointer' 
                                 title='Double click to change Sub Route'
                                 ondblclick=\"makeSubRouteEditable(this, '{$employee['emp_id']}', '$current_sub_code')\">
                                $sub_route_display
                            </div>
                        </div>
                    </td>

                    <td class='px-4 py-3 align-top'>
                        <div class='flex flex-col gap-1.5'>
                            <span class='text-gray-600 text-[11px] font-bold uppercase tracking-wide truncate max-w-[120px]' title='{$employee['department']}'>" 
                                . htmlspecialchars($employee['department']) . 
                            "</span>
                            <div>$gender_badge</div>
                        </div>
                    </td>

                  </tr>";
        }
    } else {
        echo '<tr><td colspan="5" class="px-6 py-10 text-center text-gray-500 text-sm">No employees found</td></tr>';
    }
}

// --- 2. AJAX FILTER HANDLER ---
if (isset($_GET['ajax_filter'])) {
    $filters = [
        'emp_id' => $_GET['emp_id'] ?? '',
        'department' => $_GET['department'] ?? '',
        'route_code' => $_GET['route_code'] ?? '',
        'sub_route_code' => $_GET['sub_route_code'] ?? '', 
        'staff_type' => $_GET['staff_type'] ?? ''
    ];
    $result = getEmployeeData($conn, $filters);
    renderTableRows($result);
    exit; 
}

// --- 3. EXCEL EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filters = [ 
        'emp_id' => $_GET['emp_id'] ?? '', 
        'department' => $_GET['department'] ?? '', 
        'route_code' => $_GET['route_code'] ?? '', 
        'sub_route_code' => $_GET['sub_route_code'] ?? '', 
        'staff_type' => $_GET['staff_type'] ?? '' 
    ];
    $result = getEmployeeData($conn, $filters);

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=employee_details_" . date('Y-m-d') . ".xls");
    
    echo '<table border="1"><tr><th>Emp ID</th><th>Name</th><th>Sub Route</th></tr>';
    while ($r = $result->fetch_assoc()) {
        echo "<tr><td>{$r['emp_id']}</td><td>{$r['calling_name']}</td><td>{$r['sub_route_name']}</td></tr>";
    }
    echo '</table>';
    exit;
}

// --- 4. PAGE LOAD ---
include('../../includes/header.php'); 
include('../../includes/navbar.php');

$department_options = $conn->query("SELECT DISTINCT department FROM employee ORDER BY department")->fetch_all(MYSQLI_ASSOC);

$route_code_options = $conn->query("
    SELECT DISTINCT 
        SUBSTRING(route, 1, 10) AS route_code, 
        SUBSTRING(route, 12, LENGTH(route) - 12) AS route_name 
    FROM employee 
    WHERE LENGTH(route) >= 12
     AND route != ''
    ORDER BY route_code
")->fetch_all(MYSQLI_ASSOC);

$filters = [
    'emp_id' => $_GET['emp_id'] ?? '',
    'department' => $_GET['department'] ?? '',
    'route_code' => $_GET['route_code'] ?? '',
    'sub_route_code' => $_GET['sub_route_code'] ?? '', 
    'staff_type' => $_GET['staff_type'] ?? ''
];
$initial_result = getEmployeeData($conn, $filters);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
        #filterDrawer { transition: max-height 0.4s ease-in-out, opacity 0.3s ease-in-out; overflow: hidden; }
        .drawer-closed { max-height: 0; opacity: 0; padding-top: 0 !important; padding-bottom: 0 !important; border-bottom-width: 0 !important; pointer-events: none; }
        .drawer-open { max-height: 400px; opacity: 1; pointer-events: auto; }
        .table-loading { opacity: 0.5; pointer-events: none; }
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 0.75rem 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; font-size: 0.875rem; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; max-width: 300px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #10B981; } 
        .toast.error { background-color: #EF4444; } 
        .custom-select-list {
            scrollbar-width: thin;
            scrollbar-color: #A78BFA #F3F4F6;
        }
        .custom-select-list option:checked {
            background: #DDD6FE linear-gradient(0deg, #DDD6FE 0%, #DDD6FE 100%);
            color: #5B21B6;
            font-weight: bold;
        }
    </style>
</head>

<body class="bg-gray-100 overflow-hidden text-sm"> 

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-14 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">Employee</div>
    </div>
    <div class="flex items-center gap-3 text-xs font-medium">
        
        <button onclick="triggerUpdate()" class="flex items-center gap-2 bg-gray-600 hover:bg-gray-500 text-white px-3 py-1.5 rounded shadow-md transition border border-gray-500 hover:border-gray-400 font-semibold ml-4">
            <i class="fas fa-sync-alt"></i> Update DB
        </button>
        <button onclick="exportFilteredExcel()" class="flex items-center gap-2 bg-green-600 hover:bg-green-500 text-white px-3 py-1.5 rounded shadow-md transition border border-green-500 hover:border-green-400 font-semibold">
             Export Excel
        </button>
        <button onclick="toggleFilters()" id="filterToggleBtn" class="flex items-center gap-2 bg-slate-700 hover:bg-slate-600 text-gray-100 px-3 py-1.5 rounded shadow-md transition border border-slate-600 hover:border-slate-500 focus:outline-none focus:ring-1 focus:ring-yellow-400">
            <i class="fas fa-filter text-yellow-400"></i> <span id="filterBtnText">Show Filters</span> <i id="filterArrow" class="fas fa-chevron-down text-[10px] transition-transform duration-300"></i>
        </button>

        <a href="tobeupdateEmp.php" class="flex items-center gap-2 bg-rose-600 hover:bg-rose-500 text-white px-3 py-1.5 rounded shadow-md transition border border-rose-500 hover:border-rose-400 font-semibold">
             To Be Update
        </a>

        <a href="overview.php" class="flex items-center gap-2 bg-sky-600 hover:bg-sky-500 text-white px-3 py-1.5 rounded shadow-md transition border border-sky-500 hover:border-sky-400 font-semibold">
            <i class="fas fa-chart-pie"></i> Overview
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-14 h-screen flex flex-col relative">
    
    <div id="filterDrawer" class="bg-white shadow-lg border-b border-gray-300 drawer-closed absolute top-14 left-0 w-full z-40 px-6 py-4">
       <form id="filterForm" class="grid grid-cols-1 md:grid-cols-5 gap-4 pb-2" onsubmit="return false;">
            <div>
                <label class="block text-[10px] font-semibold text-gray-500 mb-1">Emp ID / Name</label>
                <input type="text" id="filter_emp_id" oninput="debouncedFilter()" value="<?php echo htmlspecialchars($filters['emp_id']); ?>" class="w-full border border-gray-300 rounded p-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none" placeholder="Search ID or Name...">
            </div>
            <div>
                <label class="block text-[10px] font-semibold text-gray-500 mb-1">Type</label>
                <select id="filter_staff_type" onchange="applyFilters()" class="w-full border border-gray-300 rounded p-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none cursor-pointer bg-white">
                    <option value="">All Types</option>
                    <option value="S" <?php echo (strtoupper($filters['staff_type']) === 'S') ? 'selected' : ''; ?>>Staff</option>
                    <option value="F" <?php echo (strtoupper($filters['staff_type']) === 'F') ? 'selected' : ''; ?>>Factory</option>
                </select>
            </div>
            
            <div>
                <label class="block text-[10px] font-semibold text-gray-500 mb-1">Route Code</label>
                <select id="filter_route_code" onchange="applyFilters()" class="w-full border border-gray-300 rounded p-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none cursor-pointer bg-white">
                    <option value="">All Routes</option>
                    <?php foreach ($route_code_options as $route): ?>
                        <option value="<?php echo htmlspecialchars($route['route_code']); ?>" <?php echo ($filters['route_code'] === $route['route_code']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($route['route_code'] . ' - ' . $route['route_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-[10px] font-semibold text-gray-500 mb-1">Sub Route (Active)</label>
                <select id="filter_sub_route" onchange="applyFilters()" class="w-full border border-gray-300 rounded p-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none cursor-pointer bg-white">
                    <option value="">All Employees</option>
                    <option value="NONE" <?php echo ($filters['sub_route_code'] === 'NONE') ? 'selected' : ''; ?>>-- No Sub Route --</option>
                    <?php foreach ($all_sub_routes as $sub): ?>
                        <option value="<?php echo htmlspecialchars($sub['sub_route_code']); ?>" <?php echo ($filters['sub_route_code'] === $sub['sub_route_code']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sub['sub_route']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-[10px] font-semibold text-gray-500 mb-1">Department</label>
                <select id="filter_department" onchange="applyFilters()" class="w-full border border-gray-300 rounded p-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none cursor-pointer bg-white">
                    <option value="">All Departments</option>
                    <?php foreach ($department_options as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['department']); ?>" <?php echo ($filters['department'] === $dept['department']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucwords(strtolower($dept['department']))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
       </form>
    </div>
    
    <div class="flex-grow overflow-hidden bg-gray-100 p-2 mt-1 transition-all duration-300">
        <div class="bg-white shadow-lg rounded-lg border border-gray-200 h-full flex flex-col">
            <div id="tableScrollContainer" class="overflow-auto flex-grow rounded-lg">
                <table class="w-full table-auto border-collapse relative">
                    <thead class="bg-gradient-to-r from-blue-600 to-blue-700 text-white text-xs sticky top-0 z-10 shadow-md">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold tracking-wide w-[20%]">Employee</th>
                            <th class="px-4 py-3 text-left font-semibold tracking-wide w-[15%]">Phone</th>
                            <th class="px-4 py-3 text-left font-semibold tracking-wide w-[25%]">Route Details</th>
                            <th class="px-4 py-3 text-left font-semibold tracking-wide w-[25%]">Location / Sub Route</th>
                            <th class="px-4 py-3 text-left font-semibold tracking-wide w-[15%]">Dept & Gender</th>
                        </tr>
                    </thead>
                    <tbody id="employeeTableBody" class="text-gray-700 divide-y divide-gray-200 text-sm bg-white">
                        <?php renderTableRows($initial_result); ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script>
    const subRouteOptions = <?php echo json_encode($all_sub_routes); ?>;

    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        let iconHtml = (type === 'success') ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-circle mr-2"></i>';
        toast.innerHTML = `${iconHtml}<span>${message}</span>`;
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function makeEditable(td, empId) {
        if (td.querySelector('input')) return; 
        const span = td.querySelector('span');
        const currentText = span.innerText.trim() === '+ Add' ? '' : span.innerText.trim();
        td.innerHTML = `<input type="text" value="${currentText}" class="w-full p-1 border border-blue-400 rounded text-sm font-mono outline-none shadow-sm" placeholder="Enter phone...">`;
        const input = td.querySelector('input');
        input.focus();

        const saveEdit = () => {
            const newText = input.value.trim();
            const renderContent = (val) => {
                 return `<div class='flex items-center pt-1'><span class='text-gray-700 font-mono text-sm cursor-pointer hover:text-blue-600 transition'>${val ? val : '<span class="text-gray-400 text-xs border border-dashed border-gray-300 px-2 py-0.5 rounded hover:border-gray-400">+ Add</span>'}</span></div>`;
            };
            if (newText === currentText) { td.innerHTML = renderContent(newText); return; }

            const formData = new FormData();
            formData.append('action', 'update_phone');
            formData.append('emp_id', empId);
            formData.append('phone_no', newText);

            fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') { showToast(data.message, 'success'); td.innerHTML = renderContent(newText); } 
                else { showToast(data.message, 'error'); td.innerHTML = renderContent(currentText); }
            }).catch(() => { showToast('Connection error', 'error'); td.innerHTML = renderContent(currentText); });
        };
        input.addEventListener('blur', saveEdit);
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') input.blur(); });
    }

    // UPDATED FOR MULTIPLE SELECTION
    function makeSubRouteEditable(div, empId, currentCodeStr) {
    if (div.querySelector('select')) return;

    const currentCodes = currentCodeStr ? currentCodeStr.split(',') : [];
    let optionsHtml = `<option value="" class="font-bold text-red-600">-- Clear All Selection --</option>`;
    
    subRouteOptions.forEach(route => {
        const isSelected = currentCodes.includes(route.sub_route_code) ? 'selected' : '';
        optionsHtml += `<option value="${route.sub_route_code}" ${isSelected} class="py-1 px-2 border-b border-gray-100 last:border-0">${route.sub_route}</option>`;
    });

    const originalContent = div.innerHTML; 
    
    // Size eka 8 wage dunnama options 8k digata penawa. 
    // Max-height ekak dala scrollable kala lassanata penna.
    div.innerHTML = `
        <div class="relative bg-white border border-purple-400 rounded shadow-xl p-1 z-50 min-w-[200px]">
            <select multiple id="temp_select" class="w-full text-xs bg-white outline-none custom-select-list" size="8">
                ${optionsHtml}
            </select>
            <div class="bg-purple-100 text-[10px] text-purple-800 p-1.5 mt-1 rounded font-medium flex justify-between items-center">
                <span>Hold <b>Ctrl</b> to select many</span>
                <button id="save_btn" class="bg-purple-600 text-white px-2 py-0.5 rounded hover:bg-purple-700">Save</button>
            </div>
        </div>
    `;
    
    const select = div.querySelector('select');
    const saveBtn = div.querySelector('#save_btn');
    select.focus();

    // User "Save" button eka click kalama hari select box eken eliyata giyama hari save wenawa
    const performSave = () => {
        const selectedOptions = Array.from(select.selectedOptions)
                                     .map(opt => opt.value)
                                     .filter(val => val !== "");
        
        const newCodeStr = selectedOptions.join(',');

        if (newCodeStr === currentCodeStr) { div.innerHTML = originalContent; return; }

        const formData = new FormData();
        formData.append('action', 'update_sub_route');
        formData.append('emp_id', empId);
        formData.append('sub_route_code', newCodeStr);

        fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') { 
                showToast(data.message, 'success'); 
                const newName = data.new_name;
                if(newCodeStr && newName !== '-') {
                    div.innerHTML = `<div class='mt-1'><span class='text-xs font-semibold text-purple-700 bg-purple-50 px-2 py-1 rounded border border-purple-100 whitespace-normal leading-tight block text-left' title='${newName}'>${newName}</span></div>`;
                    div.setAttribute('ondblclick', `makeSubRouteEditable(this, '${empId}', '${newCodeStr}')`);
                } else {
                    div.innerHTML = `<div class='mt-1'><span class='text-gray-400 text-[10px] font-medium border border-dashed border-gray-300 px-2 py-1 rounded hover:border-gray-500 hover:text-gray-600 transition block text-center bg-gray-50'>+ Assign</span></div>`;
                    div.setAttribute('ondblclick', `makeSubRouteEditable(this, '${empId}', '')`);
                }
            } else { 
                showToast(data.message, 'error'); 
                div.innerHTML = originalContent; 
            }
        }).catch(() => { showToast('Connection error', 'error'); div.innerHTML = originalContent; });
    };

    // Events
    saveBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        performSave();
    });

    // Option ekak double click kalath save wenna puluwan
    select.addEventListener('dblclick', performSave);
}

    const filterDrawer = document.getElementById('filterDrawer');
    const filterBtnText = document.getElementById('filterBtnText');
    const filterArrow = document.getElementById('filterArrow');
    const isFilterOpen = localStorage.getItem('empFilterOpen') === 'true';

    if (isFilterOpen) openFiltersUI();
    
    function toggleFilters() {
        if (filterDrawer.classList.contains('drawer-closed')) { openFiltersUI(); localStorage.setItem('empFilterOpen', 'true'); } 
        else { closeFiltersUI(); localStorage.setItem('empFilterOpen', 'false'); }
    }
    function openFiltersUI() {
        filterDrawer.classList.remove('drawer-closed'); filterDrawer.classList.add('drawer-open');
        filterBtnText.innerText = "Hide Filters"; filterArrow.style.transform = "rotate(180deg)";
    }
    function closeFiltersUI() {
        filterDrawer.classList.remove('drawer-open'); filterDrawer.classList.add('drawer-closed');
        filterBtnText.innerText = "Show Filters"; filterArrow.style.transform = "rotate(0deg)";
    }

    let debounceTimer;
    function debouncedFilter() { clearTimeout(debounceTimer); debounceTimer = setTimeout(() => applyFilters(), 300); }

    function applyFilters() {
        const empId = document.getElementById('filter_emp_id').value;
        const staffType = document.getElementById('filter_staff_type').value;
        const routeCode = document.getElementById('filter_route_code').value;
        const subRouteCode = document.getElementById('filter_sub_route').value;
        const department = document.getElementById('filter_department').value;

        const params = new URLSearchParams();
        if (empId) params.append('emp_id', empId);
        if (staffType) params.append('staff_type', staffType);
        if (routeCode) params.append('route_code', routeCode);
        if (subRouteCode) params.append('sub_route_code', subRouteCode);
        if (department) params.append('department', department);

        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({path: newUrl}, '', newUrl);

        params.append('ajax_filter', '1');
        const tbody = document.getElementById('employeeTableBody');
        tbody.classList.add('table-loading');

        fetch(window.location.pathname + '?' + params.toString())
            .then(r => r.text())
            .then(html => { tbody.innerHTML = html; tbody.classList.remove('table-loading'); })
            .catch(e => { console.error(e); tbody.classList.remove('table-loading'); });
    }
    
    document.getElementById('clearFiltersBtn')?.addEventListener('click', function() {
       document.querySelectorAll('#filterForm input, #filterForm select').forEach(el => el.value = '');
       applyFilters();
    });

    const tableScrollContainer = document.getElementById('tableScrollContainer');
    if (tableScrollContainer) {
        tableScrollContainer.addEventListener('scroll', function() {
            if (!filterDrawer.classList.contains('drawer-closed')) {
                closeFiltersUI();
                localStorage.setItem('empFilterOpen', 'false');
            }
        });
        tableScrollContainer.addEventListener('click', function() {
            if (!filterDrawer.classList.contains('drawer-closed')) {
                closeFiltersUI();
                localStorage.setItem('empFilterOpen', 'false');
            }
        });
    }

    const searchInput = document.getElementById('filter_emp_id');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(event) {
            if (event.key === "Enter") {
                closeFiltersUI();
                localStorage.setItem('empFilterOpen', 'false');
                this.blur(); 
            }
        });
    }

    function triggerUpdate() {
        let password = prompt("Please enter the Administrator Password to update database:");
        if (password === "1500") {
            let btn = document.activeElement;
            let originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            btn.disabled = true;

            fetch('employee_fetch.php?pwd=' + password) 
            .then(response => response.text())
            .then(data => {
                alert(data); 
                btn.innerHTML = originalText;
                btn.disabled = false;
                location.reload(); 
            })
            .catch(error => {
                alert("Error: " + error);
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        } else if (password !== null) {
            alert("Incorrect Password! Access Denied.");
        }
    }
    function exportFilteredExcel() {
        // Dan browser eke thiyena URL parameters gannawa (emp_id, department, etc.)
        const urlParams = new URLSearchParams(window.location.search);
        
        // export_employee.php ekata e parameters okkoma pass karanawa
        window.location.href = 'export_employee.php?' + urlParams.toString();
    }
</script>

</body>
</html>
<?php 
if (isset($stmt)) { $stmt->close(); }
$conn->close(); 
?>