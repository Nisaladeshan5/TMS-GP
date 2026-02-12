<?php
// sub_route_payments.php
require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

include('../../../../includes/db.php');

// =======================================================================
// 1. UPDATED FUEL CALCULATION LOGIC (Like Factory Payments)
// =======================================================================

function get_fuel_price_changes_in_month($conn, $rate_id, $month, $year) {
    $start_date = "$year-$month-01";
    $end_date = date('Y-m-t', strtotime($start_date)); 

    $sql = "SELECT date, rate FROM fuel_rate 
            WHERE rate_id = ? AND date <= ? 
            ORDER BY date DESC, id DESC";
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

$consumption_rates = [];
$res = $conn->query("SELECT c_id, distance FROM consumption");
if ($res) while ($r = $res->fetch_assoc()) $consumption_rates[$r['c_id']] = $r['distance'];

function calculate_sub_route_monthly_data($conn, $parent_route_code, $vehicle_no, $selected_month, $selected_year, $distance, $fixed_rate, $with_fuel, $consumption_rates) {
    $sql = "SELECT date FROM factory_transport_vehicle_register 
            WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1 
            GROUP BY date";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $parent_route_code, $selected_month, $selected_year);
    $stmt->execute();
    $active_days = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($active_days)) return ['total_fuel_based_pay' => 0, 'avg_day_rate' => 0, 'days_run' => 0];

    $stmt = $conn->prepare("SELECT fuel_efficiency, rate_id FROM vehicle WHERE vehicle_no = ?");
    $stmt->bind_param("s", $vehicle_no);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price_slabs = [];
    if ($with_fuel == 1 && $v) {
        $price_slabs = get_fuel_price_changes_in_month($conn, $v['rate_id'], $selected_month, $selected_year);
    }
    $km_per_l = ($v) ? ($consumption_rates[$v['fuel_efficiency']] ?? 1.0) : 1.0;

    $total_pay = 0;
    foreach ($active_days as $day) {
        $current_date = $day['date'];
        $fuel_price = 0;

        if ($with_fuel == 1 && !empty($price_slabs)) {
            foreach ($price_slabs as $change_date => $rate) {
                if (strtotime($current_date) >= strtotime($change_date)) {
                    $fuel_price = $rate;
                }
            }
        }

        $fuel_cost_per_km = ($fuel_price > 0) ? ($fuel_price / $km_per_l) : 0;
        $total_pay += ($fixed_rate + $fuel_cost_per_km) * $distance;
    }

    $days_run = count($active_days);
    return [
        'total_fuel_based_pay' => $total_pay,
        'avg_day_rate' => ($days_run > 0) ? ($total_pay / $days_run) : 0,
        'days_run' => $days_run
    ];
}
$current_month_sys = (int)date('n');
$current_year_sys = (int)date('Y');
// =======================================================================
// 2. LOGIC & DATA FETCH
// =======================================================================
$max_payments_sql = "SELECT MAX(month) AS max_month, MAX(year) AS max_year FROM monthly_payments_sub"; 
$max_payments_result = $conn->query($max_payments_sql);
$db_max_month = 0; $db_max_year = 0;

if ($max_payments_result && $max_payments_result->num_rows > 0) {
    $max_data = $max_payments_result->fetch_assoc();
    $db_max_month = (int)($max_data['max_month'] ?? 0);
    $db_max_year = (int)($max_data['max_year'] ?? 0);
}
$start_month = 0; $start_year = 0;

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

$selected_month = (int)date('m');
$selected_year = (int)date('Y');

if (isset($_GET['month_year']) && !empty($_GET['month_year'])) {
    $parts = explode('-', $_GET['month_year']);
    if (count($parts) == 2) { $selected_year = (int)$parts[0]; $selected_month = (int)$parts[1]; }
}

$payment_data = [];

function get_adjustments($conn, $sub_route_code, $month, $year) {
    $sql = "SELECT SUM(adjustment_days) as total_adj FROM sub_route_adjustments WHERE sub_route_code = ? AND month = ? AND year = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $sub_route_code, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)($row['total_adj'] ?? 0);
}

$sub_route_sql = "SELECT sub_route_code, route_code, sub_route AS sub_route_name, vehicle_no, fixed_rate, with_fuel, distance FROM sub_route WHERE is_active = 1 ORDER BY sub_route_code ASC";
$sub_route_result = $conn->query($sub_route_sql);

