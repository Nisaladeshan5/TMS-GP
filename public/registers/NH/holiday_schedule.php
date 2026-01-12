<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

$grouped_data = [];
$toast_status = "";
$toast_message = "";

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_check'])) {
    
    // Check if processed data exists (Sent via JS from Excel)
    if (isset($_POST['processed_data']) && !empty($_POST['processed_data'])) {
        
        $input_text = $_POST['processed_data'];
        $lines = explode("\n", $input_text);
        $emp_ids = [];

        // 1. Extract IDs from the processed text
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $parts = preg_split('/\s+/', $line);
            $emp_id = trim($parts[0]); 
            
            if (!empty($emp_id)) {
                $emp_ids[] = "'" . $conn->real_escape_string($emp_id) . "'";
            }
        }

        // 2. Fetch Data & Group by Route
        if (!empty($emp_ids)) {
            $ids_str = implode(',', $emp_ids);
            
            $sql = "SELECT e.emp_id, e.calling_name, e.near_bus_stop, 
                           LEFT(e.route, 10) as extracted_code, 
                           r.route as full_route_name
                    FROM employee e 
                    LEFT JOIN route r ON LEFT(e.route, 10) = r.route_code
                    WHERE e.emp_id IN ($ids_str)";
            
            $result = $conn->query($sql);
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $routeKey = $row['extracted_code'] ? $row['extracted_code'] : "Unassigned";
                    $routeName = $row['full_route_name'] ? $row['full_route_name'] : "Unknown Route";

                    $entry = [
                        'emp_id' => $row['emp_id'],
                        'calling_name' => $row['calling_name'],
                        'route_code' => $routeKey,
                        'route_name' => $routeName,
                        'near_bus_stop' => $row['near_bus_stop']
                    ];

                    $grouped_data[$routeKey]['details'] = $routeName;
                    $grouped_data[$routeKey]['employees'][] = $entry;
                }
            }
            
            ksort($grouped_data);
            
            if (!empty($grouped_data)) {
                $toast_status = "success"; 
                $toast_message = "Grouped by Route successfully!";
            } else {
                $toast_status = "warning"; 
                $toast_message = "IDs found, but no matching records in database.";
            }
        } else {
            $toast_status = "error"; 
            $toast_message = "No valid IDs found in the Excel file.";
        }
    }
}

// Logic for Toggle Panel State
$panel_hidden_class = !empty($grouped_data) ? 'hidden' : '';
$toggle_btn_text = !empty($grouped_data) ? 'Show Upload Panel' : 'Hide Upload Panel';
$toggle_icon_rotate = !empty($grouped_data) ? '' : 'rotate-180';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Grouping Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        .table-fixed th, .table-fixed td { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        body { padding-bottom: 80px; }
        
        /* Fixed Header Styles */
        .fixed-main-header {
            position: fixed;
            top: 0;
            right: 0;
            width: 85%; /* Matches ml-[15%] */
            height: 64px; /* h-16 */
            z-index: 60;
        }
        .content-offset { margin-top: 70px; }
        .transition-transform { transition-property: transform; transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1); transition-duration: 300ms; }
        .rotate-180 { transform: rotate(180deg); }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
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
                    Route Grouping Tool
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
        <a href="nh_schedule.php" class="text-gray-300 hover:text-white transition">Night Schedule</a>
    </div> 
</div>

