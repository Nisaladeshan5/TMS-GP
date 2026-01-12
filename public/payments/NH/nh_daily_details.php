<?php
// nh_daily_details.php - Daily Breakdown of Night Heldup Payments (Grouped by Night Shift Date)

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

// --- 1. Get Inputs ---
$op_code = $_GET['op_code'] ?? '';
$filterMonthNum = $_GET['month_num'] ?? date('m');
$filterYear = $_GET['year'] ?? date('Y');

if (empty($op_code) || !is_numeric($filterMonthNum) || !is_numeric($filterYear)) {
    die("Invalid parameters.");
}

$filterMonthNum = str_pad($filterMonthNum, 2, '0', STR_PAD_LEFT);
$filterYearMonth = "{$filterYear}-{$filterMonthNum}";
$monthName = date('F', mktime(0, 0, 0, (int)$filterMonthNum, 1));

$page_title = "Daily Night Heldup Payments for {$op_code}";

// --- 2. CORE CALCULATION FUNCTION ---
function get_night_heldup_breakdown($conn, $op_code, $month, $year) {
    
    $filter_month_year = "{$year}-{$month}";
    $breakdown = [];
    $summary = [
        'total_days' => 0,
        'total_payment' => 0.00,
        'total_actual_distance' => 0.00,
        'vehicle_no' => 'Multiple'
    ];

    // 2.1 Fetch Service Rates (Rate & Slab) for this Op Code
    $service_sql = "SELECT slab_limit_distance, extra_rate AS rate_per_km FROM op_services WHERE op_code = ? LIMIT 1";
    $svc_stmt = $conn->prepare($service_sql);
    $svc_stmt->bind_param("s", $op_code);
    $svc_stmt->execute();
    $service_data = $svc_stmt->get_result()->fetch_assoc();
    $svc_stmt->close();

    if (!$service_data) return ['breakdown' => [], 'summary' => $summary];

    $slab_limit = (float)$service_data['slab_limit_distance'];
    $rate_per_km = (float)$service_data['rate_per_km'];

    // 2.2 Fetch Daily Aggregated Data (With Night Shift Date Logic)
    $sql = "
        SELECT 
            -- Effective Date: If time < 7AM, count as previous day
            IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date) as effective_date,
            MAX(nh.vehicle_no) as vehicle_no, 
            SUM(nh.distance) AS total_daily_distance
        FROM 
            nh_register nh
        WHERE 
            nh.op_code = ? 
            AND nh.done = 1 
        GROUP BY 
            effective_date
        HAVING 
            DATE_FORMAT(effective_date, '%Y-%m') = ?
        ORDER BY 
            effective_date ASC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $op_code, $filter_month_year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $daily_records = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($daily_records)) return ['breakdown' => [], 'summary' => $summary];

    $summary['vehicle_no'] = $daily_records[0]['vehicle_no']; 

    foreach ($daily_records as $row) {
        $actual_distance = (float)$row['total_daily_distance'];
        $payable_distance = 0;
        $calculation_method = '';

        // --- Logic: NH (Slab Applied Daily) vs EV (Actual) ---
        if (strpos($op_code, 'NH') === 0) {
            // NH: Check Daily Total vs Slab
            if ($actual_distance < $slab_limit) {
                $payable_distance = $slab_limit;
                $calculation_method = 'Slab Applied';
            } else {
                $payable_distance = $actual_distance;
                $calculation_method = 'Actual Distance';
            }
        } elseif (strpos($op_code, 'EV') === 0) {
            // EV: Always Actual
            $payable_distance = $actual_distance;
            $calculation_method = 'Actual (EV)';
        } else {
            // Default
            $payable_distance = $actual_distance;
            $calculation_method = 'Actual';
        }

        $payment = $payable_distance * $rate_per_km;

        // Store breakdown
        $breakdown[] = [
            'date' => $row['effective_date'],
            'vehicle_no' => $row['vehicle_no'],
            'slab_limit' => $slab_limit,
            'actual_distance' => $actual_distance,
            'payment_distance' => $payable_distance,
            'rate' => $rate_per_km,
            'daily_payment' => $payment,
            'method' => $calculation_method
        ];

        // Aggregate Summary
        $summary['total_payment'] += $payment;
        $summary['total_days']++; 
        $summary['total_actual_distance'] += $actual_distance;
    }

    return ['breakdown' => $breakdown, 'summary' => $summary];
}

