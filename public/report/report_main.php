<?php
// report_costs.php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include('../../includes/db.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { 
    header('location: ../../index.php'); 
    exit; 
}

// --- 1. PERMISSION CHECK (වැදගත්ම කොටස) ---
// Cost පෙන්වන්න ඕන කාටද? (මෙතනට ඔයාගේ Admin role එකේ නම හරියටම දාන්න)
// උදා: 'admin', 'manager', 'superadmin' වගේ
$allowed_roles = ['admin', 'manager', 'super admin', 'developer']; 

$show_cost = false;
if (isset($_SESSION['user_role'])) {
    // Role එකේ අකුරු ලොකු පොඩි වුණත් ප්‍රශ්නයක් නොවෙන්න strtolower දානවා
    if (in_array(strtolower(trim($_SESSION['user_role'])), $allowed_roles)) {
        $show_cost = true;
    }
}
// --- END PERMISSION CHECK ---


/// --- 2. FETCH PERIODS ---
$available_periods = [];
$cur_y = date("Y");
$cur_m = date("m"); 
$available_periods[] = ['value' => "$cur_y-$cur_m", 'label' => date("F Y"), 'year' => $cur_y, 'month' => $cur_m];

$sql_periods = "SELECT year, month FROM monthly_payments_sf 
                UNION SELECT year, month FROM monthly_payments_f 
                UNION SELECT year, month FROM monthly_payments_sub 
                UNION SELECT year, month FROM monthly_payments_dh 
                UNION SELECT year, month FROM monthly_payment_ne 
                UNION SELECT year, month FROM monthly_payments_nh 
                UNION SELECT year, month FROM monthly_payments_ev 
                ORDER BY year DESC, month DESC";
$result_periods = $conn->query($sql_periods);
if ($result_periods) {
    while ($row = $result_periods->fetch_assoc()) {
        $y = $row['year']; $m = str_pad($row['month'], 2, "0", STR_PAD_LEFT); $val = "$y-$m";
        $exists = false; foreach($available_periods as $ap) { if($ap['value'] === $val) $exists = true; }
        if(!$exists) { $available_periods[] = ['value' => "$y-$m", 'label' => date("F Y", mktime(0, 0, 0, (int)$m, 10, (int)$y)), 'year' => $y, 'month' => $m]; }
    }
}

// --- 3. SET PERIOD ---
$latest = !empty($available_periods) ? $available_periods[0] : ['year' => date('Y'), 'month' => date('m'), 'value' => date('Y-m')];
if (isset($_GET['period'])) {
    $parts = explode('-', $_GET['period']); $s_year = $parts[0]; $s_month = $parts[1]; $c_val = $_GET['period'];
} else {
    $s_year = $latest['year']; $s_month = $latest['month']; $c_val = $latest['value'];
}

// --- 4. CALCULATIONS (ONLY IF ADMIN) ---
$cost_sf = $cost_f = $cost_dh = $cost_ne = $cost_nh = $cost_extra = $grand_total = 0;
$display_label = date("F Y", mktime(0, 0, 0, (int)$s_month, 10, (int)$s_year));

if ($show_cost) {
    function getSum($conn, $t, $y, $m) {
        $s = $conn->prepare("SELECT SUM(monthly_payment) as total FROM $t WHERE year = ? AND month = ?");
        $s->bind_param("ss", $y, $m); $s->execute(); return $s->get_result()->fetch_assoc()['total'] ?? 0;
    }
    function getSumOp($conn, $y, $m, $p) {
        $s = $conn->prepare("SELECT SUM(monthly_payment) as total FROM monthly_payments_nh WHERE year = ? AND month = ? AND op_code LIKE ?");
        $s->bind_param("sss", $y, $m, $p); $s->execute(); return $s->get_result()->fetch_assoc()['total'] ?? 0;
    }
    $cost_sf = getSum($conn, 'monthly_payments_sf', $s_year, $s_month);
    $cost_f  = getSum($conn, 'monthly_payments_f', $s_year, $s_month) + getSum($conn, 'monthly_payments_sub', $s_year, $s_month);
    $cost_dh = getSum($conn, 'monthly_payments_dh', $s_year, $s_month);
    $cost_ne = getSum($conn, 'monthly_payment_ne', $s_year, $s_month);
    $cost_nh = getSumOp($conn, $s_year, $s_month, 'NH-%');
    $cost_extra = getSum($conn, 'monthly_payments_ev', $s_year, $s_month) + getSumOp($conn, $s_year, $s_month, 'EV-%');
    $grand_total = $cost_sf + $cost_f + $cost_dh + $cost_nh + $cost_ne + $cost_extra;
}

include('../../includes/header.php'); include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cost Summary Report</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .card-icon-bg { width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; border-radius: 12px; }
    </style>
