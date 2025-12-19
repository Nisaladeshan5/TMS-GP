<?php
// day_heldup_payments.php - Calculates and displays Day Heldup Payments

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
// --------------------

// Set default filter values
$current_year = date('Y');
$current_month = date('m');

$filterYear = $current_year;
$filterMonthNum = $current_month; // Numeric month (01-12)

// --- Handle Filter via GET ---
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['year']) && is_numeric($_GET['year'])) {
        $filterYear = $_GET['year'];
    }
    if (isset($_GET['month_num']) && is_numeric($_GET['month_num'])) {
        $filterMonthNum = str_pad($_GET['month_num'], 2, '0', STR_PAD_LEFT);
    }
}
// Combine for SQL filtering
$filterYearMonth = "{$filterYear}-{$filterMonthNum}";

$payment_summary = []; // Final output array: Op Code => Total Payment
$page_title = "Day Heldup Payments Summary";

// --- CORE CALCULATION FUNCTION (LOGIC IS CORRECT) ---
function calculate_day_heldup_payments($conn, $month, $year) {
    
    // 1. Fetch DH Attendance Records (This inherently checks for attendance)
    $attendance_sql = "
        SELECT 
            dha.op_code, 
            dha.date,
            dha.vehicle_no,
            dha.ac, /* AC Status: 1 (AC) or 2 (Non-AC) or NULL */
            os.slab_limit_distance,
            os.day_rate, /* IGNORED */
            os.extra_rate_ac,
            os.extra_rate AS extra_rate_nonac 
        FROM 
            dh_attendance dha
        JOIN 
            op_services os ON dha.op_code = os.op_code
        WHERE 
            DATE_FORMAT(dha.date, '%Y-%m') = ?
        ORDER BY
            dha.date ASC
    ";
    
    $stmt = $conn->prepare($attendance_sql);
    if (!$stmt) return ['error' => 'Attendance Prepare Failed'];
    
    $filter_month_year = "{$year}-{$month}";
    $stmt->bind_param("s", $filter_month_year);
    $stmt->execute();
    $attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $daily_payments = [];

    // Loop runs ONLY IF attendance records exist
    foreach ($attendance_records as $record) {
        $date = $record['date'];
        $op_code = $record['op_code'];
        $vehicle_no = $record['vehicle_no'];

        // 2. Sum Distance from day_heldup_register (If zero trips, distance_sum = 0.00)
        $distance_sum_sql = "
            SELECT 
                SUM(distance) AS total_distance 
            FROM 
                day_heldup_register 
            WHERE 
                op_code = ? AND date = ? AND done = 1
        ";
        $dist_stmt = $conn->prepare($distance_sum_sql);
        if (!$dist_stmt) continue;
        
        $dist_stmt->bind_param("ss", $op_code, $date);
        $dist_stmt->execute();
        $distance_sum = (float)($dist_stmt->get_result()->fetch_assoc()['total_distance'] ?? 0.00); 
        $dist_stmt->close();

        // 3. Calculation Variables
        $slab_limit = (float)$record['slab_limit_distance'];
        $extra_rate_ac = (float)$record['extra_rate_ac'];
        $extra_rate_nonac = (float)$record['extra_rate_nonac'];
        $ac_status = (int)$record['ac'];
        $payment = 0.00;
        $rate_per_km = 0.00;
        
        // Determine the Rate Per KM (AC or Non-AC/NULL)
        if ($ac_status === 1) {
            $rate_per_km = $extra_rate_ac;
        } else {
            $rate_per_km = $extra_rate_nonac;
        }
        
        // --- FINAL PAYMENT LOGIC (Handles distance >= 0) ---
        // Payment distance is max of (Actual distance, Slab limit). 
        $payment_distance = max($distance_sum, $slab_limit);
        
        $payment = $payment_distance * $rate_per_km;

        // 4. Aggregate monthly summary
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
// --- END CORE CALCULATION FUNCTION ---

// --- MAIN EXECUTION ---
$daily_payment_records = calculate_day_heldup_payments($conn, $filterMonthNum, $filterYear);

// --- GENERATE FILTER RANGE ---
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
        /* Custom scrollbar for better visibility */
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
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Day Heldup</p> 
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
            <a href="../night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="../own_vehicle_payments.php" class="hover:text-yellow-600">Fuel Allowance</a> 
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-3 mt-[1%]"> 
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3 pt-4">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0"><?php echo htmlspecialchars($page_title); ?></h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="flex flex-wrap gap-2 items-center">\

                <a href="download_dh_payments_excel.php?month=<?php echo htmlspecialchars($filterMonthNum); ?>&year=<?php echo htmlspecialchars($filterYear); ?>" 
                        class="px-3 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 text-center"
                        title="Download Monthly Report">
                        <i class="fas fa-download"></i>
                    </a>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="month_num" id="month_num" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php 
                            for ($m = 1; $m <= 12; $m++): 
                                $num = str_pad($m, 2, '0', STR_PAD_LEFT);
                                $is_selected = ($filterMonthNum == $num);
                                echo "<option value=\"{$num}\"";
                                echo $is_selected ? ' selected' : '';
                                echo ">" . $monthNames[$num] . "</option>";
                            endfor; 
                            ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="year" id="year" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php 
                            $min_year = 2020;
                            for ($y = date('Y'); $y >= $min_year; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($filterYear == $y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="px-3 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200" title="Filter">
                        <i class="fas fa-filter mr-1"></i> Filter
                    </button>
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
                        // Convert associative array to indexed array for simpler display
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
                                    
                                    <a href="day_heldup_daily_details.php?op_code=<?php echo htmlspecialchars($data['op_code']); ?>&month_num=<?php echo htmlspecialchars($filterMonthNum); ?>&year=<?php echo htmlspecialchars($filterYear); ?>" 
                                        class="text-blue-600 hover:text-blue-800 p-1"
                                        title="View Daily Breakdown">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <a href="download_day_heldup_pdf.php?op_code=<?php echo htmlspecialchars($data['op_code']); ?>&month_num=<?php echo htmlspecialchars($filterMonthNum); ?>&year=<?php echo htmlspecialchars($filterYear); ?>"
                                        class="text-red-600 hover:text-red-800 p-1"
                                        title="Download Monthly Summary PDF" target="_blank">
                                        <i class="fas fa-file-pdf"></i> 
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="py-12 text-center text-gray-500 text-base font-medium">No Day Heldup payment data available for <?php echo date('F', mktime(0, 0, 0, $filterMonthNum, 1)) . ", " . $filterYear; ?>.</td>
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
    // JavaScript for session timeout (ViewDailyDetails function removed)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>
</html>