<?php
// nh_payments.php - Night Heldup Payments (Sticky Header Updated)

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');

// =======================================================================
// 1. DYNAMIC DATE FILTER LOGIC
// =======================================================================

// A. Fetch Max Month and Year from NIGHT HELDUP history table
$max_payments_sql = "SELECT year as max_year, month as max_month FROM monthly_payments_nh ORDER BY year DESC, month DESC LIMIT 1";
$max_payments_result = $conn->query($max_payments_sql);

$db_max_month = 0;
$db_max_year = 0;

if ($max_payments_result && $max_payments_result->num_rows > 0) {
    $max_data = $max_payments_result->fetch_assoc();
    $db_max_month = (int)($max_data['max_month'] ?? 0);
    $db_max_year = (int)($max_data['max_year'] ?? 0);
}

// B. Calculate the LIMIT point
$start_month = 0;
$start_year = 0;

if ($db_max_month === 0 && $db_max_year === 0) {
    $start_month = 1;
    $start_year = 0; 
} elseif ($db_max_month == 12) {
    $start_month = 1;        
    $start_year = $db_max_year + 1; 
} else {
    $start_month = $db_max_month + 1;
    $start_year = $db_max_year;
}

// C. Determine the CURRENT (ENDING) point
$current_month_sys = (int)date('n');
$current_year_sys = (int)date('Y');


// =======================================================================
// 2. HELPER FUNCTIONS & SELECTION LOGIC
// =======================================================================

$selected_month = $current_month_sys;
$selected_year = $current_year_sys;

// Check if 'month_year' is passed
if (isset($_GET['month_year']) && !empty($_GET['month_year'])) {
    $parts = explode('-', $_GET['month_year']);
    if (count($parts) == 2) {
        $selected_year = (int)$parts[0];
        $selected_month = (int)$parts[1];
    }
} elseif (isset($_GET['month_num']) && isset($_GET['year'])) {
    $selected_month = (int)$_GET['month_num'];
    $selected_year = (int)$_GET['year'];
}

// --- CORE CALCULATION FUNCTION ---
function calculate_night_heldup_payments($conn, $month, $year) {
    $sql = "
        SELECT 
            nh.op_code,
            IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date) as effective_date,
            SUM(nh.distance) AS total_daily_distance,
            COUNT(nh.id) AS daily_trips,
            MAX(nh.vehicle_no) AS vehicle_no,
            os.slab_limit_distance,
            os.extra_rate AS rate_per_km
        FROM 
            nh_register nh
        JOIN 
            op_services os ON nh.op_code = os.op_code
        WHERE 
            nh.done = 1 
            AND DATE_FORMAT(IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date), '%Y-%m') = ?
        GROUP BY 
            nh.op_code, effective_date
        ORDER BY 
            nh.op_code ASC, effective_date ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    
    $filter_month_year = sprintf("%d-%02d", $year, $month);
    
    $stmt->bind_param("s", $filter_month_year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $monthly_summary = [];

    while ($row = $result->fetch_assoc()) {
        $op_code = $row['op_code'];
        $vehicle_no = $row['vehicle_no'];
        
        $daily_distance = (float)$row['total_daily_distance'];
        $slab_limit = (float)$row['slab_limit_distance'];
        $rate = (float)$row['rate_per_km'];
        
        $payable_distance = 0;

        if (strpos($op_code, 'NH') === 0) {
            $payable_distance = max($daily_distance, $slab_limit);
        } 
        elseif (strpos($op_code, 'EV') === 0) {
            $payable_distance = $daily_distance;
        } 
        else {
            $payable_distance = $daily_distance;
        }

        $payment_amount = $payable_distance * $rate;

        if (!isset($monthly_summary[$op_code])) {
            $monthly_summary[$op_code] = [
                'op_code' => $op_code,
                'vehicle_no' => $vehicle_no,
                'total_days' => 0,
                'total_actual_distance' => 0.00,
                'total_payment' => 0.00,
                'total_trips' => 0
            ];
        }

        $monthly_summary[$op_code]['total_days']++;
        $monthly_summary[$op_code]['total_actual_distance'] += $daily_distance;
        $monthly_summary[$op_code]['total_payment'] += $payment_amount;
        $monthly_summary[$op_code]['total_trips'] += $row['daily_trips'];
    }
    
    $stmt->close();
    return $monthly_summary;
}

