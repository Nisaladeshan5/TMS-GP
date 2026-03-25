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

$grouped_data = [];
$unique_routes = [];
$unique_types = [];
$efficiency_high = 0; $efficiency_med = 0; $efficiency_low = 0;
$total_sub_routes = 0;
$report_data = [];

// --- 1. FETCH MAIN ROUTE DATA (Independent Calculation) ---
$sql_main = "
    SELECT 
        r.route_code,
        r.route AS route_name,
        v.capacity AS main_seat_cap,
        COALESCE(v.standing_capacity, 0) AS main_stand_cap,
        v.vehicle_no,
        v.type as vehicle_type,
        COUNT(CASE WHEN e.to_home_distance > 3 AND e.is_active = 1 AND e.vacated = 0 THEN 1 END) AS main_seated_count,
        COUNT(CASE WHEN e.to_home_distance <= 3 AND e.is_active = 1 AND e.vacated = 0 THEN 1 END) AS main_standing_count
    FROM route r
    LEFT JOIN vehicle v ON r.vehicle_no = v.vehicle_no
    LEFT JOIN employee e ON SUBSTRING(e.route, 1, 10) = r.route_code AND e.is_active = 1 AND e.vacated = 0
    WHERE r.is_active = 1
    GROUP BY r.route_code
    ORDER BY CAST(SUBSTRING(r.route_code, 7, 3) AS UNSIGNED) ASC
";

$result_main = $conn->query($sql_main);

if ($result_main) {
    while ($row = $result_main->fetch_assoc()) {
        $r_code = $row['route_code'];
        
        $seat_cap = (int)$row['main_seat_cap'];
        $stand_cap = (int)$row['main_stand_cap'];
        $seated_act = (int)$row['main_seated_count'];
        $standing_act = (int)$row['main_standing_count'];
        
        // If no standing capacity, shift standing to seated
        if ($stand_cap <= 0) {
            $seated_act += $standing_act;
            $standing_act = 0;
        }

        $total_cap = $seat_cap + $stand_cap;
        $total_act = $seated_act + $standing_act;
        $percentage = ($total_cap > 0) ? round(($total_act / $total_cap) * 100, 0) : 0;

        // අලුත් ගණනය කිරීම් (Available & Percentages)
        $s_ava = max(0, $seat_cap - $seated_act);
        $s_pre = ($seat_cap > 0) ? round(($seated_act / $seat_cap) * 100, 0) : 0;
        $st_ava = max(0, $stand_cap - $standing_act);
        $st_pre = ($stand_cap > 0) ? round(($standing_act / $stand_cap) * 100, 0) : 0;

        // Main Route එක සඳහා Sub-route වල Badge Colors යෙදීම
        if ($percentage >= 95) { $main_status_text = 'Excellent'; $main_badge = 'background-color: #d1fae5; color: #065f46; border: 1px solid #10b981;'; }
        elseif ($percentage >= 86) { $main_status_text = 'Good'; $main_badge = 'background-color: #fef3c7; color: #92400e; border: 1px solid #f59e0b;'; }
        else { $main_status_text = 'Underutilized'; $main_badge = 'background-color: #fee2e2; color: #b91c1c; border: 1px solid #ef4444;'; }

        $v_type = ucfirst($row['vehicle_type'] ?? 'Unknown');

        $grouped_data[$r_code] = [
            'route_code' => $r_code,
            'route_name' => $row['route_name'],
            'vehicle_no' => $row['vehicle_no'],
            'vehicle_type' => $v_type,
            'main_seat_cap' => $seat_cap,
            'main_seated_act' => $seated_act,
            'main_s_ava' => $s_ava,
            'main_s_pre' => $s_pre,
            'main_stand_cap' => $stand_cap,
            'main_standing_act' => $standing_act,
            'main_st_ava' => $st_ava,
            'main_st_pre' => $st_pre,
            'main_total_act' => $total_act,
            'main_percentage' => $percentage,
            'main_badge_style' => $main_badge,
            'main_status_text' => $main_status_text,
            'sub_routes' => []
        ];
    }
}