// --- MAIN EXECUTION ---
$data = get_night_heldup_breakdown($conn, $op_code, $filterMonthNum, $filterYear);
$daily_breakdown = $data['breakdown'];
$monthly_summary = $data['summary'];
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
        .overflow-x-auto::-webkit-scrollbar { height: 8px; }
        .overflow-x-auto::-webkit-scrollbar-thumb { background-color: #a0aec0; border-radius: 4px; }
        .overflow-x-auto::-webkit-scrollbar-track { background-color: #edf2f7; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="nh_payments.php" class="hover:text-yellow-600">Back to List</a>
        </div>
    </div>

    <main class="w-[85%] ml-[15%] p-4 mt-[3%]"> 
        
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-extrabold text-gray-800">
                <i class="fas fa-moon text-blue-600 mr-2"></i> 
                Daily Breakdown: <?php echo htmlspecialchars($op_code); ?> (<?php echo htmlspecialchars($monthName . ", " . $filterYear); ?>)
            </h2>
            
            <div class="flex gap-2">
                <a href="download_nh_daily_excel.php?op_code=<?php echo htmlspecialchars($op_code); ?>&month_num=<?php echo htmlspecialchars($filterMonthNum); ?>&year=<?php echo htmlspecialchars($filterYear); ?>" 
                   class="px-3 py-3 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 transition duration-200 flex items-center">
                   <i class="fas fa-download"></i>
                </a>

                <a href="nh_payments.php?month_num=<?php echo htmlspecialchars($filterMonthNum); ?>&year=<?php echo htmlspecialchars($filterYear); ?>" 
                   class="px-3 py-3 bg-gray-500 text-white font-semibold rounded-lg shadow-md hover:bg-gray-600 transition duration-200 flex items-center">
                   <i class="fas fa-arrow-left "></i>
                </a>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl shadow-lg mb-6 border-l-4 border-blue-600">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 font-medium">
                <div><span class="text-gray-500">Op Code:</span> 
                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($op_code); ?></span></div>
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
                        <th class="py-3 px-4 text-left w-[15%]">Vehicle No</th>
                        <th class="py-3 px-4 text-center">Rate (LKR)</th>
                        <th class="py-3 px-4 text-right">Slab Limit (km)</th>
                        <th class="py-3 px-4 text-right">Daily Total Distance (km)</th>
                        <th class="py-3 px-4 text-right text-yellow-300">Payment Distance (km)</th>
                        <th class="py-3 px-4 text-right w-[15%]">Daily Payment (LKR)</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($daily_breakdown)): ?>
                        <?php foreach ($daily_breakdown as $day): 
                            // Highlight row if slab was applied (Paid > Actual)
                            $is_slab_used = ($day['payment_distance'] > $day['actual_distance']);
                        ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out <?php echo $is_slab_used ? 'bg-yellow-50' : ''; ?>">
                                <td class="py-3 px-4 whitespace-nowrap font-medium text-left">
                                    <?php echo date('Y-m-d', strtotime($day['date'])); ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap font-bold text-gray-700">
                                    <?php echo htmlspecialchars($day['vehicle_no']); ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-center">
                                    <?php echo number_format($day['rate'], 2); ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-right text-gray-500">
                                    <?php echo ($day['slab_limit'] > 0) ? number_format($day['slab_limit'], 2) : '-'; ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-right <?php echo $is_slab_used ? 'text-red-500' : 'text-green-600'; ?>">
                                    <?php echo number_format($day['actual_distance'], 2); ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-right font-bold text-gray-800">
                                    <?php echo number_format($day['payment_distance'], 2); ?>
                                    <?php if($is_slab_used): ?>
                                        <span class="text-[10px] text-gray-400 block">(Min Guarantee)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-right text-base font-extrabold text-blue-700">
                                    <?php echo number_format($day['daily_payment'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="py-12 text-center text-gray-500 text-base font-medium">No records found for this Op Code in <?php echo $monthName . ", " . $filterYear; ?>.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

</body>
</html>