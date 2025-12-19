<?php
// day_heldup_daily_details.php - Displays a day-by-day breakdown of Day Heldup Payments

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
include('../../../includes/header.php'); // Assuming this includes HTML start, head, and basic CSS
include('../../../includes/navbar.php'); // Assuming this includes the navigation bar
date_default_timezone_set('Asia/Colombo');

// --- 1. Get and Validate Inputs ---
$op_code = $_GET['op_code'] ?? '';
$filterMonthNum = $_GET['month_num'] ?? date('m');
$filterYear = $_GET['year'] ?? date('Y');

if (empty($op_code) || !is_numeric($filterMonthNum) || !is_numeric($filterYear)) {
    die("Invalid request parameters.");
}

$filterMonthNum = str_pad($filterMonthNum, 2, '0', STR_PAD_LEFT);
$filterYearMonth = "{$filterYear}-{$filterMonthNum}";
$monthName = date('F', mktime(0, 0, 0, (int)$filterMonthNum, 1));

$page_title = "Daily Day Heldup Payments for {$op_code}";

// Array to store the full breakdown
$daily_breakdown = [];
$monthly_summary = [
    'total_days' => 0,
    'total_payment' => 0.00,
    'total_actual_distance' => 0.00,
    'vehicle_no' => 'N/A'
];

// --- 2. CORE CALCULATION FUNCTION (Adapted to return daily details) ---
function get_day_heldup_breakdown($conn, $op_code, $month, $year) {
    
    $filter_month_year = "{$year}-{$month}";
    $breakdown = [];
    $summary = [
        'total_days' => 0,
        'total_payment' => 0.00,
        'total_actual_distance' => 0.00,
        'vehicle_no' => 'N/A'
    ];

    // 2.1. Fetch DH Attendance Records and rates for the specific Op Code
    $attendance_sql = "
        SELECT 
            dha.date,
            dha.vehicle_no,
            dha.ac, 
            os.slab_limit_distance,
            os.extra_rate_ac,
            os.extra_rate AS extra_rate_nonac 
        FROM 
            dh_attendance dha
        JOIN 
            op_services os ON dha.op_code = os.op_code
        WHERE 
            dha.op_code = ? AND DATE_FORMAT(dha.date, '%Y-%m') = ?
        ORDER BY
            dha.date ASC
    ";
    
    $stmt = $conn->prepare($attendance_sql);
    if (!$stmt) return ['error' => 'Attendance Prepare Failed'];
    
    $stmt->bind_param("ss", $op_code, $filter_month_year);
    $stmt->execute();
    $attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($attendance_records)) return ['breakdown' => [], 'summary' => $summary];
    
    $summary['vehicle_no'] = $attendance_records[0]['vehicle_no'];

    foreach ($attendance_records as $record) {
        $date = $record['date'];
        
        // 2.2. Sum Distance from day_heldup_register
        $distance_sum_sql = "
            SELECT SUM(distance) AS total_distance 
            FROM day_heldup_register 
            WHERE op_code = ? AND date = ? AND done = 1
        ";
        $dist_stmt = $conn->prepare($distance_sum_sql);
        if (!$dist_stmt) continue;
        
        $dist_stmt->bind_param("ss", $op_code, $date);
        $dist_stmt->execute();
        $distance_sum = (float)($dist_stmt->get_result()->fetch_assoc()['total_distance'] ?? 0.00); 
        $dist_stmt->close();

        // 2.3. Calculation Logic (Final Requested Logic)
        $slab_limit = (float)$record['slab_limit_distance'];
        $ac_status = (int)$record['ac'];
        
        // Determine the Rate Per KM
        if ($ac_status === 1) {
            $rate_per_km = (float)$record['extra_rate_ac'];
            $ac_label = 'AC';
        } else {
            $rate_per_km = (float)$record['extra_rate_nonac'];
            $ac_label = 'Non-AC';
        }
        
        // Payment distance is max of (Actual distance, Slab limit). 
        $payment_distance = max($distance_sum, $slab_limit);
        
        // Final Daily Payment Calculation
        $payment = $payment_distance * $rate_per_km;

        // 2.4. Store daily record and aggregate summary
        $breakdown[] = [
            'date' => $date,
            'vehicle_no' => $record['vehicle_no'],
            'slab_limit' => $slab_limit,
            'ac_status' => $ac_label,
            'actual_distance' => $distance_sum,
            'payment_distance' => $payment_distance, // The distance used for the final calculation
            'rate' => $rate_per_km,
            'daily_payment' => $payment
        ];
        
        $summary['total_payment'] += $payment;
        $summary['total_days']++;
        $summary['total_actual_distance'] += $distance_sum;
    }

    return ['breakdown' => $breakdown, 'summary' => $summary];
}

