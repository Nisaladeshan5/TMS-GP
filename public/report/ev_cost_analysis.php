<?php
// ev_cost_analysis.php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include('../../includes/db.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { 
    header('location: ../../index.php'); exit; 
}

// --- 1. PERIOD SELECTION ---
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// --- 2. CALCULATIONS (Rates & Logic) ---
$fuel_history = [];
$fuel_res = $conn->query("SELECT rate_id, rate, date FROM fuel_rate ORDER BY date DESC");
while ($row = $fuel_res->fetch_assoc()) {
    $fuel_history[$row['rate_id']][] = ['date' => $row['date'], 'rate' => (float)$row['rate']];
}

function get_rate_for_date($rate_id, $trip_date, $history) {
    if (!isset($history[$rate_id])) return 0;
    foreach ($history[$rate_id] as $record) {
        if ($record['date'] <= $trip_date) return $record['rate'];
    }
    return end($history[$rate_id])['rate'] ?? 0;
}

$op_rates = [];
$op_res = $conn->query("SELECT op_code, extra_rate_ac, extra_rate FROM op_services");
while ($row = $op_res->fetch_assoc()) {
    $op_rates[$row['op_code']] = ['ac' => (float)$row['extra_rate_ac'], 'non_ac' => (float)$row['extra_rate']];
}

$route_data = [];
$rt_res = $conn->query("SELECT route_code, fixed_amount, vehicle_no, with_fuel FROM route");
while ($row = $rt_res->fetch_assoc()) {
    $route_data[$row['route_code']] = ['fixed' => (float)$row['fixed_amount'], 'veh' => $row['vehicle_no'], 'fuel' => (int)$row['with_fuel']];
}

$vehicle_specs = [];
$veh_res = $conn->query("SELECT v.vehicle_no, v.rate_id, c.distance AS km_per_liter FROM vehicle v LEFT JOIN consumption c ON v.fuel_efficiency = c.c_id");
while ($row = $veh_res->fetch_assoc()) {
    $vehicle_specs[$row['vehicle_no']] = ['rate_id' => $row['rate_id'], 'km_per_liter' => (float)$row['km_per_liter']];
}

// --- 3. ANALYSIS LOGIC ---
$dept_analysis = [];
$reason_analysis = [];
$emp_analysis = [];
$grand_total_cost = 0;
$total_unique_trips = 0;
$unique_trips_array = [];

$sql = "SELECT 
            evr.id as trip_id, evr.date, evr.distance, evr.op_code, evr.route, evr.ac_status,
            r.reason, 
            e.department, e.calling_name, e.emp_id,
            (SELECT COUNT(*) FROM ev_trip_employee_reasons WHERE trip_id = evr.id) AS total_trip_employees
        FROM extra_vehicle_register evr
        JOIN ev_trip_employee_reasons eter ON evr.id = eter.trip_id
        JOIN reason r ON eter.reason_code = r.reason_code
        LEFT JOIN employee e ON eter.emp_id = e.emp_id 
        WHERE MONTH(evr.date) = ? AND YEAR(evr.date) = ? AND evr.done = 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $selected_month, $selected_year);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $trip_cost = 0;
    $dist = (float)$row['distance'];
    
    if (!empty($row['op_code'])) {
        if (isset($op_rates[$row['op_code']])) {
            $rate = ($row['ac_status'] == 1) ? $op_rates[$row['op_code']]['ac'] : $op_rates[$row['op_code']]['non_ac'];
            $trip_cost = $dist * $rate;
        }
    } elseif (!empty($row['route'])) {
        if (isset($route_data[$row['route']])) {
            $fuel_c = 0;
            $rt = $route_data[$row['route']];
            if ($rt['fuel'] == 1 && isset($vehicle_specs[$rt['veh']])) {
                $v = $vehicle_specs[$rt['veh']];
                $f_rate = get_rate_for_date($v['rate_id'], $row['date'], $fuel_history);
                if ($v['km_per_liter'] > 0) $fuel_c = $f_rate / $v['km_per_liter'];
            }
            $trip_cost = $dist * ($rt['fixed'] + $fuel_c);
        }
    }

    if(!in_array($row['trip_id'], $unique_trips_array)) {
        $unique_trips_array[] = $row['trip_id'];
        $total_unique_trips++;
    }

    $total_heads = (int)$row['total_trip_employees'];
    if ($total_heads > 0) {
        $cost_per_head = $trip_cost / $total_heads;
        $dept = $row['department'] ?: 'Unknown';
        $dept_analysis[$dept] = ($dept_analysis[$dept] ?? 0) + $cost_per_head;
        $reason = $row['reason'];
        $reason_analysis[$reason] = ($reason_analysis[$reason] ?? 0) + $cost_per_head;

        $eid = $row['emp_id'];
        if (!isset($emp_analysis[$eid])) {
            $emp_analysis[$eid] = ['name' => $row['calling_name'] ?? 'Unknown', 'dept' => $dept, 'cost' => 0, 'trips' => 0];
        }
        $emp_analysis[$eid]['cost'] += $cost_per_head;
        $emp_analysis[$eid]['trips'] += 1;
        $grand_total_cost += $cost_per_head;
    }
}

