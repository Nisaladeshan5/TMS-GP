<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- DATA FETCHING ---
$sql_report = "
    SELECT 
        sr.sub_route_code,
        sr.sub_route AS sub_route_name,
        sr.route_code,
        v.capacity,
        v.vehicle_no,
        v.type as vehicle_type,
        COUNT(e.emp_id) AS current_employees
    FROM sub_route sr
    LEFT JOIN vehicle v ON sr.vehicle_no = v.vehicle_no
    LEFT JOIN employee e ON sr.sub_route_code = e.sub_route_code AND e.is_active = 1
    GROUP BY sr.sub_route_code
    ORDER BY sr.sub_route_code ASC
";

$result = $conn->query($sql_report);
$report_data = [];

// Filter Arrays
$unique_routes = [];
$unique_types = [];

// Summary Metrics
$efficiency_high = 0; // >= 97%
$efficiency_med = 0;  // 92% - 97%
$efficiency_low = 0;  // < 92%

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $capacity = (int)($row['capacity'] ?? 0);
        $count = (int)$row['current_employees'];
        
        // Calculate Percentage
        $percentage = ($capacity > 0) ? round(($count / $capacity) * 100, 1) : 0;
        if($capacity == 0 && $count > 0) $percentage = 100;

        // --- STATUS LOGIC ---
        if ($percentage >= 97) {
            $status_key = 'high';
            $color_hex = '#10b981'; // Green
            $status_text = 'Excellent';
            $badge_style = 'background-color: #d1fae5; color: #065f46; border: 1px solid #10b981;';
            $efficiency_high++;
        } elseif ($percentage >= 92) {
            $status_key = 'medium';
            $color_hex = '#f59e0b'; // Orange
            $status_text = 'Good';
            $badge_style = 'background-color: #fef3c7; color: #92400e; border: 1px solid #f59e0b;';
            $efficiency_med++;
        } else {
            $status_key = 'low';
            $color_hex = '#ef4444'; // Red
            $status_text = 'Underutilized';
            $badge_style = 'background-color: #fee2e2; color: #b91c1c; border: 1px solid #ef4444;';
            $efficiency_low++;
        }

        if ($percentage > 100) {
            $status_text = 'Overloaded';
            $color_hex = '#a307e0'; 
        }

        // Add to filters
        if (!in_array($row['route_code'], $unique_routes) && !empty($row['route_code'])) {
            $unique_routes[] = $row['route_code'];
        }
        $v_type = ucfirst($row['vehicle_type'] ?? 'Unknown');
        if (!in_array($v_type, $unique_types) && !empty($v_type)) {
            $unique_types[] = $v_type;
        }

        $row['percentage'] = $percentage;
        $row['color_hex'] = $color_hex;
        $row['status_text'] = $status_text;
        $row['status_key'] = $status_key;
        $row['badge_style'] = $badge_style;
        $row['vehicle_type_display'] = $v_type;
        
        $report_data[] = $row;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sub-Route Efficiency</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>

    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; }
        .metric-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border-left: 5px solid #ccc; }
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
    <div class="flex items-center space-x-2 w-fit">
            <a href="report_operations.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                System Reports
            </a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Sub Route Capacity Analysis
            </span>
        </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <button onclick="downloadExcel()" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition text-xs border border-green-500">
            <i class="fas fa-file-excel"></i> Export Excel
        </button>
        <span class="text-gray-600 text-lg font-thin">|</span>
        <a href="report_operations.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">Back</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col bg-slate-50">
    <div class="flex-grow p-6 flex flex-col h-full overflow-hidden">
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4 shrink-0">
            <div class="metric-card" style="border-color: #3b82f6;">
                <p class="text-xs text-gray-500 uppercase font-bold">Total Routes</p>
                <p class="text-2xl font-bold text-gray-800 mt-1" id="metric-total"><?php echo count($report_data); ?></p>
            </div>
            <div class="metric-card" style="border-color: #10b981;">
                <p class="text-xs text-gray-500 uppercase font-bold">Excellent (≥97%)</p>
                <p class="text-2xl font-bold text-green-600 mt-1" id="metric-high"><?php echo $efficiency_high; ?></p>
            </div>
            <div class="metric-card" style="border-color: #f59e0b;">
                <p class="text-xs text-gray-500 uppercase font-bold">Good (92-97%)</p>
                <p class="text-2xl font-bold text-yellow-600 mt-1" id="metric-med"><?php echo $efficiency_med; ?></p>
            </div>
            <div class="metric-card" style="border-color: #ef4444;">
                <p class="text-xs text-gray-500 uppercase font-bold">Underutilized (<92%)</p>
                <p class="text-2xl font-bold text-red-600 mt-1" id="metric-low"><?php echo $efficiency_low; ?></p>
            </div>
        </div>

        <div class="bg-white p-3 border-b border-gray-200 flex flex-wrap gap-4 items-center justify-between shrink-0 rounded-t-lg shadow-sm">
             <div class="flex border border-gray-300 rounded overflow-hidden">
                <button onclick="switchTab('chart')" id="btn-chart" class="px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 text-sm">
                    <i class="fas fa-chart-pie mr-2"></i> Visuals
                </button>
                <button onclick="switchTab('table')" id="btn-table" class="px-4 py-2 bg-white text-gray-600 text-sm">
                    <i class="fas fa-list mr-2"></i> Table
                </button>
            </div>

            <div class="flex gap-2">
                <select id="filterRoute" onchange="applyFilters()" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="all">All Routes</option>
                    <?php foreach($unique_routes as $r): ?>
                        <option value="<?php echo $r; ?>"><?php echo $r; ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="filterType" onchange="applyFilters()" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="all">All Types</option>
                    <?php foreach($unique_types as $t): ?>
                        <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="filterStatus" onchange="applyFilters()" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="all">All Statuses</option>
                    <option value="high">🟢 Excellent</option>
                    <option value="medium">🟡 Good</option>
                    <option value="low">🔴 Low</option>
                </select>

                <input type="text" id="searchInput" onkeyup="applyFilters()" placeholder="Search..." class="border border-gray-300 rounded-md px-3 py-1.5 text-xs w-32 focus:ring-blue-500">
            </div>
        </div>

        <div id="view-chart" class="bg-white p-4 rounded-b-lg shadow-sm border border-t-0 border-gray-200 flex-grow min-h-0 flex gap-4">
            
            <div class="flex-grow w-2/3 flex flex-col">
                <h4 class="text-sm font-bold text-gray-500 uppercase mb-2">Capacity vs Actual (with Efficiency %)</h4>
                <div class="relative flex-grow min-h-0">
                    <canvas id="barChart"></canvas>
                </div>
            </div>

            <div class="w-1/3 flex flex-col border-l border-gray-100 pl-4">
                <h4 class="text-sm font-bold text-gray-500 uppercase mb-2 text-center">Efficiency Summary</h4>
                <div class="relative flex-grow min-h-0">
                    <canvas id="pieChart"></canvas>
                </div>
            </div>
        </div>

        <div id="view-table" class="bg-white border border-gray-200 rounded-b-lg flex-grow min-h-0 flex flex-col hidden-content">
            <div class="table-scroll flex-grow overflow-auto h-full">
                <table class="custom-table" id="reportTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Sub Route</th>
                            <th>Main Route</th>
                            <th>Vehicle</th>
                            <th class="text-center">Cap.</th>
                            <th class="text-center">Act.</th>
                            <th style="width: 20%;">Utilization</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                        <tr class="searchable-row" 
                            data-route="<?php echo $row['route_code']; ?>" 
                            data-type="<?php echo $row['vehicle_type_display']; ?>"
                            data-status="<?php echo $row['status_key']; ?>">
                            
                            <td class="font-mono text-xs font-bold text-blue-700"><?php echo htmlspecialchars($row['sub_route_code']); ?></td>
                            <td><?php echo htmlspecialchars($row['sub_route_name']); ?></td>
                            <td class="text-xs text-gray-500"><?php echo htmlspecialchars($row['route_code']); ?></td>
                            <td class="text-xs">
                                <?php echo htmlspecialchars($row['vehicle_type_display']); ?> <span class="font-mono text-gray-400"><?php echo htmlspecialchars($row['vehicle_no']); ?></span>
                            </td>
                            <td class="text-center font-bold text-gray-400"><?php echo $row['capacity']; ?></td>
                            <td class="text-center font-bold text-gray-800"><?php echo $row['current_employees']; ?></td>
                            <td>
                                <div class="w-full bg-gray-200 rounded-full h-3 relative">
                                    <div class="h-3 rounded-full transition-all duration-500"
                                         style="width: <?php echo min($row['percentage'], 100); ?>%; background-color: <?php echo $row['color_hex']; ?>;">
                                    </div>
                                    <span class="absolute top-0 right-0 -mt-4 text-[10px] font-bold text-gray-600"><?php echo $row['percentage']; ?>%</span>
                                </div>
                            </td>
                            <td class="text-center">
                                <span style="font-size: 0.65rem; padding: 2px 8px; border-radius: 99px; font-weight: 600; <?php echo $row['badge_style']; ?>">
                                    <?php echo $row['status_text']; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div id="noResults" class="hidden p-10 text-center text-gray-400">No matching routes found.</div>
            </div>
        </div>

    </div>
