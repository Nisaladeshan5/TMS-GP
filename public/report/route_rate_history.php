<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include('../../includes/db.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// --- 1. INPUTS ---
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// --- 2. DATA PROCESSING ---
$sql = "
    SELECT 
        t.source_type,
        t.route_code,
        t.supplier_code,
        s.supplier AS supplier_name,
        t.month,
        t.year,
        t.fixed_amount,
        t.fuel_amount,
        t.monthly_payment,
        t.total_distance,
        t.route_distance
    FROM (
        SELECT 'Factory' as source_type, route_code, supplier_code, month, year, fixed_amount, fuel_amount, monthly_payment, total_distance, route_distance 
        FROM monthly_payments_f
        UNION ALL
        SELECT 'Staff' as source_type, route_code, supplier_code, month, year, fixed_amount, fuel_amount, monthly_payment, total_distance, route_distance 
        FROM monthly_payments_sf
    ) t
    LEFT JOIN supplier s ON t.supplier_code = s.supplier_code
    WHERE t.year = ?
    ORDER BY t.month ASC, t.monthly_payment DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $selected_year);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
$unique_suppliers = [];
$total_cost = 0;
$total_km = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $supplier_name = !empty($row['supplier_name']) ? $row['supplier_name'] : 'Unknown';
        $row['supplier_name'] = $supplier_name;
        
        $dateObj   = DateTime::createFromFormat('!m', $row['month']);
        $row['month_name'] = $dateObj->format('M'); 

        $dist = (float)$row['total_distance'];
        $pay  = (float)$row['monthly_payment'];
        $row['rate_per_km'] = ($dist > 0) ? ($pay / $dist) : 0;
        
        $data[] = $row;

        if (!in_array($supplier_name, $unique_suppliers)) $unique_suppliers[] = $supplier_name;
        
        $total_cost += $pay;
        $total_km += $dist;
    }
}
sort($unique_suppliers);

