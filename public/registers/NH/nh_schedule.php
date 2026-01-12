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

    // 2. PROCESS DATA
    if (isset($_POST['processed_data']) && !empty($_POST['processed_data'])) {
        
        $input_text = $_POST['processed_data'];
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
                
                try { 
                    $time = date("H:i", strtotime($time)); 
                } catch (Exception $e) { 
                    continue; 
                }

                $emp_time_map[] = ['emp_id' => $emp_id, 'time' => $time];
                $emp_ids[] = "'" . $conn->real_escape_string($emp_id) . "'";
            }
        }

        // DB Fetch
        $emp_db_data = [];
        if (!empty($emp_ids)) {
            $ids_str = implode(',', $emp_ids);
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
                'direct' => $db_info['direct'] ?? '-',
                'department' => $db_info['department'] ?? '-',
                'route_code' => $db_info['extracted_code'] ?? '<span class="text-red-500">N/A</span>',
                'route_name' => $db_info['full_route_name'] ?? '<span class="text-gray-400 italic">Unknown</span>',
                'near_bus_stop' => $db_info['near_bus_stop'] ?? '-'
            ];
            $grouped_data[$time][] = $entry;
        }

        // Sorting Logic
        uksort($grouped_data, function($a, $b) {
            $t1 = strtotime($a);
            $t2 = strtotime($b);
            if (date('H', $t1) < 12) $t1 += 86400; 
            if (date('H', $t2) < 12) $t2 += 86400;
            return $t1 - $t2;
        });
        
        if (!empty($grouped_data)) {
            $toast_status = "success"; $toast_message = "Excel Processed successfully!";
        } else {
            $toast_status = "error"; $toast_message = "No valid data found in Excel.";
        }
    }
}

