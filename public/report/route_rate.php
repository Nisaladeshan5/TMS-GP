<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include('../../includes/db.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// --- 1. FUNCTIONS ---
function get_applicable_fuel_price($conn, $rate_id, $month, $year) {
    $end_date = date('Y-m-t', strtotime("$year-$month-01")); 
    $sql = "SELECT rate FROM fuel_rate WHERE rate_id = ? AND date <= ? ORDER BY date DESC LIMIT 1"; 
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $rate_id, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    return $row ? (float)$row['rate'] : 0;
}

// Get Consumption Rates & Descriptions
$consumption_rates = [];
$consumption_result = $conn->query("SELECT c_id, distance, c_type as description FROM consumption");
$consumption_info = [];

if ($consumption_result) {
    while ($row = $consumption_result->fetch_assoc()) {
        $consumption_rates[$row['c_id']] = (float)$row['distance'];
        $consumption_info[$row['c_id']] = $row['description'];
    }
}
$default_km_per_liter = 1.00;

// --- 2. INPUT ---
$selected_period = isset($_GET['period']) ? $_GET['period'] : date('Y-m');
$parts = explode('-', $selected_period);
$year = $parts[0];
$month = $parts[1];

// --- 3. DATA PROCESSING ---
// UPDATED SQL: Added subquery to get employee count matching first 10 chars
$sql_routes = "
    SELECT 
        r.route_code, r.route AS route_name, r.supplier_code, s.supplier AS supplier_name,
        r.distance, r.fixed_amount, r.with_fuel, 
        v.vehicle_no, v.type AS vehicle_type, v.fuel_efficiency as c_id, v.rate_id,
        (SELECT COUNT(*) FROM employee e WHERE SUBSTRING(e.route, 1, 10) = r.route_code AND e.is_active = 1 AND e.vacated = 0) as emp_count
    FROM route r
    LEFT JOIN supplier s ON r.supplier_code = s.supplier_code
    LEFT JOIN vehicle v ON r.vehicle_no = v.vehicle_no
    WHERE r.is_active = 1
    ORDER BY r.route_code ASC
";

$result = $conn->query($sql_routes);
$data = [];
$unique_suppliers = []; 
$unique_vehicle_types = [];
$route_count = 0;
$total_avg_rate = 0;
$max_rate = 0;
$min_rate = 9999;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $fixed_rate_1km = (float)$row['fixed_amount'];
        $fuel_rate_1km = 0;
        $fuel_price_liter = 0;
        $kmpl = 0;

        if ($row['with_fuel'] == 1 && !empty($row['rate_id'])) {
            $fuel_price_liter = get_applicable_fuel_price($conn, $row['rate_id'], $month, $year);
            $kmpl = isset($consumption_rates[$row['c_id']]) ? $consumption_rates[$row['c_id']] : $default_km_per_liter;
            
            if ($kmpl > 0) {
                $fuel_rate_1km = $fuel_price_liter / $kmpl;
            }
        }

        $total_rate_1km = $fixed_rate_1km + $fuel_rate_1km;

        // --- NEW CALCULATIONS FOR HEAD COST ---
        $distance = (float)$row['distance'];
        $emp_count = (int)$row['emp_count'];
        $total_trip_cost = $total_rate_1km * $distance;
        
        // Avoid division by zero
        $cost_per_head = ($emp_count > 0) ? ($total_trip_cost / $emp_count) : 0;

        $row['fixed_rate_1km'] = $fixed_rate_1km;
        $row['fuel_rate_1km'] = $fuel_rate_1km;
        $row['total_rate_1km'] = $total_rate_1km;
        $row['total_trip_cost'] = $total_trip_cost; // New
        $row['cost_per_head'] = $cost_per_head;     // New
        $row['emp_count'] = $emp_count;             // New
        
        // Vehicle & AC Info
        $v_type = !empty($row['vehicle_type']) ? ucfirst(strtolower($row['vehicle_type'])) : 'Other';
        $supplier_name = !empty($row['supplier_name']) ? $row['supplier_name'] : 'Unknown';
        $ac_desc = isset($consumption_info[$row['c_id']]) ? strtolower($consumption_info[$row['c_id']]) : '';
        
        $row['vehicle_type'] = $v_type;
        $row['ac_type'] = $ac_desc;
        $row['supplier_name'] = $supplier_name;

        // Staff vs Factory Logic
        $char_5 = strtoupper(substr($row['route_code'], 4, 1)); 
        $category = 'Other';
        if ($char_5 === 'S') {
            $category = 'Staff';
        } elseif ($char_5 === 'F') {
            $category = 'Factory';
        }
        $row['category'] = $category;
        
        $data[] = $row;

        if (!in_array($supplier_name, $unique_suppliers)) $unique_suppliers[] = $supplier_name;
        if (!in_array($v_type, $unique_vehicle_types)) $unique_vehicle_types[] = $v_type;

        if ($total_rate_1km > 0) {
            if ($total_rate_1km > $max_rate) $max_rate = $total_rate_1km;
            if ($total_rate_1km < $min_rate) $min_rate = $total_rate_1km;
            $total_avg_rate += $total_rate_1km;
            $route_count++;
        }
    }
}
sort($unique_suppliers);
sort($unique_vehicle_types);

