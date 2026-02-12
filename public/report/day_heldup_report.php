<?php
// day_heldup_report.php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include('../../includes/db.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { 
    header('location: ../../index.php'); exit; 
}

// --- 1. PERIOD SELECTION ---
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// --- 2. CALCULATION LOGIC ---
function calculate_vehicle_totals($conn, $m, $y) {
    $sql = "SELECT dha.op_code, dha.date, dha.ac, os.slab_limit_distance, os.extra_rate_ac, os.extra_rate AS extra_rate_nonac 
            FROM dh_attendance dha
            JOIN op_services os ON dha.op_code = os.op_code
            WHERE DATE_FORMAT(dha.date, '%Y-%m') = ?";
    $stmt = $conn->prepare($sql);
    $param = "$y-$m";
    $stmt->bind_param("s", $param);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $daily_data = [];

    foreach ($records as $rec) {
        $date = $rec['date'];
        $op_code = $rec['op_code'];
        $dist_sql = "SELECT SUM(distance) as total_dist FROM day_heldup_register WHERE op_code='$op_code' AND date='$date' AND done=1";
        $dist_res = $conn->query($dist_sql);
        $actual_dist = (float)($dist_res->fetch_assoc()['total_dist'] ?? 0);

        $rate = ($rec['ac'] == 1) ? $rec['extra_rate_ac'] : $rec['extra_rate_nonac'];
        $pay_dist = max($actual_dist, $rec['slab_limit_distance']); 
        $payment = $pay_dist * $rate;

        if (!isset($daily_data[$op_code])) {
            $daily_data[$op_code] = ['total_payment' => 0, 'total_actual_distance' => 0];
        }
        $daily_data[$op_code]['total_payment'] += $payment;
        $daily_data[$op_code]['total_actual_distance'] += $actual_dist;
    }
    return $daily_data;
}

$daily_totals = calculate_vehicle_totals($conn, $selected_month, $selected_year);

// Analysis Variables
$dept_analysis = [];
$gl_analysis = [];
$reason_analysis = []; // NEW: Reason Array
$emp_analysis = [];
$grand_total_cost = 0;
$total_unique_trips = 0;
$unique_trips_array = [];

$sql = "SELECT 
            dhr.op_code, dhr.trip_id, dhr.distance AS trip_distance,
            gl.gl_name, 
            r.reason, 
            e.department, e.calling_name, e.emp_id,
            (SELECT COUNT(*) FROM dh_emp_reason WHERE trip_id = dhr.trip_id) AS total_trip_employees
        FROM day_heldup_register dhr
        JOIN dh_emp_reason dher ON dhr.trip_id = dher.trip_id
        JOIN reason r ON dher.reason_code = r.reason_code
        JOIN gl gl ON r.gl_code = gl.gl_code
        LEFT JOIN employee e ON dher.emp_id = e.emp_id 
        WHERE DATE_FORMAT(dhr.date, '%Y-%m') = '$selected_year-$selected_month' AND dhr.done = 1";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $op_code = $row['op_code'];
        if (!isset($daily_totals[$op_code]) || $daily_totals[$op_code]['total_actual_distance'] <= 0) continue;

        if(!in_array($row['trip_id'], $unique_trips_array)) {
            $unique_trips_array[] = $row['trip_id'];
            $total_unique_trips++;
        }

        $totals = $daily_totals[$op_code];
        $rate_per_km = $totals['total_payment'] / $totals['total_actual_distance'];
        $trip_cost = $row['trip_distance'] * $rate_per_km;
        $total_heads = $row['total_trip_employees'];

        if ($total_heads > 0) {
            $cost_per_head = $trip_cost / $total_heads;

            // Dept
            $dept = !empty($row['department']) ? $row['department'] : 'Unknown';
            if(!isset($dept_analysis[$dept])) $dept_analysis[$dept] = 0;
            $dept_analysis[$dept] += $cost_per_head;

            // GL
            $gl = $row['gl_name'];
            if(!isset($gl_analysis[$gl])) $gl_analysis[$gl] = 0;
            $gl_analysis[$gl] += $cost_per_head;

            // Reason
            $reason = $row['reason'];
            if(!isset($reason_analysis[$reason])) $reason_analysis[$reason] = 0;
            $reason_analysis[$reason] += $cost_per_head;

            // Employee
            $eid = $row['emp_id'];
            $name = $row['calling_name'] ?? 'Unknown';
            if(!isset($emp_analysis[$eid])) {
                $emp_analysis[$eid] = ['name' => $name, 'dept' => $dept, 'cost' => 0, 'trips' => 0];
            }
            $emp_analysis[$eid]['cost'] += $cost_per_head;
            $emp_analysis[$eid]['trips'] += 1;

            $grand_total_cost += $cost_per_head;
        }
    }
}

// Sorting
arsort($dept_analysis);
arsort($gl_analysis);
arsort($reason_analysis);
usort($emp_analysis, function($a, $b) { return $b['cost'] <=> $a['cost']; });

