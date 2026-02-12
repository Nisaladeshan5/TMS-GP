<?php
// nh_daily_details.php - Daily Breakdown of Night Heldup Payments (Sticky Header Updated)

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
        'total_payment_distance' => 0.00, // Added to track total payment distance
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

    // If all records have the same vehicle, show it. Otherwise "Multiple".
    $first_vehicle = $daily_records[0]['vehicle_no'];
    $all_same = true;
    foreach($daily_records as $rec) {
        if($rec['vehicle_no'] !== $first_vehicle) { $all_same = false; break; }
    }
    $summary['vehicle_no'] = $all_same ? $first_vehicle : 'Multiple';


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
        $summary['total_payment_distance'] += $payable_distance; // Add to summary
    }

    return ['breakdown' => $breakdown, 'summary' => $summary];
}

// --- MAIN EXECUTION ---
$data = get_night_heldup_breakdown($conn, $op_code, $filterMonthNum, $filterYear);
$daily_breakdown = $data['breakdown'];
$monthly_summary = $data['summary'];
$vehicle_no_summary = $monthly_summary['vehicle_no'];
$conn->close();
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

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex flex-col justify-center">
            <div class="flex items-center space-x-2 w-fit">
                <a href="nh_payments.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Night Heldup Payments
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    <div class="text-lg font-bold text-white leading-none">
                        <?php echo htmlspecialchars($op_code); ?> 
                        <span class="text-gray-400 text-sm font-normal">/ <?php echo htmlspecialchars($vehicle_no_summary); ?></span>
                    </div>
                </span>
            </div>
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <span class="text-gray-400 font-mono text-xs"><?php echo htmlspecialchars($monthName . " " . $filterYear); ?></span>
        <span class="text-gray-600 text-lg font-thin">|</span>

        <a href="download_nh_daily_excel.php?op_code=<?php echo htmlspecialchars($op_code); ?>&month_num=<?php echo htmlspecialchars($filterMonthNum); ?>&year=<?php echo htmlspecialchars($filterYear); ?>" 
           class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-file-excel"></i> Export
        </a>

        <a href="nh_payments.php?month_num=<?php echo htmlspecialchars($filterMonthNum); ?>&year=<?php echo htmlspecialchars($filterYear); ?>" 
           class="text-gray-300 hover:text-white transition flex items-center gap-2">
            Back
        </a>

    </div>
</div>

<div class="flex flex-col items-center mt-2 w-[85%] ml-[15%] p-2">
    
    <div class="w-full">
        
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto max-h-[87vh] overflow-y-auto relative">
                <table class="min-w-full text-sm text-left">
                    <thead class="bg-blue-600 text-white uppercase text-xs tracking-wider sticky top-0 z-10">
                        <tr>
                            <th class="py-3 px-6 font-semibold border-b border-blue-500">Date</th>
                            <th class="py-3 px-6 font-semibold border-b border-blue-500">Vehicle No</th>
                            <th class="py-3 px-6 text-center font-semibold border-b border-blue-500">Rate</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Slab Limit (km)</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Actual (km)</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500 text-yellow-200">Payment (km)</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Daily Total (LKR)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!empty($daily_breakdown)): ?>
                            <?php foreach ($daily_breakdown as $day): 
                                $is_slab_used = ($day['payment_distance'] > $day['actual_distance']);
                                $row_highlight = $is_slab_used ? 'bg-yellow-50 hover:bg-yellow-100' : 'hover:bg-indigo-50';
                            ?>
                                <tr class="transition duration-150 group <?php echo $row_highlight; ?>">
                                    <td class="py-3 px-6 whitespace-nowrap font-medium text-gray-800">
                                        <?php echo date('Y-m-d', strtotime($day['date'])); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap font-bold text-gray-700">
                                        <?php echo htmlspecialchars($day['vehicle_no']); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-center text-gray-500">
                                        LKR <?php echo number_format($day['rate'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right text-gray-500">
                                        <?php echo ($day['slab_limit'] > 0) ? number_format($day['slab_limit'], 2) : '-'; ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right <?php echo $is_slab_used ? 'text-red-500 font-semibold' : 'text-green-600 font-semibold'; ?>">
                                        <?php echo number_format($day['actual_distance'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right font-bold text-gray-800 bg-opacity-50">
                                        <?php echo number_format($day['payment_distance'], 2); ?>
                                        <?php if($is_slab_used): ?>
                                            <span class="text-[9px] text-gray-400 block uppercase tracking-tighter">(Min Gtd)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right font-bold text-gray-700">
                                        <?php echo number_format($day['daily_payment'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-gray-500 text-base font-medium">
                                    No records found for this Op Code in <?php echo $monthName . ", " . $filterYear; ?>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    
                    <tfoot class="bg-gray-100 border-t-2 border-blue-500">
                        <tr>
                            <td colspan="3" class="py-4 px-6 text-right font-bold text-gray-600 uppercase text-xs tracking-wider">
                                Total Paid Days: <span class="text-green-600 text-lg ml-2"><?php echo number_format($monthly_summary['total_days']); ?></span>
                            </td>
                            <td class="py-4 px-6 text-right font-bold text-gray-400">-</td>
                            <td class="py-4 px-6 text-right font-bold text-purple-600">
                                <?php echo number_format($monthly_summary['total_actual_distance'], 2); ?>
                            </td>
                            <td class="py-4 px-6 text-right font-extrabold text-yellow-700 bg-yellow-50">
                                <?php echo number_format($monthly_summary['total_payment_distance'], 2); ?>
                            </td>
                            <td class="py-4 px-6 text-right font-extrabold text-blue-800 text-xl bg-blue-50">
                                LKR <?php echo number_format($monthly_summary['total_payment'], 2); ?>
                            </td>
                        </tr>
                    </tfoot>
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

        if(menuBtn && dropdownMenu) {
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
        }
    });
</script>

</body>
</html>