</head>
<body class="bg-gray-100">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">System Reports</div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <form method="GET"><select name="period" class="bg-gray-800 border border-gray-600 rounded py-1 px-3 text-xs" onchange="this.form.submit()">
            <?php foreach ($available_periods as $p): ?>
                <option value="<?php echo $p['value']; ?>" <?php echo ($p['value'] == $c_val) ? 'selected' : ''; ?>><?php echo $p['label']; ?></option>
            <?php endforeach; ?>
        </select></form>
        
        <?php if ($show_cost): ?>
            <button onclick="exportExcel()" class="bg-green-600 px-3 py-1 rounded hover:bg-green-700 text-xs"><i class="fas fa-file-excel"></i> Export</button>
        <?php endif; ?>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 px-4 min-h-screen pb-24">
    
    <div class="flex border-b border-gray-200 mb-6 w-full bg-white rounded-t-lg shadow-sm">
        <a href="report_costs.php?period=<?php echo $c_val; ?>" class="flex-1 text-center py-3 border-b-2 border-blue-600 text-blue-800 font-bold bg-blue-50">
            <i class="fas fa-chart-pie mr-2"></i> Cost Summary
        </a>
        <a href="report_operations.php?period=<?php echo $c_val; ?>" class="flex-1 text-center py-3 text-gray-500 hover:text-gray-700 hover:bg-gray-50 transition">
            <i class="fas fa-file-alt mr-2"></i> Operational Reports
        </a>
    </div>

    <?php if (!$show_cost): ?>
        <div class="flex flex-col items-center justify-center py-20 bg-white rounded-lg border border-dashed border-gray-300">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                <i class="fas fa-lock text-3xl text-gray-400"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-700 mb-2">Access Restricted</h2>
            <p class="text-gray-500 text-sm mb-6">You do not have permission to view sensitive cost information.</p>
            <p class="text-xs text-gray-400">Your role: <?php echo $_SESSION['role'] ?? 'Unknown'; ?></p> <a href="report_operations.php?period=<?php echo $c_val; ?>" class="mt-4 px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 transition">
                Go to Operational Reports
            </a>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-6 gap-2 mb-6">
            <div class="bg-white rounded-xl shadow-sm border p-3 relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-2 h-full bg-blue-500"></div>
                <div class="card-icon-bg bg-blue-50 text-blue-600 mb-2"><i class="fas fa-user-tie"></i></div>
                <p class="text-xs font-bold text-gray-400 uppercase">Staff Transport</p>
                <p class="text-lg font-bold text-gray-800">Rs. <?php echo number_format($cost_sf, 2); ?></p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-3 relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-2 h-full bg-teal-500"></div>
                <div class="card-icon-bg bg-teal-50 text-teal-600 mb-2"><i class="fas fa-industry"></i></div>
                <p class="text-xs font-bold text-gray-400 uppercase">Factory (Main+Sub)</p>
                <p class="text-lg font-bold text-gray-800">Rs. <?php echo number_format($cost_f, 2); ?></p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-3 relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-2 h-full bg-yellow-500"></div>
                <div class="card-icon-bg bg-yellow-50 text-yellow-600 mb-2"><i class="fas fa-sun"></i></div>
                <p class="text-xs font-bold text-gray-400 uppercase">Day Heldup</p>
                <p class="text-lg font-bold text-gray-800">Rs. <?php echo number_format($cost_dh, 2); ?></p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-3 relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-2 h-full bg-purple-500"></div>
                <div class="card-icon-bg bg-purple-50 text-purple-600 mb-2"><i class="fas fa-moon"></i></div>
                <p class="text-xs font-bold text-gray-400 uppercase">Night Heldup</p>
                <p class="text-lg font-bold text-gray-800">Rs. <?php echo number_format($cost_nh, 2); ?></p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-3 relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-2 h-full bg-red-500"></div>
                <div class="card-icon-bg bg-red-50 text-red-600 mb-2"><i class="fas fa-ambulance"></i></div>
                <p class="text-xs font-bold text-gray-400 uppercase">Night Emergency</p>
                <p class="text-lg font-bold text-gray-800">Rs. <?php echo number_format($cost_ne, 2); ?></p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-3 relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-2 h-full bg-indigo-500"></div>
                <div class="card-icon-bg bg-indigo-50 text-indigo-600 mb-2"><i class="fas fa-plus-circle"></i></div>
                <p class="text-xs font-bold text-gray-400 uppercase">Extra / EV</p>
                <p class="text-lg font-bold text-gray-800">Rs. <?php echo number_format($cost_extra, 2); ?></p>
            </div>
        </div>

        <h3 class="text-gray-700 font-bold mb-4 text-sm uppercase"><i class="fas fa-file-invoice text-gray-400"></i> Detailed Journals</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white p-4 border rounded flex justify-between items-center shadow-sm">
                <div class="flex items-center gap-3"><i class="fas fa-user-tie text-blue-600 bg-blue-100 p-2 rounded"></i> <span class="font-bold text-sm">Staff Transport</span></div>
                <div class="flex gap-2">
                    <a href="staff_journal.php?period=<?php echo $c_val; ?>" class="text-xs bg-gray-100 px-3 py-2 rounded border">Journal</a>
                    <a href="staff_cost_report.php?period=<?php echo $c_val; ?>" class="text-xs bg-blue-50 text-blue-600 px-3 py-2 rounded border border-blue-200 font-bold">Head Cost</a>
                </div>
            </div>
            <div class="bg-white p-4 border rounded flex justify-between items-center shadow-sm">
                <div class="flex items-center gap-3"><i class="fas fa-industry text-teal-600 bg-teal-100 p-2 rounded"></i> <span class="font-bold text-sm">Factory Transport</span></div>
                <div class="flex gap-2">
                    <a href="factory_journal.php?period=<?php echo $c_val; ?>" class="text-xs bg-gray-100 px-3 py-2 rounded border">Journal</a>
                    <a href="factory_cost_report.php?period=<?php echo $c_val; ?>" class="text-xs bg-teal-50 text-teal-600 px-3 py-2 rounded border border-teal-200 font-bold">Head Cost</a>
                </div>
            </div>
            <div class="bg-white p-4 border rounded flex justify-between items-center shadow-sm">
                <div class="flex items-center gap-3"><i class="fas fa-ambulance text-red-600 bg-red-100 p-2 rounded"></i> <span class="font-bold text-sm">Night Emergency</span></div>
                <a href="ne_journal.php?period=<?php echo $c_val; ?>" class="text-xs bg-gray-100 px-3 py-2 rounded border">Journal</a>
            </div>
            <div class="bg-white p-4 border rounded flex justify-between items-center shadow-sm">
                <div class="flex items-center gap-3"><i class="fas fa-sun text-yellow-600 bg-yellow-100 p-2 rounded"></i> <span class="font-bold text-sm">Day Heldup</span></div>
                <a href="download_dh_cost_breakdown.php?month=<?php echo $s_month; ?>&year=<?php echo $s_year; ?>" class="text-xs bg-gray-100 px-3 py-2 text-yellow-600 font-bold rounded border hover:bg-yellow-50 transition" target="_blank"><i class="fas fa-file-excel mr-1"></i> Cost Breakdown</a>
            </div>
            <div class="bg-white p-4 border rounded flex justify-between items-center shadow-sm">
                <div class="flex items-center gap-3"><i class="fas fa-moon text-purple-600 bg-purple-100 p-2 rounded"></i> <span class="font-bold text-sm">Night Heldup</span></div>
                <a href="download_nh_cost_breakdown.php?month=<?php echo $s_month; ?>&year=<?php echo $s_year; ?>" class="text-xs bg-gray-100 px-3 py-2 text-purple-600 font-bold rounded border hover:bg-purple-50 transition" target="_blank"><i class="fas fa-file-excel mr-1"></i> Cost Breakdown</a>
            </div>
            <div class="bg-white p-4 border rounded flex justify-between items-center shadow-sm">
                <div class="flex items-center gap-3"><i class="fas fa-plus-circle text-indigo-600 bg-indigo-100 p-2 rounded"></i><span class="font-bold text-sm">Extra Vehicle</span></div>
                <a href="download_ev_cost_breakdown.php?month=<?php echo $s_month; ?>&year=<?php echo $s_year; ?>" class="text-xs bg-gray-100 px-3 py-2 text-indigo-600 font-bold rounded border hover:bg-indigo-50 transition" target="_blank"><i class="fas fa-file-excel mr-1"></i> Cost Breakdown</a>
            </div>
        </div>
        <?php endif; ?>
