<?php
// report_main.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('../../includes/db.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('location: ../../index.php');
    exit;
}

// --- 1. FETCH AVAILABLE PERIODS FROM DATABASE ---
// Tables 7nma data thiyena unique Year/Month combinations gannawa.
// CHANGE 1: Added monthly_payments_sub to the period selection list
$sql_periods = "
    SELECT year, month FROM monthly_payments_sf
    UNION SELECT year, month FROM monthly_payments_f
    UNION SELECT year, month FROM monthly_payments_sub
    UNION SELECT year, month FROM monthly_payments_dh
    UNION SELECT year, month FROM monthly_payment_ne
    UNION SELECT year, month FROM monthly_payments_nh
    UNION SELECT year, month FROM monthly_payments_ev
    ORDER BY year DESC, month DESC
";

$result_periods = $conn->query($sql_periods);
$available_periods = [];

if ($result_periods) {
    while ($row = $result_periods->fetch_assoc()) {
        $y = $row['year'];
        $m = str_pad($row['month'], 2, "0", STR_PAD_LEFT);
        $available_periods[] = [
            'value' => "$y-$m",
            'label' => date("F Y", mktime(0, 0, 0, (int)$m, 10, (int)$y)),
            'year' => $y,
            'month' => $m
        ];
    }
}

// --- 2. SET SELECTED PERIOD ---
$latest_period = !empty($available_periods) ? $available_periods[0] : ['year' => date('Y'), 'month' => date('m'), 'value' => date('Y-m')];

if (isset($_GET['period'])) {
    $parts = explode('-', $_GET['period']);
    $selected_year = $parts[0];
    $selected_month = $parts[1];
    $current_val = $_GET['period'];
} else {
    $selected_year = $latest_period['year'];
    $selected_month = $latest_period['month'];
    $current_val = $latest_period['value'];
}

// --- 3. HELPER FUNCTIONS ---
function getSum($conn, $table, $year, $month) {
    $sql = "SELECT SUM(monthly_payment) as total FROM $table WHERE year = ? AND month = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $year, $month); 
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'] ?? 0;
}

function getSumWithOpCode($conn, $year, $month, $pattern) {
    $sql = "SELECT SUM(monthly_payment) as total FROM monthly_payments_nh WHERE year = ? AND month = ? AND op_code LIKE ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $year, $month, $pattern);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'] ?? 0;
}

// --- 4. CALCULATIONS ---

$cost_sf = getSum($conn, 'monthly_payments_sf', $selected_year, $selected_month);

// CHANGE 2: Added monthly_payments_sub to the Factory Cost calculation
$cost_f  = getSum($conn, 'monthly_payments_f', $selected_year, $selected_month) 
         + getSum($conn, 'monthly_payments_sub', $selected_year, $selected_month);

$cost_dh = getSum($conn, 'monthly_payments_dh', $selected_year, $selected_month);
$cost_ne = getSum($conn, 'monthly_payment_ne', $selected_year, $selected_month);
$cost_nh = getSumWithOpCode($conn, $selected_year, $selected_month, 'NH-%');

$cost_extra = getSum($conn, 'monthly_payments_ev', $selected_year, $selected_month) 
            + getSumWithOpCode($conn, $selected_year, $selected_month, 'EV-%');

$grand_total = $cost_sf + $cost_f + $cost_dh + $cost_nh + $cost_ne + $cost_extra;

