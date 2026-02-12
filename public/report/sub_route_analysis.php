<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include('../../includes/db.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// --- FUEL CALCULATION HELPER FUNCTIONS ---
function get_latest_fuel_price_by_rate_id($conn, $rate_id) {
    $sql = "SELECT rate FROM fuel_rate WHERE rate_id = ? ORDER BY date DESC, id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0; 
    $stmt->bind_param("i", $rate_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (float)($row['rate'] ?? 0);
}

// Fetch consumption mapping once
$consumption_rates = [];
$res_c = $conn->query("SELECT c_id, distance FROM consumption");
if ($res_c) while ($r = $res_c->fetch_assoc()) $consumption_rates[$r['c_id']] = (float)$r['distance'];

function calculate_fuel_per_km($conn, $vehicle_no, $consumption_rates) {
    if (empty($vehicle_no)) return 0;
    $stmt = $conn->prepare("SELECT fuel_efficiency, rate_id FROM vehicle WHERE vehicle_no = ?");
    $stmt->bind_param("s", $vehicle_no);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    if (!$v) return 0;
    $price = get_latest_fuel_price_by_rate_id($conn, $v['rate_id']);
    $km_per_l = $consumption_rates[$v['fuel_efficiency']] ?? 1.0;
    return ($price > 0) ? ($price / $km_per_l) : 0;
}
// ------------------------------------------

// --- DATA PROCESSING ---
$sql = "
    SELECT 
        sr.sub_route_code,
        sr.route_code,
        sr.sub_route AS sub_route_name,
        sr.vehicle_no,
        sr.distance,
        sr.fixed_rate,
        sr.with_fuel,
        v.type AS vehicle_type,
        COUNT(e.emp_id) as emp_count
    FROM sub_route sr
    LEFT JOIN employee e ON sr.sub_route_code = e.sub_route_code
    LEFT JOIN vehicle v ON sr.vehicle_no = v.vehicle_no
    WHERE sr.is_active = 1
    GROUP BY sr.sub_route_code
    ORDER BY sr.sub_route_code ASC
";

$result = $conn->query($sql);
$data = [];
$total_sub_routes = 0;
$total_daily_cost = 0;
$total_employees = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // 1. Calculate Dynamic Daily Rate
        $fixed = (float)$row['fixed_rate'];
        $fuel_per_km = 0;
        if ((int)$row['with_fuel'] === 1) {
            $fuel_per_km = calculate_fuel_per_km($conn, $row['vehicle_no'], $consumption_rates);
        }
        
        // Final Daily Rate for the sub route
        $current_daily_rate = ($fixed + $fuel_per_km) * (float)$row['distance'];
        $row['per_day_rate'] = $current_daily_rate; // Map to original variable for JS/UI compatibility

        $count = (int)$row['emp_count'];
        $cost_per_emp = ($count > 0) ? ($current_daily_rate / $count) : $current_daily_rate; 

        // Efficiency Logic
        $efficiency_status = 'Normal';
        $efficiency_color = 'text-gray-600';
        if ($count == 0) {
            $efficiency_status = 'Empty (Loss)';
            $efficiency_color = 'text-red-600 font-bold';
        } elseif ($cost_per_emp > 1000) { 
            $efficiency_status = 'High Cost';
            $efficiency_color = 'text-orange-600 font-bold';
        } else {
            $efficiency_status = 'Efficient';
            $efficiency_color = 'text-green-600 font-bold';
        }

        $v_type = !empty($row['vehicle_type']) ? strtolower(trim($row['vehicle_type'])) : 'other';
        $row['vehicle_type_raw'] = $v_type; 
        $row['vehicle_type_display'] = ucfirst($v_type);
        $row['cost_per_emp'] = $cost_per_emp;
        $row['efficiency_status'] = $efficiency_status;
        $row['efficiency_color'] = $efficiency_color;

        $data[] = $row;
        $total_sub_routes++;
        $total_daily_cost += $current_daily_rate;
        $total_employees += $count;
    }
}