// --- 2. FETCH SUB-ROUTE DATA ---
$sql_sub = "
    SELECT 
        sr.sub_route_code,
        sr.sub_route AS sub_route_name,
        r.route AS route_name,
        sr.route_code,
        v.capacity AS seat_cap,
        COALESCE(v.standing_capacity, 0) AS stand_cap,
        v.vehicle_no,
        v.type as vehicle_type,
        COUNT(CASE WHEN e.to_home_distance > 3 AND e.is_active = 1 AND e.vacated = 0 THEN 1 END) AS seated_count,
        COUNT(CASE WHEN e.to_home_distance <= 3 AND e.is_active = 1 AND e.vacated = 0 THEN 1 END) AS standing_count,
        COUNT(e.emp_id) AS total_employees
    FROM sub_route sr
    LEFT JOIN vehicle v ON sr.vehicle_no = v.vehicle_no
    LEFT JOIN employee e ON FIND_IN_SET(sr.sub_route_code, e.sub_route_code) > 0 AND e.is_active = 1 AND e.vacated = 0
    LEFT JOIN route r ON sr.route_code = r.route_code
    WHERE sr.is_active = 1
    GROUP BY sr.sub_route_code
    ORDER BY sr.sub_route_code ASC
";

$result_sub = $conn->query($sql_sub);

if ($result_sub) {
    while ($row = $result_sub->fetch_assoc()) {
        $seat_cap = (int)$row['seat_cap'];
        $stand_cap = (int)$row['stand_cap'];
        $seated_act = (int)$row['seated_count'];
        $standing_act = (int)$row['standing_count'];
        
        if ($stand_cap <= 0) {
            $seated_act += $standing_act;
            $standing_act = 0;
            $stand_cap = 0;
        }

        $total_cap = $seat_cap + $stand_cap;
        $total_act = $seated_act + $standing_act;
        $percentage = ($total_cap > 0) ? round(($total_act / $total_cap) * 100, 0) : 0;

        // අලුත් ගණනය කිරීම් (Available & Percentages)
        $s_ava = max(0, $seat_cap - $seated_act);
        $s_pre = ($seat_cap > 0) ? round(($seated_act / $seat_cap) * 100, 0) : 0;
        $st_ava = max(0, $stand_cap - $standing_act);
        $st_pre = ($stand_cap > 0) ? round(($standing_act / $stand_cap) * 100, 0) : 0;

        if ($percentage >= 95) { $status_key = 'high'; $color_hex = '#10b981'; $status_text = 'Excellent'; $badge_style = 'background-color: #d1fae5; color: #065f46; border: 1px solid #10b981;'; }
        elseif ($percentage >= 86) { $status_key = 'medium'; $color_hex = '#f59e0b'; $status_text = 'Good'; $badge_style = 'background-color: #fef3c7; color: #92400e; border: 1px solid #f59e0b;'; }
        else { $status_key = 'low'; $color_hex = '#ef4444'; $status_text = 'Underutilized'; $badge_style = 'background-color: #fee2e2; color: #b91c1c; border: 1px solid #ef4444;'; }

        $row['percentage'] = $percentage;
        $row['color_hex'] = $color_hex;
        $row['status_text'] = $status_text;
        $row['status_key'] = $status_key;
        $row['badge_style'] = $badge_style;
        $row['seated_count'] = $seated_act;
        $row['standing_count'] = $standing_act;
        $row['stand_cap'] = $stand_cap;
        $row['s_ava'] = $s_ava;
        $row['s_pre'] = $s_pre;
        $row['st_ava'] = $st_ava;
        $row['st_pre'] = $st_pre;
        $row['vehicle_type_display'] = ucfirst($row['vehicle_type'] ?? 'Unknown');
        
        $report_data[] = $row; 
        
        $r_code = $row['route_code'];
        if (isset($grouped_data[$r_code])) {
            $grouped_data[$r_code]['sub_routes'][] = $row;
        }
    }
}

// --- 3. FILTER OUT MAIN ROUTES WITHOUT SUB-ROUTES & CALCULATE METRICS ---
foreach ($grouped_data as $r_code => $data) {
    if (empty($data['sub_routes'])) {
        unset($grouped_data[$r_code]); 
    } else {
        foreach ($data['sub_routes'] as $sub) {
            $total_sub_routes++;
            if ($sub['percentage'] >= 95) { $efficiency_high++; }
            elseif ($sub['percentage'] >= 86) { $efficiency_med++; }
            else { $efficiency_low++; }
        }

        if (!in_array($r_code, $unique_routes)) $unique_routes[] = $r_code;
        if (!in_array($data['vehicle_type'], $unique_types) && $data['vehicle_type'] !== 'Unknown') $unique_types[] = $data['vehicle_type'];
    }
}

