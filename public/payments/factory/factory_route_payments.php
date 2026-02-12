<?php
// factory_route_payments.php (Factory Monthly Payments)
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
//    (Uses monthly_payments_f ONLY to find where the history ends)
// =======================================================================
$current_month_sys = (int)date('n');
$current_year_sys = (int)date('Y');
// A. Fetch Max Month and Year from FACTORY history table
$max_payments_sql = "SELECT MAX(month) AS max_month, MAX(year) AS max_year FROM monthly_payments_f";
$max_payments_result = $conn->query($max_payments_sql);

$db_max_month = 0;
$db_max_year = 0;

if ($max_payments_result && $max_payments_result->num_rows > 0) {
    $max_data = $max_payments_result->fetch_assoc();
    $db_max_month = (int)($max_data['max_month'] ?? 0);
    $db_max_year = (int)($max_data['max_year'] ?? 0);
}

// B. Calculate the LIMIT point (The month AFTER the last payment)
$start_month = 0;
$start_year = 0;

// Limit එක තීරණය කිරීම (අවසාන මාසය + 1)
if ($db_max_month == 0) {
    $limit_month = 1;
    $limit_year = $current_year_sys - 1;
} elseif ($db_max_month == 12) {
    $limit_month = 1;
    $limit_year = $db_max_year + 1;
} else {
    $limit_month = $db_max_month + 1;
    $limit_year = $db_max_year;
}

// Limit එක වත්මන් මාසයට වඩා වැඩි විය නොහැක
if (($limit_year > $current_year_sys) || ($limit_year == $current_year_sys && $limit_month > $current_month_sys)) {
    $limit_month = $current_month_sys;
    $limit_year = $current_year_sys;
}


// =======================================================================
// 2. HELPER FUNCTIONS & SETUP
// =======================================================================

// --- [CHANGED] NEW LOGIC FOR HANDLING SINGLE DROPDOWN INPUT ---
$selected_month = (int)date('m');
$selected_year = (int)date('Y');

// Check if 'month_year' is passed (e.g., "2025-12")
if (isset($_GET['month_year']) && !empty($_GET['month_year'])) {
    $parts = explode('-', $_GET['month_year']);
    if (count($parts) == 2) {
        $selected_year = (int)$parts[0];
        $selected_month = (int)$parts[1];
    }
} elseif (isset($_GET['month']) && isset($_GET['year'])) {
    // Fallback for old links
    $selected_month = (int)$_GET['month'];
    $selected_year = (int)$_GET['year'];
}
// -------------------------------------------------------------

$payment_data = [];

