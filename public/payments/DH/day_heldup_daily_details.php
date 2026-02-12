<?php
// day_heldup_daily_details.php - Displays a day-by-day breakdown of Day Heldup Payments (Sticky Header Updated)

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

$page_title = "Daily Breakdown: {$op_code}";

// Array to store the full breakdown
$daily_breakdown = [];
$monthly_summary = [
    'total_days' => 0,
    'total_payment' => 0.00,
    'total_actual_distance' => 0.00,
    'total_payment_distance' => 0.00, // Added
    'vehicle_no' => 'N/A'
];

// --- 2. CORE CALCULATION FUNCTION ---
function get_day_heldup_breakdown($conn, $op_code, $month, $year) {
    
    $filter_month_year = "{$year}-{$month}";
    $breakdown = [];
    $summary = [
        'total_days' => 0,
        'total_payment' => 0.00,
        'total_actual_distance' => 0.00,
        'total_payment_distance' => 0.00, // Added to track total payment distance
        'vehicle_no' => 'N/A'
    ];

    // 2.1. Fetch DH Attendance Records
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
        
        // 2.2. Sum Distance
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

        // 2.3. Calculation Logic
        $slab_limit = (float)$record['slab_limit_distance'];
        $ac_status = (int)$record['ac'];
        
        if ($ac_status === 1) {
            $rate_per_km = (float)$record['extra_rate_ac'];
            $ac_label = 'AC';
        } else {
            $rate_per_km = (float)$record['extra_rate_nonac'];
            $ac_label = 'Non-AC';
        }
        
        $payment_distance = max($distance_sum, $slab_limit);
        $payment = $payment_distance * $rate_per_km;

        // 2.4. Store Data
        $breakdown[] = [
            'date' => $date,
            'vehicle_no' => $record['vehicle_no'],
            'slab_limit' => $slab_limit,
            'ac_status' => $ac_label,
            'actual_distance' => $distance_sum,
            'payment_distance' => $payment_distance, 
            'rate' => $rate_per_km,
            'daily_payment' => $payment
        ];
        
        $summary['total_payment'] += $payment;
        $summary['total_days']++;
        $summary['total_actual_distance'] += $distance_sum;
        $summary['total_payment_distance'] += $payment_distance; // Add to summary
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
        <div class="flex flex-col justify-center">
            <div class="flex items-center space-x-2 w-fit">
                <a href="day_heldup_payments.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Day Heldup Payments
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    <div class="text-lg font-bold text-white leading-none">
                        <?php echo htmlspecialchars($op_code); ?> 
                        <span class="text-gray-400 text-sm font-normal">/ <?php echo htmlspecialchars($vehicle_no); ?></span>
                    </div>
                </span>
            </div>
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <span class="text-gray-400 font-mono text-xs"><?php echo htmlspecialchars($monthName . " " . $filterYear); ?></span>
        <span class="text-gray-600 text-lg font-thin">|</span>

        <a href="day_heldup_payments.php?month_num=<?php echo htmlspecialchars($filterMonthNum); ?>&year=<?php echo htmlspecialchars($filterYear); ?>" 
        onclick="showLoader()"
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
                            <th class="py-3 px-6 text-center font-semibold border-b border-blue-500">A/C Status</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Slab Limit (km)</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Actual (km)</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500 text-yellow-200">Payment (km)</th>
                            <th class="py-3 px-6 text-right font-semibold border-b border-blue-500">Daily Total (LKR)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!empty($daily_breakdown)): ?>
                            <?php foreach ($daily_breakdown as $day): 
                                $is_slab_used = ($day['actual_distance'] < $day['slab_limit']);
                                $row_highlight = $is_slab_used ? 'bg-yellow-50 hover:bg-yellow-100' : 'hover:bg-indigo-50';
                            ?>
                                <tr class="transition duration-150 group <?php echo $row_highlight; ?>">
                                    <td class="py-3 px-6 whitespace-nowrap font-medium text-gray-800">
                                        <?php echo date('Y-m-d', strtotime($day['date'])); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-center">
                                        <span class="px-2 py-1 rounded text-xs font-bold <?php echo $day['ac_status'] == 'AC' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'; ?>">
                                            <?php echo htmlspecialchars($day['ac_status']); ?>
                                        </span>
                                        <div class="text-[10px] text-gray-400 mt-1">LKR <?php echo number_format($day['rate'], 2); ?>/km</div>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right text-gray-500">
                                        <?php echo number_format($day['slab_limit'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right <?php echo $is_slab_used ? 'text-red-500 font-semibold' : 'text-green-600 font-semibold'; ?>">
                                        <?php echo number_format($day['actual_distance'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right font-bold text-gray-800 bg-opacity-50">
                                        <?php echo number_format($day['payment_distance'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 whitespace-nowrap text-right font-bold text-gray-700">
                                        <?php echo number_format($day['daily_payment'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="py-4 text-center text-gray-500 text-base font-medium">
                                    No daily records found for this Op Code in <?php echo $monthName . ", " . $filterYear; ?>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    
                    <tfoot class="bg-gray-100 border-t-2 border-blue-500">
                        <tr>
                            <td colspan="2" class="py-4 px-6 text-right font-bold text-gray-600 uppercase text-xs tracking-wider">
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

    // 1. Loader එක පෙන්වන Function එක
    function showLoader() {
        const loader = document.getElementById("pageLoader");
        loader.classList.remove("hidden");
        loader.classList.add("flex");
        
        // Text එක වෙනස් කිරීම (Optional)
        loader.querySelector("p").innerText = "Going Back...";
    }

    // 2. Browser එකේ Back Button එකෙන් ආපහු ආවොත් Loader එක අයින් කරන්න (Bfcache Fix)
    window.addEventListener('pageshow', function(event) {
        const loader = document.getElementById("pageLoader");
        if (!loader.classList.contains("hidden")) {
            loader.classList.add("hidden");
            loader.classList.remove("flex");
        }
    });

    // --- JS for Click-to-Toggle Menu (ඔබේ කලින් තිබූ කෝඩ් එක) ---
    document.addEventListener('DOMContentLoaded', function() {
        const menuBtn = document.getElementById('menuBtn');
        const dropdownMenu = document.getElementById('dropdownMenu');

        if(menuBtn && dropdownMenu) {
            menuBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdownMenu.style.display = (dropdownMenu.style.display === 'block') ? 'none' : 'block';
            });

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