<div class="w-[85%] ml-[15%] p-2 content-offset">
    
    <div id="settings-panel" class="<?php echo $panel_hidden_class; ?> mb-6">
        <div class="bg-white p-4 rounded-xl shadow-md border-t-4 border-indigo-600">
            <form method="POST" id="uploadForm">
                
                <div class="w-full border-2 border-dashed border-indigo-200 rounded-lg p-4 hover:bg-indigo-50 transition relative flex flex-wrap md:flex-nowrap items-center justify-between gap-4">
                    
                    <div class="flex items-center gap-3">
                        <div class="bg-indigo-100 p-3 rounded-full text-indigo-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        </div>
                        <div class="text-left">
                            <p class="text-sm font-bold text-gray-700 leading-tight">Select Employee Excel</p>
                            <p class="text-xs text-gray-500">.xlsx / .xls (Column A should contain IDs)</p>
                        </div>
                    </div>

                    <div class="flex-1 flex justify-end items-center gap-2">
                        <input type="file" id="excel_file" accept=".xlsx, .xls" 
                               class="block w-full md:w-auto text-xs text-gray-500 file:mr-2 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-700 cursor-pointer" 
                               onchange="handleFileSelect(this)"/>
                        
                        <div id="file-status" class="text-xs font-bold text-blue-600 whitespace-nowrap hidden bg-blue-50 px-3 py-1.5 rounded border border-blue-100">
                            Reading...
                        </div>
                    </div>

                </div>

                <input type="hidden" name="processed_data" id="processed_data">

            </form>
        </div>
    </div>

    <?php if (!empty($grouped_data)): ?>
        
        <div id="data-container" class="space-y-6 pb-4">
            <?php foreach ($grouped_data as $rCode => $data): 
                $rName = $data['details'];
                $employees = $data['employees'];
            ?>
                
                <div class="route-block bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden hover:shadow-xl transition duration-200">
                    
                    <div class="bg-gray-800 text-white px-4 py-2 flex justify-between items-center border-b border-gray-700">
                        <div class="flex items-center gap-3">
                            <span class="text-lg font-bold font-mono text-yellow-300"><?php echo $rCode; ?></span>
                            <span class="text-sm text-gray-300 border-l border-gray-600 pl-3 uppercase tracking-wide font-medium"><?php echo $rName; ?></span>
                        </div>
                        <span class="bg-gray-600 text-xs px-3 py-1 rounded-full border border-gray-500 font-bold route-count"><?php echo count($employees); ?> Pax</span>
                    </div>

                    <div class="p-0">
                        <table class="min-w-full table-fixed text-sm border-collapse">
                            <thead class="bg-blue-600 text-white uppercase text-xs font-bold">
                                <tr>
                                    <th class="py-2 px-4 w-28 text-left border-b border-blue-500">Emp ID</th>
                                    <th class="py-2 px-4 w-64 text-left border-b border-blue-500">Name</th>
                                    <th class="py-2 px-4 text-left border-b border-blue-500">Bus Stop</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-700 divide-y divide-gray-100">
                                <?php foreach ($employees as $emp): ?>
                                    <tr class="hover:bg-indigo-50 transition duration-150">
                                        <td class="py-2 px-4 font-bold text-gray-800 emp-id"><?php echo $emp['emp_id']; ?></td>
                                        <td class="py-2 px-4 font-medium emp-name truncate"><?php echo $emp['calling_name']; ?></td>
                                        <td class="py-2 px-4 text-gray-500 emp-stop truncate"><?php echo $emp['near_bus_stop']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>

<div class="fixed bottom-0 right-0 w-[85%] bg-white/95 backdrop-blur-sm border-t border-gray-300 p-3 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.1)] flex justify-end gap-3 z-50">
    
    <?php if (!empty($grouped_data)): ?>
        <button onclick="exportToExcel()" class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg shadow-md font-bold flex items-center transition transform hover:-translate-y-1 text-sm">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            Export Excel
        </button>
    <?php endif; ?>

    <button type="submit" form="uploadForm" name="upload_check" id="submitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg shadow-md font-bold flex items-center transition transform hover:-translate-y-1 disabled:opacity-50 disabled:cursor-not-allowed text-sm" disabled>
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
        Group by Route
    </button>

</div>

<div id="toast-container"></div>

</body>