// Label for display
$display_label = date("F Y", mktime(0, 0, 0, (int)$selected_month, 10, (int)$selected_year));

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transport Cost Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <style>
        .fixed-top-bar { position: fixed; top: 0; right: 0; width: 85%; z-index: 50; height: 50px; }
        .content-offset { margin-top: 60px; }
        body { padding-bottom: 70px; background-color: #f3f4f6; }
        .card-icon-bg { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
    </style>
</head>
<body class="font-sans antialiased text-gray-800">

    <div class="fixed-top-bar bg-gray-800 text-white shadow-md flex justify-between items-center px-6">
        <div class="flex items-center gap-3">
            <i class="fas fa-chart-pie text-yellow-400"></i>
            <span class="font-semibold text-sm tracking-wide uppercase">Cost Summary</span>
        </div>
        
        <form method="GET" class="flex items-center gap-2">
            
            <div class="relative">
                <select name="period" class="appearance-none bg-gray-700 border border-gray-600 text-white text-xs rounded pl-3 pr-8 py-1.5 focus:outline-none focus:border-yellow-500 cursor-pointer" onchange="this.form.submit()">
                    <?php if (empty($available_periods)): ?>
                        <option value="">No Data Found</option>
                    <?php else: ?>
                        <?php foreach ($available_periods as $p): ?>
                            <option value="<?php echo $p['value']; ?>" <?php echo ($p['value'] == $current_val) ? 'selected' : ''; ?>>
                                <?php echo $p['label']; ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-400">
                    <i class="fas fa-chevron-down text-[10px]"></i>
                </div>
            </div>

        </form>
    </div>

    <div class="w-[85%] ml-[15%] px-6 content-offset">
        
        <?php if (empty($available_periods)): ?>
            <div class="flex flex-col items-center justify-center h-64 text-gray-400">
                <i class="fas fa-database text-4xl mb-3"></i>
                <p>No transaction data found in the system.</p>
            </div>
        <?php else: ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            
            <div class="bg-white rounded-lg shadow-sm border-l-4 border-blue-500 p-3 flex justify-between items-center hover:shadow-md transition">
                <div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Staff Transport</p>
                    <p class="text-lg font-bold text-gray-800 mt-0.5">Rs. <?php echo number_format($cost_sf, 2); ?></p>
                </div>
                <div class="card-icon-bg bg-blue-50 text-blue-500">
                    <i class="fas fa-bus"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border-l-4 border-teal-500 p-3 flex justify-between items-center hover:shadow-md transition">
                <div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Factory Transport</p>
                    <p class="text-[9px] text-gray-400">(Main + Sub)</p> <p class="text-lg font-bold text-gray-800 mt-0.5">Rs. <?php echo number_format($cost_f, 2); ?></p>
                </div>
                <div class="card-icon-bg bg-teal-50 text-teal-500">
                    <i class="fas fa-industry"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border-l-4 border-yellow-500 p-3 flex justify-between items-center hover:shadow-md transition">
                <div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Day Heldup</p>
                    <p class="text-lg font-bold text-gray-800 mt-0.5">Rs. <?php echo number_format($cost_dh, 2); ?></p>
                </div>
                <div class="card-icon-bg bg-yellow-50 text-yellow-500">
                    <i class="fas fa-sun"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border-l-4 border-purple-500 p-3 flex justify-between items-center hover:shadow-md transition">
                <div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Night Heldup</p>
                    <p class="text-[9px] text-gray-300">(NH Codes)</p>
                    <p class="text-lg font-bold text-gray-800 mt-0.5">Rs. <?php echo number_format($cost_nh, 2); ?></p>
                </div>
                <div class="card-icon-bg bg-purple-50 text-purple-500">
                    <i class="fas fa-moon"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border-l-4 border-red-500 p-3 flex justify-between items-center hover:shadow-md transition">
                <div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Night Emergency</p>
                    <p class="text-lg font-bold text-gray-800 mt-0.5">Rs. <?php echo number_format($cost_ne, 2); ?></p>
                </div>
                <div class="card-icon-bg bg-red-50 text-red-500">
                    <i class="fas fa-ambulance"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border-l-4 border-indigo-500 p-3 flex justify-between items-center hover:shadow-md transition">
                <div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Extra / EV</p>
                    <p class="text-[9px] text-gray-300">(EV Table + EV Codes)</p>
                    <p class="text-lg font-bold text-gray-800 mt-0.5">Rs. <?php echo number_format($cost_extra, 2); ?></p>
                </div>
                <div class="card-icon-bg bg-indigo-50 text-indigo-500">
                    <i class="fas fa-plus-circle"></i>
                </div>
            </div>
        </div>
        
        <?php endif; ?>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-blue-100 rounded text-blue-600">
                    <i class="fas fa-users-cog text-lg"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-gray-800">Staff Transport Journal</h3>
                </div>
            </div>
            
            <div class="flex gap-3 w-full md:w-auto">
                <button onclick="location.href='staff_journal.php?period=<?php echo $current_val; ?>'" 
                class="flex-1 md:flex-none bg-white border border-blue-500 text-blue-600 hover:bg-blue-50 text-xs font-bold py-2 px-4 rounded shadow-sm transition flex items-center justify-center gap-2">
                    <i class="fas fa-file-invoice-dollar"></i> Download Journal
                </button>

                <button onclick="location.href='staff_cost_report.php?period=<?php echo $current_val; ?>'" 
                        class="flex-1 md:flex-none bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold py-2 px-4 rounded shadow-sm transition flex items-center justify-center gap-2">
                    <i class="fas fa-list-alt"></i> Cost Breakdown
                </button>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 flex flex-col md:flex-row items-center justify-between gap-3 mt-2">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-teal-100 rounded text-teal-600">
                    <i class="fas fa-industry text-lg"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-gray-800">Factory Transport Journal</h3>
                </div>
            </div>
            
            <div class="flex gap-3 w-full md:w-auto">
                <button onclick="location.href='factory_journal.php?period=<?php echo $current_val; ?>'" 
                        class="flex-1 md:flex-none bg-white border border-teal-500 text-teal-600 hover:bg-teal-50 text-xs font-bold py-2 px-4 rounded shadow-sm transition flex items-center justify-center gap-2">
                    <i class="fas fa-file-invoice-dollar"></i> Download Journal
                </button>
                
                <button onclick="location.href='factory_cost_report.php?period=<?php echo $current_val; ?>'" 
                        class="flex-1 md:flex-none bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold py-2 px-4 rounded shadow-sm transition flex items-center justify-center gap-2">
                    <i class="fas fa-list-alt"></i> Cost Breakdown
                </button>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 flex flex-col md:flex-row items-center justify-between gap-3 mt-2">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-teal-100 rounded text-teal-600">
                    <i class="fas fa-industry text-lg"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-gray-800">Night Emergency Journal</h3>
                </div>
            </div>
            
            <div class="flex gap-3 w-full md:w-auto">
                <button onclick="location.href='ne_journal.php?period=<?php echo $current_val; ?>'" 
                        class="flex-1 md:flex-none bg-white border border-teal-500 text-teal-600 hover:bg-teal-50 text-xs font-bold py-2 px-4 rounded shadow-sm transition flex items-center justify-center gap-2">
                    <i class="fas fa-file-invoice-dollar"></i> Download Journal
                </button>
            </div>
        </div>

    </div>

    <div class="fixed bottom-0 right-0 w-[85%] bg-white/95 backdrop-blur-sm border-t border-gray-300 p-2 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.1)] flex justify-between items-center px-6 z-50">
        <div class="text-xs font-bold text-gray-500">
            Total for <span class="text-blue-600"><?php echo $display_label; ?></span> : 
            <span class="text-gray-800 text-sm ml-1">LKR <?php echo number_format($grand_total, 2); ?></span>
        </div>
        
        <button onclick="exportSummaryToExcel()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-1.5 rounded shadow text-xs font-bold flex items-center gap-2 transition transform hover:-translate-y-0.5">
            <i class="fas fa-file-excel"></i> Download Excel
        </button>
    </div>

    <table id="exportTable" class="hidden">
        <thead>
            <tr>
                <th>Category</th>
                <th>Period</th>
                <th>Total Cost (LKR)</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Staff Transport</td><td><?php echo $display_label; ?></td><td><?php echo $cost_sf; ?></td></tr>
            <tr><td>Factory Transport (Main + Sub)</td><td><?php echo $display_label; ?></td><td><?php echo $cost_f; ?></td></tr>
            <tr><td>Day Heldup</td><td><?php echo $display_label; ?></td><td><?php echo $cost_dh; ?></td></tr>
            <tr><td>Night Heldup (NH)</td><td><?php echo $display_label; ?></td><td><?php echo $cost_nh; ?></td></tr>
            <tr><td>Night Emergency</td><td><?php echo $display_label; ?></td><td><?php echo $cost_ne; ?></td></tr>
            <tr><td>Extra Transport (EV)</td><td><?php echo $display_label; ?></td><td><?php echo $cost_extra; ?></td></tr>
            <tr><td><strong>GRAND TOTAL</strong></td><td></td><td><strong><?php echo $grand_total; ?></strong></td></tr>
        </tbody>
    </table>

    <script>
        function exportSummaryToExcel() {
            var table = document.getElementById("exportTable");
            var wb = XLSX.utils.table_to_book(table, {sheet: "Summary"});
            var fileName = "Transport_Summary_<?php echo $selected_year . '_' . $selected_month; ?>.xlsx";
            XLSX.writeFile(wb, fileName);
        }
    </script>

</body>
</html>