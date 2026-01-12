<?php
// nh_payments.php - Night Heldup Payments (Single Dropdown Updated)

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
$logged_in_user_id = (string)($_SESSION['user_id'] ?? ''); 

$page_title = "Night Heldup Payments Summary";

// =======================================================================
// 1. DYNAMIC DATE FILTER LOGIC
//    (Uses monthly_payments_nh to find where the history ends)
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

// B. Calculate the LIMIT point (The month AFTER the last payment)
$start_month = 0;
$start_year = 0;

if ($db_max_month === 0 && $db_max_year === 0) {
    // Case 1: No data in the table
    $start_month = 1;
    $start_year = 0; 
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

// --- CORE CALCULATION FUNCTION (Kept Exactly as Original) ---
function calculate_night_heldup_payments($conn, $month, $year) {
    
    // --- NIGHT SHIFT LOGIC ---
    // If Time < 07:00:00 (e.g., 04:00 AM), it belongs to the PREVIOUS DATE.
    
    $sql = "
        SELECT 
            nh.op_code,
            -- Calculate Effective Date based on Night Shift (5PM to 7AM)
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
            -- Filter based on the Calculated Effective Date
            AND DATE_FORMAT(IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date), '%Y-%m') = ?
        GROUP BY 
            nh.op_code, effective_date
        ORDER BY 
            nh.op_code ASC, effective_date ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    
    // Ensure format Y-m (e.g. 2025-01)
    $filter_month_year = sprintf("%d-%02d", $year, $month);
    
    $stmt->bind_param("s", $filter_month_year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $monthly_summary = [];

    while ($row = $result->fetch_assoc()) {
        $op_code = $row['op_code'];
        $vehicle_no = $row['vehicle_no'];
        
        // 1. Daily Total Distance
        $daily_distance = (float)$row['total_daily_distance'];
        $slab_limit = (float)$row['slab_limit_distance'];
        $rate = (float)$row['rate_per_km'];
        
        $payable_distance = 0;

        // --- PAYMENT LOGIC ---
        if (strpos($op_code, 'NH') === 0) {
            // NH: Apply Slab to the Daily Total
            $payable_distance = max($daily_distance, $slab_limit);
        } 
        elseif (strpos($op_code, 'EV') === 0) {
            // EV: Pay Actual Total
            $payable_distance = $daily_distance;
        } 
        else {
            $payable_distance = $daily_distance;
        }

        $payment_amount = $payable_distance * $rate;

        // --- Aggregate to Monthly Summary ---
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
            <a href="../DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a> 
            <p class="text-yellow-500 font-bold">Night Heldup</p>
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
                <form method="get" action="nh_payments.php" class="flex flex-wrap gap-2 items-center">

                    <a href="download_nh_payments_excel.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
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

                    <a href="nh_done.php" class="px-3 py-2 bg-teal-600 text-white font-semibold rounded-lg shadow-md hover:bg-teal-700 transition duration-200"><i class="fas fa-check-circle mr-1"></i></a>
                    <a href="nh_history.php" class="px-3 py-2 bg-yellow-600 text-white font-semibold rounded-lg shadow-md hover:bg-yellow-700 transition duration-200"><i class="fas fa-history mr-1"></i></a> 
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <th class="py-3 px-6 text-left">Op Code</th>
                        <th class="py-3 px-6 text-right">Days Paid</th>
                        <th class="py-3 px-6 text-right">Total Distance (km)</th>
                        <th class="py-3 px-6 text-right">Total Payment (LKR)</th>
                        <th class="py-3 px-6 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($night_payment_records)): ?>
                        <?php 
                        $display_records = array_values($night_payment_records);
                        ?>
                        <?php foreach ($display_records as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                <td class="py-3 px-6 whitespace-nowrap font-medium text-left">
                                    <?php echo htmlspecialchars($data['op_code']); ?>
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
                                    
                                    <a href="nh_daily_details.php?op_code=<?php echo htmlspecialchars($data['op_code']); ?>&month_num=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
                                       class="text-blue-600 hover:text-blue-800 p-1"
                                       title="View Daily Breakdown">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <a href="download_nh_pdf.php?op_code=<?php echo htmlspecialchars($data['op_code']); ?>&month_num=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>"
                                       class="text-red-600 hover:text-red-800 p-1"
                                       title="Download Monthly Summary PDF" target="_blank">
                                        <i class="fas fa-file-pdf"></i> 
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="py-12 text-center text-gray-500 text-base font-medium">No Night Heldup payment data available for <?php echo date('F', mktime(0, 0, 0, $selected_month, 1)) . ", " . $selected_year; ?>.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

</body>
</html>