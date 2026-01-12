<?php
// day_heldup_payments.php - Calculates and displays Day Heldup Payments (Single Dropdown Update)

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

// --- User Context ---
$user_role = $_SESSION['user_role'] ?? 'guest';
$can_act = in_array($user_role, ['super admin', 'admin', 'developer', 'manager']);

$page_title = "Day Heldup Payments Summary";

// =======================================================================
// 1. DYNAMIC DATE FILTER LOGIC
//    (Uses monthly_payments_dh to find where the history ends)
// =======================================================================

// A. Fetch Max Month and Year from DAY HELDUP history table
$max_payments_sql = "SELECT year as max_year, month as max_month FROM monthly_payments_dh ORDER BY year DESC, month DESC LIMIT 1";
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

if ($db_max_month === 0 && $db_max_year === 0) {
    // Case 1: No data in the table, limit is open (0) or set a default start year
    $start_month = 1;
    $start_year = 0; // 0 means no history limit
} elseif ($db_max_month == 12) {
    // Case 2: Max month is December
    $start_month = 1;        
    $start_year = $db_max_year + 1; 
} else {
    // Case 3: Start from next month
    $start_month = $db_max_month + 1;
    $start_year = $db_max_year;
}

// C. Determine the CURRENT (ENDING) point
$current_month_sys = (int)date('n');
$current_year_sys = (int)date('Y');


// =======================================================================
// 2. HELPER FUNCTIONS & SELECTION LOGIC
// =======================================================================

// --- [CHANGED] NEW LOGIC FOR HANDLING SINGLE DROPDOWN INPUT ---
$selected_month = $current_month_sys;
$selected_year = $current_year_sys;

// Check if 'month_year' is passed (e.g., "2025-12")
if (isset($_GET['month_year']) && !empty($_GET['month_year'])) {
    $parts = explode('-', $_GET['month_year']);
    if (count($parts) == 2) {
        $selected_year = (int)$parts[0];
        $selected_month = (int)$parts[1];
    }
} elseif (isset($_GET['month_num']) && isset($_GET['year'])) {
    // Fallback for old links
    $selected_month = (int)$_GET['month_num'];
    $selected_year = (int)$_GET['year'];
}
// -------------------------------------------------------------

$payment_summary = []; 

// --- CORE CALCULATION FUNCTION (Kept Exactly as Original) ---
function calculate_day_heldup_payments($conn, $month, $year) {
    $attendance_sql = "
        SELECT 
            dha.op_code, 
            dha.date,
            MAX(dha.vehicle_no) as vehicle_no, 
            dha.ac, 
            os.slab_limit_distance,
            os.extra_rate_ac,
            os.extra_rate AS extra_rate_nonac 
        FROM 
            dh_attendance dha
        JOIN 
            op_services os ON dha.op_code = os.op_code
        WHERE 
            DATE_FORMAT(dha.date, '%Y-%m') = ?
        GROUP BY 
            dha.op_code, dha.date
        ORDER BY
            dha.date ASC
    ";
    
    $stmt = $conn->prepare($attendance_sql);
    if (!$stmt) return [];
    
    // Ensure month is 2 digits for SQL matching if needed, though PHP date formatting handles X-X well
    $filter_month_year = sprintf("%d-%02d", $year, $month);
    
    $stmt->bind_param("s", $filter_month_year);
    $stmt->execute();
    $attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $daily_payments = [];

    foreach ($attendance_records as $record) {
        $date = $record['date'];
        $op_code = $record['op_code'];
        $vehicle_no = $record['vehicle_no'];

        $distance_sum_sql = "SELECT SUM(distance) AS total_distance FROM day_heldup_register WHERE op_code = ? AND date = ? AND done = 1";
        
        $dist_stmt = $conn->prepare($distance_sum_sql);
        $distance_sum = 0.00;
        
        if ($dist_stmt) {
            $dist_stmt->bind_param("ss", $op_code, $date);
            $dist_stmt->execute();
            $result = $dist_stmt->get_result()->fetch_assoc();
            $distance_sum = (float)($result['total_distance'] ?? 0.00); 
            $dist_stmt->close();
        }

        $slab_limit = (float)$record['slab_limit_distance'];
        $extra_rate_ac = (float)$record['extra_rate_ac'];
        $extra_rate_nonac = (float)$record['extra_rate_nonac'];
        $ac_status = (int)$record['ac'];
        
        $rate_per_km = ($ac_status === 1) ? $extra_rate_ac : $extra_rate_nonac;
        $payment_distance = max($distance_sum, $slab_limit);
        $payment = $payment_distance * $rate_per_km;

        if (!isset($daily_payments[$op_code])) {
            $daily_payments[$op_code] = [
                'op_code' => $op_code,
                'vehicle_no' => $vehicle_no, 
                'total_payment' => 0.00,
                'total_days' => 0,
                'total_actual_distance' => 0.00,
            ];
        }
        
        $daily_payments[$op_code]['total_payment'] += $payment;
        $daily_payments[$op_code]['total_days']++;
        $daily_payments[$op_code]['total_actual_distance'] += $distance_sum;
    }

    return $daily_payments;
}

