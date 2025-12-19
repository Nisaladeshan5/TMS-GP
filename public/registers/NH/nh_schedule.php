<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

$grouped_data = [];
$schedule_map = []; 
$toast_status = "";
$toast_message = "";

// Default Definitions
$defined_codes = ['1', '2', '3'];
$defined_names = ['Night', 'Extra 1', 'Extra 2'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_check'])) {
    
    // 1. CAPTURE DEFINITIONS
    if (isset($_POST['sched_code']) && isset($_POST['sched_name'])) {
        $defined_codes = $_POST['sched_code'];
        $defined_names = $_POST['sched_name'];
        
        for ($i = 0; $i < count($defined_codes); $i++) {
            if (!empty($defined_codes[$i])) {
                $code_key = strtoupper(trim($defined_codes[$i]));
                $schedule_map[$code_key] = $defined_names[$i];
            }
        }
    }

    // 2. PROCESS FILE
    if (isset($_FILES['txt_file']) && $_FILES['txt_file']['error'] == 0) {
        $file_tmp = $_FILES['txt_file']['tmp_name'];
        $file_ext = strtolower(pathinfo($_FILES['txt_file']['name'], PATHINFO_EXTENSION));

        if ($file_ext === 'txt') {
            $input_text = file_get_contents($file_tmp);
            $lines = explode("\n", $input_text);
            $emp_time_map = [];
            $emp_ids = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $parts = preg_split('/\s+/', $line);
                
                if (count($parts) >= 2) {
                    $emp_id = trim($parts[0]);
                    $time = trim($parts[1]);
                    try { $time = date("H:i", strtotime($time)); } catch (Exception $e) { continue; }

                    $emp_time_map[] = ['emp_id' => $emp_id, 'time' => $time];
                    $emp_ids[] = "'" . $conn->real_escape_string($emp_id) . "'";
                }
            }

            // DB Fetch (Updated to include direct & department)
            $emp_db_data = [];
            if (!empty($emp_ids)) {
                $ids_str = implode(',', $emp_ids);
                // Added e.direct, e.department to SQL
                $sql = "SELECT e.emp_id, e.calling_name, e.near_bus_stop, e.direct, e.department, 
                               LEFT(e.route, 10) as extracted_code, r.route as full_route_name
                        FROM employee e LEFT JOIN route r ON LEFT(e.route, 10) = r.route_code
                        WHERE e.emp_id IN ($ids_str)";
                $result = $conn->query($sql);
                if ($result) {
                    while ($row = $result->fetch_assoc()) { $emp_db_data[$row['emp_id']] = $row; }
                }
            }

            // Grouping Logic
            foreach ($emp_time_map as $item) {
                $emp_id = $item['emp_id'];
                $time = $item['time'];
                $db_info = isset($emp_db_data[$emp_id]) ? $emp_db_data[$emp_id] : null;

                $entry = [
                    'emp_id' => $emp_id,
                    'calling_name' => $db_info['calling_name'] ?? '-',
                    'direct' => $db_info['direct'] ?? '-',         // NEW
                    'department' => $db_info['department'] ?? '-', // NEW
                    'route_code' => $db_info['extracted_code'] ?? '<span class="text-red-500">N/A</span>',
                    'route_name' => $db_info['full_route_name'] ?? '<span class="text-gray-400 italic">Unknown</span>',
                    'near_bus_stop' => $db_info['near_bus_stop'] ?? '-'
                ];
                $grouped_data[$time][] = $entry;
            }
            ksort($grouped_data);
            
            if (!empty($grouped_data)) {
                $toast_status = "success"; $toast_message = "Processed successfully!";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Schedule Summary</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    
    <style>
        .table-fixed th, .table-fixed td { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .cat-input { text-align: center; font-weight: bold; border: 2px solid #e2e8f0; border-radius: 4px; width: 100%; }
        .cat-input:focus { outline: none; border-color: #3b82f6; background-color: #eff6ff; }
        .bg-gray-800 { background-color: #333; color: white; }
    </style>
</head>
<body class="bg-gray-100">

<div>
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
        <div class="text-lg font-semibold ml-3">Schedule Grouping Tool</div>
        <div class="flex gap-4">
            <a href="night_heldup_register.php" class="hover:text-yellow-600">Register</a>
            <a href="holiday_schedule.php" class="hover:text-yellow-600">Holiday</a>
        </div> 
    </div>
</div>

<div class="w-[85%] ml-[15%] mt-3 p-2">
    
    <div class="bg-white p-4 rounded-lg shadow-md mb-6 border-t-4 border-blue-600">
        <form method="POST" enctype="multipart/form-data">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                
                <div class="border border-gray-200 rounded-lg p-3 bg-gray-50">
                    <div class="flex justify-between items-center mb-3 pb-2 border-b border-gray-200">
                        <h3 class="font-bold text-gray-700">1. Define Categories</h3>
                        <button type="button" onclick="addDefinitionRow()" class="bg-green-500 hover:bg-green-600 text-white text-xs px-3 py-1 rounded shadow">+ Add Row</button>
                    </div>
                    
                    <div id="definitions-container" class="space-y-2 max-h-40 overflow-y-auto pr-2">
                        <?php for($i=0; $i < count($defined_codes); $i++): ?>
                        <div class="flex gap-2 items-center row-item">
                            <input type="text" name="sched_code[]" value="<?php echo htmlspecialchars($defined_codes[$i]); ?>" oninput="updateLegend()" class="w-14 p-1.5 border rounded text-center text-sm font-bold text-blue-700" required>
                            <span class="text-gray-400">=</span>
                            <input type="text" name="sched_name[]" value="<?php echo htmlspecialchars($defined_names[$i]); ?>" oninput="updateLegend()" class="flex-1 p-1.5 border rounded text-sm" required>
                            <button type="button" onclick="removeRow(this)" class="text-red-400 hover:text-red-600 font-bold px-2 text-lg">&times;</button>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="flex flex-col h-full">
                    <h3 class="font-bold text-gray-700 mb-2">2. Upload Data</h3>
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-5 text-center hover:bg-blue-50 transition flex-1 flex flex-col justify-center items-center">
                        <p class="text-sm text-gray-600 font-medium">Select <strong>.txt</strong> file</p>
                        <input type="file" name="txt_file" accept=".txt" required class="text-xs text-gray-500 mx-auto mt-2"/>
                    </div>
                    <button type="submit" name="upload_check" class="mt-4 w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded font-bold shadow-md transition">Process Schedule</button>
                </div>
            </div>
        </form>
    </div>

    <?php if (!empty($grouped_data)): ?>
        
        <div>
            <div id="legend-box" class="bg-yellow-50 border border-yellow-200 p-4 rounded-lg mb-6 shadow-sm">
                <h3 class="text-gray-800 font-bold text-lg mb-2 border-b border-gray-300 pb-1">Schedule Summary Key</h3>
                <div id="legend-content" class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    Loading keys...
                </div>
                <div class="text-xs text-gray-500 mt-2 italic">Use the buttons below each table to group employees.</div>
            </div>

            <div class="space-y-10 pb-24" id="all-tables-container">
                <?php foreach ($grouped_data as $time => $employees): 
                    $safe_time = str_replace(':', '', $time); 
                ?>
                    <div id="container-<?php echo $safe_time; ?>" class="time-block bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden relative" data-time="<?php echo $time; ?>">
                        <div class="bg-gray-800 text-white px-5 py-2 flex justify-between items-center sticky top-0 z-20">
                            <div class="flex items-center gap-3">
                                <span class="text-2xl font-bold tracking-wide"><?php echo $time; ?></span>
                                <span class="bg-gray-600 text-xs px-3 py-1 rounded-full border border-gray-500"><?php echo count($employees); ?> Pax</span>
                            </div>
                            
                            <div class="flex gap-2 no-print">
                                <button onclick="groupTable('<?php echo $safe_time; ?>')" title="Group" class="bg-green-500 hover:bg-green-600 text-white text-sm px-2 py-1.5 rounded shadow font-bold flex items-center transition hover:scale-105">
                                    <svg class="w-4 h-4 " fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
                                </button>
                                <button onclick="resetTable('<?php echo $safe_time; ?>')" title="Reset" class="bg-gray-600 hover:bg-gray-500 text-white text-sm px-2 py-1.5 rounded shadow flex items-center transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                                </button>
                            </div>
                        </div>

                        <div class="p-2 bg-gray-50 min-h-[100px]" id="wrapper-<?php echo $safe_time; ?>">
                            <table class="min-w-full table-fixed text-sm border-collapse bg-white shadow-sm rounded-lg overflow-hidden" id="table-<?php echo $safe_time; ?>">
                                <thead class="bg-gray-200 text-gray-700 uppercase text-xs font-bold">
                                    <tr>
                                        <th class="py-3 px-3 w-16 text-center border-b">Cat</th> 
                                        <th class="py-3 px-3 w-20 text-left border-b">ID</th>
                                        <th class="py-3 px-3 w-40 text-left border-b">Name</th>
                                        <th class="py-3 px-3 w-20 text-left border-b">Direct</th>
                                        <th class="py-3 px-3 w-20 text-left border-b">Dept</th>
                                        <th class="py-3 px-3 w-24 text-left border-b">Code</th>
                                        <th class="py-3 px-3 text-left border-b">Route</th>
                                        <th class="py-3 px-3 w-32 text-left border-b">Bus Stop</th>
                                    </tr>
                                </thead>
                                <tbody class="text-gray-700 divide-y divide-gray-100">
                                    <?php foreach ($employees as $emp): ?>
                                        <tr class="hover:bg-blue-50 transition duration-150">
                                            <td class="p-2 text-center bg-gray-50">
                                                <input type="text" class="cat-input p-1.5 text-sm" placeholder="-" maxlength="5">
                                            </td>
                                            <td class="py-2 px-3 font-bold text-gray-800 emp-id"><?php echo $emp['emp_id']; ?></td>
                                            <td class="py-2 px-3 truncate font-medium emp-name"><?php echo $emp['calling_name']; ?></td>
                                            <td class="py-2 px-3 truncate emp-direct"><?php echo $emp['direct']; ?></td>
                                            <td class="py-2 px-3 truncate emp-dept"><?php echo $emp['department']; ?></td>
                                            <td class="py-2 px-3 font-mono text-xs text-blue-600 emp-code"><?php echo $emp['route_code']; ?></td>
                                            <td class="py-2 px-3 truncate emp-route"><?php echo $emp['route_name']; ?></td>
                                            <td class="py-2 px-3 truncate text-gray-500 emp-stop"><?php echo $emp['near_bus_stop']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<div class="fixed bottom-0 right-0 w-[85%] bg-white/90 backdrop-blur-sm border-t border-gray-300 p-4 shadow-2xl flex justify-end gap-3 z-50">
    <button onclick="exportToExcel()" class="bg-green-700 hover:bg-green-800 text-white px-6 py-2 rounded shadow-lg font-bold flex items-center transition">
        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
        Export Excel
    </button>
</div>

<div id="toast-container"></div>

</body>

<script>
    let categoryMap = {};
    const originalContent = {};

    // --- 1. SETUP LOGIC ---
    function updateLegend() {
        const container = document.getElementById('definitions-container');
        const legendContent = document.getElementById('legend-content');
        if (!container || !legendContent) return;

        let html = '';
        categoryMap = {}; 

        const rows = container.querySelectorAll('.row-item');
        rows.forEach(row => {
            const codeInput = row.querySelector('input[name="sched_code[]"]');
            const nameInput = row.querySelector('input[name="sched_name[]"]');
            
            if (codeInput && nameInput) {
                const code = codeInput.value.trim().toUpperCase();
                const name = nameInput.value.trim();
                if (code && name) {
                    categoryMap[code] = name;
                    html += `<div class='flex items-center'><span class='font-bold bg-gray-200 px-2 py-1 rounded mr-2 text-sm'>${code}</span> <span class='text-gray-700'>${name}</span></div>`;
                }
            }
        });
        legendContent.innerHTML = html;
    }

    function addDefinitionRow() {
        const container = document.getElementById('definitions-container');
        const div = document.createElement('div');
        div.className = 'flex gap-2 items-center row-item';
        div.innerHTML = `
            <input type="text" name="sched_code[]" placeholder="#" oninput="updateLegend()" class="w-14 p-1.5 border rounded text-center text-sm font-bold text-blue-700" required>
            <span class="text-gray-400">=</span>
            <input type="text" name="sched_name[]" placeholder="Description" oninput="updateLegend()" class="flex-1 p-1.5 border rounded text-sm" required>
            <button type="button" onclick="removeRow(this)" class="text-red-400 hover:text-red-600 font-bold px-2 text-lg">&times;</button>
        `;
        container.appendChild(div);
        updateLegend();
    }

    function removeRow(btn) { btn.parentElement.remove(); updateLegend(); }

    document.addEventListener("DOMContentLoaded", function() {
        updateLegend();
        document.querySelectorAll('[id^="wrapper-"]').forEach(wrapper => {
            const id = wrapper.id.replace('wrapper-', '');
            originalContent[id] = wrapper.innerHTML;
        });
    });

    // --- 2. EXCEL EXPORT LOGIC (HIERARCHICAL / CLEAN) ---
    function exportToExcel() {
        let data = [];
        data.push(["TRANSPORT SCHEDULE SUMMARY"]);
        data.push(["Generated on: " + new Date().toLocaleString()]);
        data.push([]); 

        const timeBlocks = document.querySelectorAll('.time-block');
        
        timeBlocks.forEach(block => {
            const time = block.getAttribute('data-time');
            const wrapper = block.querySelector('[id^="wrapper-"]');
            const totalRowsInTime = wrapper.querySelectorAll('tbody tr').length;

            // Header with Count
            data.push([`TIME: ${time} (Total: ${totalRowsInTime} Pax)`]); 

            const subTables = wrapper.querySelectorAll('table');

            if (subTables.length > 0) {
                subTables.forEach(table => {
                    let categoryName = "Ungrouped List";
                    const prevElem = table.previousElementSibling;
                    if (prevElem && prevElem.innerText) {
                        const titleSpan = prevElem.querySelector('span:first-child');
                        if(titleSpan) categoryName = titleSpan.innerText;
                    }

                    const rows = table.querySelectorAll('tbody tr');
                    const catCount = rows.length;

                    data.push(["", `>> ${categoryName} - [${catCount} Pax]`]); 
                    // Updated Header
                    data.push(["", "ID", "Name", "Direct", "Dept", "Route Code", "Route", "Bus Stop"]);

                    rows.forEach(row => {
                        let id = row.querySelector('.emp-id')?.innerText || "";
                        let name = row.querySelector('.emp-name')?.innerText || "";
                        let direct = row.querySelector('.emp-direct')?.innerText || "";
                        let dept = row.querySelector('.emp-dept')?.innerText || "";
                        let rCode = row.querySelector('.emp-code')?.innerText || "";
                        let rName = row.querySelector('.emp-route')?.innerText || "";
                        let stop = row.querySelector('.emp-stop')?.innerText || "";

                        data.push(["", id, name, direct, dept, rCode, rName, stop]);
                    });

                    data.push([]); 
                });
            } else {
                const rawRows = wrapper.querySelectorAll('tbody tr');
                if(rawRows.length > 0) {
                    data.push(["", `Pending Grouping - [${rawRows.length} Pax]`]);
                    data.push(["", "Group Code", "ID", "Name", "Direct", "Dept", "Route Code", "Route", "Bus Stop"]);
                    
                    rawRows.forEach(row => {
                        let inputVal = row.querySelector('input.cat-input')?.value.trim().toUpperCase() || "-";
                        let displayGroup = inputVal;
                        if(categoryMap[inputVal]) {
                            displayGroup = `${categoryMap[inputVal]} (${inputVal})`;
                        }

                        let id = row.querySelector('.emp-id')?.innerText || "";
                        let name = row.querySelector('.emp-name')?.innerText || "";
                        let direct = row.querySelector('.emp-direct')?.innerText || "";
                        let dept = row.querySelector('.emp-dept')?.innerText || "";
                        let rCode = row.querySelector('.emp-code')?.innerText || "";
                        let rName = row.querySelector('.emp-route')?.innerText || "";
                        let stop = row.querySelector('.emp-stop')?.innerText || "";

                        data.push(["", displayGroup, id, name, direct, dept, rCode, rName, stop]);
                    });
                }
            }
            data.push([]); 
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(data);
        
        ws['!cols'] = [
            {wch: 25}, // Time/Indent
            {wch: 25}, // Category/ID
            {wch: 25}, // Name
            {wch: 15}, // Direct
            {wch: 15}, // Dept
            {wch: 12}, // Code
            {wch: 30}, // Route
            {wch: 25}  // Stop
        ];

        XLSX.utils.book_append_sheet(wb, ws, "Schedule Summary");
        XLSX.writeFile(wb, "Transport_Schedule_Report.xlsx");
        
        showToast("Excel Report downloaded!", "success");
    }

    // --- 3. GROUPING LOGIC ---
    function resetTable(timeId) {
        const wrapper = document.getElementById(`wrapper-${timeId}`);
        if (originalContent[timeId]) { wrapper.innerHTML = originalContent[timeId]; }
    }

    function groupTable(timeId) {
        const wrapper = document.getElementById(`wrapper-${timeId}`);
        const inputs = wrapper.querySelectorAll('input.cat-input');
        if (inputs.length === 0) return;

        const groups = {};
        const ungrouped = [];
        const originalTable = wrapper.querySelector('table');
        const headerRow = originalTable.querySelector('thead tr').cloneNode(true);
        headerRow.deleteCell(0); 
        const headerHTML = headerRow.innerHTML;

        inputs.forEach(input => {
            const code = input.value.trim().toUpperCase();
            const row = input.closest('tr').cloneNode(true);
            row.deleteCell(0); 

            if (code === '') { ungrouped.push(row); } 
            else {
                if (!groups[code]) groups[code] = [];
                groups[code].push(row);
            }
        });

        wrapper.innerHTML = ''; 
        const sortedKeys = Object.keys(groups).sort();
        sortedKeys.forEach(code => {
            const name = categoryMap[code] || 'Unknown';
            createSubTable(wrapper, `${name} (${code})`, groups[code], headerHTML, true);
        });

        if (ungrouped.length > 0) {
            createSubTable(wrapper, 'Ungrouped List', ungrouped, headerHTML, false);
        }
    }

    function createSubTable(container, title, rows, headerHTML, isGroup) {
        const titleDiv = document.createElement('div');
        let colorClass = isGroup ? 'bg-blue-100 text-blue-800 border-blue-300' : 'bg-gray-100 text-gray-600 border-gray-300';
        titleDiv.className = `mt-4 mb-2 px-3 py-1 font-bold text-sm border-l-4 ${colorClass} uppercase flex justify-between items-center`;
        titleDiv.innerHTML = `<span>${title}</span> <span class="text-xs opacity-75">${rows.length} Pax</span>`;
        container.appendChild(titleDiv);

        const table = document.createElement('table');
        table.className = 'min-w-full table-fixed text-sm border-collapse bg-white shadow-sm mb-4 border border-gray-200';
        const thead = document.createElement('thead');
        thead.className = 'bg-gray-100 text-gray-600 uppercase text-xs';
        const tr = document.createElement('tr');
        tr.innerHTML = headerHTML;
        thead.appendChild(tr);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        tbody.className = 'text-gray-700';
        rows.forEach(row => tbody.appendChild(row));
        table.appendChild(tbody);
        container.appendChild(table);
    }

    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        let iconPath = type === 'success' ? '<path d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />' : '<path d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126z" />';
        toast.innerHTML = `<svg class="toast-icon" stroke="currentColor" fill="none" viewBox="0 0 24 24" stroke-width="1.5">${iconPath}</svg><span>${message}</span>`;
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => { toast.remove(); }, 5000); 
    }
    <?php if ($toast_status && $toast_message): ?>
        showToast("<?php echo $toast_message; ?>", "<?php echo $toast_status; ?>");
    <?php endif; ?>
</script>
</html>