// Fetch Fuel Price changes within the selected Month and Year
function get_fuel_price_changes_in_month($conn, $rate_id, $month, $year)
{
    $start_date = "$year-$month-01";
    $end_date = date('Y-m-t', strtotime($start_date)); 

    $sql = "
        SELECT date, rate
        FROM fuel_rate
        WHERE rate_id = ? 
        AND date <= ? 
        ORDER BY date DESC, id DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return []; 
    
    $stmt->bind_param("is", $rate_id, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $changes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $slabs = [];
    
    foreach ($changes as $change) {
        $change_date = $change['date'];
        $rate = (float)$change['rate'];
        
        if (strtotime($change_date) < strtotime($start_date)) {
            $slabs[date('Y-m-d', strtotime($start_date))] = $rate;
            break; 
        }
        
        $slabs[$change_date] = $rate;
    }
    
    ksort($slabs);
    return $slabs;
}

// Fetch Consumption Rates
$consumption_rates = [];
$consumption_sql = "SELECT c_id, distance FROM consumption"; 
$consumption_result = $conn->query($consumption_sql);
if ($consumption_result) {
    while ($row = $consumption_result->fetch_assoc()) {
        $consumption_rates[$row['c_id']] = $row['distance'];
    }
}
$default_km_per_liter = 1.00;

// Core Calculation Logic
function calculate_total_payment($conn, $route_code, $supplier_code, $month, $year, $route_distance, $fixed_amount, $with_fuel, $consumption_id, $rate_id, $consumption_rates, $default_km_per_liter)
{
    // Fetch trip counts per day (FACTORY Table)
    $trips_sql = "
        SELECT date, COUNT(id) AS daily_trips 
        FROM factory_transport_vehicle_register 
        WHERE route = ? AND supplier_code = ? 
        AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1
        GROUP BY date
    ";
    $trips_stmt = $conn->prepare($trips_sql);
    $trips_stmt->bind_param("ssii", $route_code, $supplier_code, $month, $year);
    $trips_stmt->execute();
    $trips_result = $trips_stmt->get_result();
    $daily_trip_counts = $trips_result->fetch_all(MYSQLI_ASSOC);
    $trips_stmt->close();
    
    $total_calculated_payment = 0;
    $total_trip_count = 0;
    $trip_rate = 0; 

    if (empty($daily_trip_counts)) {
        return ['total_payment' => 0, 'total_trips' => 0, 'effective_trip_rate' => 0];
    }
    
    $price_slabs = [];
    if ($with_fuel === 1 && $rate_id !== null) {
        $price_slabs = get_fuel_price_changes_in_month($conn, $rate_id, $month, $year);
    }
    
    $km_per_liter = $consumption_rates[$consumption_id] ?? $default_km_per_liter;
    
    foreach ($daily_trip_counts as $daily_data) {
        $trip_date = $daily_data['date'];
        $daily_trips = (int)$daily_data['daily_trips'];
        $total_trip_count += $daily_trips;

        // Find fuel price for this date
        $latest_fuel_price = 0;
        if ($with_fuel === 1 && !empty($price_slabs)) {
            foreach ($price_slabs as $change_date => $rate) {
                if (strtotime($trip_date) >= strtotime($change_date)) {
                    $latest_fuel_price = $rate;
                }
            }
        }
        
        // Calculate Fuel Cost per KM
        $calculated_fuel_amount_per_km = 0;
        if ($with_fuel === 1 && $consumption_id !== null) {
            if ($latest_fuel_price > 0 && $km_per_liter > 0) {
                $calculated_fuel_amount_per_km = $latest_fuel_price / $km_per_liter;
            }
        }

        // Total Rate per KM
        $rate_per_km = $fixed_amount + $calculated_fuel_amount_per_km;

        // Rate per TRIP 
        $trip_rate = $rate_per_km * ($route_distance / 2); 

        // Daily Payment
        $daily_payment = $trip_rate * $daily_trips;
        $total_calculated_payment += $daily_payment;
    }

    return [
        'total_payment' => $total_calculated_payment, 
        'total_trips' => $total_trip_count,
        'effective_trip_rate' => $trip_rate 
    ];
}


/**
 * ADJUSTED FUNCTION: Get Total Reduction Amount
 */
function get_total_adjustment_amount($conn, $route_code, $supplier_code, $month, $year)
{
    $total_adjustment = 0.00;

    // 1. Sum 'amount' from the 'reduction' table
    $reduction_sql = "
        SELECT SUM(amount) AS total_adjustment_amount 
        FROM reduction 
        WHERE route_code = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ?
    ";
    $reduction_stmt = $conn->prepare($reduction_sql);
    
    if (!$reduction_stmt) {
        error_log("SQL Prepare failed for reduction table: " . $conn->error);
        return 0.00; 
    }
    
    $reduction_stmt->bind_param("ssii", $route_code, $supplier_code, $month, $year);
    if ($reduction_stmt->execute()) {
        $reduction_result = $reduction_stmt->get_result();
        $row = $reduction_result->fetch_assoc();
        $total_adjustment = (float)($row['total_adjustment_amount'] ?? 0);
        $reduction_result->free();
    }
    $reduction_stmt->close();

    return $total_adjustment;
}


// --- MAIN DATA FETCH (DECOUPLED FROM monthly_payments_f) ---
// Note: Using factory_transport_vehicle_register and purpose='factory'
$payments_sql = "
    SELECT DISTINCT 
        ftvr.route AS route_code, 
        ftvr.supplier_code, 
        r.route, 
        r.fixed_amount, 
        r.distance AS route_distance, 
        r.with_fuel,
        v.fuel_efficiency,  
        v.rate_id
    FROM factory_transport_vehicle_register ftvr 
    JOIN route r ON ftvr.route = r.route_code
    LEFT JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
    WHERE MONTH(ftvr.date) = ? 
    AND YEAR(ftvr.date) = ? 
    AND r.purpose = 'factory'
    ORDER BY CAST(SUBSTRING(ftvr.route, 7, 3) AS UNSIGNED) ASC;
";
$payments_stmt = $conn->prepare($payments_sql);
$payments_stmt->bind_param("ii", $selected_month, $selected_year);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();

if ($payments_result && $payments_result->num_rows > 0) {
    while ($payment_row = $payments_result->fetch_assoc()) {
        $route_code = $payment_row['route_code'];
        $supplier_code = $payment_row['supplier_code'];
        $route_name = $payment_row['route'];
        $route_distance = (float)$payment_row['route_distance']; 
        $fixed_amount = (float)$payment_row['fixed_amount']; 
        $with_fuel = (int)$payment_row['with_fuel'];
        $consumption_id = $payment_row['fuel_efficiency'] ?? null;
        $rate_id = $payment_row['rate_id'] ?? null;
        
        // 🎯 Prorated Calculation
        $calculation_results = calculate_total_payment(
            $conn, $route_code, $supplier_code, $selected_month, $selected_year,
            $route_distance, $fixed_amount, $with_fuel, $consumption_id, $rate_id,
            $consumption_rates, $default_km_per_liter
        );

        // --- ADJUSTMENT LOGIC ---
        $adjustment_vs_db = get_total_adjustment_amount($conn, $route_code, $supplier_code, $selected_month, $selected_year);
        $adjustment_vs_db = $adjustment_vs_db * -1;

        $calculated_total_payment = $calculation_results['total_payment'];
        $calculated_total_payment += $adjustment_vs_db;
        $total_trip_count = $calculation_results['total_trips'];
        $trip_rate = $calculation_results['effective_trip_rate'];

        // 6. Calculate Total Distance
        $total_distance_calculated = ($route_distance / 2) * $total_trip_count;

        $route_suffix = substr($route_code, 6, 3);

        $payment_data[] = [
            'route_code' => $route_code, 
            'supplier_code' => $supplier_code, 
            'no' => $route_suffix,
            'route' => $route_name . " (" . $supplier_code . ")",
            'price_per_1km' => $trip_rate,
            'total_working_days' => $total_trip_count, 
            'total_distance' => $total_distance_calculated, 
            'other_amount' => $adjustment_vs_db,
            'payments' => $calculated_total_payment
        ];
    }
    $payments_stmt->close();
}

$page_title = "Factory Payments";
$table_headers = [
    "No",
    "Route (Supplier)", 
    "Trip Rate",
    "Trips", 
    "Distance (km)",
    "Adjustment",
    "Total (LKR)",
    "PDF"
];

include('../../../includes/header.php'); // Removed to use custom styled header
include('../../../includes/navbar.php'); // Removed to use custom styled navbar
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    
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
            Factory Payments
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <form method="get" action="factory_route_payments.php" class="flex items-center">
            <div class="relative">
                <select name="month_year" onchange="this.form.submit()" 
                        class="appearance-none bg-gray-800 text-white border border-gray-600 rounded-md py-1.5 pl-3 pr-8 text-xs focus:outline-none focus:ring-1 focus:ring-yellow-500 cursor-pointer hover:bg-gray-700 transition font-mono">
                    <?php 
                    $loop_curr_year = $current_year_sys;
                    $loop_curr_month = $current_month_sys;
                    $stop_year = ($limit_year > 0) ? $limit_year : $current_year_sys - 2;
                    $stop_month = ($limit_year > 0) ? $limit_month : 1;

                    while (true) {
                        if ($loop_curr_year < $stop_year) break;
                        if ($loop_curr_year == $stop_year && $loop_curr_month < $stop_month) break;

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

        <a href="download_factory_route_payments.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
           class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide no-loader">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        
        <a href="f_payments_done.php" class="flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-check-circle"></i> Done
        </a>

        <a href="f_payments_history.php" class="flex items-center gap-2 bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
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
                    <a href="sub/sub_route_payments.php" class="dropdown-item"><i class="fas fa-project-diagram w-5 text-indigo-500"></i> Sub Route</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="../DH/day_heldup_payments.php" class="dropdown-item"><i class="fas fa-sun w-5 text-orange-500"></i> Day Heldup</a>
                    <a href="../NH/nh_payments.php" class="dropdown-item"><i class="fas fa-moon w-5 text-purple-500"></i> Night Heldup</a>
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
                            <?php foreach ($table_headers as $index => $header): 
                                $align = ($index >= 2 && $index <= 6) ? 'text-right' : (($index == 7) ? 'text-center' : 'text-left');
                            ?>
                                <th class="py-3 px-6 font-semibold border-b border-blue-500 <?php echo $align; ?>"><?php echo $header; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!empty($payment_data)): ?>
                            <?php foreach ($payment_data as $data): ?>
                                <tr class="hover:bg-indigo-50 transition duration-150 group">
                                    <td class="py-3 px-6 font-mono text-gray-500"><?php echo htmlspecialchars($data['no']); ?></td>
                                    <td class="py-3 px-6 font-medium text-gray-800"><?php echo htmlspecialchars($data['route']); ?></td>
                                    <td class="py-3 px-6 text-right font-mono text-purple-600"><?php echo number_format($data['price_per_1km'], 2); ?></td>
                                    <td class="py-3 px-6 text-right text-gray-700"><?php echo number_format($data['total_working_days'], 0); ?></td>
                                    <td class="py-3 px-6 text-right text-gray-600"><?php echo number_format($data['total_distance'], 2); ?></td>
                                    <td class="py-3 px-6 text-right font-semibold <?php echo $data['other_amount'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php echo number_format($data['other_amount'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 text-right font-extrabold text-blue-700 text-base">
                                        <?php echo number_format($data['payments'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 text-center">
                                        <?php 
                                            $calc_pay = urlencode(number_format($data['payments'], 2, '.', ''));
                                            $tot_dist = urlencode(number_format($data['total_distance'], 2, '.', ''));
                                        ?>
                                        <a href="download_factory_pdf.php?route_code=<?= $data['route_code'] ?>&supplier_code=<?= $data['supplier_code'] ?>&month=<?= $selected_month ?>&year=<?= $selected_year ?>&calculated_payment=<?= $calc_pay ?>&total_distance_calc=<?= $tot_dist ?>"
                                           class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 p-2 rounded-lg transition-colors inline-block no-loader" 
                                           title="Download PDF">
                                            <i class="fas fa-file-pdf fa-lg"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="py-4 text-center text-gray-500">
                                    <div class="flex flex-col items-center justify-center">
                                        <p class="text-lg font-medium">No factory route payment data available.</p>
                                    </div>
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

<?php $conn->close(); ?>