if ($sub_route_result && $sub_route_result->num_rows > 0) {
    while ($row = $sub_route_result->fetch_assoc()) {
        $sub_route_code = $row['sub_route_code'];
        $parent_route_code = $row['route_code'];
        $vehicle_no = $row['vehicle_no'];
        $distance = (float)$row['distance'];
        $fixed_rate = (float)$row['fixed_rate'];
        $with_fuel = (int)$row['with_fuel'];

        $calc = calculate_sub_route_monthly_data($conn, $parent_route_code, $vehicle_no, $selected_month, $selected_year, $distance, $fixed_rate, $with_fuel, $consumption_rates);
        
        $base_attendance = $calc['days_run'];
        $adjustments = get_adjustments($conn, $sub_route_code, $selected_month, $selected_year);
        
        $final_days = $base_attendance + $adjustments;
        if($final_days < 0) $final_days = 0;

        $total_payment = $calc['total_fuel_based_pay'] + ($adjustments * $calc['avg_day_rate']);

        $payment_data[] = [
            'sub_route_code' => $sub_route_code,
            'sub_route_name' => $row['sub_route_name'],
            'parent_route'   => $parent_route_code,
            'vehicle_no'     => $vehicle_no,
            'day_rate'       => $calc['avg_day_rate'], 
            'base_days'      => $base_attendance,
            'adjustments'    => $adjustments,
            'final_days'     => $final_days,
            'total_payment'  => $total_payment
        ];
    }
}

$page_title = "Sub Route Payments";
$table_headers = ["Sub Route Code", "Sub Route Name", "Parent Route", "Vehicle No", "Daily Rate", "Days Worked", "Total Payment", "PDF"];

