<?php
// sub_payments_history.php (Sub Route Monthly Payments History)
require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

include('../../../../includes/db.php');
include('../../../../includes/header.php');
include('../../../../includes/navbar.php'); 

// --- 1. SETUP FILTERS ---
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$history_data = [];

// --- 2. FETCH HISTORY DATA ---
// We join with 'sub_route' and 'supplier' tables to get names instead of just codes
$history_sql = "
    SELECT 
        mps.sub_route_code,
        mps.supplier_code,
        mps.no_of_attendance_days,
        mps.monthly_payment,
        sr.sub_route AS sub_route_name,
        sr.vehicle_no,
        s.supplier AS supplier_name
    FROM 
        monthly_payments_sub mps
    LEFT JOIN 
        sub_route sr ON mps.sub_route_code = sr.sub_route_code
    LEFT JOIN 
        supplier s ON mps.supplier_code = s.supplier_code
    WHERE 
        mps.month = ? 
    AND 
        mps.year = ? 
    ORDER BY 
        sr.sub_route ASC
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

// --- 3. TEMPLATE SETUP ---
$page_title = "Sub Route Payments History";

$table_headers = [
    "Sub Route (Vehicle No)", 
    "Supplier",
    "Attendance Days",      
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
            <a href="../../payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="../factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Sub Route</p>
            <a href="../../DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a>
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
            <a href="../../night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="../../own_vehicle_payments.php" class="hover:text-yellow-600">Manager</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-3 mt-[1%]">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3 pt-4">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0">
                <?php echo htmlspecialchars($page_title); ?>
            </h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="sub_payments_history.php" class="flex flex-wrap gap-2 items-center">
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="month" id="month" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php for ($m=1; $m<=12; $m++): ?>
                                <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($selected_month == $m) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="year" id="year" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
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
                    
                    <a href="sub_route_payments.php" 
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
                                    <div class="text-gray-900"><?php echo htmlspecialchars($data['sub_route_name']); ?></div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo htmlspecialchars($data['sub_route_code']); ?> | <?php echo htmlspecialchars($data['vehicle_no']); ?>
                                    </div>
                                </td>
                                
                                <td class="py-3 px-6 whitespace-nowrap text-left">
                                    <div class="text-gray-800"><?php echo htmlspecialchars($data['supplier_name'] ?? 'N/A'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($data['supplier_code']); ?></div>
                                </td>

                                <td class="py-3 px-6 whitespace-nowrap text-right font-semibold">
                                    <?php echo number_format($data['no_of_attendance_days'], 0); ?>
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