// Execute Calculation
$night_payment_records = calculate_night_heldup_payments($conn, $selected_month, $selected_year);
$page_title = "Night Heldup Payments";

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Dropdown Menu Styles */
        .dropdown-menu { display: none; position: absolute; right: 0; top: 120%; z-index: 50; min-width: 220px; background-color: white; border: 1px solid #e5e7eb; border-radius: 0.75rem; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15); overflow: hidden; animation: slideDown 0.2s ease-out; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .dropdown-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: #374151; font-size: 0.875rem; transition: background-color 0.15s; }
        .dropdown-item:hover { background-color: #f3f4f6; color: #111827; }
    </style>
    
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 
        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>

<body class="bg-gray-100">

<div id="pageLoader" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-gray-900 bg-opacity-90">
    <div class="flex flex-col items-center gap-4">
        <div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-yellow-400"></div>
        <p class="text-gray-300 text-sm tracking-wide">Loading...</p>
    </div>
</div>

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Night Heldup Payments
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <form method="get" action="nh_payments.php" class="flex items-center">
            <div class="relative">
                <select name="month_year" onchange="this.form.submit()" 
                        class="appearance-none bg-gray-800 text-white border border-gray-600 rounded-md py-1.5 pl-3 pr-8 text-xs focus:outline-none focus:ring-1 focus:ring-yellow-500 cursor-pointer hover:bg-gray-700 transition font-mono">
                    <?php 
                    $loop_curr_year = $current_year_sys;
                    $loop_curr_month = $current_month_sys;
                    $limit_year = ($start_year > 0) ? $start_year : $current_year_sys - 2;
                    $limit_month = ($start_year > 0) ? $start_month : 1;

                    while (true) {
                        if ($loop_curr_year < $limit_year) break;
                        if ($loop_curr_year == $limit_year && $loop_curr_month < $limit_month) break;

                        $val = sprintf('%04d-%02d', $loop_curr_year, $loop_curr_month);
                        $lbl = date('F Y', mktime(0, 0, 0, $loop_curr_month, 10, $loop_curr_year));
                        $sel = ($selected_year == $loop_curr_year && $selected_month == $loop_curr_month) ? 'selected' : '';
                        echo "<option value='$val' $sel>$lbl</option>";

                        $loop_curr_month--;
                        if ($loop_curr_month == 0) { $loop_curr_month = 12; $loop_curr_year--; }
                    }
                    ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-400">
                    <i class="fas fa-chevron-down text-[10px]"></i>
                </div>
            </div>
        </form>

        <span class="text-gray-600 text-lg font-thin">|</span>

        <a href="download_nh_payments_excel.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
           class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide no-loader">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        
        <a href="nh_done.php" class="flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-check-circle"></i> Done
        </a>

        <a href="nh_history.php" class="flex items-center gap-2 bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-history"></i> History
        </a>

        <span class="text-gray-600 text-lg font-thin">|</span>

        <div class="relative">
            <button id="menuBtn" class="flex items-center gap-2 text-gray-300 hover:text-white transition focus:outline-none text-xs uppercase tracking-wide font-bold bg-gray-800 hover:bg-gray-700 px-3 py-1.5 rounded-md border border-gray-600">
                <i class="fas fa-layer-group"></i> Categories <i class="fas fa-chevron-down text-[10px] ml-1"></i>
            </button>
            <div id="dropdownMenu" class="dropdown-menu">
                <div class="py-1">
                    <a href="../all_payments_summary.php" class="dropdown-item font-bold"><i class="fas fa-chart-pie w-5 text-gray-500"></i> Summary</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="../payments_category.php" class="dropdown-item"><i class="fas fa-user-tie w-5 text-blue-500"></i> Staff</a>
                    <a href="../factory/factory_route_payments.php" class="dropdown-item"><i class="fas fa-industry w-5 text-indigo-500"></i> Factory</a>
                    <a href="../factory/sub/sub_route_payments.php" class="dropdown-item"><i class="fas fa-project-diagram w-5 text-indigo-500"></i> Sub Route</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="../DH/day_heldup_payments.php" class="dropdown-item"><i class="fas fa-sun w-5 text-orange-500"></i> Day Heldup</a>
                    <a href="../night_emergency_payment.php" class="dropdown-item"><i class="fas fa-ambulance w-5 text-red-500"></i> Night Emergency</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="../EV/ev_payments.php" class="dropdown-item"><i class="fas fa-car-side w-5 text-green-500"></i> Extra Vehicle</a>
                    <a href="../own_vehicle_payments.php" class="dropdown-item"><i class="fas fa-gas-pump w-5 text-yellow-500"></i> Fuel Allowance</a>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="flex flex-col items-center mt-2 w-[85%] ml-[15%] p-2">
    
    <div class="w-full">
        
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto max-h-[87vh] overflow-y-auto relative">
                <table class="min-w-full text-sm text-left">
                    <thead class="bg-blue-600 text-white uppercase text-xs tracking-wider sticky top-0 z-10">
                        <tr>
                            <th class="py-3 px-6 text-left font-semibold border-b border-blue-500">Op Code (Vehicle)</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Days Paid</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Total Distance (km)</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Total Payment (LKR)</th>
                            <th class="py-3 px-6 text-center font-semibold border-b border-blue-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!empty($night_payment_records)): ?>
                            <?php 
                            $display_records = array_values($night_payment_records);
                            ?>
                            <?php foreach ($display_records as $data): ?>
                                <tr class="hover:bg-indigo-50 transition duration-150 group">
                                    <td class="py-3 px-6 whitespace-nowrap font-medium text-left text-gray-800">
                                        <?php echo htmlspecialchars($data['op_code'] . ' (' . $data['vehicle_no'] . ')'); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right text-gray-700">
                                        <?php echo number_format($data['total_days']); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right font-mono text-purple-600">
                                        <?php echo number_format($data['total_actual_distance'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right text-blue-700 text-base font-extrabold">
                                        <?php echo number_format($data['total_payment'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-center space-x-2">
                                        
                                        <a href="nh_daily_details.php?op_code=<?php echo htmlspecialchars($data['op_code']); ?>&month_num=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
                                           class="bg-blue-50 text-blue-600 hover:text-blue-800 hover:bg-blue-100 p-2 rounded-lg transition-colors inline-block"
                                           title="View Daily Breakdown">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <a href="download_nh_pdf.php?op_code=<?php echo htmlspecialchars($data['op_code']); ?>&month_num=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>"
                                           class="bg-red-50 text-red-500 hover:text-red-700 hover:bg-red-100 p-2 rounded-lg transition-colors inline-block no-loader"
                                           title="Download Monthly Summary PDF" target="_blank">
                                            <i class="fas fa-file-pdf"></i> 
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="py-4 text-center text-gray-500 text-base font-medium">
                                    No Night Heldup payment data available for <?php echo date('F', mktime(0, 0, 0, $selected_month, 1)) . ", " . $selected_year; ?>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
    // --- JS for Click-to-Toggle Menu ---
    document.addEventListener('DOMContentLoaded', function() {
        const menuBtn = document.getElementById('menuBtn');
        const dropdownMenu = document.getElementById('dropdownMenu');

        // Toggle on click
        menuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (dropdownMenu.style.display === 'block') {
                dropdownMenu.style.display = 'none';
            } else {
                dropdownMenu.style.display = 'block';
            }
        });

        // Close on click outside
        document.addEventListener('click', function(e) {
            if (!menuBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
                dropdownMenu.style.display = 'none';
            }
        });
    });

    const loader = document.getElementById("pageLoader");

    function showLoader(text = "Loading factory payments…") {
        loader.querySelector("p").innerText = text;
        loader.classList.remove("hidden");
        loader.classList.add("flex");
    }

    // 🔹 All normal links
    document.querySelectorAll("a").forEach(link => {
        link.addEventListener("click", function () {
            if (link.target !== "_blank" && !link.classList.contains("no-loader")) {
                showLoader("Loading page…");
            }
        });
    });

    // 🔹 All forms (including month filter form)
    document.querySelectorAll("form").forEach(form => {
        form.addEventListener("submit", function () {
            showLoader("Applying filter…");
        });
    });

    // 🔹 Month-Year dropdown (important for onchange submit)
    const monthSelect = document.querySelector("select[name='month_year']");
    if (monthSelect) {
        monthSelect.addEventListener("change", function () {
            showLoader("Loading selected month…");
        });
    }
</script>

</body>
</html>