include('../../../../includes/header.php'); 
include('../../../../includes/navbar.php'); 
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
        .animate-spin { animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .dropdown-menu { display: none; position: absolute; right: 0; top: 120%; z-index: 50; min-width: 220px; background-color: white; border: 1px solid #e5e7eb; border-radius: 0.75rem; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15); overflow: hidden; animation: slideDown 0.2s ease-out; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .dropdown-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: #374151; font-size: 0.875rem; transition: background-color 0.15s; }
        .dropdown-item:hover { background-color: #f3f4f6; color: #111827; }
        .modal-animate { animation: modalPop 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes modalPop { 0% { opacity: 0; transform: scale(0.95) translateY(10px); } 100% { opacity: 1; transform: scale(1) translateY(0); } }
    </style>
</head>

<body class="bg-gray-100">
<div id="pageLoader" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-gray-900 bg-opacity-90">
    <div class="flex flex-col items-center gap-4">
        <div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-r-4 border-r-transparent border-yellow-400"></div>
        <p class="text-gray-300 text-sm tracking-wide">Loading...</p>
    </div>
</div>

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">Sub Route Payments</div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        <form method="get" action="sub_route_payments.php" class="flex items-center">
            <div class="relative">
                <select name="month_year" onchange="showLoader('Applying filter...'); this.form.submit()" class="appearance-none bg-gray-800 text-white border border-gray-600 rounded-md py-1.5 pl-3 pr-8 text-xs focus:outline-none focus:ring-1 focus:ring-yellow-500 cursor-pointer hover:bg-gray-700 transition font-mono">
                    <?php 
                    $loop_curr_year = $current_year_sys; $loop_curr_month = $current_month_sys;
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
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-400"><i class="fas fa-chevron-down text-[10px]"></i></div>
            </div>
        </form>
        <span class="text-gray-600 text-lg font-thin">|</span>
        <button onclick="viewAdjustmentsLog()" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide no-loader"><i class="fas fa-list-alt"></i> Log</button>
        
        <form method="POST" action="download_sub_route_excel.php" target="_blank" class="inline no-loader">
            <input type="hidden" name="month" value="<?= $selected_month ?>">
            <input type="hidden" name="year" value="<?= $selected_year ?>">
            <input type="hidden" name="payment_json" value='<?php echo json_encode($payment_data); ?>'>
            <button type="submit" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide no-loader">
                <i class="fas fa-file-excel"></i> Excel
            </button>
        </form>

        <a href="sub_payments_done.php" class="flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide"><i class="fas fa-check-circle"></i> Done</a>
        <a href="sub_payments_history.php" class="flex items-center gap-2 bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide"><i class="fas fa-history"></i> History</a>
        <span class="text-gray-600 text-lg font-thin">|</span>
        <div class="relative">
            <button id="menuBtn" class="flex items-center gap-2 text-gray-300 hover:text-white transition focus:outline-none text-xs uppercase tracking-wide font-bold bg-gray-800 hover:bg-gray-700 px-3 py-1.5 rounded-md border border-gray-600">
                <i class="fas fa-layer-group"></i> Categories <i class="fas fa-chevron-down text-[10px] ml-1"></i>
            </button>
            <div id="dropdownMenu" class="dropdown-menu">
                <div class="py-1">
                    <a href="../../all_payments_summary.php" class="dropdown-item font-bold"><i class="fas fa-chart-pie w-5 text-gray-500"></i> Summary</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="../../payments_category.php" class="dropdown-item"><i class="fas fa-user-tie w-5 text-blue-500"></i> Staff</a>
                    <a href="../factory_route_payments.php" class="dropdown-item"><i class="fas fa-industry w-5 text-indigo-500"></i> Factory</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="../../DH/day_heldup_payments.php" class="dropdown-item"><i class="fas fa-sun w-5 text-orange-500"></i> Day Heldup</a>
                    <a href="../../NH/nh_payments.php" class="dropdown-item"><i class="fas fa-moon w-5 text-purple-500"></i> Night Heldup</a>
                    <a href="../../night_emergency_payment.php" class="dropdown-item"><i class="fas fa-ambulance w-5 text-red-500"></i> Night Emergency</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="../../EV/ev_payments.php" class="dropdown-item"><i class="fas fa-car-side w-5 text-green-500"></i> Extra Vehicle</a>
                    <a href="../../own_vehicle_payments.php" class="dropdown-item"><i class="fas fa-gas-pump w-5 text-yellow-500"></i> Fuel Allowance</a>
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
                                $align = ($index >= 4 && $index <= 6) ? 'text-right' : (($index == 7) ? 'text-center' : 'text-left'); ?>
                                <th class="py-3 px-6 font-semibold border-b border-blue-500 <?php echo $align; ?>"><?php echo $header; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!empty($payment_data)): ?>
                            <?php foreach ($payment_data as $data): ?>
                                <tr class="hover:bg-indigo-50 transition duration-150 group">
                                    <td class="py-3 px-6 font-mono text-gray-500"><?php echo $data['sub_route_code']; ?></td>
                                    <td class="py-3 px-6 font-medium text-gray-800"><?php echo $data['sub_route_name']; ?></td>
                                    <td class="py-3 px-6 text-gray-500 text-xs"><?php echo $data['parent_route']; ?></td>
                                    <td class="py-3 px-6 font-mono text-gray-600"><?php echo $data['vehicle_no']; ?></td>
                                    <td class="py-3 px-6 text-right font-semibold text-purple-600"><?php echo number_format($data['day_rate'], 2); ?></td>
                                    <td class="py-3 px-6 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1">
                                            <span class="text-base font-bold text-gray-800"><?php echo $data['final_days']; ?></span>
                                            <button onclick="openAdjustModal('<?php echo $data['sub_route_code']; ?>', '<?php echo htmlspecialchars($data['sub_route_name']); ?>')" class="text-gray-400 hover:text-blue-600 transition-colors p-1" title="Adjust Days"><i class="fas fa-edit"></i></button>
                                            <?php if ($data['adjustments'] != 0): ?>
                                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full <?php echo $data['adjustments'] > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                                    <?php echo ($data['adjustments'] > 0 ? '+' : '') . $data['adjustments']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3 px-6 text-right font-extrabold text-blue-700 text-base"><?php echo number_format($data['total_payment'], 2); ?></td>
                                    <td class="py-3 px-6 text-center">
                                        <a href="download_sub_route_pdf.php?sub_route_code=<?php echo $data['sub_route_code']; ?>&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>&amount=<?php echo urlencode($data['total_payment']); ?>" class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 p-2 rounded-lg transition-colors inline-block no-loader" title="Download PDF"><i class="fas fa-file-pdf fa-lg"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="py-4 text-center text-gray-500 font-medium">No active sub-routes found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="adjustModal" class="fixed inset-0 z-50 hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900 bg-opacity-60 backdrop-blur-sm transition-opacity" onclick="closeAdjustModal()"></div>
    <div class="flex items-center justify-center min-h-screen px-4 py-4 pointer-events-none">
        <div class="pointer-events-auto relative bg-white rounded-xl shadow-2xl w-full max-w-md transform transition-all modal-animate overflow-hidden">
            <div class="h-1 bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500"></div>
            <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <div>
                    <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-sliders-h text-blue-600"></i> Adjust Payment</h3>
                    <p class="text-xs text-gray-500 mt-1">For: <span id="modalRouteName" class="font-bold text-blue-600">...</span></p>
                </div>
                <button onclick="closeAdjustModal()" class="text-gray-400 hover:text-red-500 transition-colors"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="px-6 py-6 space-y-4">
                <div><label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Action</label>
                    <select id="adjType" class="block w-full border-gray-300 bg-white border rounded-md p-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="add">Add Extra Days (+)</option>
                        <option value="deduct">Reduce Days (-)</option>
                    </select>
                </div>
                <div><label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Days</label>
                    <input type="number" id="adjQuantity" min="1" value="1" class="block w-full border-gray-300 bg-white border rounded-md p-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div><label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Reason</label>
                    <textarea id="adjReason" rows="2" class="block w-full border-gray-300 bg-white border rounded-md p-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none" placeholder="Reason..."></textarea>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 flex gap-3 flex-row-reverse border-t border-gray-100">
                <button type="button" onclick="submitAdjustment()" class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-md hover:bg-blue-700 transition shadow-sm">Save</button>
                <button type="button" onclick="closeAdjustModal()" class="px-4 py-2 bg-white text-gray-700 text-sm font-semibold border border-gray-300 rounded-md hover:bg-gray-100 transition">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div id="viewLogModal" class="fixed inset-0 z-50 hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900 bg-opacity-60 backdrop-blur-sm transition-opacity" onclick="closeViewLogModal()"></div>
    <div class="flex items-center justify-center min-h-screen px-4 py-4 pointer-events-none">
        <div class="pointer-events-auto relative bg-white rounded-xl shadow-2xl w-full max-w-4xl transform transition-all modal-animate overflow-hidden">
            <div class="h-1 bg-gradient-to-r from-purple-500 via-pink-500 to-red-500"></div>
            <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-history text-purple-600"></i> Adjustment History</h3>
                <div class="flex items-center gap-2">
                    <select id="historyFilter" onchange="loadLogData()" class="text-xs border-gray-300 rounded-md p-1 focus:outline-none focus:ring-1 focus:ring-purple-500">
                        <option value="current">Current Month</option>
                        <option value="all">All History</option>
                    </select>
                    <button onclick="closeViewLogModal()" class="text-gray-400 hover:text-red-500 ml-2"><i class="fas fa-times text-lg"></i></button>
                </div>
            </div>
            <div class="max-h-[60vh] overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-100 sticky top-0">
                        <tr>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600 uppercase text-xs">Sub Route</th>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600 uppercase text-xs">For Month</th>
                            <th class="px-5 py-3 text-center font-semibold text-gray-600 uppercase text-xs">Adj</th>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600 uppercase text-xs">Reason</th>
                            <th class="px-5 py-3 text-right font-semibold text-gray-600 uppercase text-xs">Date</th>
                        </tr>
                    </thead>
                    <tbody id="adjustmentListBody" class="divide-y divide-gray-100"></tbody>
                </table>
                <div id="noAdjustmentsMsg" class="hidden py-8 text-center text-gray-400 text-sm">No records found.</div>
            </div>
            <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 text-right">
                <button type="button" onclick="closeViewLogModal()" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-100">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const menuBtn = document.getElementById('menuBtn');
        const dropdownMenu = document.getElementById('dropdownMenu');
        menuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdownMenu.style.display = (dropdownMenu.style.display === 'block') ? 'none' : 'block';
        });
        document.addEventListener('click', function(e) {
            if (!menuBtn.contains(e.target) && !dropdownMenu.contains(e.target)) dropdownMenu.style.display = 'none';
        });
    });

    let currentSubRouteCode = '';

    function openAdjustModal(code, name) {
        currentSubRouteCode = code;
        document.getElementById('modalRouteName').innerText = name;
        document.getElementById('adjType').value = 'add';
        document.getElementById('adjQuantity').value = 1;
        document.getElementById('adjReason').value = '';
        document.getElementById('adjustModal').classList.remove('hidden');
    }

    function closeAdjustModal() { document.getElementById('adjustModal').classList.add('hidden'); }

    function submitAdjustment() {
        const type = document.getElementById('adjType').value;
        const quantity = document.getElementById('adjQuantity').value;
        const reason = document.getElementById('adjReason').value;

        if (quantity <= 0) { alert("Please enter a valid number of days."); return; }
        if (!reason.trim()) { alert("Please enter a reason."); return; }

        showLoader("Saving adjustment...");

        const payload = {
            sub_route_code: currentSubRouteCode,
            month: <?php echo $selected_month; ?>,
            year: <?php echo $selected_year; ?>,
            type: type,
            quantity: quantity,
            reason: reason
        };

        fetch('save_sub_route_adjustment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                closeAdjustModal();
                location.reload(); 
            } else {
                document.getElementById('pageLoader').classList.add('hidden');
                alert("Error: " + (data.message || "Unknown error"));
            }
        })
        .catch(err => { 
            console.error(err); 
            document.getElementById('pageLoader').classList.add('hidden');
            alert("Connection error."); 
        });
    }

    function getMonthName(monthNumber) {
        const date = new Date();
        date.setMonth(monthNumber - 1);
        return date.toLocaleString('default', { month: 'short' });
    }

    function viewAdjustmentsLog() {
        document.getElementById('viewLogModal').classList.remove('hidden');
        document.getElementById('historyFilter').value = 'current'; 
        loadLogData();
    }

    function loadLogData() {
        const filterMode = document.getElementById('historyFilter').value;
        const tbody = document.getElementById('adjustmentListBody');
        const noDataMsg = document.getElementById('noAdjustmentsMsg');

        tbody.innerHTML = '<tr><td colspan="5" class="py-6 text-center text-gray-400"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
        noDataMsg.classList.add('hidden');

        fetch(`get_adjustment_list.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>&mode=${filterMode}`)
        .then(response => response.json())
        .then(data => {
            tbody.innerHTML = '';
            if (data.length === 0) {
                noDataMsg.classList.remove('hidden');
            } else {
                data.forEach(item => {
                    const isPositive = item.adjustment_days > 0;
                    const badgeClass = isPositive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
                    const sign = isPositive ? '+' : '';
                    const paymentMonthStr = `${getMonthName(item.month)} ${item.year}`;
                    const row = `
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-5 py-3 text-gray-800 font-medium">${item.sub_route_code} <br> <span class="text-xs text-gray-500 font-normal">${item.sub_route}</span></td>
                            <td class="px-5 py-3 text-gray-600">${paymentMonthStr}</td>
                            <td class="px-5 py-3 text-center"><span class="px-2 py-0.5 rounded text-xs font-bold ${badgeClass}">${sign}${item.adjustment_days}</span></td>
                            <td class="px-5 py-3 text-gray-600 italic">"${item.reason}"</td>
                            <td class="px-5 py-3 text-right text-xs text-gray-400">${new Date(item.created_at).toLocaleDateString()}</td>
                        </tr>`;
                    tbody.innerHTML += row;
                });
            }
        })
        .catch(err => {
            console.error(err);
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-red-500 py-4">Error loading data.</td></tr>';
        });
    }

    function closeViewLogModal() { document.getElementById('viewLogModal').classList.add('hidden'); }

    const loader = document.getElementById("pageLoader");
    function showLoader(text = "Loading...") {
        loader.querySelector("p").innerText = text;
        loader.classList.remove("hidden");
        loader.classList.add("flex");
    }

    // Link වලට Loader එක
    document.querySelectorAll("a").forEach(link => {
        link.addEventListener("click", function () {
            if (link.target !== "_blank" && !link.classList.contains("no-loader") && link.href.includes('.php')) {
                showLoader("Loading page...");
            }
        });
    });

    // Forms submit වෙද්දී Loader එක (Dropdown එකත් ඇතුළුව)
    document.querySelectorAll("form").forEach(form => {
        form.addEventListener("submit", function () {
            // මෙන්න මේ condition එක එකතු කරා "no-loader" තියෙන form එකක් නම් loader එක එන්න එපා කියලා
            if (!form.classList.contains("no-loader")) {
                showLoader("Applying data...");
            }
        });
    });
</script>
</body>
</html>
<?php $conn->close(); ?>