</div>

<?php if ($show_cost): ?>
<div class="fixed bottom-0 right-0 w-[85%] bg-white/90 backdrop-blur border-t p-3 shadow flex justify-between items-center px-8 z-40">
    <div class="text-xs font-bold text-gray-500 uppercase">Total Cost: <span class="text-blue-600"><?php echo $display_label; ?></span></div>
    <div class="text-2xl font-black text-gray-900">LKR <?php echo number_format($grand_total, 2); ?></div>
</div>

<table id="exportTable" class="hidden">
    <thead><tr><th>Category</th><th>Total</th></tr></thead>
    <tbody>
        <tr><td>Staff</td><td><?php echo $cost_sf; ?></td></tr>
        <tr><td>Factory</td><td><?php echo $cost_f; ?></td></tr>
        <tr><td>Day Heldup</td><td><?php echo $cost_dh; ?></td></tr>
        <tr><td>Night Heldup</td><td><?php echo $cost_nh; ?></td></tr>
        <tr><td>Night Emergency</td><td><?php echo $cost_ne; ?></td></tr>
        <tr><td>Extra</td><td><?php echo $cost_extra; ?></td></tr>
        <tr><td><strong>TOTAL</strong></td><td><strong><?php echo $grand_total; ?></strong></td></tr>
    </tbody>
</table>

<script>
function exportExcel() {
    var wb = XLSX.utils.table_to_book(document.getElementById("exportTable"), {sheet: "Summary"});
    XLSX.writeFile(wb, "Cost_Report.xlsx");
}
</script>
<?php endif; ?>

</body>
</html>