$global_avg = ($route_count > 0) ? ($total_avg_rate / $route_count) : 0;
if ($min_rate == 9999) $min_rate = 0;

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Cost Analysis</title>
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
                Route Cost Analysis
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <button onclick="downloadExcel()" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md text-xs border border-green-500">
            <i class="fas fa-file-excel"></i> Export
        </button>
        <span class="text-gray-600 text-lg font-thin">|</span>
        <a href="route_rate_history.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">Rate History</a>
        <a href="report_operations.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">Back</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col bg-slate-50">
    <div class="flex-grow p-4 flex flex-col h-full overflow-hidden">

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-3 shrink-0">
            <div class="bg-white p-4 rounded-lg shadow-sm border-l-4 border-indigo-500">
                <p class="text-xs text-gray-400 uppercase font-bold">Avg Rate (1KM)</p>
                <p class="text-2xl font-bold text-gray-800">Rs. <?php echo number_format($global_avg, 2); ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-sm border-l-4 border-green-500">
                <p class="text-xs text-gray-400 uppercase font-bold">Lowest Rate</p>
                <p class="text-2xl font-bold text-gray-800">Rs. <?php echo number_format($min_rate, 2); ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-sm border-l-4 border-red-500">
                <p class="text-xs text-gray-400 uppercase font-bold">Highest Rate</p>
                <p class="text-2xl font-bold text-gray-800">Rs. <?php echo number_format($max_rate, 2); ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-sm border-l-4 border-gray-500">
                <p class="text-xs text-gray-400 uppercase font-bold">Total Routes</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo $route_count; ?></p>
            </div>
        </div>

        <div class="bg-white p-2 border-b border-gray-200 flex flex-wrap gap-4 items-center justify-between shrink-0 rounded-t-lg">
             <div class="flex border border-gray-300 rounded overflow-hidden">
                <button onclick="switchTab('chart')" id="btn-chart" class="px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 text-sm"><i class="fas fa-chart-pie mr-2"></i> Dashboard</button>
                <button onclick="switchTab('headcost')" id="btn-headcost" class="px-4 py-2 bg-white text-gray-600 text-sm border-r border-gray-300"><i class="fas fa-users-cog mr-2"></i> Efficiency</button>
                <button onclick="switchTab('table')" id="btn-table" class="px-4 py-2 bg-white text-gray-600 text-sm"><i class="fas fa-list mr-2"></i> Details</button>
            </div>
            
            <div class="flex gap-2 items-center">
                <select id="categoryFilter" onchange="applyFilters()" class="text-xs border border-gray-300 rounded px-2 py-1.5 bg-white min-w-[120px]">
                    <option value="all">All Types</option>
                    <option value="Staff">Staff</option>
                    <option value="Factory">Factory</option>
                </select>
                <select id="typeFilter" onchange="applyFilters()" class="text-xs border border-gray-300 rounded px-2 py-1.5 bg-white min-w-[120px]">
                    <option value="all">All Vehicles</option>
                    <?php foreach ($unique_vehicle_types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="fuelFilter" onchange="applyFilters()" class="text-xs border border-gray-300 rounded px-2 py-1.5 bg-white min-w-[120px]">
                    <option value="all">All Modes</option>
                    <option value="1">With Fuel</option>
                    <option value="0">Without Fuel</option>
                </select>
                <select id="supplierFilter" onchange="applyFilters()" class="text-xs border border-gray-300 rounded px-2 py-1.5 bg-white min-w-[150px]">
                    <option value="all">All Suppliers</option>
                    <?php foreach ($unique_suppliers as $s): ?>
                        <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="tableSearch" onkeyup="applyFilters()" placeholder="Search..." class="text-xs border border-gray-300 rounded px-2 py-1.5 w-32">
            </div>
        </div>

        <div id="view-chart" class="bg-white p-4 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 flex flex-col gap-4">
            <div class="flex justify-center gap-6 pb-2 border-b border-gray-100 shrink-0 flex-wrap">
                <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-blue-500"></span><span class="text-xs font-bold text-gray-600">Bus</span></div>
                <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-[#eab308]"></span><span class="text-xs font-bold text-gray-600">Van (Non A/C)</span></div>
                <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-[#f97316]"></span><span class="text-xs font-bold text-gray-600">Van (Front A/C)</span></div>
                <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-[#dc2626]"></span><span class="text-xs font-bold text-gray-600">Van (Dual A/C)</span></div>
                <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-gray-400"></span><span class="text-xs font-bold text-gray-600">Other</span></div>
            </div>

            <div class="flex gap-4 flex-grow h-full overflow-hidden">
                <div class="w-1/2 h-full flex flex-col border-r pr-4">
                    <h4 class="text-sm font-bold text-gray-700 mb-2 text-center">Cost Comparison (Rs/KM)</h4>
                    <div class="relative flex-grow">
                        <canvas id="routeBarChart"></canvas>
                    </div>
                </div>

                <div class="w-1/2 h-full flex flex-col pl-4">
                    <h4 class="text-sm font-bold text-gray-700 mb-2 text-center">Distance vs Cost Matrix</h4>
                    <div class="relative flex-grow">
                        <canvas id="scatterChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div id="view-headcost" class="bg-white p-4 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 flex-col gap-4 hidden-content">
            
            <div class="flex justify-between items-center pb-2 border-b border-gray-100 shrink-0">
                <h3 class="text-sm font-bold text-gray-700">Cost Per Employee Analysis</h3>
                <div class="text-xs text-gray-500 bg-yellow-50 px-2 py-1 rounded border border-yellow-200">
                    <i class="fas fa-info-circle"></i> Formula: (Rate/KM × Distance) ÷ Employee Count
                </div>
            </div>

            <div class="flex justify-center gap-6 pb-2 border-b border-gray-100 shrink-0 flex-wrap">
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-blue-500"></span>
                    <span class="text-xs font-bold text-gray-600">Bus</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-[#eab308]"></span>
                    <span class="text-xs font-bold text-gray-600">Van (Non A/C)</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-[#f97316]"></span>
                    <span class="text-xs font-bold text-gray-600">Van (Front A/C)</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-[#dc2626]"></span>
                    <span class="text-xs font-bold text-gray-600">Van (Dual A/C)</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-gray-400"></span>
                    <span class="text-xs font-bold text-gray-600">Other</span>
                </div>
            </div>
            
            <div class="relative flex-grow w-full">
                <canvas id="headCostChart"></canvas>
            </div>
        </div>

        <div id="view-table" class="bg-white border border-gray-200 rounded-b-lg flex-grow min-h-0 flex flex-col hidden-content">
            <div class="table-scroll flex-grow overflow-auto h-full">
                <table class="w-full text-sm text-left text-gray-600" id="rateTable">
                    <thead class="text-xs uppercase bg-gray-100 text-gray-700 border-b-2 border-gray-300">
                        <tr>
                            <th class="px-4 py-3 whitespace-nowrap">Route</th>
                            <th class="px-4 py-3 whitespace-nowrap">Supplier</th>
                            <th class="px-4 py-3 whitespace-nowrap text-center">Vehicle</th>
                            <th class="px-4 py-3 whitespace-nowrap text-center">Dist (KM)</th>
                            <th class="px-4 py-3 whitespace-nowrap text-center">Employees</th>
                            <th class="px-4 py-3 whitespace-nowrap text-right">Total (1KM)</th>
                            <th class="px-4 py-3 whitespace-nowrap text-right">Trip Cost</th>
                            <th class="px-4 py-3 whitespace-nowrap text-right font-bold bg-green-50 text-green-700">Cost / Head</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($data as $row): ?>
                        <tr class="hover:bg-indigo-50 transition" 
                            data-supplier="<?php echo htmlspecialchars($row['supplier_name']); ?>"
                            data-type="<?php echo htmlspecialchars($row['vehicle_type']); ?>"
                            data-category="<?php echo $row['category']; ?>"
                            data-fuel="<?php echo $row['with_fuel']; ?>"
                            data-ac="<?php echo htmlspecialchars($row['ac_type']); ?>">
                            
                            <td class="px-4 py-2 font-medium text-gray-800">
                                <?php echo $row['route_code']; ?>
                                <span class="block text-[10px] text-gray-400 font-normal"><?php echo $row['route_name']; ?></span>
                            </td>
                            <td class="px-4 py-2 text-xs"><?php echo $row['supplier_name']; ?></td>
                            <td class="px-4 py-2 text-center">
                                <span class="bg-gray-200 px-2 py-0.5 rounded text-[10px] uppercase"><?php echo $row['vehicle_type']; ?></span>
                                <div class="text-[9px] text-gray-400 mt-0.5"><?php echo ucfirst($row['ac_type']); ?></div>
                            </td>
                            <td class="px-4 py-2 text-center font-mono"><?php echo $row['distance']; ?></td>
                            
                            <td class="px-4 py-2 text-center font-bold text-gray-700">
                                <?php if($row['emp_count'] > 0): ?>
                                    <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full text-xs"><?php echo $row['emp_count']; ?></span>
                                <?php else: ?>
                                    <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="px-4 py-2 text-right text-xs">Rs. <?php echo number_format($row['total_rate_1km'], 2); ?></td>
                            
                            <td class="px-4 py-2 text-right text-xs font-mono text-gray-500">
                                <?php echo number_format($row['total_trip_cost'], 0); ?>
                            </td>
                            
                            <td class="px-4 py-2 text-right font-bold text-green-700 bg-green-50 border-l border-green-100">
                                <?php echo ($row['cost_per_head'] > 0) ? 'Rs. '.number_format($row['cost_per_head'], 2) : '-'; ?>
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
    let routeChartInstance = null;
    let scatterChartInstance = null;
    let headCostChartInstance = null; // New Chart Instance

    function getColorForVehicle(type, acType) {
        const t = type.toLowerCase();
        const ac = acType ? acType.toLowerCase() : '';
        if(t.includes('bus')) return '#3b82f6';
        if(t.includes('van')) {
            if(ac.includes('non')) return '#eab308';
            if(ac.includes('dual')) return '#dc2626';
            if(ac.includes('front')) return '#f97316';
            return '#f97316';
        }
        return '#9ca3af';
    }

    function switchTab(view) {
        // Hide all views
        document.getElementById('view-chart').classList.add('hidden-content');
        document.getElementById('view-headcost').classList.add('hidden-content');
        document.getElementById('view-table').classList.add('hidden-content');
        
        // Reset buttons
        document.getElementById('btn-chart').className = "px-4 py-2 bg-white text-gray-600 text-sm border-r border-gray-300";
        document.getElementById('btn-headcost').className = "px-4 py-2 bg-white text-gray-600 text-sm border-r border-gray-300";
        document.getElementById('btn-table').className = "px-4 py-2 bg-white text-gray-600 text-sm";
        
        // Show selected view
        const selectedView = document.getElementById('view-'+view);
        selectedView.classList.remove('hidden-content');
        selectedView.classList.add('flex');
        
        // Highlight active button
        document.getElementById('btn-'+view).className = "px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 text-sm";
    }

    function applyFilters() {
        const category = document.getElementById('categoryFilter').value;
        const supplier = document.getElementById('supplierFilter').value;
        const vType = document.getElementById('typeFilter').value;
        const fuelMode = document.getElementById('fuelFilter').value;
        const search = document.getElementById('tableSearch').value.toLowerCase();
        
        const rows = document.querySelectorAll('#rateTable tbody tr');
        let filteredData = [];

        rows.forEach((row, index) => {
            const rowCat = row.getAttribute('data-category');
            const rowSup = row.getAttribute('data-supplier');
            const rowType = row.getAttribute('data-type');
            const rowFuel = row.getAttribute('data-fuel');
            const text = row.innerText.toLowerCase();
            
            const matchCat = (category === 'all' || rowCat === category);
            const matchSup = (supplier === 'all' || rowSup === supplier);
            const matchType = (vType === 'all' || rowType === vType);
            const matchFuel = (fuelMode === 'all' || rowFuel === fuelMode);
            const matchSearch = text.includes(search);

            if (matchCat && matchSup && matchType && matchFuel && matchSearch) {
                row.style.display = '';
                filteredData.push(fullData[index]);
            } else {
                row.style.display = 'none';
            }
        });

        updateCharts(filteredData);
    }

    function updateCharts(data) {
        // --- 1. Bar Chart (Existing Logic) ---
        let barData = [...data].sort((a,b) => parseFloat(b.total_rate_1km) - parseFloat(a.total_rate_1km));
        const barLabels = barData.map(d => d.route_code);
        const barValues = barData.map(d => d.total_rate_1km);
        const barColors = barData.map(d => getColorForVehicle(d.vehicle_type, d.ac_type));

        if (routeChartInstance) routeChartInstance.destroy();
        routeChartInstance = new Chart(document.getElementById('routeBarChart'), {
            type: 'bar',
            data: {
                labels: barLabels,
                datasets: [{ label: 'Rate (Rs)', data: barValues, backgroundColor: barColors, borderRadius: 3 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { 
                    x: { ticks: { display: true, font: {size: 8}, maxRotation: 90, minRotation: 90 } },
                    y: { beginAtZero: true }
                }
            }
        });

        // --- 2. Scatter Chart (FIXED: Tooltips & Colors) ---
        
        // Helper to safely check strings
        const checkStr = (str, keyword) => (str && str.toLowerCase().includes(keyword));

        // Dataset Creator Helper
        const createDataset = (label, color, filterFn) => {
            return {
                label: label,
                data: data.filter(filterFn).map(d => ({ 
                    x: parseFloat(d.distance), 
                    y: parseFloat(d.total_rate_1km), 
                    // Store extra info for the tooltip here:
                    label: d.route_code,
                    vehicle: d.vehicle_type,
                    supplier: d.supplier_name
                })),
                backgroundColor: color, 
                pointRadius: 5, // Made points slightly bigger for easier hovering
                pointHoverRadius: 9
            };
        };

        if (scatterChartInstance) scatterChartInstance.destroy();
        scatterChartInstance = new Chart(document.getElementById('scatterChart'), {
            type: 'scatter',
            data: {
                datasets: [
                    // ORDER & COLORS MATCHING THE LEGEND EXACTLY
                    
                    // 1. Bus (Blue)
                    createDataset('Bus', '#3b82f6', d => checkStr(d.vehicle_type, 'bus')),
                    
                    // 2. Van - Non A/C (Yellow)
                    createDataset('Van (Non A/C)', '#eab308', d => checkStr(d.vehicle_type, 'van') && checkStr(d.ac_type, 'non')),
                    
                    // 3. Van - Front A/C (Orange)
                    createDataset('Van (Front A/C)', '#f97316', d => checkStr(d.vehicle_type, 'van') && checkStr(d.ac_type, 'front')),
                    
                    // 4. Van - Dual A/C (Red)
                    createDataset('Van (Dual A/C)', '#dc2626', d => checkStr(d.vehicle_type, 'van') && checkStr(d.ac_type, 'dual')),
                    
                    // 5. Other (Gray) - Catches anything not matched above
                    createDataset('Other', '#9ca3af', d => 
                        !checkStr(d.vehicle_type, 'bus') && 
                        !(checkStr(d.vehicle_type, 'van') && (checkStr(d.ac_type, 'non') || checkStr(d.ac_type, 'front') || checkStr(d.ac_type, 'dual')))
                    )
                ]
            },
            options: {
                responsive: true, 
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false }, // We use the custom HTML legend on top
                    tooltip: { 
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 10,
                        callbacks: { 
                            // CUSTOM TOOLTIP LOGIC HERE
                            label: function(context) {
                                const point = context.raw;
                                // Shows: "ROUTE001: Rs. 150.00 (45 km)"
                                return `${point.label}: Rs. ${point.y.toFixed(2)} (${point.x} km)`;
                            },
                            afterLabel: function(context) {
                                const point = context.raw;
                                return `Vehicle: ${point.vehicle}`;
                            }
                        } 
                    } 
                },
                scales: { 
                    x: { title: {display: true, text: 'Distance (KM)'}, beginAtZero: true }, 
                    y: { title: {display: true, text: 'Cost per KM (Rs)'}, beginAtZero: true } 
                }
            }
        });

        // --- 3. Head Cost Chart (Existing Logic) ---
        let headData = [...data]
            .filter(d => parseFloat(d.cost_per_head) > 0)
            .sort((a,b) => parseFloat(b.cost_per_head) - parseFloat(a.cost_per_head));

        const headLabels = headData.map(d => d.route_code + ' (' + d.emp_count + ')');
        const headValues = headData.map(d => d.cost_per_head);
        const headColors = headData.map(d => getColorForVehicle(d.vehicle_type, d.ac_type));

        if (headCostChartInstance) headCostChartInstance.destroy();
        headCostChartInstance = new Chart(document.getElementById('headCostChart'), {
            type: 'bar',
            data: {
                labels: headLabels,
                datasets: [{
                    label: 'Cost Per Head (Rs)',
                    data: headValues,
                    backgroundColor: headColors,
                    borderRadius: 4
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
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('en-LK', { style: 'currency', currency: 'LKR' }).format(context.parsed.y);
                                }
                                return label;
                            },
                            afterLabel: function(context) {
                                const idx = context.dataIndex;
                                return 'Employees: ' + headData[idx].emp_count; 
                            }
                        }
                    }
                },
                scales: { 
                    x: { ticks: { display: true, font: {size: 9}, autoSkip: false, maxRotation: 90, minRotation: 90 } },
                    y: { 
                        beginAtZero: true, 
                        title: {display: true, text: 'Cost Per Employee (Rs)'},
                        grid: { color: '#f3f4f6' }
                    }
                }
            }
        });
    }

    // Init
    updateCharts(fullData);

    async function downloadExcel() {
        const workbook = new ExcelJS.Workbook();
        const sheet = workbook.addWorksheet('Rates');
        sheet.columns = [{width:15},{width:35},{width:15},{width:15},{width:25},{width:12},{width:15},{width:15},{width:15},{width:15}];
        
        const headerRow = sheet.getRow(1);
        headerRow.values = ['Code', 'Route Name', 'Type', 'Vehicle', 'Supplier', 'Dist(KM)', 'Empl. Count', 'Trip Cost', 'Cost/Head', 'Total(1KM)'];
        headerRow.font = { bold: true };
        
        fullData.forEach(d => {
            sheet.addRow([
                d.route_code, d.route_name, d.category, 
                d.vehicle_type + (d.ac_type ? ' (' + d.ac_type + ')' : ''), 
                d.supplier_name, 
                d.distance, 
                d.emp_count, // New
                d.total_trip_cost, // New
                d.cost_per_head,   // New
                parseFloat(d.total_rate_1km)
            ]);
        });
        const buffer = await workbook.xlsx.writeBuffer();
        saveAs(new Blob([buffer]), 'Route_Analysis_Enhanced.xlsx');
    }
</script>
</body>
</html>
<?php $conn->close(); ?>