sort($unique_routes);
sort($unique_types);

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sub-Route Efficiency</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f8fafc; }
        .metric-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border-left: 5px solid #ccc; }
        .table-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        table.custom-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        table.custom-table th { background-color: #f1f5f9; color: #334155; font-weight: 700; text-transform: uppercase; font-size: 0.7rem; padding: 12px 16px; position: sticky; top: 0; z-index: 10; border-bottom: 2px solid #cbd5e1; }
        table.custom-table td { padding: 10px 16px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .route-header:hover td { background-color: #f1f5f9; }
        .hidden-content { display: none !important; }
    </style>
</head>
<body class="overflow-hidden h-screen">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50">
    <div class="flex items-center space-x-2">
        <a href="report_operations.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">System Reports</a>
        <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
        <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1">SUB-ROUTE CAPACITY ANALYSIS</span>
    </div>
    <div class="flex items-center gap-4">
        <button onclick="downloadExcel()" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded text-xs flex items-center gap-2">
            <i class="fas fa-file-excel"></i> Export Excel
        </button>
        <a href="report_operations.php" class="text-gray-300 hover:text-white transition text-sm">Back</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col bg-slate-50">
    <div class="flex-grow p-6 flex flex-col h-full overflow-hidden">
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4 shrink-0">
            <div class="metric-card" style="border-color: #3b82f6;"><p class="text-xs text-gray-500 uppercase font-bold">Total Sub-Routes</p><p class="text-2xl font-bold text-gray-800" id="metric-total"><?php echo $total_sub_routes; ?></p></div>
            <div class="metric-card" style="border-color: #10b981;"><p class="text-xs text-gray-500 uppercase font-bold">Excellent (≥95%)</p><p class="text-2xl font-bold text-green-600" id="metric-high"><?php echo $efficiency_high; ?></p></div>
            <div class="metric-card" style="border-color: #f59e0b;"><p class="text-xs text-gray-500 uppercase font-bold">Good (86-95%)</p><p class="text-2xl font-bold text-yellow-600" id="metric-med"><?php echo $efficiency_med; ?></p></div>
            <div class="metric-card" style="border-color: #ef4444;"><p class="text-xs text-gray-500 uppercase font-bold">Underutilized (<86%)</p><p class="text-2xl font-bold text-red-600" id="metric-low"><?php echo $efficiency_low; ?></p></div>
        </div>

        <div class="bg-white p-3 border-b flex justify-between items-center shrink-0 rounded-t-lg shadow-sm">
             <div class="flex border rounded overflow-hidden">
                <button onclick="switchTab('chart')" id="btn-chart" class="px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r text-sm"><i class="fas fa-chart-pie mr-2"></i> Visuals</button>
                <button onclick="switchTab('table')" id="btn-table" class="px-4 py-2 bg-white text-gray-600 text-sm"><i class="fas fa-list mr-2"></i> Table</button>
            </div>
            <div class="flex gap-2">
                <select id="filterRoute" onchange="applyFilters()" class="border rounded px-2 py-1.5 text-xs">
                    <option value="all">All Routes</option>
                    <?php foreach($unique_routes as $r): ?> <option value="<?php echo $r; ?>"><?php echo $r; ?></option> <?php endforeach; ?>
                </select>
                <input type="text" id="searchInput" onkeyup="applyFilters()" placeholder="Search..." class="border rounded px-3 py-1.5 text-xs w-48">
            </div>
        </div>

        <div id="view-chart" class="bg-white p-4 rounded-b-lg shadow-sm border border-t-0 flex-grow flex gap-4 overflow-hidden">
            <div class="w-2/3 h-full"><canvas id="barChart"></canvas></div>
            <div class="w-1/3 h-full border-l pl-4"><canvas id="pieChart"></canvas></div>
        </div>

        <div id="view-table" class="bg-white border rounded-b-lg flex-grow overflow-hidden hidden-content">
            <div class="table-scroll h-full overflow-auto">
                <table class="custom-table" id="reportTable">
                    <thead>
                        <tr>
                            <th width="3%"></th>
                            <th>Route / Sub-Route Name</th>
                            <th>Type</th>
                            <th class="text-center bg-blue-50/50">S.Cap</th>
                            <th class="text-center bg-blue-50/50">S.Act</th>
                            <th class="text-center bg-blue-50/50">S.Ava</th>
                            <th class="text-center bg-blue-50/50">S.Pre</th>
                            <th class="text-center bg-red-50/50">St.Cap</th>
                            <th class="text-center bg-red-50/50">St.Act</th>
                            <th class="text-center bg-red-50/50">St.Ava</th>
                            <th class="text-center bg-red-50/50">St.Pre</th>
                            <th class="text-center bg-slate-100">Total</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grouped_data as $route_code => $data): ?>
                        
                        <tr class="route-header cursor-pointer bg-slate-100 font-bold main-route-row" data-route="<?php echo $route_code; ?>" onclick="toggleRoute('<?php echo $route_code; ?>')">
                            <td class="text-center"><i id="icon-<?php echo $route_code; ?>" class="fas fa-chevron-right text-blue-600"></i></td>
                            <td>
                                <span class="text-blue-900"><?php echo htmlspecialchars($data['route_name']); ?></span>
                                <span class="text-xs font-normal text-gray-500 ml-1">(<?php echo $route_code; ?>)</span>
                            </td>
                            <td>
                                <span class="text-xs text-blue-700 uppercase"><?php echo $data['vehicle_type']; ?></span>
                                <?php if(!empty($data['vehicle_no'])) { echo "<span class='text-[10px] text-gray-400 ml-1 font-normal'>| {$data['vehicle_no']}</span>"; } ?>
                            </td>
                            
                            <td class="text-center text-blue-700"><?php echo $data['main_seat_cap']; ?></td>
                            <td class="text-center text-blue-900"><?php echo $data['main_seated_act']; ?></td>
                            <td class="text-center text-blue-500 font-normal"><?php echo $data['main_s_ava']; ?></td>
                            <td class="text-center text-[10px] text-blue-600"><?php echo $data['main_s_pre']; ?>%</td>
                            
                            <td class="text-center text-red-600"><?php echo $data['main_stand_cap']; ?></td>
                            <td class="text-center text-red-800"><?php echo $data['main_standing_act']; ?></td>
                            <td class="text-center text-red-400 font-normal"><?php echo $data['main_st_ava']; ?></td>
                            <td class="text-center text-[10px] text-red-500"><?php echo $data['main_st_pre']; ?>%</td>
                            
                            <td class="text-center bg-gray-200"><?php echo $data['main_total_act']; ?></td>
                            
                            <td class="text-center">
                                <span style="font-size: 0.65rem; padding: 2px 8px; border-radius: 99px; font-weight: 700; <?php echo $data['main_badge_style']; ?>">
                                    <?php echo $data['main_status_text']; ?> (<?php echo $data['main_percentage']; ?>%)
                                </span>
                            </td>
                        </tr>

                        <?php foreach ($data['sub_routes'] as $sub): ?>
                        <tr class="searchable-row sub-row-<?php echo $route_code; ?> hidden bg-white hover:bg-blue-50/30" 
                            data-route="<?php echo $sub['route_code']; ?>" 
                            data-status="<?php echo $sub['status_key']; ?>">
                            <td></td>
                            <td class="italic text-gray-600 pl-4">
                                <i class="fas fa-level-up-alt fa-rotate-90 mr-1 text-gray-300"></i> 
                                <?php echo htmlspecialchars(ucwords(strtolower($sub['sub_route_name']))); ?>
                            </td>
                            <td>
                                <span class="text-xs text-gray-500 uppercase"><?php echo $sub['vehicle_type_display']; ?></span>
                                <?php if(!empty($sub['vehicle_no'])) { echo "<span class='text-[10px] text-gray-400 ml-1'>| {$sub['vehicle_no']}</span>"; } ?>
                            </td>
                            
                            <td class="text-center text-gray-500"><?php echo $sub['seat_cap']; ?></td>
                            <td class="text-center font-bold text-gray-800"><?php echo $sub['seated_count']; ?></td>
                            <td class="text-center text-blue-400 text-xs"><?php echo $sub['s_ava']; ?></td>
                            <td class="text-center text-gray-400 text-[10px]"><?php echo $sub['s_pre']; ?>%</td>
                            
                            <td class="text-center text-gray-500"><?php echo $sub['stand_cap']; ?></td>
                            <td class="text-center font-bold text-gray-800"><?php echo $sub['standing_count']; ?></td>
                            <td class="text-center text-red-400 text-xs"><?php echo $sub['st_ava']; ?></td>
                            <td class="text-center text-gray-400 text-[10px]"><?php echo $sub['st_pre']; ?>%</td>
                            
                            <td class="text-center font-black bg-slate-50"><?php echo ($sub['seated_count'] + $sub['standing_count']); ?></td>
                            
                            <td class="text-center">
                                <span style="font-size: 0.65rem; padding: 2px 8px; border-radius: 99px; font-weight: 700; <?php echo $sub['badge_style']; ?>">
                                    <?php echo $sub['status_text']; ?> (<?php echo $sub['percentage']; ?>%)
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    Chart.register(ChartDataLabels);
    const fullDataArray = <?php echo json_encode($report_data); ?>; 
    const fullGroupedData = <?php echo json_encode($grouped_data); ?>; 
    let barChart, pieChart;

    function switchTab(view) {
        document.getElementById('view-chart').classList.toggle('hidden-content', view !== 'chart');
        document.getElementById('view-table').classList.toggle('hidden-content', view !== 'table');
        document.getElementById('btn-chart').className = view === 'chart' ? "px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r text-sm" : "px-4 py-2 bg-white text-gray-600 text-sm";
        document.getElementById('btn-table').className = view === 'table' ? "px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r text-sm" : "px-4 py-2 bg-white text-gray-600 text-sm";
    }

    function toggleRoute(code) {
        const rows = document.querySelectorAll('.sub-row-' + code);
        const icon = document.getElementById('icon-' + code);
        rows.forEach(row => row.classList.toggle('hidden'));
        icon.classList.toggle('fa-chevron-right');
        icon.classList.toggle('fa-chevron-down');
    }

    function applyFilters() {
        const search = document.getElementById('searchInput').value.toLowerCase();
        const route = document.getElementById('filterRoute').value;
        const filtered = fullDataArray.filter(d => {
            return (d.sub_route_name.toLowerCase().includes(search) || d.sub_route_code.toLowerCase().includes(search)) && (route === 'all' || d.route_code === route);
        });
        updateCharts(filtered);

        document.querySelectorAll('.main-route-row').forEach(row => {
            const rCode = row.dataset.route;
            row.style.display = (route === 'all' || rCode === route) ? '' : 'none';
            document.querySelectorAll('.sub-row-' + rCode).forEach(sr => sr.classList.add('hidden'));
            const icon = document.getElementById('icon-' + rCode);
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-right');
        });
    }

    function updateCharts(data) {
        if(barChart) barChart.destroy();
        if(pieChart) pieChart.destroy();
        if(data.length === 0) return;

        barChart = new Chart(document.getElementById('barChart'), {
            type: 'bar',
            data: {
                labels: data.map(d => d.sub_route_code),
                datasets: [{ label: 'Total Emps', data: data.map(d => (d.seated_count + d.standing_count)), backgroundColor: data.map(d => d.color_hex) }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { datalabels: { anchor: 'end', align: 'top', formatter: (v, ctx) => data[ctx.dataIndex].percentage + '%' } } }
        });

        pieChart = new Chart(document.getElementById('pieChart'), {
            type: 'doughnut',
            data: {
                labels: ['Excellent', 'Good', 'Low'],
                datasets: [{ data: [data.filter(x=>x.status_key==='high').length, data.filter(x=>x.status_key==='medium').length, data.filter(x=>x.status_key==='low').length], backgroundColor: ['#10b981', '#f59e0b', '#ef4444'] }]
            }
        });
    }

    // --- EXCEL EXPORT ---
    async function downloadExcel() {
        const workbook = new ExcelJS.Workbook();
        const sheet = workbook.addWorksheet('Capacity Analysis', { views: [{ showGridLines: false }] });

        sheet.columns = [
            { key: 'name', width: 45 },
            { key: 'v_type', width: 25 },
            { key: 's_cap', width: 10 },
            { key: 's_act', width: 10 },
            { key: 's_ava', width: 10 },
            { key: 's_pre', width: 10 },
            { key: 'st_cap', width: 10 },
            { key: 'st_act', width: 10 },
            { key: 'st_ava', width: 10 },
            { key: 'st_pre', width: 10 },
            { key: 'total', width: 12 },
            { key: 'util', width: 15 }
        ];

        const tableBorder = {
            top: { style: 'thin', color: { argb: 'FF000000' } },
            bottom: { style: 'thin', color: { argb: 'FF000000' } },
            left: { style: 'thin', color: { argb: 'FF000000' } },
            right: { style: 'thin', color: { argb: 'FF000000' } }
        };

        sheet.mergeCells('A1:L1');
        const titleRow = sheet.getRow(1);
        titleRow.height = 30;
        for (let i = 1; i <= 12; i++) {
            const cell = titleRow.getCell(i);
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0F4C81' } }; 
            cell.border = tableBorder; 
        }
        const titleCell = titleRow.getCell(1);
        titleCell.value = 'ROUTE & SUB-ROUTE CAPACITY REPORT';
        titleCell.font = { size: 14, bold: true, color: { argb: 'FFFFFFFF' } };
        titleCell.alignment = { vertical: 'middle', horizontal: 'center' };

        sheet.mergeCells('A2:L2');
        const dateRow = sheet.getRow(2);
        dateRow.height = 20;
        for (let i = 1; i <= 12; i++) {
            const cell = dateRow.getCell(i);
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF1F5F9' } };
            cell.border = tableBorder; 
        }
        const dateCell = dateRow.getCell(1);
        dateCell.value = 'Generated on: ' + new Date().toLocaleString('en-GB');
        dateCell.font = { size: 10, italic: true, color: { argb: 'FF475569' }, bold: true };
        dateCell.alignment = { vertical: 'middle', horizontal: 'right' };

        // අලුත් Columns ටික Excel එකටත් එකතු කළා
        const headerRow = sheet.addRow([
            'Route Name (Code)', 'Vehicle Type | No', 
            'S.Cap', 'S.Act', 'S.Ava', 'S.Pre%', 
            'St.Cap', 'St.Act', 'St.Ava', 'St.Pre%', 
            'Total Emps', 'Status'
        ]);
        headerRow.height = 25;
        headerRow.eachCell((cell, colNum) => {
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF3B82F6' } };
            cell.font = { bold: true, color: { argb: 'FFFFFFFF' } };
            cell.alignment = { vertical: 'middle', horizontal: colNum > 2 ? 'center' : 'left' };
            cell.border = tableBorder; 
        });

        Object.keys(fullGroupedData).forEach(code => {
            const d = fullGroupedData[code];
            
            const mainRow = sheet.addRow([
                d.route_name + ' (' + d.route_code + ')', 
                d.vehicle_type + ' | ' + (d.vehicle_no || 'N/A'), 
                d.main_seat_cap, d.main_seated_act, d.main_s_ava, d.main_s_pre + '%', 
                d.main_stand_cap, d.main_standing_act, d.main_st_ava, d.main_st_pre + '%', 
                d.main_total_act, 
                d.main_status_text + ' (' + d.main_percentage + '%)'
            ]);
            mainRow.font = { bold: true };
            mainRow.eachCell((cell, colNum) => {
                cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE2E8F0' } };
                cell.alignment = { vertical: 'middle', horizontal: colNum > 2 ? 'center' : 'left' };
                cell.border = tableBorder; 
            });

            d.sub_routes.forEach(s => {
                const subRow = sheet.addRow([
                    '    ↳ ' + s.sub_route_name + ' (' + s.sub_route_code + ')', 
                    s.vehicle_type_display + ' | ' + (s.vehicle_no || 'N/A'), 
                    s.seat_cap, s.seated_count, s.s_ava, s.s_pre + '%', 
                    s.stand_cap, s.standing_count, s.st_ava, s.st_pre + '%', 
                    (s.seated_count + s.standing_count), 
                    s.status_text + ' (' + s.percentage + '%)'
                ]);
                subRow.eachCell((cell, colNum) => {
                    cell.alignment = { vertical: 'middle', horizontal: colNum > 2 ? 'center' : 'left' };
                    cell.border = tableBorder; 
                });
            });
            
            sheet.addRow([]); 
        });

        sheet.views = [{ state: 'frozen', ySplit: 3, showGridLines: false }];
        const buffer = await workbook.xlsx.writeBuffer();
        saveAs(new Blob([buffer]), `Capacity_Report_${new Date().toISOString().split('T')[0]}.xlsx`);
    }

    updateCharts(fullDataArray);
</script>
</body>
</html>
<?php $conn->close(); ?>