$panel_hidden_class = !empty($grouped_data) ? 'hidden' : '';
$toggle_btn_text = !empty($grouped_data) ? 'Show Panel' : 'Hide Panel';
$toggle_icon_rotate = !empty($grouped_data) ? '' : 'rotate-180';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Schedule Summary</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        .table-fixed th, .table-fixed td { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .cat-input { text-align: center; font-weight: bold; border: 2px solid #e2e8f0; border-radius: 4px; width: 100%; }
        .cat-input:focus { outline: none; border-color: #3b82f6; background-color: #eff6ff; }
        
        /* Updated Tab Colors for Indigo Theme */
        .tab-btn.active { background-color: #4f46e5; color: white; border-color: #4f46e5; } /* indigo-600 */
        .tab-btn { transition: all 0.2s; }
        
        .drag-handle { cursor: grab; }
        .drag-handle:active { cursor: grabbing; }
        #all-tables-container { transition: all 0.3s ease; }
        .sortable-ghost { opacity: 0.4; background: #e2e8f0; }
        html { scroll-behavior: smooth; }
        
        /* --- STICKY & FIXED POSITIONS CALCULATIONS (Updated for h-16 header) --- */
        /* Height Reference:
           Main Header: 64px (h-16)
           Filter Bar: ~56px
           Time Header: ~44px 
        */

        .fixed-main-header {
            position: fixed;
            top: 0;
            right: 0;
            width: 85%; /* Matches ml-[15%] */
            height: 64px; /* h-16 */
            z-index: 60; /* Highest Priority */
        }

        .main-content-offset { margin-top: 70px; } /* Push content below fixed header */

        .sticky-filter-bar {
            position: sticky;
            top: 64px; /* Starts exactly after Main Header */
            z-index: 50;
        }

        .sticky-time-header {
            position: sticky;
            top: 120px; /* 64px (Main) + 56px (Filter) = 120px */
            z-index: 40;
        }

        /* NEW: Table Header Sticky as well */
        .sticky-thead {
            position: sticky;
            top: 164px; /* 120px (Time Header Top) + 44px (Time Header Height) */
            z-index: 30;
            box-shadow: 0 2px 4px -1px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="fixed-main-header bg-gradient-to-r from-gray-900 to-indigo-900 text-white shadow-lg flex justify-between items-center px-6 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 p-3 w-fit">
                <a href="night_heldup_register.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Night Heldup
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Schedule Grouping Tool
                </span>
            </div>
    </div>
    <div class="flex gap-4 items-center text-sm font-medium">
        
        <button onclick="togglePanel()" class="bg-gray-700 hover:bg-gray-600 text-gray-200 px-3 py-1.5 rounded-md text-xs flex items-center gap-2 border border-gray-600 transition">
            <span id="toggle-text"><?php echo $toggle_btn_text; ?></span>
            <svg id="toggle-icon" class="w-3 h-3 transition-transform duration-300 <?php echo $toggle_icon_rotate; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
        </button>

        <span class="text-gray-500">|</span>

        <a href="night_heldup_register.php" class="text-gray-300 hover:text-white transition">Register</a>
        <a href="holiday_schedule.php" class="text-gray-300 hover:text-white transition">Holiday</a>
    </div> 
</div>

<div class="w-[85%] ml-[15%] p-2 main-content-offset">
    
    <div id="settings-panel" class="<?php echo $panel_hidden_class; ?> mb-4">
        <div class="bg-white p-4 rounded-lg shadow-md border-t-4 border-indigo-600">
            <form method="POST" id="uploadForm">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="border border-gray-200 rounded-lg p-3 bg-gray-50 h-full">
                        <div class="flex justify-between items-center mb-2 pb-1 border-b border-gray-200">
                            <h3 class="font-bold text-gray-700 text-sm">1. Define Categories</h3>
                            <button type="button" onclick="addDefinitionRow()" class="bg-green-500 hover:bg-green-600 text-white text-xs px-2 py-1 rounded shadow">+ Add</button>
                        </div>
                        <div id="definitions-container" class="space-y-2 max-h-32 overflow-y-auto pr-2 custom-scrollbar">
                            <?php for($i=0; $i < count($defined_codes); $i++): ?>
                            <div class="flex gap-2 items-center row-item">
                                <input type="text" name="sched_code[]" value="<?php echo htmlspecialchars($defined_codes[$i]); ?>" oninput="updateLegend()" class="w-12 p-1 border rounded text-center text-xs font-bold text-indigo-700 focus:ring-indigo-500 focus:border-indigo-500" required>
                                <span class="text-gray-400 text-xs">=</span>
                                <input type="text" name="sched_name[]" value="<?php echo htmlspecialchars($defined_names[$i]); ?>" oninput="updateLegend()" class="flex-1 p-1 border rounded text-xs focus:ring-indigo-500 focus:border-indigo-500" required>
                                <button type="button" onclick="removeRow(this)" class="text-red-400 hover:text-red-600 font-bold px-1 text-base">&times;</button>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="flex flex-col h-full">
                        <div class="flex justify-between items-center mb-1">
                             <h3 class="font-bold text-gray-700 text-sm">2. Upload Excel Data</h3>
                        </div>
                        <div class="border-2 border-dashed border-gray-300 rounded-lg p-3 text-center hover:bg-indigo-50 transition flex-1 flex flex-col justify-center items-center relative min-h-[100px]">
                            <p class="text-xs text-gray-600 font-medium">Select <strong>.xlsx / .xls</strong> file</p>
                            <input type="file" id="excel_file" accept=".xlsx, .xls" class="text-xs text-gray-500 mx-auto mt-2 w-full max-w-[200px]" onchange="handleFileSelect(this)"/>
                            <input type="hidden" name="processed_data" id="processed_data">
                            <div id="file-status" class="mt-1 text-xs font-bold text-indigo-600 hidden">Reading file...</div>
                        </div>
                        <button type="submit" name="upload_check" id="submitBtn" class="mt-2 w-full bg-indigo-600 hover:bg-indigo-700 text-white py-1.5 rounded font-bold text-sm shadow-md transition disabled:opacity-50" disabled>Process Schedule</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($grouped_data)): ?>
        
        <div>
            <div class="sticky-filter-bar mb-2 bg-white p-2 rounded-lg shadow-sm border border-gray-200 flex justify-between items-center min-h-[50px]">
                
                <div class="flex items-center gap-2 overflow-x-auto pb-1 no-scrollbar flex-1">
                    <span class="text-xs font-bold text-gray-500 uppercase whitespace-nowrap">Time Slots:</span>
                    <button onclick="showAll()" id="btn-show-all" class="tab-btn bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-1 px-3 rounded-full border border-gray-300 text-xs shadow-sm whitespace-nowrap">
                        Show All
                    </button>
                    <?php 
                        $first_time = true;
                        foreach ($grouped_data as $time => $employees): 
                            $safe_time_btn = str_replace(':', '', $time);
                            $activeClass = $first_time ? 'active' : 'bg-gray-100 text-gray-700 hover:bg-gray-200';
                    ?>
                        <button onclick="switchTab('<?php echo $safe_time_btn; ?>')" id="btn-<?php echo $safe_time_btn; ?>" class="tab-btn <?php echo $activeClass; ?> font-bold py-1 px-3 rounded-full border border-gray-300 text-xs shadow-sm whitespace-nowrap flex items-center gap-1">
                            <?php echo $time; ?> 
                            <span class="bg-black/10 px-1.5 rounded-full text-[10px]"><?php echo count($employees); ?></span>
                        </button>
                    <?php 
                        $first_time = false;
                        endforeach; 
                    ?>
                </div>

                <div class="flex gap-1 ml-2 border-l pl-2 border-gray-300">
                    <button onclick="setLayout('list')" id="btn-layout-list" class="p-1.5 rounded hover:bg-gray-100 text-indigo-600" title="List View">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    </button>
                    <button onclick="setLayout('grid')" id="btn-layout-grid" class="p-1.5 rounded hover:bg-gray-100 text-gray-400" title="Grid View">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    </button>
                </div>
            </div>

            <div id="legend-box" class="bg-yellow-50 border border-yellow-200 p-2 rounded-lg mb-3 shadow-sm flex items-center gap-4">
                <h3 class="text-gray-800 font-bold text-sm whitespace-nowrap">Keys:</h3>
                <div id="legend-content" class="flex flex-wrap gap-3 text-xs">Loading keys...</div>
            </div>

            <div class="grid grid-cols-1 gap-4 pb-24" id="all-tables-container">
                <?php 
                    $is_first = true;
                    foreach ($grouped_data as $time => $employees): 
                    $safe_time = str_replace(':', '', $time); 
                    $displayClass = $is_first ? '' : 'hidden';
                ?>
                    <div id="container-<?php echo $safe_time; ?>" class="time-block <?php echo $displayClass; ?> bg-white rounded-lg shadow-lg border border-gray-200 relative group" data-time="<?php echo $time; ?>">
                        
                        <div class="sticky-time-header bg-gray-800 text-white px-3 py-1.5 flex justify-between items-center shadow-md rounded-t-lg">
                            <div class="flex items-center gap-2">
                                <div class="drag-handle text-gray-400 hover:text-white cursor-grab p-1" title="Drag to reorder">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path></svg>
                                </div>
                                <span class="text-xl font-bold tracking-wide"><?php echo $time; ?></span>
                                <span class="bg-gray-600 text-[10px] px-2 py-0.5 rounded-full border border-gray-500"><?php echo count($employees); ?> Pax</span>
                            </div>
                            
                            <div class="flex gap-2 no-print">
                                <button onclick="groupTable('<?php echo $safe_time; ?>')" title="Group" class="bg-green-500 hover:bg-green-600 text-white text-xs px-2 py-1 rounded shadow font-bold flex items-center transition hover:scale-105">
                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg> Group
                                </button>
                                <button onclick="resetTable('<?php echo $safe_time; ?>')" title="Reset" class="bg-gray-600 hover:bg-gray-500 text-white text-xs px-2 py-1 rounded shadow flex items-center transition">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                                </button>
                            </div>
                        </div>

                        <div class="p-2 bg-gray-50 min-h-[50px]" id="wrapper-<?php echo $safe_time; ?>">
                            <table class="min-w-full table-fixed text-sm border-collapse bg-white shadow-sm rounded-lg" id="table-<?php echo $safe_time; ?>">
                                <thead class="bg-blue-600 text-white uppercase text-xs font-bold sticky-thead">
                                    <tr>
                                        <th class="py-2 px-2 w-14 text-center border-b border-blue-500">Cat</th> 
                                        <th class="py-2 px-2 w-20 text-left border-b border-blue-500">ID</th>
                                        <th class="py-2 px-2 w-32 text-left border-b border-blue-500">Name</th>
                                        <th class="py-2 px-2 w-16 text-left border-b border-blue-500">Direct</th>
                                        <th class="py-2 px-2 w-16 text-left border-b border-blue-500">Dept</th>
                                        <th class="py-2 px-2 w-20 text-left border-b border-blue-500">Code</th>
                                        <th class="py-2 px-2 text-left border-b border-blue-500">Route</th>
                                        <th class="py-2 px-2 w-24 text-left border-b border-blue-500">Bus Stop</th>
                                    </tr>
                                </thead>
                                <tbody class="text-gray-700 divide-y divide-gray-100 text-xs">
                                    <?php foreach ($employees as $emp): ?>
                                        <tr class="hover:bg-blue-50 transition duration-150">
                                            <td class="p-1 text-center bg-gray-50">
                                                <input type="text" class="cat-input p-1 text-xs" placeholder="-" maxlength="5">
                                            </td>
                                            <td class="py-1 px-2 font-bold text-gray-800 emp-id"><?php echo $emp['emp_id']; ?></td>
                                            <td class="py-1 px-2 truncate font-medium emp-name"><?php echo $emp['calling_name']; ?></td>
                                            <td class="py-1 px-2 truncate emp-direct"><?php echo $emp['direct']; ?></td>
                                            <td class="py-1 px-2 truncate emp-dept"><?php echo $emp['department']; ?></td>
                                            <td class="py-1 px-2 font-mono text-xs text-indigo-600 emp-code"><?php echo $emp['route_code']; ?></td>
                                            <td class="py-1 px-2 truncate emp-route"><?php echo $emp['route_name']; ?></td>
                                            <td class="py-1 px-2 truncate text-gray-500 emp-stop"><?php echo $emp['near_bus_stop']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php 
                    $is_first = false;
                    endforeach; 
                ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<div class="fixed bottom-0 right-0 w-[85%] bg-white/90 backdrop-blur-sm border-t border-gray-300 p-3 shadow-2xl flex justify-end gap-3 z-50">
    <button onclick="exportToExcel()" class="bg-green-700 hover:bg-green-800 text-white px-4 py-2 rounded shadow-lg font-bold flex items-center transition text-sm">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
        Export Excel
    </button>
</div>

<div id="toast-container"></div>

</body>

<script>
    let categoryMap = {};
    const originalContent = {};

    function togglePanel() {
        const panel = document.getElementById('settings-panel');
        const icon = document.getElementById('toggle-icon');
        const text = document.getElementById('toggle-text');
        
        if (panel.classList.contains('hidden')) {
            panel.classList.remove('hidden');
            icon.classList.remove('rotate-180');
            text.innerText = "Hide Panel";
        } else {
            panel.classList.add('hidden');
            icon.classList.add('rotate-180');
            text.innerText = "Show Panel";
        }
    }

    function setLayout(type) {
        const container = document.getElementById('all-tables-container');
        const btnList = document.getElementById('btn-layout-list');
        const btnGrid = document.getElementById('btn-layout-grid');
        
        showAll();

        if (type === 'grid') {
            container.classList.remove('grid-cols-1');
            container.classList.add('md:grid-cols-2');
            
            btnGrid.classList.add('text-indigo-600');
            btnGrid.classList.remove('text-gray-400');
            btnList.classList.remove('text-indigo-600');
            btnList.classList.add('text-gray-400');
        } else {
            container.classList.add('grid-cols-1');
            container.classList.remove('md:grid-cols-2');
            
            btnList.classList.add('text-indigo-600');
            btnList.classList.remove('text-gray-400');
            btnGrid.classList.remove('text-indigo-600');
            btnGrid.classList.add('text-gray-400');
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        const container = document.getElementById('all-tables-container');
        if(container) {
            new Sortable(container, {
                animation: 150,
                handle: '.drag-handle', 
                ghostClass: 'sortable-ghost' 
            });
        }
        
        updateLegend();
        document.querySelectorAll('[id^="wrapper-"]').forEach(wrapper => {
            const id = wrapper.id.replace('wrapper-', '');
            originalContent[id] = wrapper.innerHTML;
        });
    });

    function switchTab(timeId) {
        setLayout('list'); 

        document.querySelectorAll('.time-block').forEach(el => {
            el.classList.add('hidden');
        });

        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
            btn.classList.add('bg-gray-100', 'text-gray-700'); 
            btn.classList.remove('bg-indigo-600', 'text-white');
        });

        const selectedBlock = document.getElementById('container-' + timeId);
        if (selectedBlock) {
            selectedBlock.classList.remove('hidden');
        }

        const selectedBtn = document.getElementById('btn-' + timeId);
        if (selectedBtn) {
            selectedBtn.classList.add('active');
            selectedBtn.classList.remove('bg-gray-100', 'text-gray-700');
        }
    }

    function showAll() {
        document.querySelectorAll('.time-block').forEach(el => {
            el.classList.remove('hidden');
        });

        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
            btn.classList.add('bg-gray-100', 'text-gray-700');
        });

        const allBtn = document.getElementById('btn-show-all');
        if(allBtn) {
            allBtn.classList.add('active');
            allBtn.classList.remove('bg-gray-100', 'text-gray-700');
        }
    }

    function handleFileSelect(input) {
        const file = input.files[0];
        const statusDiv = document.getElementById('file-status');
        const submitBtn = document.getElementById('submitBtn');
        const hiddenInput = document.getElementById('processed_data');

        if (!file) {
            submitBtn.disabled = true;
            statusDiv.classList.add('hidden');
            return;
        }

        statusDiv.classList.remove('hidden');
        statusDiv.innerText = "Parsing Excel file...";
        submitBtn.disabled = true;

        const reader = new FileReader();
        
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, {type: 'array'});
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                const jsonData = XLSX.utils.sheet_to_json(worksheet, {header: 1, raw: false, defval: ""});

                let textOutput = "";
                let count = 0;

                for(let i=0; i < jsonData.length; i++) {
                    const row = jsonData[i];
                    
                    if (row.length > 0 && row[0]) {
                        let rawId = row[0].toString().trim().toUpperCase();
                        let time = (row[1]) ? row[1].toString().trim() : "";
                        
                        if(rawId.includes("ID") || rawId.includes("EMP")) continue;

                        if(rawId) {
                            let formattedId = "";
                            if (rawId.startsWith("ST")) {
                                let numberPart = rawId.replace("ST", "").trim();
                                formattedId = "ST" + numberPart.padStart(6, '0');
                            } else {
                                let numberPart = rawId.replace("GP", "").trim();
                                formattedId = "GP" + numberPart.padStart(6, '0');
                            }
                            textOutput += formattedId + " " + time + "\n";
                            count++;
                        }
                    }
                }

                hiddenInput.value = textOutput;
                statusDiv.className = "mt-1 text-xs font-bold text-green-600";
                statusDiv.innerText = `Ready! Found ${count} rows.`;
                submitBtn.disabled = false;

            } catch (err) {
                console.error(err);
                statusDiv.className = "mt-1 text-xs font-bold text-red-600";
                statusDiv.innerText = "Error parsing Excel file.";
                hiddenInput.value = "";
                submitBtn.disabled = true;
            }
        };
        reader.readAsArrayBuffer(file);
    }

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
                    html += `<div class='flex items-center'><span class='font-bold bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded mr-1 text-xs'>${code}</span> <span class='text-gray-700'>${name}</span></div>`;
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
            <input type="text" name="sched_code[]" placeholder="#" oninput="updateLegend()" class="w-12 p-1 border rounded text-center text-xs font-bold text-indigo-700 focus:ring-indigo-500 focus:border-indigo-500" required>
            <span class="text-gray-400 text-xs">=</span>
            <input type="text" name="sched_name[]" placeholder="Desc" oninput="updateLegend()" class="flex-1 p-1 border rounded text-xs focus:ring-indigo-500 focus:border-indigo-500" required>
            <button type="button" onclick="removeRow(this)" class="text-red-400 hover:text-red-600 font-bold px-1 text-base">&times;</button>
        `;
        container.appendChild(div);
        updateLegend();
    }

    function removeRow(btn) { btn.parentElement.remove(); updateLegend(); }

    function exportToExcel() {
        let data = [];
        data.push(["TRANSPORT SCHEDULE SUMMARY"]);
        data.push(["Generated on: " + new Date().toLocaleString()]);
        data.push([]); 

        const timeBlocks = Array.from(document.querySelectorAll('.time-block'));
        
        timeBlocks.forEach(block => {
            const time = block.getAttribute('data-time');
            const wrapper = block.querySelector('[id^="wrapper-"]');
            const totalRowsInTime = wrapper.querySelectorAll('tbody tr').length;

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
        ws['!cols'] = [{wch: 25}, {wch: 25}, {wch: 25}, {wch: 15}, {wch: 15}, {wch: 12}, {wch: 30}, {wch: 25}];
        XLSX.utils.book_append_sheet(wb, ws, "Schedule Summary");
        XLSX.writeFile(wb, "Transport_Schedule_Report.xlsx");
        showToast("Excel Report downloaded!", "success");
    }

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
        let colorClass = isGroup ? 'bg-indigo-100 text-indigo-800 border-indigo-300' : 'bg-gray-100 text-gray-600 border-gray-300';
        titleDiv.className = `mt-2 mb-1 px-2 py-0.5 font-bold text-xs border-l-4 ${colorClass} uppercase flex justify-between items-center`;
        titleDiv.innerHTML = `<span>${title}</span> <span class="text-[10px] opacity-75">${rows.length} Pax</span>`;
        container.appendChild(titleDiv);

        const table = document.createElement('table');
        table.className = 'min-w-full table-fixed text-sm border-collapse bg-white shadow-sm mb-3 border border-gray-200';
        const thead = document.createElement('thead');
        thead.className = 'bg-blue-600 text-white uppercase text-xs sticky-thead';
        // Dynamically created tables also need top adjusted if sticky
        thead.style.top = "164px"; 
        thead.style.zIndex = "30";
        
        const tr = document.createElement('tr');
        tr.innerHTML = headerHTML;
        thead.appendChild(tr);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        tbody.className = 'text-gray-700 text-xs';
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