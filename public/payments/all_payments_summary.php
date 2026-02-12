<?php
// all_payments_summary.php - Grand Summary (Styled like own_vehicle_payments.php)

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php'); // Commented out in template, usually replaced by custom header
include('../../includes/navbar.php'); // Commented out in template
date_default_timezone_set('Asia/Colombo');

// =======================================================================
// 1. FILTERS & LOGIC (UNCHANGED)
// =======================================================================
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// --- 2. DATA AGGREGATION LOGIC (UNCHANGED) ---
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
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            <?php echo htmlspecialchars($page_title); ?>
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <form method="get" action="" class="flex items-center gap-2">
            
            <div class="relative">
                <select name="month" onchange="this.form.submit()" 
                        class="appearance-none bg-gray-800 text-white border border-gray-600 rounded-md py-1.5 pl-3 pr-8 text-xs focus:outline-none focus:ring-1 focus:ring-yellow-500 cursor-pointer hover:bg-gray-700 transition font-mono">
                    <?php foreach ($monthNames as $val => $name): ?>
                        <option value="<?php echo $val; ?>" <?php echo ($selected_month == $val) ? 'selected' : ''; ?>>
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-400">
                    <i class="fas fa-chevron-down text-[10px]"></i>
                </div>
            </div>

            <div class="relative">
                <select name="year" onchange="this.form.submit()" 
                        class="appearance-none bg-gray-800 text-white border border-gray-600 rounded-md py-1.5 pl-3 pr-8 text-xs focus:outline-none focus:ring-1 focus:ring-yellow-500 cursor-pointer hover:bg-gray-700 transition font-mono">
                    <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-400">
                    <i class="fas fa-chevron-down text-[10px]"></i>
                </div>
            </div>

        </form>

        <span class="text-gray-600 text-lg font-thin">|</span>

        <div class="relative">
            <button id="menuBtn" class="flex items-center gap-2 text-gray-300 hover:text-white transition focus:outline-none text-xs uppercase tracking-wide font-bold bg-gray-800 hover:bg-gray-700 px-3 py-1.5 rounded-md border border-gray-600">
                <i class="fas fa-layer-group"></i> Categories <i class="fas fa-chevron-down text-[10px] ml-1"></i>
            </button>
            <div id="dropdownMenu" class="dropdown-menu">
                <div class="py-1">
                    <a href="all_payments_summary.php" class="dropdown-item font-bold bg-gray-50"><i class="fas fa-chart-pie w-5 text-gray-500"></i> Summary</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="payments_category.php" class="dropdown-item"><i class="fas fa-user-tie w-5 text-blue-500"></i> Staff</a>
                    <a href="factory/factory_route_payments.php" class="dropdown-item"><i class="fas fa-industry w-5 text-indigo-500"></i> Factory</a>
                    <a href="factory/sub/sub_route_payments.php" class="dropdown-item"><i class="fas fa-project-diagram w-5 text-indigo-500"></i> Sub Route</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="DH/day_heldup_payments.php" class="dropdown-item"><i class="fas fa-sun w-5 text-orange-500"></i> Day Heldup</a>
                    <a href="NH/nh_payments.php" class="dropdown-item"><i class="fas fa-moon w-5 text-purple-500"></i> Night Heldup</a>
                    <a href="night_emergency_payment.php" class="dropdown-item"><i class="fas fa-ambulance w-5 text-red-500"></i> Night Emergency</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="EV/ev_payments.php" class="dropdown-item"><i class="fas fa-car-side w-5 text-green-500"></i> Extra Vehicle</a>
                    <a href="own_vehicle_payments.php" class="dropdown-item"><i class="fas fa-gas-pump w-5 text-yellow-500"></i> Fuel Allowance</a>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="flex flex-col items-center mt-2 w-[85%] ml-[15%] p-2">
    
    <div class="w-full">
        
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-left">
                    <thead class="bg-blue-600 text-white uppercase text-xs tracking-wider">
                        <tr>
                            <th class="py-3 px-6 font-semibold border-b border-blue-500 text-left">Supplier Code</th>
                            <th class="py-3 px-6 font-semibold border-b border-blue-500 text-left">Supplier Name</th>
                            <th class="py-3 px-6 font-semibold border-b border-blue-500 text-right">Total Payment (LKR)</th>
                            <th class="py-3 px-6 font-semibold border-b border-blue-500 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($summary_data)): ?>
                            <tr>
                                <td colspan="4" class="py-4 text-center text-gray-500 text-base font-medium">
                                    No payment records found for <?php echo date('F Y', mktime(0, 0, 0, $selected_month, 10, $selected_year)); ?>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($summary_data as $sup_code => $data): ?>
                                <tr class="hover:bg-indigo-50 transition duration-150 group">
                                    <td class="py-3 px-6 font-medium text-gray-800">
                                        <?php echo htmlspecialchars($data['code']); ?>
                                    </td>
                                    <td class="py-3 px-6 font-medium text-gray-600">
                                        <?php echo htmlspecialchars($data['name']); ?>
                                    </td>
                                    <td class="py-3 px-6 text-right font-extrabold text-blue-700 text-base">
                                        <?php echo number_format($data['total'], 2); ?>
                                    </td>
                                    <td class="py-3 px-6 text-center">
                                        
                                        <button onclick='openModal(<?php echo json_encode($data); ?>)' 
                                                class="text-blue-500 hover:text-blue-700 bg-blue-50 hover:bg-blue-100 p-2 rounded-lg transition-colors inline-block mr-2"
                                                title="View Breakdown">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <a href="download_all_payment_pdf.php?supplier_code=<?php echo urlencode($data['code']); ?>&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
                                           class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 p-2 rounded-lg transition-colors inline-block"
                                           title="Download PDF" target="_blank">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<div id="detailModal" class="fixed inset-0 bg-gray-900 bg-opacity-60 backdrop-blur-sm hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6 transform transition-all scale-100 border border-gray-200">
        <div class="flex justify-between items-center mb-4 border-b pb-3">
            <h3 class="text-xl font-bold text-gray-800" id="modalTitle">Payment Breakdown</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-red-500 text-2xl transition focus:outline-none">&times;</button>
        </div>
        
        <div id="modalContent" class="space-y-3">
            </div>

        <div class="mt-6 pt-4 border-t flex justify-end">
            <button onclick="closeModal()" class="bg-gray-800 text-white px-4 py-2 rounded-md hover:bg-gray-700 transition shadow-md font-semibold text-sm">Close</button>
        </div>
    </div>