// Fetch Data
$daily_payment_records = calculate_day_heldup_payments($conn, $selected_month, $selected_year);

$monthNames = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .overflow-x-auto::-webkit-scrollbar { height: 8px; }
        .overflow-x-auto::-webkit-scrollbar-thumb { background-color: #a0aec0; border-radius: 4px; }
        .overflow-x-auto::-webkit-scrollbar-track { background-color: #edf2f7; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="../payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="../factory/factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <a href="../factory/sub/sub_route_payments.php" class="hover:text-yellow-600">Sub Route</a>
            <p class="text-yellow-500 font-bold">Day Heldup</p> 
            <a href="../NH/nh_payments.php" class="hover:text-yellow-600">Night Heldup</a>
            <a href="../night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="../EV/ev_payments.php" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="../own_vehicle_payments.php" class="hover:text-yellow-600">Fuel Allowance</a> 
            <a href="../all_payments_summary.php" class="hover:text-yellow-600">Summary</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-3 mt-[1%]"> 
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3 pt-4">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0"><?php echo htmlspecialchars($page_title); ?></h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="day_heldup_payments.php" class="flex flex-wrap gap-2 items-center">

                    <a href="download_dh_payments_excel.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
                        class="px-3 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 text-center"
                        title="Download Monthly Report">
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
                                
                                $is_selected = ($selected_year == $loop_curr_year && $selected_month == $loop_curr_month) ? 'selected' : '';
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
                    
                    <button type="submit" class="px-3 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200" title="Filter">
                        <i class="fas fa-filter"></i>
                    </button>

                    <a href="day_heldup_done.php" class="px-3 py-2 bg-teal-600 text-white font-semibold rounded-lg shadow-md hover:bg-teal-700 transition duration-200"><i class="fas fa-check-circle mr-1"></i></a>
                    <a href="day_heldup_history.php" class="px-3 py-2 bg-yellow-600 text-white font-semibold rounded-lg shadow-md hover:bg-yellow-700 transition duration-200"><i class="fas fa-history mr-1"></i></a> 
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <th class="py-3 px-6 text-left">Op Code (Vehicle)</th>
                        <th class="py-3 px-6 text-right">Days Paid</th>
                        <th class="py-3 px-6 text-right">Total Distance (km)</th>
                        <th class="py-3 px-6 text-right">Total Payment (LKR)</th>
                        <th class="py-3 px-6 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($daily_payment_records)): ?>
                        <?php 
                        $display_records = array_values($daily_payment_records);
                        ?>
                        <?php foreach ($display_records as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                <td class="py-3 px-6 whitespace-nowrap font-medium text-left">
                                    <?php echo htmlspecialchars($data['op_code'] . ' (' . $data['vehicle_no'] . ')'); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right font-semibold">
                                    <?php echo number_format($data['total_days']); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right text-purple-600">
                                    <?php echo number_format($data['total_actual_distance'], 2); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right text-blue-700 text-base font-extrabold">
                                    <?php echo number_format($data['total_payment'], 2); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-center space-x-2">
                                    <a href="day_heldup_daily_details.php?op_code=<?php echo htmlspecialchars($data['op_code']); ?>&month_num=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
                                       class="text-blue-600 hover:text-blue-800 p-1"
                                       title="View Daily Breakdown">
                                       <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <a href="download_day_heldup_pdf.php?op_code=<?php echo htmlspecialchars($data['op_code']); ?>&month_num=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>"
                                       class="text-red-600 hover:text-red-800 p-1"
                                       title="Download Monthly Summary PDF" target="_blank">
                                       <i class="fas fa-file-pdf"></i> 
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="py-12 text-center text-gray-500 text-base font-medium">No Day Heldup payment data available for <?php echo date('F', mktime(0, 0, 0, $selected_month, 1)) . ", " . $selected_year; ?>.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    </div>

</body>
<script>
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>
</html>