// --- MAIN EXECUTION ---
$data = get_day_heldup_breakdown($conn, $op_code, $filterMonthNum, $filterYear);
$daily_breakdown = $data['breakdown'];
$monthly_summary = $data['summary'];
$vehicle_no = $monthly_summary['vehicle_no'];
$conn->close();

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
        /* Re-using styles from the main summary page */
        .overflow-x-auto::-webkit-scrollbar { height: 8px; }
        .overflow-x-auto::-webkit-scrollbar-thumb { background-color: #a0aec0; border-radius: 4px; }
        .overflow-x-auto::-webkit-scrollbar-track { background-color: #edf2f7; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    
    <main class="w-[85%] ml-[15%] p-4 "> 
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-extrabold text-gray-800">
                <i class="fas fa-calendar-alt text-blue-600 mr-2"></i> 
                Daily Breakdown: <?php echo htmlspecialchars($op_code); ?> (<?php echo htmlspecialchars($monthName . ", " . $filterYear); ?>)
            </h2>
            <a href="day_heldup_payments.php?month_num=<?php echo htmlspecialchars($filterMonthNum); ?>&year=<?php echo htmlspecialchars($filterYear); ?>" 
               class="px-4 py-2 bg-gray-500 text-white font-semibold rounded-lg shadow-md hover:bg-gray-600 transition duration-200">
               <i class="fas fa-arrow-left mr-2"></i>Back to Payments
            </a>
        </div>

        <div class="bg-white p-4 rounded-xl shadow-lg mb-6 border-l-4 border-blue-600">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 font-medium">
                <div><span class="text-gray-500">Op Code / Vehicle:</span> 
                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($op_code . ' / ' . $vehicle_no); ?></span></div>
                <div><span class="text-gray-500">Total Days Paid:</span> 
                    <span class="font-bold text-green-600"><?php echo number_format($monthly_summary['total_days']); ?></span></div>
                <div><span class="text-gray-500">Monthly Total Payment:</span> 
                    <span class="text-xl font-extrabold text-blue-700">LKR <?php echo number_format($monthly_summary['total_payment'], 2); ?></span></div>
            </div>
        </div>

        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <th class="py-3 px-4 text-left w-[15%]">Date</th>
                        <th class="py-3 px-4 text-center">A/C Status / Rate</th>
                        <th class="py-3 px-4 text-right">Slab Limit (km)</th>
                        <th class="py-3 px-4 text-right">Actual Distance (km)</th>
                        <th class="py-3 px-4 text-right text-yellow-300">Payment Distance (km)</th>
                        <th class="py-3 px-4 text-right w-[20%]">Daily Payment (LKR)</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($daily_breakdown)): ?>
                        <?php foreach ($daily_breakdown as $day): 
                            $is_slab_used = ($day['actual_distance'] < $day['slab_limit']);
                        ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out <?php echo $is_slab_used ? 'bg-yellow-50' : ''; ?>">
                                <td class="py-3 px-4 whitespace-nowrap font-medium text-left">
                                    <?php echo date('Y-m-d', strtotime($day['date'])); ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-center">
                                    <?php echo htmlspecialchars($day['ac_status']); ?> 
                                    <span class="text-xs text-gray-500">(LKR <?php echo number_format($day['rate'], 2); ?>/km)</span>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-right">
                                    <?php echo number_format($day['slab_limit'], 2); ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-right <?php echo $is_slab_used ? 'text-red-500' : 'text-green-600'; ?>">
                                    <?php echo number_format($day['actual_distance'], 2); ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-right font-bold text-gray-800">
                                    <?php echo number_format($day['payment_distance'], 2); ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-right text-base font-extrabold text-blue-700">
                                    <?php echo number_format($day['daily_payment'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="py-12 text-center text-gray-500 text-base font-medium">No daily records found for this Op Code in <?php echo $monthName . ", " . $filterYear; ?>.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

</body>
</html>