<?php
// report_operations.php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
include('../../includes/db.php');
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { header('location: ../../index.php'); exit; }

// --- 1. CHECK PERMISSIONS (ROLE BASED ACCESS) ---
// මෙම වාර්තා බැලිය හැකි අයගේ ලැයිස්තුව
$allowed_roles = ['admin', 'super admin', 'manager', 'developer'];
$has_full_access = false;

if (isset($_SESSION['user_role'])) {
    // Role එකේ අකුරු කුඩා කර (lowercase) සහ අනවශ්‍ය හිස්තැන් ඉවත් කර පරීක්ෂා කිරීම
    $current_role = strtolower(trim($_SESSION['user_role']));
    
    if (in_array($current_role, $allowed_roles)) {
        $has_full_access = true;
    }
}

// --- 2. FETCH PERIODS ---
$sql_periods = "SELECT year, month FROM monthly_payments_sf UNION SELECT year, month FROM monthly_payments_f ORDER BY year DESC, month DESC";
$result_periods = $conn->query($sql_periods);
$available_periods = [];
if ($result_periods) {
    while ($row = $result_periods->fetch_assoc()) {
        $available_periods[] = ['value' => $row['year'].'-'.str_pad($row['month'], 2, "0", STR_PAD_LEFT), 'label' => date("F Y", mktime(0, 0, 0, (int)$row['month'], 10, (int)$row['year']))];
    }
}
$c_val = isset($_GET['period']) ? $_GET['period'] : ($available_periods[0]['value'] ?? date('Y-m'));

include('../../includes/header.php'); include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Operational Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }</style>
</head>
<body class="bg-gray-100">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">System Reports</div>
</div>