$avg_cost_per_emp = ($total_employees > 0) ? ($total_daily_cost / $total_employees) : 0;

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sub Route Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .table-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        table th { position: sticky; top: 0; z-index: 20; background-color: #f3f4f6; }
        .hidden-content { display: none !important; }
    </style>
</head>
<body class="overflow-hidden h-screen">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="report_operations.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">System Reports</a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">Sub Route Analysis</span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <button onclick="downloadExcel()" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded shadow-sm text-xs border border-green-500 font-bold transition">
            <i class="fas fa-file-excel"></i> Export
        </button>
        <span class="text-gray-600 text-lg font-thin">|</span>
        <a href="report_operations.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">Back</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col bg-slate-50">
    <div class="flex-grow p-6 flex flex-col h-full overflow-hidden">

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4 shrink-0">
            <div class="bg-white p-4 rounded shadow-sm border-l-4 border-indigo-600">
                <p class="text-xs text-gray-400 uppercase font-bold">Total Daily Cost</p>
                <p class="text-2xl font-bold text-gray-800">Rs. <?php echo number_format($total_daily_cost, 0); ?></p>
            </div>
            <div class="bg-white p-4 rounded shadow-sm border-l-4 border-blue-500">
                <p class="text-xs text-gray-400 uppercase font-bold">Total Sub Routes</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo $total_sub_routes; ?></p>
            </div>
            <div class="bg-white p-4 rounded shadow-sm border-l-4 border-green-500">
                <p class="text-xs text-gray-400 uppercase font-bold">Total Employees</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo $total_employees; ?></p>
            </div>
            <div class="bg-white p-4 rounded shadow-sm border-l-4 border-orange-500">
                <p class="text-xs text-gray-400 uppercase font-bold">Avg Cost / Employee</p>
                <p class="text-2xl font-bold text-gray-800">Rs. <?php echo number_format($avg_cost_per_emp, 2); ?></p>
            </div>
        </div>

        <div class="bg-white p-3 border-b border-gray-200 flex flex-wrap gap-4 items-center justify-between shrink-0 rounded-t shadow-sm">
             <div class="flex border border-gray-300 rounded overflow-hidden">
                <button onclick="switchTab('chart')" id="btn-chart" class="px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 text-sm transition"><i class="fas fa-chart-bar mr-2"></i> Efficiency</button>
                <button onclick="switchTab('table')" id="btn-table" class="px-4 py-2 bg-white text-gray-600 text-sm transition"><i class="fas fa-table mr-2"></i> Details</button>
            </div>
            <div class="flex gap-2 items-center">
                <select id="efficiencyFilter" onchange="applyFilters()" class="text-xs border border-gray-300 rounded px-2 py-1.5 bg-white min-w-[150px] font-semibold text-gray-700 focus:ring-2 focus:ring-indigo-500">
                    <option value="all">All Efficiency Levels</option>
                    <option value="Efficient">🟢 Efficient</option>
                    <option value="High Cost">🟠 High Cost</option>
                    <option value="Empty (Loss)">🔴 Empty</option>
                </select>
                <input type="text" id="tableSearch" onkeyup="applyFilters()" placeholder="Search Sub Route..." class="text-xs border border-gray-300 rounded px-2 py-1.5 w-48 focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>

        <div id="view-chart" class="bg-white p-6 rounded-b shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 flex flex-col">
            <div class="flex justify-center gap-4 mb-2 flex-wrap text-xs">
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-orange-500"></span> <span class="font-bold text-gray-600">Van</span></div>
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-purple-500"></span> <span class="font-bold text-gray-600">Three Wheel</span></div>
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-gray-400"></span> <span class="font-bold text-gray-600">Other</span></div>
                <div class="flex items-center gap-1.5 border-l pl-3 ml-2 border-gray-300"><span class="w-6 h-0.5 bg-orange-600 border-t-2 border-orange-600"></span> <span class="font-bold text-gray-600">Cost/Emp</span></div>
                <div class="flex items-center gap-1.5 ml-2"><span class="w-6 h-0.5 bg-green-500 border-t-2 border-dashed border-green-500"></span> <span class="font-bold text-gray-600">Distance (KM)</span></div>
            </div>
            <div class="relative flex-grow w-full">
                <canvas id="efficiencyChart"></canvas>
            </div>
        </div>

        <div id="view-table" class="bg-white border border-gray-200 rounded-b shadow-sm flex-grow min-h-0 flex flex-col hidden-content">
            <div class="table-scroll flex-grow overflow-auto h-full">
                <table class="w-full text-sm text-left text-gray-600" id="subRouteTable">
                    <thead class="text-xs uppercase bg-gray-100 text-gray-700 border-b-2 border-gray-300">
                        <tr>
                            <th class="px-4 py-3">Code</th>
                            <th class="px-4 py-3">Sub Route Name</th>
                            <th class="px-4 py-3">Main Route</th>
                            <th class="px-4 py-3 text-center">Vehicle</th>
                            <th class="px-4 py-3 text-center">Distance</th>
                            <th class="px-4 py-3 text-right">Daily Rate</th>
                            <th class="px-4 py-3 text-center">Employees</th>
                            <th class="px-4 py-3 text-right font-bold text-indigo-700 bg-indigo-50">Cost / Emp</th>
                            <th class="px-4 py-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($data as $row): ?>
                        <tr class="hover:bg-gray-50 transition" data-efficiency="<?php echo $row['efficiency_status']; ?>">
                            <td class="px-4 py-2 font-bold text-gray-800 text-xs font-mono"><?php echo $row['sub_route_code']; ?></td>
                            <td class="px-4 py-2 font-medium"><?php echo $row['sub_route_name']; ?></td>
                            <td class="px-4 py-2 text-xs text-gray-500"><?php echo $row['route_code']; ?></td>
                            <td class="px-4 py-2 text-center">
                                <div class="font-mono text-xs font-bold text-gray-700"><?php echo $row['vehicle_no']; ?></div>
                                <span class="text-[9px] px-1.5 py-0.5 rounded uppercase font-bold <?php echo (strpos($row['vehicle_type_raw'], 'van') !== false) ? 'bg-orange-100 text-orange-600' : ((strpos($row['vehicle_type_raw'], 'three') !== false) ? 'bg-purple-100 text-purple-600' : 'bg-gray-100 text-gray-500'); ?>">
                                    <?php echo $row['vehicle_type_display']; ?>
                                </span>
                            </td>
                            <td class="px-4 py-2 text-center font-mono"><?php echo $row['distance']; ?> km</td>
                            <td class="px-4 py-2 text-right">Rs. <?php echo number_format($row['per_day_rate'], 0); ?></td>
                            <td class="px-4 py-2 text-center font-bold text-gray-800"><?php echo $row['emp_count']; ?></td>
                            <td class="px-4 py-2 text-right font-bold text-indigo-700 bg-indigo-50 border-l border-indigo-100">Rs. <?php echo number_format($row['cost_per_emp'], 0); ?></td>
                            <td class="px-4 py-2 text-center text-xs <?php echo $row['efficiency_color']; ?>"><?php echo $row['efficiency_status']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div id="noResults" class="hidden p-8 text-center text-gray-400"><i class="fas fa-search text-2xl mb-2"></i><br>No matching sub-routes found.</div>
            </div>
        </div>
    </div>
</div>

<script>
    const fullData = <?php echo json_encode($data); ?>;
    let chartInstance = null;

    function getColorForVehicle(type) {
        if (!type) return '#9ca3af'; 
        type = type.toLowerCase();
        if (type.includes('van')) return '#f97316';
        if (type.includes('three') || type.includes('wheel')) return '#a855f7';
        return '#9ca3af';
    }

    function switchTab(view) {
        document.getElementById('view-chart').classList.add('hidden-content');
        document.getElementById('view-table').classList.add('hidden-content');
        document.getElementById('btn-chart').className = "px-4 py-2 bg-white text-gray-600 text-sm transition";
        document.getElementById('btn-table').className = "px-4 py-2 bg-white text-gray-600 text-sm transition";
        const selectedView = document.getElementById('view-'+view);
        selectedView.classList.remove('hidden-content');
        if (view === 'chart') {
            selectedView.classList.add('flex');
            document.getElementById('btn-chart').className = "px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 text-sm transition";
        } else {
            selectedView.classList.add('flex');
            document.getElementById('btn-table').className = "px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 text-sm transition";
        }
    }

    function applyFilters() {
        const efficiency = document.getElementById('efficiencyFilter').value;
        const search = document.getElementById('tableSearch').value.toLowerCase();
        const rows = document.querySelectorAll('#subRouteTable tbody tr');
        let visibleCount = 0;
        let filteredData = [];
        rows.forEach((row, index) => {
            const rowEff = row.getAttribute('data-efficiency');
            const text = row.innerText.toLowerCase();
            if ((efficiency === 'all' || rowEff === efficiency) && text.includes(search)) {
                row.style.display = '';
                filteredData.push(fullData[index]);
                visibleCount++;
            } else { row.style.display = 'none'; }
        });
        document.getElementById('noResults').classList.toggle('hidden', visibleCount > 0);
        updateCharts(filteredData);
    }

    function updateCharts(data) {
        const chartData = data.length > 30 ? data.slice(0, 30) : data;
        const labels = chartData.map(d => d.sub_route_code);
        const dailyRate = chartData.map(d => d.per_day_rate);
        const costPerEmp = chartData.map(d => d.cost_per_emp);
        const distance = chartData.map(d => d.distance);
        const barColors = chartData.map(d => getColorForVehicle(d.vehicle_type_raw));
        if(chartInstance) chartInstance.destroy();
        const ctx = document.getElementById('efficiencyChart').getContext('2d');
        chartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Distance (KM)', data: distance, type: 'line', borderColor: '#10b981', borderWidth: 2, borderDash: [5, 5], pointRadius: 3, yAxisID: 'y2', tension: 0.2, order: 1 },
                    { label: 'Cost Per Employee (Rs)', data: costPerEmp, type: 'line', borderColor: '#ea580c', borderWidth: 2, pointRadius: 3, yAxisID: 'y1', tension: 0.3, order: 2 },
                    { label: 'Daily Rate (Rs)', data: dailyRate, backgroundColor: barColors, borderRadius: 4, yAxisID: 'y', order: 3 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { tooltip: { callbacks: { title: (context) => { const idx = context[0].dataIndex; return chartData[idx].sub_route_name + ' (' + chartData[idx].vehicle_type_display + ')'; } } }, legend: { display: false } },
                scales: {
                    y: { type: 'linear', display: true, position: 'left', title: { display: true, text: 'Daily Rate (Rs)' }, grid: { display: false } },
                    y1: { type: 'linear', display: true, position: 'right', title: { display: true, text: 'Cost / Emp (Rs)' }, grid: { borderDash: [2, 4], color: '#e5e7eb' } },
                    y2: { type: 'linear', display: true, position: 'right', title: { display: true, text: 'Distance (KM)' }, grid: { display: false }, beginAtZero: true },
                    x: { ticks: { font: { size: 9 } } }
                }
            }
        });
    }

    async function downloadExcel() {
        const workbook = new ExcelJS.Workbook();
        const sheet = workbook.addWorksheet('Sub Route Analysis');
        sheet.columns = [{width:15}, {width:30}, {width:15}, {width:15}, {width:15}, {width:12}, {width:15}, {width:12}, {width:20}, {width:15}];
        const headerRow = sheet.getRow(1);
        headerRow.values = ['Code', 'Sub Route Name', 'Main Route', 'Vehicle', 'Type', 'Distance', 'Daily Rate', 'Employees', 'Cost / Emp', 'Status'];
        headerRow.font = { bold: true };
        headerRow.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFADD8E6' } };
        fullData.forEach(d => {
            const row = sheet.addRow([d.sub_route_code, d.sub_route_name, d.route_code, d.vehicle_no, d.vehicle_type_display, parseFloat(d.distance), parseFloat(d.per_day_rate), parseInt(d.emp_count), parseFloat(d.cost_per_emp), d.efficiency_status]);
            if(parseFloat(d.cost_per_emp) > 1000) { row.getCell(9).font = { color: { argb: 'FFFF0000' }, bold: true }; }
        });
        const buffer = await workbook.xlsx.writeBuffer();
        saveAs(new Blob([buffer]), 'Sub_Route_Analysis.xlsx');
    }
    updateCharts(fullData);
</script>
</body>
</html>
<?php $conn->close(); ?>