// TOP 25 Employees
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
    <title>Day Heldup Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; }
        .metric-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border-left: 5px solid #ccc; transition: transform 0.2s; }
        .metric-card:hover { transform: translateY(-2px); }
        .tab-btn { padding: 10px 20px; font-weight: 600; color: #64748b; border-bottom: 3px solid transparent; cursor: pointer; transition: all 0.3s; }
        .tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; background-color: #eff6ff; border-top-left-radius: 8px; border-top-right-radius: 8px; }
        
        .table-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        table.custom-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        table.custom-table th { background-color: #f1f5f9; color: #334155; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; padding: 14px 16px; text-align: left; position: sticky; top: 0; z-index: 10; border-bottom: 2px solid #cbd5e1; }
        table.custom-table td { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; color: #475569; vertical-align: middle; }
        table.custom-table tr:hover td { background-color: #f8fafc; }
        
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
                Day Heldup Analysis
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
        <a href="download_dh_cost_breakdown.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
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
                <p class="text-lg font-bold text-gray-800 mt-1 truncate" title="<?php echo htmlspecialchars($top_dept_name); ?>">
                    <?php echo htmlspecialchars(substr($top_dept_name, 0, 15)); ?>...
                </p>
                <p class="text-xs text-orange-600 font-bold"><?php echo number_format($top_dept_share, 1); ?>% of total cost</p>
            </div>
        </div>

        <div class="bg-white p-3 border-b border-gray-200 flex flex-wrap gap-4 items-center justify-between shrink-0 rounded-t-lg">
             <div class="flex border border-gray-300 rounded overflow-hidden">
                <button onclick="switchTab('chart')" id="btn-chart" class="px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 transition hover:bg-blue-100 text-sm">
                    <i class="fas fa-chart-pie mr-2"></i> Overview
                </button>
                <button onclick="switchTab('reason')" id="btn-reason" class="px-4 py-2 bg-white text-gray-600 border-r border-gray-300 transition hover:bg-gray-50 text-sm">
                    <i class="fas fa-tags mr-2 text-purple-500"></i> Reason Analysis
                </button>
                <button onclick="switchTab('analysis')" id="btn-analysis" class="px-4 py-2 bg-white text-gray-600 border-r border-gray-300 transition hover:bg-gray-50 text-sm">
                    <i class="fas fa-chart-bar mr-2 text-red-500"></i> Top Spenders
                </button>
                <button onclick="switchTab('table')" id="btn-table" class="px-4 py-2 bg-white text-gray-600 transition hover:bg-gray-50 text-sm">
                    <i class="fas fa-list mr-2"></i> Employee List
                </button>
            </div>
            <div class="relative">
                <input type="text" id="tableSearch" onkeyup="filterTable()" placeholder="Search employee..." class="border border-gray-300 rounded-md px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 w-64">
                <i class="fas fa-search absolute right-3 top-2 text-gray-400 text-xs"></i>
            </div>
        </div>

        <div id="view-chart" class="bg-white p-6 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 overflow-y-auto">
            <?php if($grand_total_cost > 0): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 h-full">
                <div class="flex flex-col h-full">
                    <h3 class="font-bold text-gray-600 text-xs uppercase mb-4 text-center border-b pb-2">Department Breakdown</h3>
                    <div class="relative flex-grow min-h-[300px]">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>
                <div class="flex flex-col h-full">
                    <h3 class="font-bold text-gray-600 text-xs uppercase mb-4 text-center border-b pb-2">GL Code Breakdown</h3>
                    <div class="relative flex-grow min-h-[300px]">
                        <canvas id="glChart"></canvas>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <div class="h-full flex flex-col items-center justify-center text-gray-400">
                    <i class="fas fa-folder-open text-4xl mb-3"></i>
                    <p>No Data Available for this period.</p>
                </div>
            <?php endif; ?>
        </div>

        <div id="view-reason" class="bg-white p-6 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 overflow-y-auto hidden-content">
            <div class="flex flex-col h-full">
                <h3 class="font-bold text-gray-700 text-sm uppercase mb-2 flex justify-between items-center">
                    <span><i class="fas fa-tags text-purple-500 mr-2"></i> Cost by Reason</span>
                    <span class="text-xs text-gray-400 font-normal">Sorted by Total Cost (High to Low)</span>
                </h3>
                <div class="relative flex-grow w-full">
                    <canvas id="reasonChart"></canvas>
                </div>
            </div>
        </div>

        <div id="view-analysis" class="bg-white p-6 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 overflow-y-auto hidden-content">
            <div class="flex flex-col h-full">
                <h3 class="font-bold text-gray-700 text-sm uppercase mb-2 flex justify-between items-center">
                    <span><i class="fas fa-chart-bar text-red-500 mr-2"></i> Top 25 Highest Cost Employees</span>
                    <span class="text-xs text-gray-400 font-normal">Sorted by Total Cost (High to Low)</span>
                </h3>
                <div class="relative flex-grow w-full">
                    <canvas id="topEmpBarChart"></canvas>
                </div>
            </div>
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
                        <?php 
                        $rank = 1;
                        foreach($emp_analysis as $emp): 
                            $percentage = ($grand_total_cost > 0) ? ($emp['cost'] / $grand_total_cost) * 100 : 0;
                        ?>
                        <tr>
                            <td class="font-mono text-gray-500">#<?php echo $rank++; ?></td>
                            <td class="font-bold text-gray-700"><?php echo htmlspecialchars($emp['name']); ?></td>
                            <td>
                                <span class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded text-xs font-semibold border border-blue-100">
                                    <?php echo htmlspecialchars($emp['dept']); ?>
                                </span>
                            </td>
                            <td class="text-center font-mono"><?php echo $emp['trips']; ?></td>
                            <td class="text-right font-bold text-gray-800"><?php echo number_format($emp['cost'], 2); ?></td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <div class="w-16 bg-gray-200 rounded-full h-1.5 overflow-hidden">
                                        <div class="bg-blue-600 h-1.5 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500 w-8"><?php echo number_format($percentage, 1); ?>%</span>
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
// --- TAB SWITCHING ---
function switchTab(viewName) {
    ['chart', 'reason', 'analysis', 'table'].forEach(v => {
        document.getElementById('view-' + v).classList.add('hidden-content');
        let btn = document.getElementById('btn-' + v);
        btn.className = "px-4 py-2 bg-white text-gray-600 border-r border-gray-300 transition hover:bg-gray-50 text-sm";
    });
    
    document.getElementById('view-' + viewName).classList.remove('hidden-content');
    let activeBtn = document.getElementById('btn-' + viewName);
    activeBtn.className = "px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 transition hover:bg-blue-100 text-sm";
}

// --- TABLE FILTER ---
function filterTable() {
    const filter = document.getElementById("tableSearch").value.toLowerCase();
    const rows = document.getElementById("empTable").getElementsByTagName("tr");
    for (let i = 1; i < rows.length; i++) {
        let name = rows[i].getElementsByTagName("td")[1]?.innerText || "";
        let dept = rows[i].getElementsByTagName("td")[2]?.innerText || "";
        rows[i].style.display = (name.toLowerCase().includes(filter) || dept.toLowerCase().includes(filter)) ? "" : "none";
    }
}

// --- CHART DATA ---
const colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#6366F1', '#14B8A6'];

// 1. Department & GL Data
const deptLabels = <?php echo json_encode(array_keys($dept_analysis)); ?>;
const deptData = <?php echo json_encode(array_values($dept_analysis)); ?>;
const glLabels = <?php echo json_encode(array_keys($gl_analysis)); ?>;
const glData = <?php echo json_encode(array_values($gl_analysis)); ?>;

// 2. Reason Data
const reasonLabels = <?php echo json_encode(array_keys($reason_analysis)); ?>;
const reasonData = <?php echo json_encode(array_values($reason_analysis)); ?>;

if(deptLabels.length > 0) {
    // Dept Chart
    new Chart(document.getElementById('deptChart'), {
        type: 'doughnut',
        data: { labels: deptLabels, datasets: [{ data: deptData, backgroundColor: colors, borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: {size: 10} } } } }
    });
    
    // GL Chart
    new Chart(document.getElementById('glChart'), {
        type: 'pie',
        data: { labels: glLabels, datasets: [{ data: glData, backgroundColor: colors, borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: {size: 10} } } } }
    });

    // Reason Chart (Separate Tab - Horizontal Bar)
    new Chart(document.getElementById('reasonChart'), {
        type: 'bar',
        data: {
            labels: reasonLabels,
            datasets: [{
                label: 'Cost (LKR)',
                data: reasonData,
                backgroundColor: '#8B5CF6', // Purple color
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y', // Horizontal Bar for better readability
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Rs. ' + context.raw.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                x: { 
                    grid: { borderDash: [2, 2] },
                    position: 'top' // Put X labels on top for easier reading
                },
                y: { grid: { display: false } }
            }
        }
    });
}

// --- TOP 25 RED BAR CHART (VERTICAL) ---
const topNames = <?php echo json_encode(array_column($top_spenders, 'name')); ?>;
const topCosts = <?php echo json_encode(array_column($top_spenders, 'cost')); ?>;

if(topNames.length > 0) {
    new Chart(document.getElementById('topEmpBarChart'), {
        type: 'bar',
        data: {
            labels: topNames,
            datasets: [{
                label: 'Cost (LKR)',
                data: topCosts,
                backgroundColor: '#EF4444', 
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Rs. ' + context.raw.toLocaleString();
                        }
                    }
                }
            },
            scales: { 
                x: { 
                    grid: { display: false },
                    ticks: {
                        autoSkip: false,
                        maxRotation: 90,
                        minRotation: 45
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: { borderDash: [2, 2] }
                }
            }
        }
    });
}
</script>
</body>
</html>