<script>
    // --- Toggle Panel Function ---
    function togglePanel() {
        const panel = document.getElementById('settings-panel');
        const icon = document.getElementById('toggle-icon');
        const text = document.getElementById('toggle-text');
        
        if (panel.classList.contains('hidden')) {
            panel.classList.remove('hidden');
            icon.classList.remove('rotate-180');
            text.innerText = "Hide Upload Panel";
        } else {
            panel.classList.add('hidden');
            icon.classList.add('rotate-180');
            text.innerText = "Show Upload Panel";
        }
    }

    // --- 1. FILE HANDLING (Formatted IDs) ---
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
        statusDiv.className = "mt-0 text-xs font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded";
        statusDiv.innerText = "Reading...";
        submitBtn.disabled = true;

        const reader = new FileReader();
        
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, {type: 'array'});
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                const jsonData = XLSX.utils.sheet_to_json(worksheet, {header: 1, defval: ""});

                let textOutput = "";
                let count = 0;

                for(let i=0; i < jsonData.length; i++) {
                    const row = jsonData[i];
                    if (row.length > 0 && row[0]) {
                        let rawId = row[0].toString().trim().toUpperCase();
                        
                        if(rawId.includes("ID") || rawId.includes("EMP")) continue;

                        if(rawId) {
                            let formattedId = "";
                            // Logic to Format ID (ST/GP)
                            if (rawId.startsWith("ST")) {
                                let numberPart = rawId.replace("ST", "").trim();
                                formattedId = "ST" + numberPart.padStart(6, '0');
                            } else {
                                let numberPart = rawId.replace("GP", "").trim();
                                formattedId = "GP" + numberPart.padStart(6, '0');
                            }

                            textOutput += formattedId + "\n"; 
                            count++;
                        }
                    }
                }

                if (count > 0) {
                    hiddenInput.value = textOutput;
                    statusDiv.className = "mt-0 text-xs font-bold text-green-600 bg-green-50 px-2 py-1 rounded";
                    statusDiv.innerHTML = `<i class="fas fa-check-circle"></i> Ready (${count})`;
                    submitBtn.disabled = false;
                } else {
                    throw new Error("No valid IDs");
                }

            } catch (err) {
                console.error(err);
                statusDiv.className = "mt-0 text-xs font-bold text-red-600 bg-red-50 px-2 py-1 rounded";
                statusDiv.innerText = "Error!";
                hiddenInput.value = "";
                submitBtn.disabled = true;
            }
        };

        reader.readAsArrayBuffer(file);
    }

    // --- 2. EXCEL EXPORT LOGIC ---
    function exportToExcel() {
        let data = [];
        data.push(["ROUTE GROUPING SUMMARY"]);
        data.push(["Generated on: " + new Date().toLocaleString()]);
        data.push([]); 

        const blocks = document.querySelectorAll('.route-block');
        
        blocks.forEach(block => {
            const code = block.querySelector('.font-mono').innerText;
            const name = block.querySelector('.border-l').innerText;
            const count = block.querySelector('.route-count').innerText;

            data.push([`ROUTE: ${code}`, name, count]);
            data.push(["ID", "Name", "Bus Stop"]);

            const rows = block.querySelectorAll('tbody tr');
            rows.forEach(row => {
                let id = row.querySelector('.emp-id').innerText;
                let eName = row.querySelector('.emp-name').innerText;
                let stop = row.querySelector('.emp-stop').innerText;
                
                data.push([id, eName, stop]);
            });

            data.push([]); 
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(data);
        ws['!cols'] = [{wch: 15}, {wch: 30}, {wch: 25}]; 

        XLSX.utils.book_append_sheet(wb, ws, "Route Summary");
        XLSX.writeFile(wb, "Route_Grouping_Export.xlsx");
        
        showToast("Excel Exported Successfully!", "success");
    }

    // --- 3. TOAST ---
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        let color = type === 'success' ? 'bg-green-500' : 'bg-red-500';
        
        toast.className = `fixed top-20 right-5 ${color} text-white px-6 py-3 rounded shadow-lg transition transform duration-300 z-50 flex items-center gap-2`;
        toast.innerHTML = type === 'success' 
            ? `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> <span>${message}</span>`
            : `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg> <span>${message}</span>`;

        toastContainer.appendChild(toast);
        
        setTimeout(() => { toast.classList.add('translate-y-2', 'opacity-100'); }, 10);
        setTimeout(() => { 
            toast.classList.remove('translate-y-2', 'opacity-100');
            setTimeout(() => toast.remove(), 300);
        }, 3000); 
    }

    <?php if ($toast_status && $toast_message): ?>
        showToast("<?php echo $toast_message; ?>", "<?php echo $toast_status; ?>");
    <?php endif; ?>
</script>
</html>