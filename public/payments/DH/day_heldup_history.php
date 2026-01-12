<?php
// day_heldup_history.php (Day Heldup Monthly Payments History)

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

// --- 1. FETCH AVAILABLE HISTORY DATES (DISTINCT MONTHS) ---
// ඩේටා තියෙන මාස සහ අවුරුදු පමණක් ගෙන්වා ගැනීම (අලුත් ඒවා උඩට)
$dates_sql = "SELECT DISTINCT year, month FROM monthly_payments_dh ORDER BY year DESC, month DESC";
$dates_result = $conn->query($dates_sql);

$available_dates = [];
if ($dates_result && $dates_result->num_rows > 0) {
    while ($d = $dates_result->fetch_assoc()) {
        $available_dates[] = $d;
    }
}

// --- 2. DETERMINE SELECTED MONTH/YEAR ---
$selected_year = 0;
$selected_month = 0;

if (isset($_GET['period']) && !empty($_GET['period'])) {
    // Filter එකෙන් තේරුවා නම් ඒ අගයන් ගන්න
    list($selected_year, $selected_month) = explode('-', $_GET['period']);
    $selected_year = (int)$selected_year;
    $selected_month = (int)$selected_month;
} elseif (!empty($available_dates)) {
    // Filter කරලා නැත්නම්, සහ History තියෙනවා නම්, අලුත්ම එක ගන්න
    $selected_year = (int)$available_dates[0]['year'];
    $selected_month = (int)$available_dates[0]['month'];
} else {
    // History මුකුත් නැත්නම්, වර්තමාන මාසය ගන්න
    $selected_year = (int)date('Y');
    $selected_month = (int)date('m');
}

// --- 3. FETCH HISTORY DATA FOR SELECTED PERIOD ---
$history_data = [];

$history_sql = "
    SELECT 
        mph.op_code,
        mph.month,
        mph.year,
        mph.total_distance,
        mph.monthly_payment,
        os.vehicle_no
    FROM 
        monthly_payments_dh mph
    LEFT JOIN 
        op_services os ON mph.op_code = os.op_code
    WHERE 
        mph.month = ? 
    AND 
        mph.year = ? 
    ORDER BY 
        mph.op_code ASC
";

$history_stmt = $conn->prepare($history_sql);
$history_stmt->bind_param("ii", $selected_month, $selected_year);
$history_stmt->execute();
$history_result = $history_stmt->get_result();

if ($history_result && $history_result->num_rows > 0) {
    while ($row = $history_result->fetch_assoc()) {
        $history_data[] = $row;
    }
}
$history_stmt->close();
$conn->close();

// --- 4. TEMPLATE SETUP ---
$page_title = "Day Heldup Payments History";

$table_headers = [
    "Op Code (Vehicle No)", 
    "Month / Year",
    "Total Distance (km)",      
    "Monthly Payment (LKR)" 
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
            <a href="../factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <a href="../factory/sub/sub_route_payments.php" class="hover:text-yellow-600">Sub Route</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Day Heldup</p>
            <a href="../NH/nh_payments.php" class="hover:text-yellow-600">Night Heldup</a>
            <a href="../night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="../EV/ev_payments.php" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="../own_vehicle_payments.php" class="hover:text-yellow-600">Fuel Allowance</a>
            <a href="../all_payments_summary.php" class="hover:text-yellow-600">Summary</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-3 mt-[1%]">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3 pt-4">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0">
                <?php echo htmlspecialchars($page_title); ?>
            </h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="day_heldup_history.php" class="flex flex-wrap gap-2 items-center">
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="period" id="period" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white min-w-[200px]">
                            <?php if (empty($available_dates)): ?>
                                <option value="<?php echo date('Y-m'); ?>" selected>
                                    <?php echo date('F Y'); ?> (No History)
                                </option>
                            <?php else: ?>
                                <?php foreach ($available_dates as $date): ?>
                                    <?php 
                                        $val = $date['year'] . '-' . str_pad($date['month'], 2, '0', STR_PAD_LEFT);
                                        $display = date('F Y', mktime(0, 0, 0, $date['month'], 10, $date['year']));
                                        $isSelected = ($selected_year == $date['year'] && $selected_month == $date['month']) ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo $val; ?>" <?php echo $isSelected; ?>>
                                        <?php echo $display; ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="px-3 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200" title="Filter">
                        <i class="fas fa-filter"></i>
                    </button>
                    
                    <a href="day_heldup_payments.php" 
                    class="px-3 py-2 bg-purple-600 text-white font-semibold rounded-lg shadow-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-200 text-center"
                    title="Back to Current Payments"> 
                        Current
                    </a> 
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <?php foreach ($table_headers as $index => $header): ?>
                            <th class="py-3 px-6 <?php echo ($index >= 2) ? 'text-right' : 'text-left'; ?> border-b border-blue-500">
                                <?php echo htmlspecialchars($header); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($history_data)): ?>
                        <?php foreach ($history_data as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                
                                <td class="py-3 px-6 whitespace-nowrap font-medium text-left">
                                    <div class="text-gray-900 font-bold"><?php echo htmlspecialchars($data['op_code']); ?></div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo htmlspecialchars($data['vehicle_no'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                
                                <td class="py-3 px-6 whitespace-nowrap text-left text-gray-600">
                                    <?php echo date('F Y', mktime(0, 0, 0, $data['month'], 10, $data['year'])); ?>
                                </td>

                                <td class="py-3 px-6 whitespace-nowrap text-right font-semibold text-purple-600">
                                    <?php echo number_format($data['total_distance'], 2); ?>
                                </td>

                                <td class="py-3 px-6 whitespace-nowrap text-right text-blue-700 text-base font-extrabold">
                                    <?php echo number_format($data['monthly_payment'], 2); ?>
                                </td>
                                
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo count($table_headers); ?>" class="py-12 text-center text-gray-500 text-base font-medium">
                                No payment history data available for <?php echo date('F Y', mktime(0, 0, 0, $selected_month, 10, $selected_year)); ?>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>