<div class="w-[85%] ml-[15%] pt-20 px-4 min-h-screen">
    
    <div class="flex border-b border-gray-200 mb-8 w-full bg-white rounded-t-lg shadow-sm">
        <a href="report_main.php?period=<?php echo $c_val; ?>" class="flex-1 text-center py-3 text-gray-500 hover:text-gray-700 hover:bg-gray-50 transition">
            <i class="fas fa-chart-pie mr-2"></i> Cost Summary
        </a>
        <a href="report_operations.php?period=<?php echo $c_val; ?>" class="flex-1 text-center py-3 border-b-2 border-blue-600 text-blue-800 font-bold bg-blue-50">
            <i class="fas fa-file-alt mr-2"></i> Operational Reports
        </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        
        <a href="seating_capacity_report.php" class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 hover:shadow-lg transition transform hover:-translate-y-1 block group">
            <div class="flex justify-between items-start">
                <div class="bg-orange-100 text-orange-600 w-12 h-12 rounded-lg flex items-center justify-center mb-4 group-hover:bg-orange-600 group-hover:text-white transition">
                    <i class="fas fa-chair text-xl"></i>
                </div>
                <i class="fas fa-arrow-right text-gray-300 group-hover:text-orange-500 transition"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-800">Seating Capacity</h3>
            <p class="text-sm text-gray-500 mt-2">Analyze vehicle seating allocation and utilization.</p>
        </a>

        <a href="sub_seating_capacity_report.php" class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 hover:shadow-lg transition transform hover:-translate-y-1 block group">
            <div class="flex justify-between items-start">
                <div class="bg-teal-100 text-teal-600 w-12 h-12 rounded-lg flex items-center justify-center mb-4 group-hover:bg-teal-600 group-hover:text-white transition">
                    <i class="fas fa-shuttle-van text-xl"></i>
                </div>
                <i class="fas fa-arrow-right text-gray-300 group-hover:text-teal-500 transition"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-800">Sub Routes Capacity</h3>
            <p class="text-sm text-gray-500 mt-2">
                Analyze sub-route efficiency, seat utilization percentages.
            </p>
        </a>

        <?php if ($has_full_access): ?>

            <a href="route_rate.php" class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 hover:shadow-lg transition transform hover:-translate-y-1 block group">
                <div class="flex justify-between items-start">
                    <div class="bg-green-100 text-green-600 w-12 h-12 rounded-lg flex items-center justify-center mb-4 group-hover:bg-green-600 group-hover:text-white transition">
                        <i class="fas fa-chart-line text-xl"></i>
                    </div>
                    <i class="fas fa-arrow-right text-gray-300 group-hover:text-green-500 transition"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-800">Route Rate Analysis</h3>
                <p class="text-sm text-gray-500 mt-2">Track historical route rate changes and cost trends.</p>
            </a>

            <a href="sub_route_analysis.php" class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 hover:shadow-lg transition transform hover:-translate-y-1 block group">
                <div class="flex justify-between items-start">
                    <div class="bg-purple-100 text-purple-600 w-12 h-12 rounded-lg flex items-center justify-center mb-4 group-hover:bg-purple-600 group-hover:text-white transition">
                        <i class="fas fa-route text-xl"></i>
                    </div>
                    <i class="fas fa-arrow-right text-gray-300 group-hover:text-purple-500 transition"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-800">Sub Route Analysis</h3>
                <p class="text-sm text-gray-500 mt-2">Analyze individual route segments and distance breakdowns.</p>
            </a>

            <a href="employee_distance_report.php" class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 hover:shadow-lg transition transform hover:-translate-y-1 block group">
                <div class="flex justify-between items-start">
                    <div class="bg-indigo-100 text-indigo-600 w-12 h-12 rounded-lg flex items-center justify-center mb-4 group-hover:bg-indigo-600 group-hover:text-white transition">
                        <i class="fas fa-th text-xl"></i>
                    </div>
                    <i class="fas fa-arrow-right text-gray-300 group-hover:text-indigo-500 transition"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-800">Employee Distance Analysis</h3>
                <p class="text-sm text-gray-500 mt-2">
                    A matrix view of employee concentration across distance zones and categories.
                </p>
            </a>

            <a href="day_heldup_report.php" class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 hover:shadow-lg transition transform hover:-translate-y-1 block group">
                <div class="flex justify-between items-start">
                    <div class="bg-yellow-100 text-yellow-600 w-12 h-12 rounded-lg flex items-center justify-center mb-4 group-hover:bg-yellow-600 group-hover:text-white transition">
                        <i class="fas fa-sun text-xl"></i>
                    </div>
                    <i class="fas fa-arrow-right text-gray-300 group-hover:text-yellow-500 transition"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-800">Day Heldup Analysis</h3>
                <p class="text-sm text-gray-500 mt-2">
                    Detailed cost breakdown of daily delays and operational holds.
                </p>
            </a>

            <a href="night_heldup_report.php" class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 hover:shadow-lg transition transform hover:-translate-y-1 block group">
                <div class="flex justify-between items-start">
                    <div class="bg-purple-100 text-purple-600 w-12 h-12 rounded-lg flex items-center justify-center mb-4 group-hover:bg-purple-600 group-hover:text-white transition">
                        <i class="fas fa-moon text-xl"></i>
                    </div>
                    <i class="fas fa-arrow-right text-gray-300 group-hover:text-purple-500 transition"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-800">Night Heldup Analysis</h3>
                <p class="text-sm text-gray-500 mt-2">
                    Detailed cost breakdown of nightly delays and operational holds.
                </p>
            </a>

            <a href="ev_cost_analysis.php" class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 hover:shadow-lg transition transform hover:-translate-y-1 block group">
                <div class="flex justify-between items-start">
                    <div class="bg-indigo-100 text-indigo-600 w-12 h-12 rounded-lg flex items-center justify-center mb-4 group-hover:bg-indigo-600 group-hover:text-white transition">
                        <i class="fas fa-shuttle-van text-xl"></i>
                    </div>
                    <i class="fas fa-arrow-right text-gray-300 group-hover:text-indigo-500 transition"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-800">Extra Vehicle Cost Analysis</h3>
                <p class="text-sm text-gray-500 mt-2">
                    Detailed cost breakdown of Extra Vehicle usage by employee and department.
                </p>
            </a>

        <?php endif; // End restriction check ?>
        
    </div>
</div>
</body>
</html>