</div>

<script>
    // --- Dropdown Menu Logic ---
    document.addEventListener('DOMContentLoaded', function() {
        const menuBtn = document.getElementById('menuBtn');
        const dropdownMenu = document.getElementById('dropdownMenu');

        menuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (dropdownMenu.style.display === 'block') {
                dropdownMenu.style.display = 'none';
            } else {
                dropdownMenu.style.display = 'block';
            }
        });

        document.addEventListener('click', function(e) {
            if (!menuBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
                dropdownMenu.style.display = 'none';
            }
        });
    });

    // --- Modal Logic ---
    function openModal(data) {
        document.getElementById('modalTitle').innerText = data.name + " (" + data.code + ")";
        const content = document.getElementById('modalContent');
        let html = '<table class="w-full text-sm">';
        
        // Loop through details
        for (const [category, amount] of Object.entries(data.details)) {
            html += `
                <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50">
                    <td class="py-2 text-gray-600 font-medium">${category}</td>
                    <td class="py-2 text-right font-bold text-gray-800">${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                </tr>
            `;
        }
        
        // Grand Total Row
        html += `
            <tr class="bg-blue-50 font-bold border-t-2 border-blue-100">
                <td class="py-3 pl-2 text-blue-800 uppercase text-xs tracking-wider">Grand Total</td>
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

    const loader = document.getElementById("pageLoader");

    function showLoader(text = "Loading factory payments…") {
        loader.querySelector("p").innerText = text;
        loader.classList.remove("hidden");
        loader.classList.add("flex");
    }

    // 🔹 All normal links
    document.querySelectorAll("a").forEach(link => {
        link.addEventListener("click", function () {
            if (link.target !== "_blank" && !link.classList.contains("no-loader")) {
                showLoader("Loading page…");
            }
        });
    });

    // 🔹 All forms (including month filter form)
    document.querySelectorAll("form").forEach(form => {
        form.addEventListener("submit", function () {
            showLoader("Applying filter…");
        });
    });

    // 🔹 Month and Year dropdowns (Updated for Summary Page)
    const monthSelect = document.querySelector("select[name='month']");
    const yearSelect = document.querySelector("select[name='year']");

    if (monthSelect) {
        monthSelect.addEventListener("change", function () {
            showLoader("Loading summary...");
        });
    }

    if (yearSelect) {
        yearSelect.addEventListener("change", function () {
            showLoader("Loading summary...");
        });
    }
</script>

</body>
</html>
<?php $conn->close(); ?>