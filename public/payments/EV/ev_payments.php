<?php
// ev_payments.php - Extra Vehicle Payments (Sticky Header Updated)

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
// 1. FILTER LOGIC (DATE RANGE CALCULATION)
// =======================================================================
$current_month_sys = (int)date('n');
$current_year_sys = (int)date('Y');

// A. Get the Last Finalized Payment Month/Year from monthly_payments_ev
$max_payments_sql = "SELECT MAX(month) AS max_month, MAX(year) AS max_year FROM monthly_payments_ev";
$max_payments_result = $conn->query($max_payments_sql);

$db_max_month = 0;
$db_max_year = 0;

if ($max_payments_result && $max_payments_result->num_rows > 0) {
    $max_data = $max_payments_result->fetch_assoc();
    $db_max_month = (int)($max_data['max_month'] ?? 0);
    $db_max_year = (int)($max_data['max_year'] ?? 0);
}

// B. Calculate the STARTING point (Next Due Month)
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
// 2. HELPER FUNCTIONS & SELECTION LOGIC
// =======================================================================

$selected_month = str_pad($current_month_sys, 2, '0', STR_PAD_LEFT);
$selected_year = $current_year_sys;

// Check if 'month_year' is passed
if (isset($_GET['month_year']) && !empty($_GET['month_year'])) {
    $parts = explode('-', $_GET['month_year']);
    if (count($parts) == 2) {
        $selected_year = (int)$parts[0];
        $selected_month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
    }
} elseif (isset($_GET['month_num']) && isset($_GET['year'])) {
    // Fallback for old links
    $selected_month = str_pad($_GET['month_num'], 2, '0', STR_PAD_LEFT);
    $selected_year = (int)$_GET['year'];
}


// =======================================================================
// 3. PRE-FETCH DATA & CALCULATIONS
// =======================================================================

// A. Fuel Rate History
$fuel_history = [];
$fuel_res = $conn->query("SELECT rate_id, rate, date FROM fuel_rate ORDER BY date DESC");
if ($fuel_res) {
    while ($row = $fuel_res->fetch_assoc()) {
        $fuel_history[$row['rate_id']][] = ['date' => $row['date'], 'rate' => (float)$row['rate']];
    }
}

function get_rate_for_date($rate_id, $trip_date, $history) {
    if (!isset($history[$rate_id])) return 0;
    foreach ($history[$rate_id] as $record) {
        if ($record['date'] <= $trip_date) return $record['rate'];
    }
    $last = end($history[$rate_id]);
    return $last ? $last['rate'] : 0;
}

// B. Op Rates
$op_rates = [];
$op_res = $conn->query("SELECT op_code, extra_rate_ac, extra_rate FROM op_services");
while ($row = $op_res->fetch_assoc()) {
    $op_rates[$row['op_code']] = ['ac' => (float)$row['extra_rate_ac'], 'non_ac' => (float)$row['extra_rate']];
}

// C. Vehicle Specs
$vehicle_specs = [];
$veh_res = $conn->query("SELECT v.vehicle_no, v.rate_id, c.distance AS km_per_liter FROM vehicle v LEFT JOIN consumption c ON v.fuel_efficiency = c.c_id");
while ($row = $veh_res->fetch_assoc()) {
    $vehicle_specs[$row['vehicle_no']] = ['rate_id' => $row['rate_id'], 'km_per_liter' => (float)$row['km_per_liter']];
}

// D. Route Data
$route_data = [];
$rt_res = $conn->query("SELECT route_code, fixed_amount, vehicle_no, with_fuel FROM route");
while ($row = $rt_res->fetch_assoc()) {
    $route_data[$row['route_code']] = ['fixed_amount' => (float)$row['fixed_amount'], 'assigned_vehicle' => $row['vehicle_no'], 'with_fuel' => (int)$row['with_fuel']];
}

// --- 4. MAIN QUERY ---
$payment_data = [];
$page_title = "Extra Vehicle Payments";

