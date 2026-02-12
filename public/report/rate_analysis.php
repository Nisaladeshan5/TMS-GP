<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include('../../includes/db.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// --- 1. INITIAL SETUP ---
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$selected_route = isset($_GET['route']) ? $_GET['route'] : '';

// --- 2. GET UNIQUE ROUTES ---
$route_sql = "
    SELECT DISTINCT route_code FROM monthly_payments_f WHERE year = ?
    UNION
    SELECT DISTINCT route_code FROM monthly_payments_sf WHERE year = ?
    ORDER BY route_code ASC
";
$stmt_r = $conn->prepare($route_sql);
$stmt_r->bind_param("ss", $selected_year, $selected_year);
$stmt_r->execute();
$res_r = $stmt_r->get_result();
$routes_list = [];
while($r = $res_r->fetch_assoc()) {
    $routes_list[] = $r['route_code'];
}

// --- 3. GET DATA ---
$chart_data = []; 
$table_data = []; 

if ($selected_route) {
    $sql_data = "
        SELECT 
            month, 
            SUM(monthly_payment) as total_pay, 
            SUM(total_distance) as total_dist
        FROM (
            SELECT route_code, month, monthly_payment, total_distance FROM monthly_payments_f WHERE year = ? AND route_code = ?
            UNION ALL
            SELECT route_code, month, monthly_payment, total_distance FROM monthly_payments_sf WHERE year = ? AND route_code = ?
        ) t
        GROUP BY month
        ORDER BY month ASC
    ";
    
    $stmt_d = $conn->prepare($sql_data);
    $stmt_d->bind_param("ssss", $selected_year, $selected_route, $selected_year, $selected_route);
    $stmt_d->execute();
    $res_d = $stmt_d->get_result();

    $prev_rate = 0;
    while($row = $res_d->fetch_assoc()) {
        $month_num = (int)$row['month'];
        $dateObj   = DateTime::createFromFormat('!m', $month_num);
        $month_name = $dateObj->format('M');

        $dist = (float)$row['total_dist'];
        $pay  = (float)$row['total_pay'];
        $rate = ($dist > 0) ? ($pay / $dist) : 0;

        $variance = 0;
        $variance_percent = 0;
        if($prev_rate > 0) {
            $variance = $rate - $prev_rate;
            $variance_percent = ($variance / $prev_rate) * 100;
        }

        $entry = [
            'month' => $month_name,
            'month_num' => $month_num,
            'payment' => $pay,
            'distance' => $dist,
            'rate' => $rate,
            'variance' => $variance,
            'variance_percent' => $variance_percent
        ];

        $chart_data[] = $entry;
        $table_data[] = $entry;
        $prev_rate = $rate; 
    }
}

$js_labels = json_encode(array_column($chart_data, 'month'));
$js_rates  = json_encode(array_column($chart_data, 'rate'));

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Rate Volatility</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                Single Route Analysis (<?php echo htmlspecialchars($selected_route); ?>)
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="route_rate_history.php?year=<?php echo $selected_year; ?>&tab=table" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            Back
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col bg-slate-50">
    <div class="flex-grow p-6 flex flex-col h-full overflow-hidden">
        
        <!-- <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 mb-4 shrink-0 flex items-center justify-between">
            <form method="GET" class="flex items-center gap-4 w-full">
                
                <div class="flex flex-col">
                    <label class="text-[10px] font-bold text-gray-500 uppercase tracking-wide mb-1">Year</label>
                    <select name="year" onchange="this.form.submit()" class="text-sm border-gray-300 rounded px-3 py-1.5 focus:ring-2 focus:ring-indigo-500 bg-gray-50 w-24 font-bold">
                        <?php 
                        $curr_year = date('Y');
                        for($y=$curr_year; $y>=2023; $y--) {
                            $sel = ($y == $selected_year) ? 'selected' : '';
                            echo "<option value='$y' $sel>$y</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="flex flex-col flex-grow max-w-md">
                    <label class="text-[10px] font-bold text-gray-500 uppercase tracking-wide mb-1">Select Route to Analyze</label>
                    <div class="flex gap-2">
                        <select name="route" class="text-sm border-gray-300 rounded px-3 py-1.5 focus:ring-2 focus:ring-indigo-500 bg-white w-full font-medium">
                            <option value="" disabled <?php echo empty($selected_route) ? 'selected' : ''; ?>>-- Choose a Route --</option>
                            <?php foreach($routes_list as $r_code): ?>
                                <option value="<?php echo $r_code; ?>" <?php echo ($selected_route == $r_code) ? 'selected' : ''; ?>>
                                    <?php echo $r_code; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-1.5 rounded text-sm font-bold shadow transition">
                            Analyze
                        </button>
                    </div>
                </div>
            </form>

            <?php if($selected_route): ?>
            <div class="flex flex-col items-end border-l border-gray-200 pl-6">
                <p class="text-[10px] text-gray-400 uppercase font-bold">Current Route</p>
                <p class="text-xl font-bold text-indigo-700 tracking-tight"><?php echo htmlspecialchars($selected_route); ?></p>
            </div>
            <?php endif; ?>
        </div> -->

        <?php if($selected_route && !empty($chart_data)): ?>
        
        <div class="bg-white p-5 rounded-lg shadow-sm border border-gray-200 flex-grow min-h-0 mb-4 flex flex-col">
            <h4 class="text-sm font-bold text-gray-700 mb-3 flex items-center justify-between">
                <span><i class="fas fa-chart-line text-orange-500 mr-2"></i>Rate Fluctuation Trend</span>
                <span class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Rs. per KM</span>
            </h4>
            <div class="relative flex-grow w-full">
                <canvas id="rateChart"></canvas>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-lg h-[40%] flex flex-col shrink-0">
            <div class="px-4 py-2 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                <h4 class="text-xs font-bold text-gray-600 uppercase tracking-wider">Detailed Monthly Breakdown</h4>
            </div>
            <div class="table-scroll flex-grow overflow-auto">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="text-xs uppercase bg-gray-100 text-gray-700 sticky top-0 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 font-bold border-r border-gray-200">Month</th>
                            <th class="px-6 py-3 text-right border-r border-gray-200">Distance</th>
                            <th class="px-6 py-3 text-right border-r border-gray-200">Total Payment</th>
                            <th class="px-6 py-3 text-right font-bold text-indigo-700 border-r border-gray-200 bg-indigo-50">Rate (1KM)</th>
                            <th class="px-6 py-3 text-center">Variance (vs Prev Month)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach($table_data as $row): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-2 font-bold text-gray-800 border-r border-gray-100">
                                <?php echo $row['month']; ?>
                            </td>
                            <td class="px-6 py-2 text-right font-mono border-r border-gray-100">
                                <?php echo number_format($row['distance'], 0); ?> KM
                            </td>
                            <td class="px-6 py-2 text-right font-mono border-r border-gray-100">
                                Rs. <?php echo number_format($row['payment'], 2); ?>
                            </td>
                            <td class="px-6 py-2 text-right font-bold text-indigo-700 bg-indigo-50 border-r border-gray-100 border-b-white">
                                Rs. <?php echo number_format($row['rate'], 2); ?>
                            </td>
                            <td class="px-6 py-2 text-center text-xs">
                                <?php if($row['variance'] == 0): ?>
                                    <span class="text-gray-300 font-bold">-</span>
                                <?php elseif($row['variance'] > 0): ?>
                                    <div class="inline-flex items-center gap-2 px-2 py-0.5 rounded bg-red-50 text-red-600 border border-red-100">
                                        <i class="fas fa-arrow-trend-up"></i> 
                                        <span class="font-bold">+<?php echo number_format($row['variance'], 2); ?></span>
                                        <span class="text-[10px] opacity-75">(<?php echo number_format($row['variance_percent'], 1); ?>%)</span>
                                    </div>
                                <?php else: ?>
                                    <div class="inline-flex items-center gap-2 px-2 py-0.5 rounded bg-green-50 text-green-600 border border-green-100">
                                        <i class="fas fa-arrow-trend-down"></i> 
                                        <span class="font-bold"><?php echo number_format($row['variance'], 2); ?></span>
                                        <span class="text-[10px] opacity-75">(<?php echo number_format($row['variance_percent'], 1); ?>%)</span>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif($selected_route): ?>
            <div class="flex-grow flex flex-col items-center justify-center text-gray-400 bg-white rounded-lg border border-dashed border-gray-300">
                <i class="fas fa-folder-open text-4xl mb-3 text-gray-300"></i>
                <p>No records found for <strong><?php echo htmlspecialchars($selected_route); ?></strong> in <?php echo $selected_year; ?>.</p>
            </div>
        <?php else: ?>
            <div class="flex-grow flex flex-col items-center justify-center text-gray-400 bg-white rounded-lg border border-dashed border-gray-300">
                <i class="fas fa-search-dollar text-4xl mb-3 text-indigo-300"></i>
                <h3 class="text-lg font-bold text-gray-600">Select a Route</h3>
                <p class="text-sm">Choose a route from the dropdown above to analyze monthly rate changes.</p>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
    <?php if(!empty($chart_data)): ?>
    const ctx = document.getElementById('rateChart').getContext('2d');
    
    // Sharp Gradient
    let gradient = ctx.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(79, 70, 229, 0.2)'); 
    gradient.addColorStop(1, 'rgba(79, 70, 229, 0.0)'); 

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo $js_labels; ?>,
            datasets: [{
                label: 'Cost Per KM (Rs)',
                data: <?php echo $js_rates; ?>,
                borderColor: '#4f46e5',
                backgroundColor: gradient,
                borderWidth: 2,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#4f46e5',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.3 // Slightly sharper curve than 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(17, 24, 39, 0.9)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 10,
                    cornerRadius: 4, // Sharper corners on tooltip
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return 'Rate: Rs. ' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false, 
                    grid: { color: '#f3f4f6' },
                    ticks: { font: { size: 11 }, color: '#64748b' },
                    title: { display: true, text: 'Rate (Rs)' }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11, weight: 'bold' }, color: '#64748b' }
                }
            }
        }
    });
    <?php endif; ?>
</script>

</body>
</html>
<?php $conn->close(); ?>