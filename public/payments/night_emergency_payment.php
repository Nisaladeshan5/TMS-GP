<?php
// night_emergency_payment.php - Calculates Night Emergency Payments per Op Code (Single Dropdown Updated)

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// =======================================================================
// 1. FILTER LOGIC (DATE RANGE CALCULATION)
// =======================================================================

// A. Get the Last Finalized Payment Month/Year from monthly_payment_ne
$max_payments_sql = "SELECT MAX(month) AS max_month, MAX(year) AS max_year FROM monthly_payment_ne";
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
    $start_year = 0; // 0 means no history limit yet
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
} elseif (isset($_GET['month']) && isset($_GET['year'])) {
    // Fallback for old links
    $selected_month = str_pad($_GET['month'], 2, '0', STR_PAD_LEFT);
    $selected_year = (int)$_GET['year'];
}
// -------------------------------------------------------------


$payment_data = [];
$page_title = "Night Emergency Payments Summary";

// --- CALCULATION LOGIC ---
// Group by Op Code to show details per vehicle/service
$sql = "
    SELECT 
        nea.op_code,
        os.vehicle_no,
        s.supplier,
        s.supplier_code,
        COUNT(DISTINCT nea.date) as total_worked_days,
        (COUNT(DISTINCT nea.date) * os.day_rate) as total_payment
    FROM 
        night_emergency_attendance nea
    JOIN 
        op_services os ON nea.op_code = os.op_code
    JOIN 
        supplier s ON os.supplier_code = s.supplier_code
    WHERE 
        MONTH(nea.date) = ? AND YEAR(nea.date) = ?
    GROUP BY 
        nea.op_code
    ORDER BY 
        s.supplier ASC, nea.op_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $selected_month, $selected_year);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $payment_data[] = $row;
    }
}
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
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
            <a href="payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="factory/factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <a href="factory/sub/sub_route_payments.php" class="hover:text-yellow-600">Sub Route</a>
            <a href="DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a>
            <a href="NH/nh_payments.php" class="hover:text-yellow-600">Night Heldup</a>
            <p class="text-yellow-500 font-bold">Night Emergency</p>
            <a href="EV/ev_payments.php" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="own_vehicle_payments.php" class="hover:text-yellow-600">Fuel Allowance</a>
            <a href="all_payments_summary.php" class="hover:text-yellow-600">Summary</a>
        </div>
    </div>

    <main class="w-[85%] ml-[15%] p-4 mt-[2%]">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0">
                <?php echo htmlspecialchars($page_title); ?>
            </h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="night_emergency_payment.php" class="flex flex-wrap gap-2 items-center">
                    
                    <a href="download_night_emergency_payments.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>"
                       class="px-3 py-2 bg-green-600 text-white font-semibold rounded-md shadow-lg hover:bg-green-700 text-center"
                       title="Download Excel">
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
                    
                    <button type="submit" class="px-3 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200">
                        <i class="fas fa-filter"></i>
                    </button>

                    <a href="ne_done.php" class="px-3 py-2 bg-teal-600 text-white font-semibold rounded-lg shadow-md hover:bg-teal-700 transition duration-200" title="Finalize Payments">
                        <i class="fas fa-check-circle mr-1"></i>
                    </a>
                    <a href="ne_history.php" class="px-3 py-2 bg-yellow-600 text-white font-semibold rounded-lg shadow-md hover:bg-yellow-700 transition duration-200" title="View History">
                        <i class="fas fa-history mr-1"></i>
                    </a> 
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <th class="py-3 px-6 text-left">Op Code</th>
                        <th class="py-3 px-6 text-left">Supplier</th>
                        <th class="py-3 px-6 text-left">Supplier Code</th>
                        <th class="py-3 px-6 text-right">Total Worked Days</th>
                        <th class="py-3 px-6 text-right">Total Payment (LKR)</th>
                        <th class="py-3 px-6 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($payment_data)): ?>
                        <?php foreach ($payment_data as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                <td class="py-3 px-6 whitespace-nowrap font-bold text-gray-900 text-left">
                                    <?php echo htmlspecialchars($data['op_code']); ?>
                                    <span class="text-gray-500 text-xs font-normal block"><?php echo htmlspecialchars($data['vehicle_no']); ?></span>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap font-medium text-left">
                                    <?php echo htmlspecialchars($data['supplier']); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-left">
                                    <?php echo htmlspecialchars($data['supplier_code']); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right font-semibold">
                                    <?php echo number_format($data['total_worked_days']); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right font-bold text-blue-700 text-lg">
                                    <?php echo number_format($data['total_payment'], 2); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-center">
                                    <a href="download_night_emergency_pdf.php?op_code=<?php echo urlencode($data['op_code']); ?>&month=<?php echo urlencode($selected_month); ?>&year=<?php echo urlencode($selected_year); ?>" 
                                       class="text-red-500 hover:text-red-700 p-2"
                                       title="Download PDF" target="_blank">
                                        <i class="fas fa-file-pdf fa-lg"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="py-12 text-center text-gray-500 text-base font-medium">
                                No Night Emergency payments found for <?php echo date('F', mktime(0, 0, 0, $selected_month, 10)) . ", " . $selected_year; ?>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

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
<?php $conn->close(); ?>