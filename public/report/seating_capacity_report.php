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

// --- DATA FETCHING ---
$sql_report = "
    SELECT 
        r.route_code,
        r.route AS route_name,
        v.capacity AS seat_capacity,
        COALESCE(v.standing_capacity, 0) AS standing_capacity,
        v.vehicle_no,
        v.type as vehicle_type, 
        COUNT(CASE WHEN e.to_home_distance > 3 AND e.is_active = 1 AND e.vacated = 0 THEN 1 END) AS emp_seated,
        COUNT(CASE WHEN e.to_home_distance <= 3 AND e.is_active = 1 AND e.vacated = 0 THEN 1 END) AS emp_standing
    FROM route r
    LEFT JOIN vehicle v ON r.vehicle_no = v.vehicle_no
    LEFT JOIN employee e ON SUBSTRING(e.route, 1, 10) = r.route_code AND e.is_active = 1 AND e.vacated = 0
    WHERE r.is_active = 1
    GROUP BY r.route_code
    ORDER BY CAST(SUBSTRING(r.route_code, 7, 3) AS UNSIGNED) ASC
";

$result = $conn->query($sql_report);
$report_data = [];
$unique_types = [];

$total_routes = 0;
$total_emp = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $seat_cap = (int)($row['seat_capacity'] ?? 0);
        $stand_cap = (int)($row['standing_capacity'] ?? 0);
        $emp_seated = (int)$row['emp_seated'];
        $emp_standing = (int)$row['emp_standing'];
        $v_type = !empty($row['vehicle_type']) ? ucfirst($row['vehicle_type']) : 'Not Assigned';

        $is_bus = (strpos(strtolower($v_type), 'bus') !== false);

        // --- Logic: If no standing capacity, treat all as seated ---
        if ($stand_cap <= 0) {
            $emp_seated += $emp_standing;
            $emp_standing = 0;
            $stand_cap = 0;
        }

        // Utilization Calculations
        $seat_util = ($seat_cap > 0) ? round(($emp_seated / $seat_cap) * 100, 1) : ($emp_seated > 0 ? 100 : 0);
        $stand_util = ($stand_cap > 0) ? round(($emp_standing / $stand_cap) * 100, 1) : ($emp_standing > 0 ? 100 : 0);

        // Efficiency Colors
        $get_eff_color = function($util, $actual, $cap) {
            if ($actual > $cap && $cap > 0) return '#ce09ad'; // Overload
            if ($util >= 97) return '#10b981'; // Green
            if ($util >= 92) return '#f59e0b'; // Orange
            return '#ef4444'; // Red
        };

        $row['seat_eff_color'] = $get_eff_color($seat_util, $emp_seated, $seat_cap);
        $row['stand_eff_color'] = ($stand_cap > 0) ? $get_eff_color($stand_util, $emp_standing, $stand_cap) : '#94a3b8';

        // Vehicle Colors
        if ($is_bus) {
            $row['bar_color'] = '#1d4ed8'; // Blue
            $row['bar_bg'] = '#bfdbfe';
        } else {
            $row['bar_color'] = '#0acad1'; // Amber
            $row['bar_bg'] = '#bfe6f1';
        }

        if($v_type !== 'Not Assigned' && !in_array($v_type, $unique_types)) {
            $unique_types[] = $v_type;
        }

        $row['seat_capacity'] = $seat_cap;
        $row['standing_capacity'] = $stand_cap;
        $row['emp_seated'] = $emp_seated;
        $row['emp_standing'] = $emp_standing;
        $row['seat_util'] = $seat_util;
        $row['stand_util'] = $stand_util;
        $row['vehicle_type_display'] = $v_type;
        
        $report_data[] = $row;
        $total_routes++;
        $total_emp += ($emp_seated + $emp_standing);
    }
}
sort($unique_types);

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Capacity Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f8fafc; overflow: hidden; }
        .metric-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border-left: 5px solid #ccc; }
        .hidden-content { display: none !important; }
        .table-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    </style>