$sql = "
    SELECT 
        evr.*,
        s.supplier,
        s.supplier_code
    FROM 
        extra_vehicle_register evr
    JOIN 
        supplier s ON evr.supplier_code = s.supplier_code
    WHERE 
        MONTH(evr.date) = ? AND YEAR(evr.date) = ? AND evr.done = 1
    ORDER BY 
        evr.date ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $selected_month, $selected_year);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $pay_amount = 0.00;
    $distance = (float)$row['distance'];
    $identifier = '';
    $type = '';
    $trip_date = $row['date'];

    // LOGIC 1: OP CODE
    if (!empty($row['op_code'])) {
        $identifier = $row['op_code'];
        $type = 'Operation';
        if (isset($op_rates[$identifier])) {
            $rate = ($row['ac_status'] == 1) ? $op_rates[$identifier]['ac'] : $op_rates[$identifier]['non_ac'];
            $pay_amount = $distance * $rate;
        }
    } 
    // LOGIC 2: ROUTE CODE
    elseif (!empty($row['route'])) {
        $identifier = $row['route'];
        $type = 'Route';
        if (isset($route_data[$identifier])) {
            $fixed = $route_data[$identifier]['fixed_amount'];
            $assigned_veh = $route_data[$identifier]['assigned_vehicle'];
            $with_fuel = $route_data[$identifier]['with_fuel'];
            $fuel_cost = 0;
            
            if ($with_fuel == 1 && !empty($assigned_veh) && isset($vehicle_specs[$assigned_veh])) {
                $v = $vehicle_specs[$assigned_veh];
                $km_l = $v['km_per_liter'];
                $f_rate = get_rate_for_date($v['rate_id'], $trip_date, $fuel_history);
                if ($km_l > 0) $fuel_cost = $f_rate / $km_l;
            }
            $pay_amount = $distance * ($fixed + $fuel_cost);
        }
    }

    $key = $row['supplier_code'] . '_' . $identifier;
    if (!isset($payment_data[$key])) {
        $payment_data[$key] = [
            'supplier' => $row['supplier'],
            'supplier_code' => $row['supplier_code'],
            'identifier' => $identifier,
            'type' => $type,
            'total_trips' => 0,
            'total_distance' => 0,
            'total_payment' => 0,
            'display_vehicle' => $row['vehicle_no']
        ];
    }
    $payment_data[$key]['total_trips']++;
    $payment_data[$key]['total_distance'] += $distance;
    $payment_data[$key]['total_payment'] += $pay_amount;
}
$stmt->close();
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
            Extra Vehicle Payments
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <form method="get" action="ev_payments.php" class="flex items-center">
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
                        $sel = ($selected_year == $loop_curr_year && $selected_month == sprintf('%02d', $loop_curr_month)) ? 'selected' : '';
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

        <a href="download_ev_payments_excel.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
           class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide no-loader">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        
        <a href="ev_done.php" class="flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-check-circle"></i> Done
        </a>

        <a href="ev_history.php" class="flex items-center gap-2 bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
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
                    <a href="../NH/nh_payments.php" class="dropdown-item"><i class="fas fa-moon w-5 text-purple-500"></i> Night Heldup</a>
                    <a href="../night_emergency_payment.php" class="dropdown-item"><i class="fas fa-ambulance w-5 text-red-500"></i> Night Emergency</a>
                    <div class="border-t border-gray-100 my-1"></div>
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
                            <th class="py-3 px-6 text-left font-semibold border-b border-blue-500">Identifier</th>
                            <th class="py-3 px-6 text-left font-semibold border-b border-blue-500">Type</th>
                            <th class="py-3 px-6 text-left font-semibold border-b border-blue-500">Supplier</th>
                            <th class="py-3 px-6 text-center font-semibold border-b border-blue-500">Trips</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Total Distance</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Total Payment (LKR)</th>
                            <th class="py-3 px-6 text-center font-semibold border-b border-blue-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!empty($payment_data)): ?>
                            <?php foreach ($payment_data as $data): ?>
                                <tr class="hover:bg-indigo-50 transition duration-150 group">
                                    <td class="py-3 px-6 whitespace-nowrap font-bold text-gray-800 text-left">
                                        <?php echo htmlspecialchars($data['identifier']); ?>
                                        <span class="text-xs text-gray-500 block font-normal"><?php echo htmlspecialchars($data['display_vehicle']); ?></span>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-left">
                                        <?php if($data['type'] == 'Route'): ?>
                                            <span class="bg-indigo-100 text-indigo-700 py-1 px-3 rounded-full text-xs font-semibold">Route</span>
                                        <?php else: ?>
                                            <span class="bg-purple-100 text-purple-700 py-1 px-3 rounded-full text-xs font-semibold">Operation</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-left font-medium text-gray-700">
                                        <?php echo htmlspecialchars($data['supplier']); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-center font-semibold">
                                        <?php echo $data['total_trips']; ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right text-gray-600 font-mono">
                                        <?php echo number_format($data['total_distance'], 2); ?> km
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right text-blue-700 text-base font-extrabold">
                                        <?php echo number_format($data['total_payment'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-center">
                                        <a href="download_ev_pdf.php?id=<?php echo urlencode($data['identifier']); ?>&type=<?php echo urlencode($data['type']); ?>&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
                                           class="bg-red-50 text-red-500 hover:text-red-700 hover:bg-red-100 p-2 rounded-lg transition-colors inline-block no-loader" 
                                           title="Download PDF" target="_blank">
                                            <i class="fas fa-file-pdf fa-lg"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="py-4 text-center text-gray-500 text-base font-medium">
                                    No Extra Vehicle payments found for <?php echo date('F', mktime(0, 0, 0, $selected_month, 1)) . ", " . $selected_year; ?>.
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