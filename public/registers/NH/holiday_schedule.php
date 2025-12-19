<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

$grouped_data = [];
$toast_status = "";
$toast_message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_check'])) {
    
    if (isset($_FILES['txt_file']) && $_FILES['txt_file']['error'] == 0) {
        $file_tmp = $_FILES['txt_file']['tmp_name'];
        $file_ext = strtolower(pathinfo($_FILES['txt_file']['name'], PATHINFO_EXTENSION));

        if ($file_ext === 'txt') {
            $input_text = file_get_contents($file_tmp);
            $lines = explode("\n", $input_text);
            $emp_ids = [];

            // 1. Extract IDs from file
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Split by space/tab to get ID (Assuming first part is ID)
                $parts = preg_split('/\s+/', $line);
                $emp_id = trim($parts[0]);
                
                if (!empty($emp_id)) {
                    $emp_ids[] = "'" . $conn->real_escape_string($emp_id) . "'";
                }
            }

            // 2. Fetch Data & Group by Route
            if (!empty($emp_ids)) {
                $ids_str = implode(',', $emp_ids);
                
                // Query to get Route details
                $sql = "SELECT e.emp_id, e.calling_name, e.near_bus_stop, 
                               LEFT(e.route, 10) as extracted_code, 
                               r.route as full_route_name
                        FROM employee e 
                        LEFT JOIN route r ON LEFT(e.route, 10) = r.route_code
                        WHERE e.emp_id IN ($ids_str)";
                
                $result = $conn->query($sql);
                
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        // Define Group Key (Route Code)
                        // If no route, put in "Unassigned"
                        $routeKey = $row['extracted_code'] ? $row['extracted_code'] : "Unassigned";
                        $routeName = $row['full_route_name'] ? $row['full_route_name'] : "Unknown Route";

                        $entry = [
                            'emp_id' => $row['emp_id'],
                            'calling_name' => $row['calling_name'],
                            'route_code' => $routeKey,
                            'route_name' => $routeName,
                            'near_bus_stop' => $row['near_bus_stop']
                        ];

                        // Grouping Logic: [RouteCode] -> [Rows]
                        // We store the Route Name in the key for easier display or separate it
                        // Let's use Route Code as key, and store name inside first entry or separate map
                        $grouped_data[$routeKey]['details'] = $routeName;
                        $grouped_data[$routeKey]['employees'][] = $entry;
                    }
                }
                
                // Sort by Route Code
                ksort($grouped_data);
                $toast_status = "success"; 
                $toast_message = "Grouped by Route successfully!";
            } else {
                $toast_status = "warning"; 
                $toast_message = "No valid IDs found in file.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Grouping Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <style>
        .table-fixed th, .table-fixed td { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="font-semibold ml-3">Schedule Grouping Tool</div>
    <div class="flex gap-4">
            <a href="night_heldup_register.php" class="hover:text-yellow-600">Register</a>
            <a href="nh_schedule.php" class="hover:text-yellow-600">Night Schedule</a>
        </div> 
</div>

<div class="w-[85%] ml-[15%] mt-3 p-2">
    
    <div class="bg-white p-5 rounded-lg shadow-md mb-6 border-t-4 border-purple-600">
        <form method="POST" enctype="multipart/form-data" class="flex items-center gap-4">
            <div class="flex-1">
                <p class="text-sm text-gray-600 mb-1 font-bold">Upload Employee List (.txt)</p>
                <input type="file" name="txt_file" accept=".txt" required class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100"/>
            </div>
            <button type="submit" name="upload_check" class="bg-purple-600 text-white px-6 py-2 rounded hover:bg-purple-700 font-bold shadow transition mt-5">Group by Route</button>
        </form>
    </div>

    <?php if (!empty($grouped_data)): ?>
        
        <div id="data-container" class="space-y-8 pb-24">
            <?php foreach ($grouped_data as $rCode => $data): 
                $rName = $data['details'];
                $employees = $data['employees'];
            ?>
                
                <div class="route-block bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
                    
                    <div class="bg-gray-700 text-white px-5 py-2 flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <span class="text-xl font-bold font-mono text-yellow-300"><?php echo $rCode; ?></span>
                            <span class="text-sm text-gray-200 border-l border-gray-500 pl-3"><?php echo $rName; ?></span>
                        </div>
                        <span class="bg-gray-600 text-xs px-3 py-1 rounded-full border border-gray-500 route-count"><?php echo count($employees); ?> Pax</span>
                    </div>

                    <div class="p-0">
                        <table class="min-w-full table-fixed text-sm border-collapse">
                            <thead class="bg-gray-100 text-gray-600 uppercase text-xs">
                                <tr>
                                    <th class="py-2 px-4 w-24 text-left border-b">Emp ID</th>
                                    <th class="py-2 px-4 w-64 text-left border-b">Name</th>
                                    <th class="py-2 px-4 text-left border-b">Bus Stop</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-700 divide-y divide-gray-100">
                                <?php foreach ($employees as $emp): ?>
                                    <tr class="hover:bg-purple-50 transition">
                                        <td class="py-2 px-4 font-bold text-gray-800 emp-id"><?php echo $emp['emp_id']; ?></td>
                                        <td class="py-2 px-4 font-medium emp-name"><?php echo $emp['calling_name']; ?></td>
                                        <td class="py-2 px-4 text-gray-500 emp-stop"><?php echo $emp['near_bus_stop']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>

        <div class="fixed bottom-0 right-0 w-[85%] bg-white/90 backdrop-blur-sm border-t border-gray-300 p-4 shadow-2xl flex justify-end gap-3 z-50">
            <button onclick="exportToExcel()" class="bg-green-700 hover:bg-green-800 text-white px-6 py-2 rounded shadow-lg font-bold flex items-center transition">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Export Excel
            </button>
        </div>

    <?php endif; ?>

</div>

<div id="toast-container"></div>

</body>

<script>
    // --- EXCEL EXPORT LOGIC ---
    function exportToExcel() {
        let data = [];
        data.push(["ROUTE GROUPING SUMMARY"]);
        data.push(["Generated on: " + new Date().toLocaleString()]);
        data.push([]); 

        const blocks = document.querySelectorAll('.route-block');
        
        blocks.forEach(block => {
            // Get Header Info
            const code = block.querySelector('.font-mono').innerText;
            const name = block.querySelector('.border-l').innerText;
            const count = block.querySelector('.route-count').innerText;

            // Add Header Row
            data.push([`ROUTE: ${code}`, name, count]);
            
            // Add Table Headers
            data.push(["ID", "Name", "Bus Stop"]);

            // Add Data
            const rows = block.querySelectorAll('tbody tr');
            rows.forEach(row => {
                let id = row.querySelector('.emp-id').innerText;
                let eName = row.querySelector('.emp-name').innerText;
                let stop = row.querySelector('.emp-stop').innerText;
                
                data.push([id, eName, stop]);
            });

            data.push([]); // Space between routes
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(data);
        
        ws['!cols'] = [{wch: 15}, {wch: 30}, {wch: 25}]; // Column widths

        XLSX.utils.book_append_sheet(wb, ws, "Route Summary");
        XLSX.writeFile(wb, "Route_Grouping_Export.xlsx");
    }

    // --- TOAST ---
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        // CSS for toast manually added here to be self-contained in script if needed, 
        // but relied on header styles.
        let color = type === 'success' ? 'bg-green-500' : 'bg-red-500';
        toast.setAttribute('class', `fixed top-4 right-4 ${color} text-white px-6 py-3 rounded shadow-lg transition transform duration-300`);
        toast.innerHTML = message;

        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    <?php if ($toast_status && $toast_message): ?>
        showToast("<?php echo $toast_message; ?>", "<?php echo $toast_status; ?>");
    <?php endif; ?>
</script>
</html>