</head>
<body class="h-screen flex flex-col">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center space-x-2">
        <div class="flex items-center space-x-2 w-fit">
            <a href="report_operations.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">System Reports</a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">ROUTE CAPACITY ANALYSIS</span>
        </div>
    </div>
    <div class="flex items-center gap-4">
        <button onclick="downloadExcel()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-1.5 rounded text-xs flex items-center gap-2 transition font-bold uppercase">
            <i class="fas fa-file-excel"></i> Export Excel
        </button>
        <a href="report_operations.php" class="text-gray-300 hover:text-white transition text-sm">Back</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col bg-slate-50">
    <div class="p-6 flex flex-col h-full overflow-hidden">
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4 shrink-0">
            <div class="metric-card border-blue-500"><p class="text-xs font-bold text-gray-400 uppercase">Routes</p><p class="text-2xl font-bold"><?php echo $total_routes; ?></p></div>
            <div class="metric-card border-amber-500"><p class="text-xs font-bold text-gray-400 uppercase">Vehicles</p><p class="text-2xl font-bold"><?php echo count($report_data); ?></p></div>
            <div class="metric-card border-emerald-500"><p class="text-xs font-bold text-gray-400 uppercase">Employees</p><p class="text-2xl font-bold"><?php echo $total_emp; ?></p></div>
        </div>

        <div class="bg-white p-3 border border-gray-200 flex justify-between items-center rounded-t-lg shrink-0">
            <div class="flex border rounded overflow-hidden">
                <button onclick="switchTab('chart')" id="btn-chart" class="px-5 py-2 bg-blue-50 text-blue-700 font-bold text-sm">Chart View</button>
                <button onclick="switchTab('table')" id="btn-table" class="px-5 py-2 bg-white text-gray-600 text-sm border-l">Table View</button>
            </div>
            <div class="flex gap-2">
                <select id="vehicleTypeFilter" onchange="applyFilters()" class="border rounded px-3 py-1.5 text-xs bg-gray-50 outline-none">
                    <option value="all">All Vehicles</option>
                    <?php foreach($unique_types as $type): ?> <option value="<?php echo $type; ?>"><?php echo $type; ?></option> <?php endforeach; ?>
                </select>
                <input type="text" id="tableSearch" onkeyup="applyFilters()" placeholder="Search Route..." class="border rounded px-3 py-1.5 text-xs w-48 outline-none">
            </div>
        </div>

        <div id="view-chart" class="bg-white p-6 rounded-b-lg border border-t-0 flex-grow flex flex-col overflow-hidden shadow-sm">
            <div class="flex justify-center gap-6 mb-4 text-[9px] font-black uppercase text-gray-500 tracking-widest items-center">
                <div class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-blue-700"></span> Seats</div>
                <div class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-red-700"></span> Standing</div>
                <div class="flex items-center gap-4 border-l pl-4">
                    <span class="text-emerald-500">Efficiency: ■ >97%</span>
                    <span class="text-amber-500">■ 92-97%</span>
                    <span class="text-red-500">■ <92%</span>
                </div>
            </div>
            <div class="relative flex-grow">
                <canvas id="capacityChart"></canvas>
            </div>
        </div>

        <div id="view-table" class="bg-white border rounded-b-lg flex-grow overflow-hidden hidden-content shadow-sm">
            <div class="table-scroll h-full overflow-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-100 sticky top-0 uppercase text-[10px] font-black text-slate-600 border-b">
                        <tr>
                            <th class="px-6 py-4">Route Info</th>
                            <th class="px-3 py-4 text-center bg-blue-50/50">Seat Cap</th>
                            <th class="px-3 py-4 text-center bg-blue-50/50">Seated</th>
                            <th class="px-3 py-4 text-center bg-blue-50/50">Seat %</th>
                            <th class="px-3 py-4 text-center border-l bg-red-50/30">Stand Cap</th>
                            <th class="px-3 py-4 text-center bg-red-50/30">Standing</th>
                            <th class="px-3 py-4 text-center bg-red-50/30">Stand %</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($report_data as $row): ?>
                        <tr class="hover:bg-slate-50 transition searchable-row" data-type="<?php echo $row['vehicle_type_display']; ?>" data-code="<?php echo $row['route_code']; ?>">
                            <td class="px-6 py-3">
                                <div class="font-bold text-slate-800"><?php echo $row['route_code']; ?></div>
                                <div class="text-[10px] text-slate-400 font-bold uppercase"><?php echo $row['vehicle_no']; ?> | <?php echo $row['vehicle_type_display']; ?></div>
                            </td>
                            <td class="px-3 py-3 text-center"><?php echo $row['seat_capacity']; ?></td>
                            <td class="px-3 py-3 text-center font-bold text-blue-800"><?php echo $row['emp_seated']; ?></td>
                            <td class="px-3 py-3 text-center">
                                <span class="font-black" style="color: <?php echo $row['seat_eff_color']; ?>"><?php echo $row['seat_util']; ?>%</span>
                            </td>
                            <td class="px-3 py-3 text-center border-l text-slate-400"><?php echo ($row['standing_capacity'] > 0) ? $row['standing_capacity'] : '-'; ?></td>
                            <td class="px-3 py-3 text-center font-bold text-red-800"><?php echo ($row['standing_capacity'] > 0) ? $row['emp_standing'] : '-'; ?></td>
                            <td class="px-3 py-3 text-center">
                                <?php if($row['standing_capacity'] > 0): ?>
                                    <span class="font-black" style="color: <?php echo $row['stand_eff_color']; ?>"><?php echo $row['stand_util']; ?>%</span>
                                <?php else: ?> <span class="text-slate-300">-</span> <?php endif; ?>
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
    Chart.register(ChartDataLabels);
    const fullData = <?php echo json_encode($report_data); ?>;
    let chartInstance = null;

    function renderChart(data) {
        const ctx = document.getElementById('capacityChart').getContext('2d');
        if (chartInstance) chartInstance.destroy();

        chartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(i => i.route_code),
                datasets: [
                    // --- SEATING BLOCK ---
                    {
                        label: 'Seated',
                        data: data.map(i => i.emp_seated),
                        backgroundColor: data.map(i => i.bar_color),
                        stack: 'S1',
                        datalabels: {
                            color: (ctx) => data[ctx.dataIndex].seat_eff_color,
                            anchor: 'end',
                            font: { weight: '900', size: 10 },
                            formatter: (v, ctx) => data[ctx.dataIndex].seat_util + '%',
                            textStrokeColor: 'white', textStrokeWidth: 2
                        }
                    },
                    {
                        label: 'Free Seats',
                        data: data.map(i => Math.max(0, i.seat_capacity - i.emp_seated)),
                        backgroundColor: data.map(i => i.bar_bg),
                        stack: 'S1', datalabels: { display: false }
                    },
                    // --- STANDING BLOCK (Stacked on top of seating) ---
                    {
                        label: 'Standing Actual',
                        data: data.map(i => i.emp_standing),
                        backgroundColor: '#b91c1c', // Dark Red
                        stack: 'S1',
                        datalabels: {
                            color: (ctx) => data[ctx.dataIndex].stand_eff_color,
                            font: { weight: '900', size: 10 },
                            display: (c) => c.dataset.data[c.dataIndex] > 0,
                            formatter: (v, ctx) => data[ctx.dataIndex].stand_util + '%',
                            textStrokeColor: 'white', textStrokeWidth: 2
                        }
                    },
                    {
                        label: 'Free Standing',
                        data: data.map(i => Math.max(0, i.standing_capacity - i.emp_standing)),
                        backgroundColor: '#fca5a5', // Light Pinkish Red for Free standing space
                        stack: 'S1', datalabels: { display: false }
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { 
                    x: { stacked: true, grid: { display: false } }, 
                    y: { stacked: true, beginAtZero: true } 
                },
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: (ctx) => ctx.raw > 0 ? `${ctx.dataset.label}: ${ctx.raw}` : null
                        }
                    }
                }
            }
        });
    }

    function switchTab(v) {
        document.getElementById('view-chart').classList.toggle('hidden-content', v !== 'chart');
        document.getElementById('view-table').classList.toggle('hidden-content', v !== 'table');
        document.getElementById('btn-chart').className = v === 'chart' ? "px-5 py-2 bg-blue-50 text-blue-700 font-bold text-sm" : "px-5 py-2 bg-white text-gray-600 text-sm";
        document.getElementById('btn-table').className = v === 'table' ? "px-5 py-2 bg-blue-50 text-blue-700 font-bold text-sm border-l" : "px-5 py-2 bg-white text-gray-600 text-sm border-l";
    }

    function applyFilters() {
        const s = document.getElementById('tableSearch').value.toLowerCase();
        const t = document.getElementById('vehicleTypeFilter').value;
        const filtered = fullData.filter(d => 
            d.route_code.toLowerCase().includes(s) && (t === 'all' || d.vehicle_type_display === t)
        );
        renderChart(filtered);
        
        document.querySelectorAll('.searchable-row').forEach((row) => {
            const match = row.getAttribute('data-code').toLowerCase().includes(s) && (t === 'all' || row.getAttribute('data-type') === t);
            row.style.display = match ? '' : 'none';
        });
    }

    async function downloadExcel() {
    const wb = new ExcelJS.Workbook();
    const ws = wb.addWorksheet('Transport Analysis', {
        views: [{ showGridLines: false }] // මේකෙන් මුළු Excel එකම සුදු පාටට (කොටු නැතිව) පේනවා
    });

    // 1. Column Definitions
    ws.columns = [
        { key: 'code', width: 15 },
        { key: 'name', width: 40 },
        { key: 'v_no', width: 15 },
        { key: 's_cap', width: 12 },
        { key: 's_act', width: 12 },
        { key: 's_util', width: 12 },
        { key: 'st_cap', width: 12 },
        { key: 'st_act', width: 12 },
        { key: 'st_util', width: 12 }
    ];

    // --- 2. YELLOW TITLE HEADER (Row 1) ---
    ws.mergeCells('A1:I1'); // A සිට I දක්වා සම්පූර්ණයෙන් එකතු කරයි
    const titleCell = ws.getCell('A1');
    const today = new Date().toLocaleDateString('en-GB'); 
    
    titleCell.value = 'Route Capacity Report ' + today; 
    ws.getRow(1).height = 40; // Title එකට ඉඩ
    
    titleCell.font = { size: 18, bold: true, name: 'Segoe UI' };
    titleCell.alignment = { vertical: 'middle', horizontal: 'center' };
    titleCell.fill = {
        type: 'pattern',
        pattern: 'solid',
        fgColor: { argb: 'FFFF00' } // Yellow
    };
    // Title එකට වටේට තද බෝඩර් එකක්
    titleCell.border = {
        top: { style: 'medium' },
        left: { style: 'medium' },
        bottom: { style: 'medium' },
        right: { style: 'medium' }
    };

    // --- 3. BLUE COLUMN HEADERS (Row 2) ---
    const headerValues = ['Route Code', 'Route Name', 'Vehicle No', 'Seat Cap', 'Seated', 'Seat %', 'Stand Cap', 'Standing', 'Stand %'];
    ws.getRow(2).values = headerValues;

    const headerRow = ws.getRow(2);
    headerRow.height = 30;
    headerRow.font = { bold: true, color: { argb: 'FFFFFF' }, name: 'Segoe UI' };
    headerRow.alignment = { vertical: 'middle', horizontal: 'center' };
    
    headerRow.eachCell((cell) => {
        cell.fill = {
            type: 'pattern',
            pattern: 'solid',
            fgColor: { argb: '4F81BD' } // Blue
        };
        cell.border = {
            top: { style: 'thin' },
            left: { style: 'thin' },
            bottom: { style: 'thin' },
            right: { style: 'thin' }
        };
    });

    // --- 4. DATA ROWS (Row 3 onwards) ---
    fullData.forEach((d) => {
        const row = ws.addRow({
            code: d.route_code,
            name: d.route_name,
            v_no: d.vehicle_no,
            s_cap: d.seat_capacity,
            s_act: d.emp_seated,
            s_util: d.seat_util + '%',
            st_cap: d.standing_capacity > 0 ? d.standing_capacity : '-',
            st_act: d.standing_capacity > 0 ? d.emp_standing : '-',
            st_util: d.standing_capacity > 0 ? d.stand_util + '%' : '-'
        });

        row.eachCell((cell, colNumber) => {
            cell.border = {
                top: { style: 'thin' },
                left: { style: 'thin' },
                bottom: { style: 'thin' },
                right: { style: 'thin' }
            };

            if (colNumber === 2) {
                cell.alignment = { horizontal: 'left', vertical: 'middle', indent: 1 };
            } else {
                cell.alignment = { horizontal: 'center', vertical: 'middle' };
            }
        });

        // Seat % color logic
        const seatUtilCell = row.getCell(6); 
        const utilValue = parseFloat(d.seat_util);
        if (utilValue >= 100) {
            seatUtilCell.font = { color: { argb: '059669' }, bold: true }; // Green for full
        } else if (utilValue < 90) {
            seatUtilCell.font = { color: { argb: 'DC2626' }, bold: true }; // Red for low
        }
    });

    // 5. Download
    const buf = await wb.xlsx.writeBuffer();
    saveAs(new Blob([buf]), `Route_Capacity_Report_${new Date().toISOString().split('T')[0]}.xlsx`);
}

    renderChart(fullData);
</script>
</body>
</html>
<?php $conn->close(); ?>