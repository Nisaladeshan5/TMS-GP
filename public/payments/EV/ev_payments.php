<?php
// ev_payments.php - Extra Vehicle Payments (Single Dropdown Updated)

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');
date_default_timezone_set('Asia/Colombo');

// =======================================================================
// 1. FILTER LOGIC (DATE RANGE CALCULATION)
// =======================================================================

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

if ($db_max_month === 0 && $db_max_year === 0) {
    // Case 1: No data, start from current year Jan or specific default
    $start_month = 1;
    $start_year = 0; // 0 means no limit yet
} elseif ($db_max_month == 12) {
    // Case 2: Max month is Dec, start from Jan next year
    $start_month = 1;        
    $start_year = $db_max_year + 1; 
} else {
    // Case 3: Start from next month same year
    $start_month = $db_max_month + 1;
    $start_year = $db_max_year;
}

// C. Determine the ENDING point (Current System Date)
$current_month_sys = (int)date('n');
$current_year_sys = (int)date('Y');


// =======================================================================
// 2. HELPER FUNCTIONS & SELECTION LOGIC
// =======================================================================

// --- [CHANGED] NEW LOGIC FOR HANDLING SINGLE DROPDOWN INPUT ---
$selected_month = str_pad($current_month_sys, 2, '0', STR_PAD_LEFT);
$selected_year = $current_year_sys;

// Check if 'month_year' is passed (e.g., "2025-12")
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
// -------------------------------------------------------------


// =======================================================================
// 3. PRE-FETCH DATA & CALCULATIONS (EXISTING LOGIC)
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
$page_title = "Extra Vehicle Payments Summary";

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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="../payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="../factory/factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <a href="../factory/sub/sub_route_payments.php" class="hover:text-yellow-600">Sub Route</a>
            <a href="../DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a>
            <a href="../NH/nh_payments.php" class="hover:text-yellow-600">Night Heldup</a>
            <a href="../night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <p class="text-yellow-500 font-bold">Extra Vehicle</p>
            <a href="../own_vehicle_payments.php" class="hover:text-yellow-600">Fuel Allowance</a>
            <a href="../all_payments_summary.php" class="hover:text-yellow-600">Summary</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-3 mt-[2%]"> 
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0"><?php echo htmlspecialchars($page_title); ?></h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="ev_payments.php" class="flex flex-wrap gap-2 items-center">

                    <a href="download_ev_payments.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
                       class="px-3 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 transition duration-200 text-center"
                       title="Download Report">
                        <i class="fas fa-download"></i>
                    </a>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm min-w-[200px]">
                        <select name="month_year" id="month_year" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php 
                            // 1. Loop setup
                            $loop_curr_year = $current_year_sys;
                            $loop_curr_month = $current_month_sys;

                            // 2. Limit Setup
                            // If start_year > 0, stop there. Else default to 2 years back.
                            $limit_year = ($start_year > 0) ? $start_year : $current_year_sys - 2;
                            $limit_month = ($start_year > 0) ? $start_month : 1;

                            // 3. Loop Backwards
                            while (true) {
                                if ($loop_curr_year < $limit_year) break;
                                if ($loop_curr_year == $limit_year && $loop_curr_month < $limit_month) break;

                                $option_value = sprintf('%04d-%02d', $loop_curr_year, $loop_curr_month);
                                $option_label = date('F Y', mktime(0, 0, 0, $loop_curr_month, 10, $loop_curr_year));
                                
                                $is_selected = ($selected_year == $loop_curr_year && $selected_month == sprintf('%02d', $loop_curr_month)) ? 'selected' : '';
                                ?>
                                
                                <option value="<?php echo $option_value; ?>" <?php echo $is_selected; ?>>
                                    <?php echo $option_label; ?>
                                </option>

                                <?php
                                $loop_curr_month--;
                                if ($loop_curr_month == 0) {
                                    $loop_curr_month = 12;
                                    $loop_curr_year--;
                                }
                            }
                            ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="px-3 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 transition duration-200">
                        <i class="fas fa-filter"></i>
                    </button>

                    <a href="ev_done.php" class="px-3 py-2 bg-teal-600 text-white font-semibold rounded-lg shadow-md hover:bg-teal-700 transition duration-200" title="Finalize">
                        <i class="fas fa-check-circle"></i>
                    </a>
                    <a href="ev_history.php" class="px-3 py-2 bg-yellow-600 text-white font-semibold rounded-lg shadow-md hover:bg-yellow-700 transition duration-200" title="History">
                        <i class="fas fa-history"></i>
                    </a> 
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <th class="py-3 px-6 text-left">Identifier</th>
                        <th class="py-3 px-6 text-left">Type</th>
                        <th class="py-3 px-6 text-left">Supplier</th>
                        <th class="py-3 px-6 text-center">Trips</th>
                        <th class="py-3 px-6 text-right">Total Distance</th>
                        <th class="py-3 px-6 text-right">Total Payment (LKR)</th>
                        <th class="py-3 px-6 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($payment_data)): ?>
                        <?php foreach ($payment_data as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                <td class="py-3 px-6 whitespace-nowrap font-bold text-gray-800 text-left">
                                    <?php echo htmlspecialchars($data['identifier']); ?>
                                    <span class="text-xs text-gray-500 block font-normal"><?php echo htmlspecialchars($data['display_vehicle']); ?></span>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-left">
                                    <?php if($data['type'] == 'Route'): ?>
                                        <span class="bg-indigo-100 text-indigo-700 py-1 px-3 rounded-full text-xs">Route</span>
                                    <?php else: ?>
                                        <span class="bg-purple-100 text-purple-700 py-1 px-3 rounded-full text-xs">Operation</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-left">
                                    <?php echo htmlspecialchars($data['supplier']); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-center font-semibold">
                                    <?php echo $data['total_trips']; ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right text-gray-600">
                                    <?php echo number_format($data['total_distance'], 2); ?> km
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right text-blue-700 text-base font-extrabold">
                                    <?php echo number_format($data['total_payment'], 2); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-center">
                                    <a href="download_ev_pdf.php?id=<?php echo urlencode($data['identifier']); ?>&type=<?php echo urlencode($data['type']); ?>&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
                                       class="text-red-500 hover:text-red-700 p-1" title="Download PDF" target="_blank">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="py-12 text-center text-gray-500 text-base font-medium">
                                No Extra Vehicle payments found for <?php echo date('F', mktime(0, 0, 0, $selected_month, 1)) . ", " . $selected_year; ?>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</body>
</html>
<?php $conn->close(); ?>