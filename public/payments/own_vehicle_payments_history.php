<?php
// own_vehicle_payments_history.php (Own Vehicle Monthly Payments History)
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
include('../../includes/navbar.php'); // Assuming you want the header/navbar here

// --- 1. SETUP FILTERS ---
// Get selected month and year, default to current month/year
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$history_data = [];

// --- 2. FETCH HISTORY DATA ---
// Fetch data from own_vehicle_payments
$history_sql = "
    SELECT 
        ovp.emp_id, 
        ovp.month, 
        ovp.year, 
        ovp.no_of_attendance, 
        ovp.distance AS total_distance, 
        ovp.monthly_payment,
        ovp.fixed_amount,
        e.calling_name,
        ov.vehicle_no
    FROM 
        own_vehicle_payments ovp
    JOIN 
        employee e ON ovp.emp_id = e.emp_id
    LEFT JOIN
        own_vehicle ov ON ovp.emp_id = ov.emp_id
    WHERE 
        ovp.month = ? 
    AND 
        ovp.year = ? 
    ORDER BY 
        e.calling_name ASC
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
$page_title = "Own Vehicle Payments History";

// Updated headers for Own Vehicle data (PDF link removed)
$table_headers = [
    "Employee (Vehicle No)", // Left Align
    "Attendance Days",       // Right Align
    "Total Distance (km)",   // Right Align
    "Fixed Amount",
    "Monthly Payment (LKR)"  // Right Align
];

// Month name lookup for display in page title
$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));

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
        /* Table Cell Alignment Classes (Ensures right alignment for numbers) */
        .text-right-data { text-align: right; }
        
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
            <a href="" class="hover:text-yellow-600">Day Heldup</a>
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
            <a href="night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Own Vehicle</p>  
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-3 mt-[1%]">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3 pt-4">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0">
                <?php echo htmlspecialchars($page_title); ?>
            </h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="own_vehicle_payments_history.php" class="flex flex-wrap gap-2 items-center">
                    
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
                    <a href="own_vehicle_payments.php" 
                    class="px-3 py-2 bg-purple-600 text-white font-semibold rounded-lg shadow-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-200 text-center"
                    title="Current Payments"> Current
                    </a> 
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <th class="py-3 px-6 text-left border-b border-blue-500">
                            <?php echo htmlspecialchars($table_headers[0]); ?>
                        </th>
                        <?php for ($i = 1; $i < count($table_headers); $i++): ?>
                            <th class="py-3 px-6 text-right border-b border-blue-500">
                                <?php echo htmlspecialchars($table_headers[$i]); ?>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($history_data)): ?>
                        <?php foreach ($history_data as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                
                                <td class="py-3 px-6 whitespace-nowrap font-medium text-left">
                                    <?php echo htmlspecialchars($data['emp_id']) . ' - ' . htmlspecialchars($data['calling_name']) . " (" . htmlspecialchars($data['vehicle_no']) . ")"; ?>
                                </td>
                                
                                <td class="py-3 px-6 whitespace-nowrap text-right font-semibold">
                                    <?php echo number_format($data['no_of_attendance'], 0); ?>
                                </td>

                                <td class="py-3 px-6 whitespace-nowrap text-right text-purple-600">
                                    <?php echo number_format($data['total_distance'], 2); ?>
                                </td>

                                <td class="py-3 px-6 whitespace-nowrap text-right text-green-600">
                                    <?php echo number_format($data['fixed_amount'], 2); ?>
                                </td>

                                <td class="py-3 px-6 whitespace-nowrap text-right text-blue-700 text-base font-extrabold">
                                    <?php echo number_format($data['monthly_payment'], 2); ?>
                                </td>
                                
                                </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo count($table_headers); ?>" class="py-12 text-center text-gray-500 text-base font-medium">No payment history data available for the selected period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>