arsort($dept_analysis);
arsort($reason_analysis);
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
    <title>EV Cost Analysis</title>
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
        <div class="flex items-center space-x-2">
            <a href="report_operations.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">System Reports</a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider">Extra Vehicle Analysis</span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <form method="GET" class="flex items-center gap-2" id="filterForm">
            <select name="month" class="bg-gray-800 border border-gray-600 text-white rounded px-2 py-1 text-xs focus:ring-1 focus:ring-yellow-400" onchange="this.form.submit()">
                <?php foreach(range(1,12) as $m): $m_val = str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                    <option value="<?= $m_val ?>" <?= ($m_val == $selected_month) ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,10)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="year" class="bg-gray-800 border border-gray-600 text-white rounded px-2 py-1 text-xs focus:ring-1 focus:ring-yellow-400" onchange="this.form.submit()">
                <?php for($y=date('Y'); $y>=2023; $y--): ?>
                    <option value="<?= $y ?>" <?= ($y == $selected_year) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
        <a href="download_ev_cost_breakdown.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
           class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-green-500">
            <i class="fas fa-file-excel"></i> Export
        </a>
        <a href="report_operations.php" class="text-gray-300 hover:text-white transition text-sm">Back</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col bg-slate-50">
    <div class="flex-grow p-6 flex flex-col h-full overflow-hidden">
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4 shrink-0">
            <div class="metric-card" style="border-color: #3b82f6;">
                <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Total EV Cost (LKR)</p>
                <p class="text-2xl font-bold text-gray-800 mt-1"><?= number_format($grand_total_cost, 2) ?></p>
            </div>
            <div class="metric-card" style="border-color: #10b981;">
                <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Staff Involved</p>
                <p class="text-2xl font-bold text-gray-800 mt-1"><?= count($emp_analysis) ?></p>
            </div>
            <div class="metric-card" style="border-color: #6366f1;">
                <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Total Trips</p>
                <p class="text-2xl font-bold text-gray-800 mt-1"><?= $total_unique_trips ?></p>
            </div>
            <div class="metric-card" style="border-color: #f59e0b;">
                <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Top Contributor</p>
                <p class="text-lg font-bold text-gray-800 mt-1 truncate"><?= substr($top_dept_name, 0, 15) ?>...</p>
                <p class="text-xs text-orange-600 font-bold"><?= number_format($top_dept_share, 1) ?>% Share</p>
            </div>
        </div>

        <div class="bg-white p-3 border-b border-gray-200 flex items-center justify-between shrink-0 rounded-t-lg">
             <div class="flex border border-gray-300 rounded overflow-hidden">
                <button onclick="switchTab('chart')" id="btn-chart" class="px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 transition text-sm">
                    <i class="fas fa-chart-pie mr-2"></i> Overview
                </button>
                <button onclick="switchTab('reason')" id="btn-reason" class="px-4 py-2 bg-white text-gray-600 border-r border-gray-300 transition text-sm">
                    <i class="fas fa-tags mr-2 text-purple-500"></i> Reason Analysis
                </button>
                <button onclick="switchTab('analysis')" id="btn-analysis" class="px-4 py-2 bg-white text-gray-600 border-r border-gray-300 transition text-sm">
                    <i class="fas fa-chart-bar mr-2 text-red-500"></i> Top Spenders
                </button>
                <button onclick="switchTab('table')" id="btn-table" class="px-4 py-2 bg-white text-gray-600 transition text-sm">
                    <i class="fas fa-list mr-2"></i> Employee List
                </button>
            </div>
            <div class="relative">
                <input type="text" id="tableSearch" onkeyup="filterTable()" placeholder="Search employee..." class="border border-gray-300 rounded-md px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 w-64">
                <i class="fas fa-search absolute right-3 top-2 text-gray-400 text-xs"></i>
            </div>
        </div>

        <div id="view-chart" class="bg-white p-6 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 overflow-y-auto">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 h-full">
                <div class="flex flex-col">
                    <h3 class="font-bold text-gray-600 text-xs uppercase mb-4 text-center border-b pb-2">Department Breakdown</h3>
                    <div class="relative flex-grow min-h-[300px]"><canvas id="deptChart"></canvas></div>
                </div>
                <div class="flex flex-col">
                    <h3 class="font-bold text-gray-600 text-xs uppercase mb-4 text-center border-b pb-2">Top Spend Contributors</h3>
                    <div class="relative flex-grow min-h-[300px]"><canvas id="topSpendDoughnut"></canvas></div>
                </div>
            </div>
        </div>

        <div id="view-reason" class="bg-white p-6 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 overflow-y-auto hidden-content">
            <h3 class="font-bold text-gray-700 text-sm uppercase mb-2">Cost by Reason</h3>
            <div class="relative flex-grow w-full min-h-[400px]"><canvas id="reasonChart"></canvas></div>
        </div>

        <div id="view-analysis" class="bg-white p-6 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 overflow-y-auto hidden-content">
            <h3 class="font-bold text-gray-700 text-sm uppercase mb-2">Top 25 Spenders</h3>
            <div class="relative flex-grow w-full min-h-[500px]"><canvas id="topEmpBarChart"></canvas></div>
        </div>

        <div id="view-table" class="bg-white border border-gray-200 rounded-b-lg flex-grow min-h-0 flex flex-col hidden-content">
            <div class="table-scroll flex-grow overflow-auto h-full">
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
                            $percentage = ($grand_total_cost > 0) ? ($emp['cost'] / $grand_total_cost) * 100 : 0;
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="font-mono text-gray-500">#<?= $rank++ ?></td>
                            <td class="font-bold text-gray-700"><?= htmlspecialchars($emp['name']) ?></td>
                            <td><span class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded text-xs font-semibold border border-blue-100"><?= $emp['dept'] ?></span></td>
                            <td class="text-center font-mono"><?= $emp['trips'] ?></td>
                            <td class="text-right font-bold text-gray-800"><?= number_format($emp['cost'], 2) ?></td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <div class="w-16 bg-gray-200 rounded-full h-1.5 overflow-hidden">
                                        <div class="bg-blue-600 h-1.5" style="width: <?= $percentage ?>%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500 w-8"><?= number_format($percentage, 1) ?>%</span>
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
    ['chart', 'reason', 'analysis', 'table'].forEach(v => {
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
        let name = rows[i].getElementsByTagName("td")[1]?.innerText || "";
        rows[i].style.display = name.toLowerCase().includes(filter) ? "" : "none";
    }
}

const colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#6366F1', '#14B8A6'];

// Charts Configuration
new Chart(document.getElementById('deptChart'), {
    type: 'doughnut',
    data: { labels: <?= json_encode(array_keys($dept_analysis)) ?>, datasets: [{ data: <?= json_encode(array_values($dept_analysis)) ?>, backgroundColor: colors, borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: {size: 10} } } } }
});

new Chart(document.getElementById('topSpendDoughnut'), {
    type: 'pie',
    data: { labels: <?= json_encode(array_slice(array_keys($dept_analysis), 0, 5)) ?>, datasets: [{ data: <?= json_encode(array_slice(array_values($dept_analysis), 0, 5)) ?>, backgroundColor: colors, borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: {size: 10} } } } }
});

new Chart(document.getElementById('reasonChart'), {
    type: 'bar',
    data: { labels: <?= json_encode(array_keys($reason_analysis)) ?>, datasets: [{ label: 'Cost LKR', data: <?= json_encode(array_values($reason_analysis)) ?>, backgroundColor: '#8B5CF6', borderRadius: 4 }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false }
});

new Chart(document.getElementById('topEmpBarChart'), {
    type: 'bar',
    data: { labels: <?= json_encode(array_column($top_spenders, 'name')) ?>, datasets: [{ label: 'Cost LKR', data: <?= json_encode(array_column($top_spenders, 'cost')) ?>, backgroundColor: '#EF4444', borderRadius: 4 }] },
    options: { responsive: true, maintainAspectRatio: false }
});
</script>
</body>
</html>