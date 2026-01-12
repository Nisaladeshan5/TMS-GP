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

// --- 1. AJAX HANDLER: UPDATE PHONE NUMBER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_phone') {
    header('Content-Type: application/json');

    $emp_id = $_POST['emp_id'];
    $new_phone = trim($_POST['phone_no']);

    if (empty($emp_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Employee ID.']);
        exit;
    }

    $update_sql = "UPDATE employee SET phone_no = ? WHERE emp_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("ss", $new_phone, $emp_id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Phone updated.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Update failed.']);
    }
    $stmt->close();
    exit;
}

// --- HELPER FUNCTION: GET DATA ---
function getEmployeeData($conn, $filters) {
    $sql = "SELECT 
                e.emp_id, 
                e.calling_name, 
                e.department, 
                e.gender, 
                e.route, 
                e.near_bus_stop,
                e.phone_no,  
                SUBSTRING(e.route, 1, 10) AS route_code, 
                SUBSTRING(e.route, 12, LENGTH(e.route) - 12) AS route_name,
                sr.sub_route AS sub_route_name 
            FROM employee e
            LEFT JOIN sub_route sr ON 
                (e.near_bus_stop REGEXP '^[0-9]') 
                AND 
                sr.sub_route_code = CONCAT(SUBSTRING(e.route, 1, 10), '-', SUBSTRING(e.near_bus_stop, 1, 6))
            WHERE e.route != ''"; 

    $params = [];
    $param_types = '';

    if (!empty($filters['emp_id'])) {
        $sql .= " AND e.emp_id LIKE ?";
        $params[] = "%" . $filters['emp_id'] . "%";
        $param_types .= 's';
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

// --- HELPER FUNCTION: RENDER ROWS (MERGED COLUMNS LAYOUT) ---
function renderTableRows($result) {
    if ($result && $result->num_rows > 0) {
        while ($employee = $result->fetch_assoc()) {
            
            $raw_bus_stop = $employee['near_bus_stop'] ?? '';
            $display_bus_stop = $raw_bus_stop;
            $sub_route_display = ""; // Default empty
            $phone_no = htmlspecialchars($employee['phone_no'] ?? '');

            // Sub Route Logic
            if (!empty($raw_bus_stop) && is_numeric($raw_bus_stop[0])) {
                if (strlen($raw_bus_stop) > 6) {
                    $display_bus_stop = substr($raw_bus_stop, 6); 
                }

                $char_at_6 = isset($raw_bus_stop[5]) ? strtoupper($raw_bus_stop[5]) : '';
                $vehicle_type = 'Other';
                $bg_color = 'bg-gray-100 text-gray-600'; 

                switch ($char_at_6) {
                    case 'T': $vehicle_type = '3-Wheel'; $bg_color = 'bg-yellow-100 text-yellow-800 border-yellow-200'; break;
                    case 'V': $vehicle_type = 'Van'; $bg_color = 'bg-purple-100 text-purple-800 border-purple-200'; break;
                    case 'B': $vehicle_type = 'Bus'; $bg_color = 'bg-blue-100 text-blue-800 border-blue-200'; break;
                }

                $display_name = !empty($employee['sub_route_name']) ? $employee['sub_route_name'] : "Unknown";
                $code_part = substr($raw_bus_stop, 0, 6);

                $sub_route_display = "
                    <div class='mt-1'>
                        <div class='text-xs text-gray-500 mt-0.5 truncate max-w-[140px]' title='$display_name'>$display_name <span class='px-1.5 py-0.5 rounded border text-[10px] font-bold uppercase $bg_color inline-block whitespace-nowrap'>
                            $vehicle_type
                        </span></div>
                    </div>
                ";
            } else {
                // If not a sub-route, just show a placeholder or nothing
                $sub_route_display = "<span class='text-gray-300 text-xs'>-</span>";
            }

            // Gender Badge
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
                        <div class='flex flex-col'>
                             <span class='text-gray-800 text-sm font-semibold truncate max-w-[160px]' title='$display_bus_stop'>" 
                                . htmlspecialchars($display_bus_stop) . 
                            "</span>
                             $sub_route_display
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
        'staff_type' => $_GET['staff_type'] ?? ''
    ];
    $result = getEmployeeData($conn, $filters);
    renderTableRows($result);
    exit; 
}

// --- 3. EXCEL EXPORT (Keeps Detailed Columns for Excel) ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filters = [
        'emp_id' => $_GET['emp_id'] ?? '',
        'department' => $_GET['department'] ?? '',
        'route_code' => $_GET['route_code'] ?? '',
        'staff_type' => $_GET['staff_type'] ?? ''
    ];
    $result = getEmployeeData($conn, $filters);

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=employee_details_" . date('Y-m-d') . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>';
    echo '<table border="1">';
    $headerStyle = 'style="background-color: #4CAF50; color: white; font-weight: bold;"';
    // Excel requires separate columns for data analysis
    echo '<tr>
            <th ' . $headerStyle . '>Emp ID</th>
            <th ' . $headerStyle . '>Name</th>
            <th ' . $headerStyle . '>Phone</th>
            <th ' . $headerStyle . '>Dept</th>
            <th ' . $headerStyle . '>Gender</th>
            <th ' . $headerStyle . '>Route Code</th>
            <th ' . $headerStyle . '>Route Name</th>
            <th ' . $headerStyle . '>Bus Stop</th>
            <th ' . $headerStyle . '>Sub Route</th>
            <th ' . $headerStyle . '>Vehicle</th>
          </tr>';

    while ($employee = $result->fetch_assoc()) {
        $raw_bus_stop = $employee['near_bus_stop'] ?? '';
        $display_bus_stop = $raw_bus_stop;
        $sub_route_name = '';
        $vehicle_type = '';
        $phone_no = $employee['phone_no'] ?? '';

        if (!empty($raw_bus_stop) && is_numeric($raw_bus_stop[0])) {
            if (strlen($raw_bus_stop) > 6) {
                $display_bus_stop = substr($raw_bus_stop, 6);
            }
            $sub_route_name = $employee['sub_route_name'] ?? '';
            $char_at_6 = isset($raw_bus_stop[5]) ? strtoupper($raw_bus_stop[5]) : '';
            switch ($char_at_6) {
                case 'T': $vehicle_type = 'Three Wheel'; break;
                case 'V': $vehicle_type = 'Van'; break;
                case 'B': $vehicle_type = 'Bus'; break;
                default: $vehicle_type = 'Other'; break;
            }
        }

        echo '<tr>
                <td>' . $employee['emp_id'] . '</td>
                <td>' . ucwords(strtolower($employee['calling_name'])) . '</td>
                <td>' . $phone_no . '</td>
                <td>' . ucwords(strtolower($employee['department'])) . '</td>
                <td>' . $employee['gender'] . '</td>
                <td>' . $employee['route_code'] . '</td>
                <td>' . ucwords(strtolower($employee['route_name'])) . '</td>
                <td>' . $display_bus_stop . '</td>
                <td>' . $sub_route_name . '</td>
                <td>' . $vehicle_type . '</td>
              </tr>';
    }
    echo '</table></body></html>';
    exit;
}

// --- 4. PAGE LOAD ---
include('../../includes/header.php'); 
include('../../includes/navbar.php');

$department_options = $conn->query("SELECT DISTINCT department FROM employee ORDER BY department")->fetch_all(MYSQLI_ASSOC);
$route_code_options = $conn->query("SELECT DISTINCT SUBSTRING(route, 1, 10) AS route_code_distinct FROM employee WHERE LENGTH(route) >= 10 ORDER BY route_code_distinct")->fetch_all(MYSQLI_ASSOC);

$filters = [
    'emp_id' => $_GET['emp_id'] ?? '',
    'department' => $_GET['department'] ?? '',
    'route_code' => $_GET['route_code'] ?? '',
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

<body class="bg-gray-100 overflow-hidden text-sm"> 

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-14 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Employee
        </div>
    </div>
    <div class="flex items-center gap-3 text-xs font-medium">
        <button onclick="toggleFilters()" id="filterToggleBtn" class="flex items-center gap-2 bg-gray-700 hover:bg-gray-600 text-white px-3 py-1.5 rounded-md shadow-md transition border border-gray-500 focus:outline-none focus:ring-1 focus:ring-yellow-400">
            <i class="fas fa-filter text-yellow-400"></i> 
            <span id="filterBtnText">Show Filters</span>
            <i id="filterArrow" class="fas fa-chevron-down text-[10px] transition-transform duration-300"></i>
        </button>
        <a href="overview.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition">
            <i class="fas fa-chart-pie"></i> Overview
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-14 h-screen flex flex-col relative">
    
    <div id="filterDrawer" class="bg-white shadow-lg border-b border-gray-300 drawer-closed absolute top-14 left-0 w-full z-40 px-6 py-4">
        <div class="flex justify-between items-center mb-3">
            <h3 class="text-xs font-bold text-gray-700 uppercase flex items-center gap-2">
                <i class="fas fa-search text-blue-500"></i> Advanced Search
            </h3>
            <div class="flex gap-2">
                <button type="button" id="clearFiltersBtn" class="text-[10px] font-semibold text-gray-500 hover:text-red-600 px-3 py-1 bg-gray-100 rounded hover:bg-gray-200 transition">
                    Clear All
                </button>
                <button type="button" id="exportExcelBtn" class="text-[10px] font-semibold text-white bg-green-600 hover:bg-green-700 px-3 py-1 rounded shadow transition flex items-center gap-1">
                    <i class="fas fa-file-excel"></i> Export Excel
                </button>
            </div>
        </div>
        
        <form id="filterForm" class="grid grid-cols-1 md:grid-cols-4 gap-4 pb-2" onsubmit="return false;">
            <div>
                <label class="block text-[10px] font-semibold text-gray-500 mb-1">Emp ID</label>
                <input type="text" id="filter_emp_id" oninput="debouncedFilter()" value="<?php echo htmlspecialchars($filters['emp_id']); ?>" 
                       class="w-full border border-gray-300 rounded p-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none" placeholder="Type to search...">
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
                        <option value="<?php echo htmlspecialchars($route['route_code_distinct']); ?>" <?php echo ($filters['route_code'] === $route['route_code_distinct']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($route['route_code_distinct']); ?>
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
                    <thead class="bg-gradient-to-r from-blue-600 to-blue-700  text-white text-xs sticky top-0 z-10 shadow-md">
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
    // --- Toast Function ---
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

    // --- Double Click to Edit Phone Number ---
    function makeEditable(td, empId) {
        if (td.querySelector('input')) return; // Already editing

        const span = td.querySelector('span');
        const currentText = span.innerText.trim() === '+ Add' ? '' : span.innerText.trim();
        
        // Compact Input styling for merged cell
        td.innerHTML = `<input type="text" value="${currentText}" class="w-full p-1 border border-blue-400 rounded text-sm font-mono outline-none shadow-sm" placeholder="Enter phone...">`;
        const input = td.querySelector('input');
        input.focus();

        const saveEdit = () => {
            const newText = input.value.trim();

            // Revert content helper
            const renderContent = (val) => {
                 return `<div class='flex items-center h-full pt-1'>
                             <span class='text-gray-700 font-mono text-sm cursor-pointer hover:text-blue-600 transition'>
                                ${val ? val : '<span class="text-gray-400 text-xs border border-dashed border-gray-300 px-2 py-0.5 rounded hover:border-gray-400">+ Add</span>'}
                            </span>
                        </div>`;
            };

            if (newText === currentText) {
                td.innerHTML = renderContent(newText);
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_phone');
            formData.append('emp_id', empId);
            formData.append('phone_no', newText);

            fetch(window.location.href, { 
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    td.innerHTML = renderContent(newText);
                } else {
                    showToast(data.message, 'error');
                    td.innerHTML = renderContent(currentText);
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Connection error', 'error');
                td.innerHTML = renderContent(currentText);
            });
        };

        input.addEventListener('blur', saveEdit);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                input.blur();
            }
        });
    }

    // --- UI Elements & Filter Logic ---
    const filterDrawer = document.getElementById('filterDrawer');
    const filterBtnText = document.getElementById('filterBtnText');
    const filterArrow = document.getElementById('filterArrow');
    const tableScrollContainer = document.getElementById('tableScrollContainer');
    const isFilterOpen = localStorage.getItem('empFilterOpen') === 'true';

    if (isFilterOpen) {
        openFiltersUI();
    }

    function toggleFilters() {
        if (filterDrawer.classList.contains('drawer-closed')) {
            openFiltersUI();
            localStorage.setItem('empFilterOpen', 'true');
        } else {
            closeFiltersUI();
            localStorage.setItem('empFilterOpen', 'false');
        }
    }

    function openFiltersUI() {
        filterDrawer.classList.remove('drawer-closed');
        filterDrawer.classList.add('drawer-open');
        filterBtnText.innerText = "Hide Filters";
        filterArrow.style.transform = "rotate(180deg)";
    }

    function closeFiltersUI() {
        filterDrawer.classList.remove('drawer-open');
        filterDrawer.classList.add('drawer-closed');
        filterBtnText.innerText = "Show Filters";
        filterArrow.style.transform = "rotate(0deg)";
    }

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

    document.getElementById('filter_emp_id').addEventListener('keydown', function(event) {
        if (event.key === "Enter") {
            closeFiltersUI();
            localStorage.setItem('empFilterOpen', 'false');
            this.blur(); 
        }
    });

    // --- AJAX Filter Logic ---
    let debounceTimer;

    function debouncedFilter() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => applyFilters(), 300); 
    }

    function applyFilters() {
        const empId = document.getElementById('filter_emp_id').value;
        const staffType = document.getElementById('filter_staff_type').value;
        const routeCode = document.getElementById('filter_route_code').value;
        const department = document.getElementById('filter_department').value;

        const params = new URLSearchParams();
        if (empId) params.append('emp_id', empId);
        if (staffType) params.append('staff_type', staffType);
        if (routeCode) params.append('route_code', routeCode);
        if (department) params.append('department', department);

        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({path: newUrl}, '', newUrl);

        params.append('ajax_filter', '1');

        const tbody = document.getElementById('employeeTableBody');
        tbody.classList.add('table-loading');

        fetch(window.location.pathname + '?' + params.toString())
            .then(response => response.text())
            .then(html => {
                tbody.innerHTML = html;
                tbody.classList.remove('table-loading');
            })
            .catch(error => {
                console.error('Error fetching data:', error);
                tbody.classList.remove('table-loading');
            });
    }

    document.getElementById('clearFiltersBtn').addEventListener('click', function() {
        document.getElementById('filter_emp_id').value = '';
        document.getElementById('filter_staff_type').value = '';
        document.getElementById('filter_route_code').value = '';
        document.getElementById('filter_department').value = '';
        applyFilters();
    });

    document.getElementById('exportExcelBtn').addEventListener('click', function() {
        const params = new URLSearchParams(window.location.search);
        params.delete('export'); 
        params.append('export', 'excel'); 
        window.location.href = window.location.pathname + '?' + params.toString();
    });
</script>

</body>
</html>

<?php 
if (isset($stmt)) { $stmt->close(); }
$conn->close(); 
?>