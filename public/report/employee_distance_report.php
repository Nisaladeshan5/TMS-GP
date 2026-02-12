<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include('../../includes/db.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// --- DATA PROCESSING ---
$sql = "SELECT emp_id, calling_name, emp_category, to_home_distance FROM employee WHERE is_active = 1 AND vacated = 0 ORDER BY to_home_distance ASC";
$result = $conn->query($sql);

$categories = [];
$matrix = []; 
$zones = ["0-5km", "5-10km", "10-15km", "15-20km", "20-25km", "25km+"];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $cat = $row['emp_category'];
        $dist = (float)$row['to_home_distance'];
        if (!in_array($cat, $categories)) $categories[] = $cat;

        if ($dist <= 5) $z_idx = 0;
        elseif ($dist <= 10) $z_idx = 1;
        elseif ($dist <= 15) $z_idx = 2;
        elseif ($dist <= 20) $z_idx = 3;
        elseif ($dist <= 25) $z_idx = 4;
        else $z_idx = 5;

        $matrix[$cat][$z_idx][] = [
            'id' => $row['emp_id'],
            'name' => $row['calling_name'],
            'dist' => $row['to_home_distance']
        ];
    }
}

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Density Heatmap</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        
        .heat-cell { cursor: pointer; transition: all 0.2s; position: relative; }
        .heat-cell:hover { transform: scale(1.02); z-index: 10; box-shadow: inset 0 0 0 2px rgba(0,0,0,0.1); }
        
        /* Density Colors */
        .cell-empty { background-color: #ffffff; color: #e2e8f0; cursor: default; }
        .cell-low { background-color: #eff6ff; color: #1e40af; border: 1px solid #dbeafe; }
        .cell-med { background-color: #bfdbfe; color: #1e3a8a; border: 1px solid #93c5fd; }
        .cell-high { background-color: #3b82f6; color: white; border: 1px solid #2563eb; }
        .cell-extreme { background-color: #1e40af; color: white; border: 1px solid #1e3a8a; }

        /* Main Table Sticky Styles */
        .sticky-col { position: sticky; left: 0; z-index: 20; background-color: #f9fafb; border-right: 1px solid #e5e7eb; }
        .sticky-header { position: sticky; top: 0; z-index: 30; background-color: #f3f4f6; }

        /* Modal Styles & Animation */
        #empModal { display: none; }
        .modal-overlay { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); }
        
        /* Modal Table Sticky Header Fix */
        .modal-table-container { max-height: 60vh; overflow-y: auto; position: relative; }
        .modal-table-container table thead th { 
            position: sticky; 
            top: 0; 
            z-index: 50; 
            background-color: #ffffff; 
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
            border-bottom: 2px solid #f1f5f9;
        }

        .table-scroll::-webkit-scrollbar { width: 6px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="overflow-hidden h-screen text-slate-800">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="report_operations.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">System Reports</a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">EMPLOYEE DENSITY HEATMAP</span>
        </div>
    </div>
    <a href="report_operations.php" class="text-gray-300 hover:text-white transition text-xs flex items-center gap-2">
        Back
    </a>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col bg-slate-50">
    <div class="p-8 flex-grow flex flex-col h-full overflow-hidden">
        
        <div class="mb-6 flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-black text-slate-800 tracking-tight">Zone Density Matrix</h1>
                <p class="text-sm text-slate-500 italic">Click on a cell to see the specific employee breakdown.</p>
            </div>
            
            <div class="flex gap-4 items-center bg-white p-3 rounded-lg border border-slate-200 shadow-sm">
                <span class="text-[10px] font-bold text-slate-400 uppercase">Intensity Scale:</span>
                <div class="flex gap-2">
                    <div class="w-8 h-6 rounded cell-low text-[9px] flex items-center justify-center font-bold" title="1-2 Emp">1-2</div>
                    <div class="w-8 h-6 rounded cell-med text-[9px] flex items-center justify-center font-bold" title="3-5 Emp">3-5</div>
                    <div class="w-8 h-6 rounded cell-high text-[9px] flex items-center justify-center font-bold" title="6-10 Emp">6-10</div>
                    <div class="w-8 h-6 rounded cell-extreme text-[9px] flex items-center justify-center font-bold" title="10+ Emp">10+</div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-md border border-slate-200 flex-grow overflow-auto relative table-scroll">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th class="p-5 border-b border-r text-left text-[10px] font-black text-slate-400 uppercase sticky-header sticky-col z-40">Category \ Distance</th>
                        <?php foreach($zones as $zone): ?>
                            <th class="p-5 border-b border-r text-center text-xs font-bold text-slate-600 sticky-header"><?php echo $zone; ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($categories as $cat): ?>
                    <tr>
                        <td class="p-5 border-b border-r font-bold text-slate-700 text-sm sticky-col"><?php echo $cat; ?></td>
                        <?php foreach($zones as $idx => $zone): 
                            $list = $matrix[$cat][$idx] ?? [];
                            $count = count($list);
                            $class = 'cell-empty';
                            if ($count >= 10) $class = 'cell-extreme';
                            elseif ($count >= 6) $class = 'cell-high';
                            elseif ($count >= 3) $class = 'cell-med';
                            elseif ($count >= 1) $class = 'cell-low';
                            
                            $jsonData = htmlspecialchars(json_encode($list), ENT_QUOTES, 'UTF-8');
                        ?>
                        <td class="p-6 border-b border-r text-center heat-cell <?php echo $class; ?>" 
                            onclick="showEmployees('<?php echo $cat; ?>', '<?php echo $zone; ?>', <?php echo $jsonData; ?>)">
                            <span class="text-2xl font-black"><?php echo ($count > 0 ? $count : '-'); ?></span>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="empModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
    <div class="modal-overlay absolute inset-0" onclick="closeModal()"></div>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl z-10 overflow-hidden transform transition-all border border-slate-200">
        
        <div class="bg-slate-900 p-5 text-white flex justify-between items-center">
            <div>
                <h3 id="modalTitle" class="text-lg font-bold text-yellow-400">Employee List</h3>
                <p id="modalSubTitle" class="text-[10px] text-slate-400 uppercase tracking-widest font-bold mt-1"></p>
            </div>
            <button onclick="closeModal()" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="modal-table-container table-scroll">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr>
                        <th class="py-4 px-6 font-black text-slate-500 uppercase text-[10px] tracking-wider">Emp ID</th>
                        <th class="py-4 px-6 font-black text-slate-500 uppercase text-[10px] tracking-wider">Calling Name</th>
                        <th class="py-4 px-6 font-black text-slate-500 uppercase text-[10px] tracking-wider text-right">Distance</th>
                    </tr>
                </thead>
                <tbody id="modalTableBody" class="divide-y divide-slate-100">
                    </tbody>
            </table>
        </div>

        <div class="bg-slate-50 p-4 border-t border-slate-200 text-right">
            <button onclick="closeModal()" class="px-6 py-2 bg-slate-800 text-white rounded-lg text-xs font-bold hover:bg-slate-700 transition shadow-md">Close Window</button>
        </div>
    </div>
</div>

<script>
    function showEmployees(cat, zone, data) {
        if (!data || data.length === 0) return;

        document.getElementById('modalTitle').innerText = cat;
        document.getElementById('modalSubTitle').innerText = "Zone: " + zone + " | Count: " + data.length + " Employees";
        
        const tbody = document.getElementById('modalTableBody');
        tbody.innerHTML = '';

        data.forEach(emp => {
            const row = `
                <tr class="hover:bg-slate-50 transition">
                    <td class="py-4 px-6 font-mono font-bold text-indigo-600">${emp.id}</td>
                    <td class="py-4 px-6 font-medium text-slate-700">${emp.name}</td>
                    <td class="py-4 px-6 text-right font-bold text-slate-900">${emp.dist} KM</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });

        // Show modal with a smooth flex display
        document.getElementById('empModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('empModal').style.display = 'none';
    }
</script>

</body>
</html>