<?php
// nh_payments_analysis.php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include('../../includes/db.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { 
    header('location: ../../index.php'); exit; 
}

// --- 1. PERIOD SELECTION ---
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$filter_month_year = sprintf("%d-%02d", $selected_year, $selected_month);

// --- 2. CORE CALCULATION ---
function get_nh_op_totals($conn, $filter) {
    $sql = "SELECT nh.op_code, SUM(nh.distance) as actual_dist, os.slab_limit_distance, os.extra_rate
            FROM nh_register nh
            JOIN op_services os ON nh.op_code = os.op_code
            WHERE nh.done = 1 AND DATE_FORMAT(IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date), '%Y-%m') = ?
            GROUP BY nh.op_code";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $filter);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $totals = [];
    foreach($res as $r) {
        $pay_dist = (strpos($r['op_code'], 'NH') === 0) ? max($r['actual_dist'], $r['slab_limit_distance']) : $r['actual_dist'];
        $totals[$r['op_code']] = [
            'payment' => $pay_dist * $r['extra_rate'],
            'actual_dist' => $r['actual_dist']
        ];
    }
    return $totals;
}

$op_totals = get_nh_op_totals($conn, $filter_month_year);

$dept_analysis = [];
$emp_analysis = [];
$grand_total_cost = 0;
$total_unique_trips = 0;
$unique_trips_array = [];

$sql = "SELECT nh.op_code, nh.id as trip_id, nh.distance as trip_dist, e.department, e.calling_name, e.emp_id,
        (SELECT COUNT(*) FROM nh_trip_departments WHERE trip_id = nh.id) as head_count
        FROM nh_register nh
        JOIN nh_trip_departments ntd ON nh.id = ntd.trip_id
        JOIN employee e ON ntd.emp_id = e.emp_id
        WHERE nh.done = 1 AND DATE_FORMAT(IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date), '%Y-%m') = '$filter_month_year'";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $op = $row['op_code'];
        if (!isset($op_totals[$op]) || $op_totals[$op]['actual_dist'] <= 0) continue;

        if(!in_array($row['trip_id'], $unique_trips_array)) {
            $unique_trips_array[] = $row['trip_id'];
            $total_unique_trips++;
        }

        $rate_per_km = $op_totals[$op]['payment'] / $op_totals[$op]['actual_dist'];
        $trip_cost = $row['trip_dist'] * $rate_per_km;
        $total_heads = $row['head_count'];

        if ($total_heads > 0) {
            $cost_per_head = $trip_cost / $total_heads;

            $dept = !empty($row['department']) ? $row['department'] : 'Unknown';
            if(!isset($dept_analysis[$dept])) $dept_analysis[$dept] = 0;
            $dept_analysis[$dept] += $cost_per_head;

            $eid = $row['emp_id'];
            if(!isset($emp_analysis[$eid])) {
                $emp_analysis[$eid] = ['name' => $row['calling_name'] ?? 'Unknown', 'dept' => $dept, 'cost' => 0, 'trips' => 0];
            }
            $emp_analysis[$eid]['cost'] += $cost_per_head;
            $emp_analysis[$eid]['trips'] += 1;
            $grand_total_cost += $cost_per_head;
        }
    }
}

arsort($dept_analysis);
usort($emp_analysis, function($a, $b) { return $b['cost'] <=> $a['cost']; });

$top_spenders = array_slice($emp_analysis, 0, 25);
$top_dept_name = !empty($dept_analysis) ? array_key_first($dept_analysis) : "N/A";
$top_dept_cost = !empty($dept_analysis) ? reset($dept_analysis) : 0;
$top_dept_share = ($grand_total_cost > 0) ? ($top_dept_cost / $grand_total_cost) * 100 : 0;

