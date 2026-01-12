<?php
// all_payments_summary.php - Grand Summary (Styled exactly like ev_payments.php)

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
date_default_timezone_set('Asia/Colombo');

// --- 1. FILTERS ---
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// --- 2. DATA AGGREGATION LOGIC ---
$sql = "
    SELECT supplier_code, SUM(monthly_payment) as amount, 'Staff' as category FROM monthly_payments_sf WHERE month = ? AND year = ? GROUP BY supplier_code
    UNION ALL
    SELECT supplier_code, SUM(monthly_payment) as amount, 'Factory' as category FROM monthly_payments_f WHERE month = ? AND year = ? GROUP BY supplier_code
    UNION ALL
    SELECT supplier_code, SUM(monthly_payment) as amount, 'Night Emergency' as category FROM monthly_payment_ne WHERE month = ? AND year = ? GROUP BY supplier_code
    UNION ALL
    SELECT supplier_code, SUM(monthly_payment) as amount, 'Extra Vehicle' as category FROM monthly_payments_ev WHERE month = ? AND year = ? GROUP BY supplier_code
    UNION ALL
    SELECT supplier_code, SUM(monthly_payment) as amount, 'Day Heldup' as category FROM monthly_payments_dh WHERE month = ? AND year = ? GROUP BY supplier_code
    UNION ALL
    SELECT supplier_code, SUM(monthly_payment) as amount, 'Night Heldup' as category FROM monthly_payments_nh WHERE month = ? AND year = ? GROUP BY supplier_code
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiiiiiiiiiii", 
    $selected_month, $selected_year, 
    $selected_month, $selected_year, 
    $selected_month, $selected_year, 
    $selected_month, $selected_year, 
    $selected_month, $selected_year, 
    $selected_month, $selected_year
);
$stmt->execute();
$result = $stmt->get_result();

$summary_data = [];
$supplier_info = [];

$sup_res = $conn->query("SELECT supplier_code, supplier FROM supplier");
while ($row = $sup_res->fetch_assoc()) {
    $supplier_info[$row['supplier_code']] = $row['supplier'];
}

while ($row = $result->fetch_assoc()) {
    $sup_code = $row['supplier_code'];
    $cat = $row['category'];
    $amt = (float)$row['amount'];

    if (!isset($summary_data[$sup_code])) {
        $summary_data[$sup_code] = [
            'name' => $supplier_info[$sup_code] ?? 'Unknown',
            'code' => $sup_code,
            'total' => 0,
            'details' => [] 
        ];
    }

    $summary_data[$sup_code]['total'] += $amt;
    $summary_data[$sup_code]['details'][$cat] = $amt;
}
$stmt->close();

$page_title = "Total Payments Summary";

// Month Names for Dropdown
$monthNames = [
    '1' => 'January', '2' => 'February', '3' => 'March', '4' => 'April',
    '5' => 'May', '6' => 'June', '7' => 'July', '8' => 'August',
    '9' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
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
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">All Payments</div>
        <div class="flex gap-4">
            <a href="payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="factory/factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <a href="factory/sub/sub_route_payments.php" class="hover:text-yellow-600">Sub Route</a>
            <a href="DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a>
            <a href="NH/nh_payments.php" class="hover:text-yellow-600">Night Heldup</a>
            <a href="night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="ev_payments.php" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="own_vehicle_payments.php" class="hover:text-yellow-600">Fuel Allowance</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Summary</p>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-3 mt-[2%]">
        
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3 ">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0">
                <?php echo htmlspecialchars($page_title); ?>
            </h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="" class="flex flex-wrap gap-2 items-center">
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="month" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none bg-white">
                            <?php foreach ($monthNames as $val => $name): ?>
                                <option value="<?php echo $val; ?>" <?php echo ($selected_month == $val) ? 'selected' : ''; ?>>
                                    <?php echo $name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="year" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none bg-white">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="px-3 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 transition duration-200">
                        <i class="fas fa-filter"></i>
                    </button>
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <th class="py-3 px-6 text-left">Supplier Code</th>
                        <th class="py-3 px-6 text-left">Supplier Name</th>
                        <th class="py-3 px-6 text-right">Total Payment (LKR)</th>
                        <th class="py-3 px-6 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (empty($summary_data)): ?>
                        <tr>
                            <td colspan="4" class="py-12 text-center text-gray-500 text-base font-medium">
                                No payment records found for <?php echo date('F Y', mktime(0, 0, 0, $selected_month, 10, $selected_year)); ?>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($summary_data as $sup_code => $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                <td class="py-3 px-6 whitespace-nowrap font-bold text-gray-800 text-left">
                                    <?php echo htmlspecialchars($data['code']); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-left text-gray-600">
                                    <?php echo htmlspecialchars($data['name']); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right text-blue-700 text-base font-extrabold">
                                    <?php echo number_format($data['total'], 2); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-center">
                                    <button onclick='openModal(<?php echo json_encode($data); ?>)' 
                                            class="text-blue-500 hover:text-blue-700 p-1 mr-2" title="View Breakdown">
                                        <i class="fas fa-eye text-lg"></i>
                                    </button>
                                    
                                    <a href="download_all_payment_pdf.php?supplier_code=<?php echo urlencode($data['code']); ?>&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
                                       class="text-red-500 hover:text-red-700 p-1" title="Download PDF" target="_blank">
                                        <i class="fas fa-file-pdf text-lg"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div id="detailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6 transform transition-all scale-100">
            <div class="flex justify-between items-center mb-4 border-b pb-3">
                <h3 class="text-xl font-bold text-gray-800" id="modalTitle">Payment Breakdown</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-red-500 text-2xl transition">&times;</button>
            </div>
            
            <div id="modalContent" class="space-y-3">
                </div>

            <div class="mt-6 pt-4 border-t flex justify-end">
                <button onclick="closeModal()" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 transition shadow-md font-semibold">Close</button>
            </div>
        </div>
    </div>

    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);

        function openModal(data) {
            document.getElementById('modalTitle').innerText = data.name + " (" + data.code + ")";
            const content = document.getElementById('modalContent');
            let html = '<table class="w-full text-sm">';
            
            // Loop through details
            for (const [category, amount] of Object.entries(data.details)) {
                html += `
                    <tr class="border-b border-gray-100 last:border-0">
                        <td class="py-2 text-gray-600 font-medium">${category}</td>
                        <td class="py-2 text-right font-bold text-gray-800">${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    </tr>
                `;
            }
            
            // Grand Total Row
            html += `
                <tr class="bg-blue-50 font-bold border-t-2 border-blue-100">
                    <td class="py-3 pl-2 text-blue-800">GRAND TOTAL</td>
                    <td class="py-3 pr-2 text-right text-blue-800 text-lg">${parseFloat(data.total).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                </tr>
            </table>`;
            
            content.innerHTML = html;
            document.getElementById('detailModal').classList.remove('hidden');
            document.getElementById('detailModal').classList.add('flex');
        }

        function closeModal() {
            document.getElementById('detailModal').classList.add('hidden');
            document.getElementById('detailModal').classList.remove('flex');
        }
        
        // Close on clicking outside
        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>