$avg_cost_km = ($total_km > 0) ? ($total_cost / $total_km) : 0;

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment History & Rate Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .hidden-content { display: none !important; }
        .table-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        table th { position: sticky; top: 0; z-index: 20; background-color: #f3f4f6; }
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
                Payment History (<?php echo $selected_year; ?>)
            </span>
        </div>
    </div>
    
    <form method="GET" class="flex items-center gap-4">
        <select name="year" onchange="this.form.submit()" class="text-sm text-gray-900 font-bold py-1 px-3 rounded border border-gray-300 focus:ring-2 focus:ring-indigo-500">
            <?php 
            $curr_year = date('Y');
            for($y=$curr_year; $y>=2023; $y--) {
                $sel = ($y == $selected_year) ? 'selected' : '';
                echo "<option value='$y' $sel>$y</option>";
            }
            ?>
        </select>
        <button type="button" onclick="downloadExcel()" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md text-xs border border-green-500 font-bold">
            <i class="fas fa-file-excel"></i> Export
        </button>
        <div class="flex items-center gap-4 text-sm font-medium">
            <span class="text-gray-600 text-lg font-thin">|</span>
            <a href="route_rate.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">Back</a>
        </div>
    </form>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col bg-slate-50">
    <div class="flex-grow p-6 flex flex-col h-full overflow-hidden">

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4 shrink-0">
            <div class="bg-white p-4 rounded-lg shadow-sm border-l-4 border-indigo-600">
                <p class="text-xs text-gray-400 uppercase font-bold">Total Payment (Year)</p>
                <p class="text-2xl font-bold text-gray-800">Rs. <?php echo number_format($total_cost/1000000, 2); ?>M</p>
                <p class="text-[10px] text-gray-400">Rs. <?php echo number_format($total_cost, 0); ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-sm border-l-4 border-blue-500">
                <p class="text-xs text-gray-400 uppercase font-bold">Total Distance</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($total_km, 0); ?> <span class="text-sm text-gray-500">KM</span></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-sm border-l-4 border-orange-500">
                <p class="text-xs text-gray-400 uppercase font-bold">Avg Cost per KM</p>
                <p class="text-2xl font-bold text-gray-800">Rs. <?php echo number_format($avg_cost_km, 2); ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-sm border-l-4 border-gray-500">
                <p class="text-xs text-gray-400 uppercase font-bold">Total Records</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo count($data); ?></p>
            </div>
        </div>

        <div class="bg-white p-3 border-b border-gray-200 flex flex-wrap gap-4 items-center justify-between shrink-0 rounded-t-lg">
             <div class="flex border border-gray-300 rounded overflow-hidden">
                <button onclick="switchTab('chart')" id="btn-chart" class="px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 text-sm"><i class="fas fa-chart-line mr-2"></i> Trends</button>
                <button onclick="switchTab('table')" id="btn-table" class="px-4 py-2 bg-white text-gray-600 text-sm"><i class="fas fa-table mr-2"></i> Data</button>
            </div>
            
            <div class="flex gap-2 items-center">
                <select id="typeFilter" onchange="applyFilters()" class="text-xs border border-gray-300 rounded px-2 py-1.5 bg-white min-w-[120px] font-semibold text-gray-700">
                    <option value="all">All Categories</option>
                    <option value="Factory">Factory</option>
                    <option value="Staff">Staff</option>
                </select>

                <select id="supplierFilter" onchange="applyFilters()" class="text-xs border border-gray-300 rounded px-2 py-1.5 bg-white min-w-[150px]">
                    <option value="all">All Suppliers</option>
                    <?php foreach ($unique_suppliers as $s): ?>
                        <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="text" id="tableSearch" onkeyup="applyFilters()" placeholder="Search Route/Code..." class="text-xs border border-gray-300 rounded px-2 py-1.5 w-40">
            </div>
        </div>

        <div id="view-chart" class="bg-white p-4 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 flex gap-4">
            <div class="w-2/3 h-full flex flex-col border-r pr-4">
                <h4 class="text-sm font-bold text-gray-700 mb-2 text-center">Cost vs Rate Efficiency Trend</h4>
                <div class="relative flex-grow">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            <div class="w-1/3 h-full flex flex-col pl-4">
                <h4 class="text-sm font-bold text-gray-700 mb-2 text-center">Top Suppliers (By Cost)</h4>
                <div class="relative flex-grow">
                    <canvas id="supplierChart"></canvas>
                </div>
            </div>
        </div>

        <div id="view-table" class="bg-white border border-gray-200 rounded-b-lg flex-grow min-h-0 flex flex-col hidden-content">
            <div class="table-scroll flex-grow overflow-auto h-full">
                <table class="w-full text-sm text-left text-gray-600" id="historyTable">
                    <thead class="text-xs uppercase bg-gray-100 text-gray-700 border-b-2 border-gray-300">
                        <tr>
                            <th class="px-4 py-3 text-center">Type</th>
                            <th class="px-4 py-3">Month</th>
                            <th class="px-4 py-3">Route</th>
                            <th class="px-4 py-3">Supplier</th>
                            <th class="px-4 py-3 text-center">Distance (KM)</th>
                            <th class="px-4 py-3 text-right">Fixed Amt</th>
                            <th class="px-4 py-3 text-right">Fuel Amt</th>
                            <th class="px-4 py-3 text-right font-bold text-orange-600 bg-orange-50">Rate (1KM)</th>
                            <th class="px-4 py-3 text-right font-bold bg-indigo-50 text-indigo-700">Total Payment</th>
                            <th class="px-4 py-3 text-center bg-gray-50">Action</th> </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($data as $row): ?>
                        <tr class="hover:bg-indigo-50 transition" 
                            data-type="<?php echo $row['source_type']; ?>"
                            data-supplier="<?php echo htmlspecialchars($row['supplier_name']); ?>">
                            
                            <td class="px-4 py-2 text-center">
                                <?php if($row['source_type'] == 'Factory'): ?>
                                    <span class="px-2 py-0.5 rounded bg-orange-100 text-orange-700 text-[10px] font-bold">Factory</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded bg-blue-100 text-blue-700 text-[10px] font-bold">Staff</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs"><?php echo $row['month_name']; ?></td>
                            <td class="px-4 py-2 font-medium text-gray-800">
                                <?php echo $row['route_code']; ?>
                            </td>
                            <td class="px-4 py-2 text-xs"><?php echo $row['supplier_name']; ?></td>
                            <td class="px-4 py-2 text-center font-mono"><?php echo $row['total_distance']; ?></td>
                            <td class="px-4 py-2 text-right text-xs"><?php echo number_format($row['fixed_amount'], 2); ?></td>
                            <td class="px-4 py-2 text-right text-xs text-green-600"><?php echo number_format($row['fuel_amount'], 2); ?></td>
                            
                            <td class="px-4 py-2 text-right font-bold text-xs text-orange-600 bg-orange-50 border-l border-orange-100">
                                Rs. <?php echo number_format($row['rate_per_km'], 2); ?>
                            </td>

                            <td class="px-4 py-2 text-right font-bold text-indigo-700 bg-indigo-50 border-l border-indigo-100">
                                Rs. <?php echo number_format($row['monthly_payment'], 2); ?>
                            </td>
                            
                            <td class="px-4 py-2 text-center">
                                <a href="rate_analysis.php?year=<?php echo $row['year']; ?>&route=<?php echo urlencode($row['route_code']); ?>" 
                                   class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-indigo-100 text-indigo-600 hover:bg-indigo-600 hover:text-white transition shadow-sm"
                                   title="Analyze Rate">
                                    <i class="fas fa-chart-line text-xs"></i>
                                </a>
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
    const fullData = <?php echo json_encode($data); ?>;
    let trendChart = null;
    let supplierChart = null;

    function switchTab(view) {
        document.getElementById('view-chart').classList.add('hidden-content');
        document.getElementById('view-table').classList.add('hidden-content');
        document.getElementById('btn-chart').className = "px-4 py-2 bg-white text-gray-600 text-sm";
        document.getElementById('btn-table').className = "px-4 py-2 bg-white text-gray-600 text-sm";
        
        const selectedView = document.getElementById('view-'+view);
        selectedView.classList.remove('hidden-content');
        selectedView.classList.add('flex');
        document.getElementById('btn-'+view).className = "px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 text-sm";
    }

    function applyFilters() {
        const type = document.getElementById('typeFilter').value;
        const supplier = document.getElementById('supplierFilter').value;
        const search = document.getElementById('tableSearch').value.toLowerCase();
        
        const rows = document.querySelectorAll('#historyTable tbody tr');
        let filteredData = [];

        rows.forEach((row, index) => {
            const rowType = row.getAttribute('data-type');
            const rowSup = row.getAttribute('data-supplier');
            const text = row.innerText.toLowerCase();
            
            const matchType = (type === 'all' || rowType === type);
            const matchSup = (supplier === 'all' || rowSup === supplier);
            const matchSearch = text.includes(search);

            if (matchType && matchSup && matchSearch) {
                row.style.display = '';
                filteredData.push(fullData[index]);
            } else {
                row.style.display = 'none';
            }
        });

        updateCharts(filteredData);
    }

    function updateCharts(data) {
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        
        const monthCost = {};
        const monthDist = {};
        months.forEach(m => { monthCost[m] = 0; monthDist[m] = 0; });

        data.forEach(d => {
            if(monthCost[d.month_name] !== undefined) {
                monthCost[d.month_name] += parseFloat(d.monthly_payment);
                monthDist[d.month_name] += parseFloat(d.total_distance);
            }
        });

        const monthRate = months.map(m => {
            return monthDist[m] > 0 ? (monthCost[m] / monthDist[m]) : 0;
        });
        const monthTotalCostValues = months.map(m => monthCost[m]);

        const supplierTotals = {};
        data.forEach(d => {
            const s = d.supplier_name;
            supplierTotals[s] = (supplierTotals[s] || 0) + parseFloat(d.monthly_payment);
        });
        const sortedSuppliers = Object.entries(supplierTotals).sort(([,a],[,b]) => b-a).slice(0, 5);

        if(trendChart) trendChart.destroy();
        trendChart = new Chart(document.getElementById('trendChart'), {
            type: 'bar', 
            data: {
                labels: months,
                datasets: [
                    {
                        label: 'Avg Rate (Rs/KM)',
                        data: monthRate,
                        type: 'line', 
                        borderColor: '#ea580c', 
                        backgroundColor: '#ea580c',
                        borderWidth: 2,
                        pointRadius: 4,
                        yAxisID: 'y1', 
                        tension: 0.3
                    },
                    {
                        label: 'Total Cost',
                        data: monthTotalCostValues,
                        backgroundColor: 'rgba(59, 130, 246, 0.2)', 
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        borderRadius: 4,
                        yAxisID: 'y' 
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: true } },
                scales: { 
                    y: { 
                        beginAtZero: true, 
                        title: { display: true, text: 'Total Cost (Rs)' },
                        position: 'left'
                    },
                    y1: {
                        beginAtZero: true,
                        title: { display: true, text: 'Rate (Rs/KM)' },
                        position: 'right',
                        grid: { drawOnChartArea: false } 
                    },
                    x: { grid: { display: false } }
                }
            }
        });

        if(supplierChart) supplierChart.destroy();
        supplierChart = new Chart(document.getElementById('supplierChart'), {
            type: 'bar',
            data: {
                labels: sortedSuppliers.map(x => x[0]),
                datasets: [{
                    label: 'Payment',
                    data: sortedSuppliers.map(x => x[1]),
                    backgroundColor: [ '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6' ],
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true } }
            }
        });
    }

    async function downloadExcel() {
        const workbook = new ExcelJS.Workbook();
        const sheet = workbook.addWorksheet('Payment History');
        sheet.columns = [
            {width:10}, {width:10}, {width:15}, {width:30}, {width:15}, {width:15}, {width:15}, {width:15}, {width:20}
        ];
        
        const headerRow = sheet.getRow(1);
        headerRow.values = ['Type', 'Month', 'Route', 'Supplier', 'Distance', 'Fixed Amt', 'Fuel Amt', 'Rate(1KM)', 'Total Payment'];
        headerRow.font = { bold: true };
        headerRow.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFADD8E6' } };

        fullData.forEach(d => {
            sheet.addRow([
                d.source_type, d.month_name, d.route_code, d.supplier_name,
                parseFloat(d.total_distance), parseFloat(d.fixed_amount), 
                parseFloat(d.fuel_amount), parseFloat(d.rate_per_km).toFixed(2), parseFloat(d.monthly_payment)
            ]);
        });

        const buffer = await workbook.xlsx.writeBuffer();
        saveAs(new Blob([buffer]), 'Payment_History_<?php echo $selected_year; ?>.xlsx');
    }

    updateCharts(fullData);

    // Check URL for tab parameter
    window.addEventListener('DOMContentLoaded', (event) => {
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('tab') === 'table'){
            switchTab('table');
        }
    });
</script>

</body>
</html>
<?php $conn->close(); ?>