include('../../includes/header.php'); include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Night Heldup Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; }
        .metric-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border-left: 5px solid #ccc; transition: transform 0.2s; }
        .metric-card:hover { transform: translateY(-2px); }
        .table-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        table.custom-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        table.custom-table th { background-color: #f1f5f9; color: #334155; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; padding: 14px 16px; text-align: left; position: sticky; top: 0; z-index: 10; border-bottom: 2px solid #cbd5e1; }
        table.custom-table td { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; color: #475569; vertical-align: middle; }
        .hidden-content { display: none !important; }
    </style>
</head>
<body class="overflow-hidden h-screen">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="report_operations.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                System Reports
            </a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Night Heldup Analysis
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <form method="GET" class="flex items-center gap-2" id="filterForm">
            <select name="month" class="bg-gray-800 border border-gray-600 text-white rounded px-2 py-1 text-xs focus:ring-1 focus:ring-yellow-400" onchange="document.getElementById('filterForm').submit()">
                <?php foreach(range(1,12) as $m): $m_val = str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                    <option value="<?php echo $m_val; ?>" <?php echo ($m_val == $selected_month) ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0,0,0,$m,10)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="year" class="bg-gray-800 border border-gray-600 text-white rounded px-2 py-1 text-xs focus:ring-1 focus:ring-yellow-400" onchange="document.getElementById('filterForm').submit()">
                <?php for($y=date('Y'); $y>=2023; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo ($y == $selected_year) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </form>
        <span class="text-gray-600 text-lg font-thin">|</span>
        <a href="download_nh_cost_breakdown.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
           class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-green-500">
            <i class="fas fa-file-excel"></i> Export
        </a>
        <a href="report_operations.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">Back</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col bg-slate-50">
    <div class="flex-grow p-6 flex flex-col h-full overflow-hidden">
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4 shrink-0">
            <div class="metric-card" style="border-color: #3b82f6;">
                <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Total Cost (LKR)</p>
                <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo number_format($grand_total_cost, 2); ?></p>
            </div>
            <div class="metric-card" style="border-color: #10b981;">
                <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Employees Involved</p>
                <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo count($emp_analysis); ?></p>
            </div>
            <div class="metric-card" style="border-color: #6366f1;">
                <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Total Trips</p>
                <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $total_unique_trips; ?></p>
            </div>
            <div class="metric-card" style="border-color: #f59e0b;">
                <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Biggest Contributor</p>
                <p class="text-lg font-bold text-gray-800 mt-1 truncate"><?php echo htmlspecialchars($top_dept_name); ?></p>
                <p class="text-xs text-orange-600 font-bold"><?php echo number_format($top_dept_share, 1); ?>% of cost</p>
            </div>
        </div>

        <div class="bg-white p-3 border-b border-gray-200 flex flex-wrap gap-4 items-center justify-between shrink-0 rounded-t-lg">
             <div class="flex border border-gray-300 rounded overflow-hidden">
                <button onclick="switchTab('chart')" id="btn-chart" class="px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 transition text-sm">
                    <i class="fas fa-chart-pie mr-2"></i> Overview
                </button>
                <button onclick="switchTab('analysis')" id="btn-analysis" class="px-4 py-2 bg-white text-gray-600 border-r border-gray-300 transition text-sm">
                    <i class="fas fa-chart-bar mr-2 text-red-500"></i> Top Spenders
                </button>
                <button onclick="switchTab('table')" id="btn-table" class="px-4 py-2 bg-white text-gray-600 transition text-sm">
                    <i class="fas fa-list mr-2"></i> Employee List
                </button>
            </div>
            <div class="relative">
                <input type="text" id="tableSearch" onkeyup="filterTable()" placeholder="Search employee..." class="border border-gray-300 rounded-md px-3 py-1.5 text-xs w-64">
            </div>
        </div>

        <div id="view-chart" class="bg-white p-6 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 overflow-y-auto">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 h-full">
                <div class="flex flex-col h-full">
                    <h3 class="font-bold text-gray-600 text-xs uppercase mb-4 text-center border-b pb-2">Department Breakdown</h3>
                    <div class="relative flex-grow min-h-[300px]">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>
                <div class="flex flex-col h-full">
                    <h3 class="font-bold text-gray-600 text-xs uppercase mb-4 text-center border-b pb-2">Top 10 Cost Centers</h3>
                    <div class="relative flex-grow min-h-[300px]">
                        <canvas id="deptBarChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div id="view-analysis" class="bg-white p-6 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 overflow-y-auto hidden-content">
            <h3 class="font-bold text-gray-700 text-sm uppercase mb-2">Top 25 Highest Cost Employees</h3>
            <div class="relative flex-grow w-full h-[400px]">
                <canvas id="topEmpBarChart"></canvas>
            </div>
        </div>

        <div id="view-table" class="bg-white border border-gray-200 rounded-b-lg flex-grow min-h-0 flex flex-col hidden-content">
            <div class="table-scroll flex-grow overflow-auto">
                <table class="custom-table" id="empTable">
                    <thead>
                        <tr>
                            <th class="w-16">Rank</th>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th class="text-center">Trips</th>
                            <th class="text-right">Cost (LKR)</th>
                            <th class="text-right w-32">% Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach($emp_analysis as $emp): 
                            $percentage = ($grand_total_cost > 0) ? ($emp['cost'] / $grand_total_cost) * 100 : 0; ?>
                        <tr>
                            <td class="font-mono text-gray-500">#<?php echo $rank++; ?></td>
                            <td class="font-bold text-gray-700"><?php echo htmlspecialchars($emp['name']); ?></td>
                            <td><span class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded text-xs font-semibold"><?php echo htmlspecialchars($emp['dept']); ?></span></td>
                            <td class="text-center"><?php echo $emp['trips']; ?></td>
                            <td class="text-right font-bold"><?php echo number_format($emp['cost'], 2); ?></td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <div class="w-16 bg-gray-200 rounded-full h-1.5 overflow-hidden">
                                        <div class="bg-blue-600 h-1.5 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500"><?php echo number_format($percentage, 1); ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(viewName) {
    ['chart', 'analysis', 'table'].forEach(v => {
        document.getElementById('view-' + v).classList.add('hidden-content');
        document.getElementById('btn-' + v).className = "px-4 py-2 bg-white text-gray-600 border-r border-gray-300 transition text-sm";
    });
    document.getElementById('view-' + viewName).classList.remove('hidden-content');
    document.getElementById('btn-' + viewName).className = "px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 transition text-sm";
}

function filterTable() {
    const filter = document.getElementById("tableSearch").value.toLowerCase();
    const rows = document.getElementById("empTable").getElementsByTagName("tr");
    for (let i = 1; i < rows.length; i++) {
        rows[i].style.display = rows[i].innerText.toLowerCase().includes(filter) ? "" : "none";
    }
}

const colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#6366F1'];

new Chart(document.getElementById('deptChart'), {
    type: 'doughnut',
    data: { labels: <?php echo json_encode(array_keys($dept_analysis)); ?>, datasets: [{ data: <?php echo json_encode(array_values($dept_analysis)); ?>, backgroundColor: colors }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: {size: 10} } } } }
});

new Chart(document.getElementById('deptBarChart'), {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_keys($dept_analysis)); ?>, datasets: [{ label: 'Cost', data: <?php echo json_encode(array_values($dept_analysis)); ?>, backgroundColor: '#6366F1' }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false }
});

new Chart(document.getElementById('topEmpBarChart'), {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($top_spenders, 'name')); ?>, datasets: [{ label: 'Cost (LKR)', data: <?php echo json_encode(array_column($top_spenders, 'cost')); ?>, backgroundColor: '#EF4444' }] },
    options: { responsive: true, maintainAspectRatio: false }
});
</script>
</body>
</html>