</div>

<script>
    // Register the DataLabels Plugin globally
    Chart.register(ChartDataLabels);

    const fullData = <?php echo json_encode($report_data); ?>;
    let barChart, pieChart;

    function switchTab(view) {
        document.getElementById('view-chart').classList.toggle('hidden-content', view !== 'chart');
        document.getElementById('view-table').classList.toggle('hidden-content', view !== 'table');
        
        const btnChart = document.getElementById('btn-chart');
        const btnTable = document.getElementById('btn-table');
        
        if(view === 'chart') {
            btnChart.className = "px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 text-sm";
            btnTable.className = "px-4 py-2 bg-white text-gray-600 text-sm";
        } else {
            btnTable.className = "px-4 py-2 bg-blue-50 text-blue-700 font-bold border-r border-gray-300 text-sm";
            btnChart.className = "px-4 py-2 bg-white text-gray-600 text-sm";
        }
    }

    function applyFilters() {
        const search = document.getElementById('searchInput').value.toLowerCase();
        const route = document.getElementById('filterRoute').value;
        const type = document.getElementById('filterType').value;
        const status = document.getElementById('filterStatus').value;

        // Filter Data for Charts
        const filteredData = fullData.filter(d => {
            const mSearch = d.sub_route_name.toLowerCase().includes(search) || d.sub_route_code.toLowerCase().includes(search);
            const mRoute = route === 'all' || d.route_code === route;
            const mType = type === 'all' || d.vehicle_type_display === type;
            const mStatus = status === 'all' || d.status_key === status;
            return mSearch && mRoute && mType && mStatus;
        });

        updateCharts(filteredData);

        // Filter Table Rows
        let count = 0;
        document.querySelectorAll('.searchable-row').forEach(row => {
            const rSearch = row.innerText.toLowerCase().includes(search);
            const rRoute = route === 'all' || row.dataset.route === route;
            const rType = type === 'all' || row.dataset.type === type;
            const rStatus = status === 'all' || row.dataset.status === status;
            
            if(rSearch && rRoute && rType && rStatus) {
                row.style.display = '';
                count++;
            } else {
                row.style.display = 'none';
            }
        });
        document.getElementById('noResults').classList.toggle('hidden', count > 0);
    }

    function updateCharts(data) {
        const labels = data.map(d => d.sub_route_name);
        const actual = data.map(d => d.current_employees);
        const capacity = data.map(d => d.capacity);
        const colors = data.map(d => d.color_hex);
        const percentages = data.map(d => d.percentage); // Extract percentages

        // Pie Data Calculation
        let h = 0, m = 0, l = 0;
        data.forEach(d => {
            if(d.status_key === 'high') h++;
            else if(d.status_key === 'medium') m++;
            else l++;
        });

        // Metrics Update
        document.getElementById('metric-total').innerText = data.length;
        document.getElementById('metric-high').innerText = h;
        document.getElementById('metric-med').innerText = m;
        document.getElementById('metric-low').innerText = l;

        if(barChart) barChart.destroy();
        if(pieChart) pieChart.destroy();

        // Bar Chart
        barChart = new Chart(document.getElementById('barChart'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { 
                        label: 'Actual', 
                        data: actual, 
                        backgroundColor: colors, 
                        order: 1,
                        // Datalabels Configuration for THIS dataset
                        datalabels: {
                            anchor: 'end',
                            align: 'top',
                            formatter: function(value, context) {
                                return percentages[context.dataIndex] + '%';
                            },
                            font: { weight: 'bold', size: 11 },
                            color: function(context) {
                                // Match the text color to the bar color for cool effect, or use black
                                return colors[context.dataIndex]; 
                            }
                        }
                    },
                    { 
                        label: 'Capacity', 
                        data: capacity, 
                        backgroundColor: '#e5e7eb', 
                        order: 2,
                        datalabels: { display: false } // Hide labels for capacity bar
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 20 } }, // Give space for the labels
                scales: { 
                    x: { stacked: true, display: false }, 
                    y: { beginAtZero: true } 
                },
                plugins: { 
                    legend: { display: false }
                }
            }
        });

        // Pie Chart
        pieChart = new Chart(document.getElementById('pieChart'), {
            type: 'doughnut',
            data: {
                labels: ['Excellent', 'Good', 'Low'],
                datasets: [{ 
                    data: [h, m, l], 
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'], 
                    borderWidth: 0 
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { position: 'bottom', labels: { boxWidth: 10 } },
                    datalabels: { display: false } // Disable labels on Pie for cleanliness
                }
            }
        });
    }

    // Init
    updateCharts(fullData);

</script>

</body>
</html>
<?php $conn->close(); ?>