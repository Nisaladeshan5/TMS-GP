<?php
// ev_history.php (Extra Vehicle Monthly Payments History)

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');

// --- 1. FETCH AVAILABLE HISTORY DATES ---
$dates_sql = "SELECT DISTINCT year, month FROM monthly_payments_ev ORDER BY year DESC, month DESC";
$dates_result = $conn->query($dates_sql);

$available_dates = [];
if ($dates_result && $dates_result->num_rows > 0) {
    while ($d = $dates_result->fetch_assoc()) {
        $available_dates[] = $d;
    }
}

// --- 2. SETUP FILTERS ---
$selected_year = 0;
$selected_month = 0;

if (isset($_GET['period']) && !empty($_GET['period'])) {
    list($selected_year, $selected_month) = explode('-', $_GET['period']);
    $selected_year = (int)$selected_year;
    $selected_month = (int)$selected_month;
} elseif (!empty($available_dates)) {
    $selected_year = (int)$available_dates[0]['year'];
    $selected_month = (int)$available_dates[0]['month'];
} else {
    $selected_year = (int)date('Y');
    $selected_month = (int)date('m');
}

// --- 3. FETCH HISTORY DATA ---
$history_data = [];
$history_sql = "
    SELECT 
        mph.code, mph.supplier_code, mph.month, mph.year,
        mph.rate, mph.total_distance, mph.monthly_payment,
        s.supplier
    FROM monthly_payments_ev mph
    LEFT JOIN supplier s ON mph.supplier_code = s.supplier_code
    WHERE mph.month = ? AND mph.year = ? 
    ORDER BY mph.code ASC
";

$history_stmt = $conn->prepare($history_sql);
$history_stmt->bind_param("ii", $selected_month, $selected_year);
$history_stmt->execute();
$history_result = $history_stmt->get_result();

while ($row = $history_result->fetch_assoc()) {
    $history_data[] = $row;
}
$history_stmt->close();
$conn->close();

$page_title = "Extra Vehicle Payments History";
$table_headers = [
    ["label" => "Identifier (Op/Route)", "align" => "text-left"],
    ["label" => "Supplier", "align" => "text-left"],
    ["label" => "Rate (LKR)", "align" => "text-right"],
    ["label" => "Total Distance (km)", "align" => "text-right"],
    ["label" => "Monthly Payment (LKR)", "align" => "text-right"]
];

include('../../../includes/header.php');
include('../../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; overflow: hidden; } /* Hide body scroll to use internal scroll */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Dynamic Height Calculation */
        .dynamic-table-container {
            height: calc(100vh - 140px); /* Adjust based on your header/navbar height */
        }
    </style>
    
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 
        setTimeout(function() {
            alert("Your session has expired. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>

<body class="bg-gray-100 h-full">

<div id="pageLoader" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-gray-900 bg-opacity-90">
    <div class="flex flex-col items-center gap-4">
        <div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-yellow-400"></div>
        <p class="text-gray-300 text-sm tracking-wide">Loading...</p>
    </div>
</div>

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2">
            <a href="ev_payments.php" class="text-md font-bold bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Extra Vehicle Payments
            </a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider">History</span>
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        <form method="get" action="ev_history.php" class="flex items-center gap-2">
            <div class="relative">
                <select name="period" id="period" onchange="this.form.submit()" 
                        class="appearance-none bg-gray-800 text-white border border-gray-600 rounded-md py-1.5 pl-3 pr-8 text-xs focus:ring-1 focus:ring-yellow-500 cursor-pointer font-mono min-w-[160px]">
                    <?php if (empty($available_dates)): ?>
                        <option value="<?php echo date('Y-m'); ?>" selected><?php echo date('F Y'); ?> (No Data)</option>
                    <?php else: ?>
                        <?php foreach ($available_dates as $date): ?>
                            <?php 
                                $val = $date['year'] . '-' . str_pad($date['month'], 2, '0', STR_PAD_LEFT);
                                $display = date('F Y', mktime(0, 0, 0, $date['month'], 10, $date['year']));
                                $isSelected = ($selected_year == $date['year'] && $selected_month == $date['month']) ? 'selected' : '';
                            ?>
                            <option value="<?php echo $val; ?>" <?php echo $isSelected; ?>><?php echo $display; ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-400">
                    <i class="fas fa-chevron-down text-[10px]"></i>
                </div>
            </div>
        </form>

        <a href="download_ev_history_excel.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
           class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 text-xs no-loader">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        <span class="text-gray-600">|</span>
        <a href="ev_payments.php" class="text-gray-300 hover:text-white transition text-xs uppercase font-bold">Current Payments</a>
    </div>
</div>

<main class="w-[85%] ml-[15%] p-2 h-full">
    <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden h-full flex flex-col">
        
        <div class="dynamic-table-container overflow-y-auto overflow-x-auto flex-grow">
            <table class="min-w-full text-sm text-left border-separate border-spacing-0">
                <thead class="text-white uppercase text-xs tracking-wider sticky top-0 z-20">
                    <tr>
                        <?php foreach ($table_headers as $header): ?>
                            <th class="bg-blue-600 py-3 px-6 font-semibold border-b border-blue-500 <?php echo $header['align']; ?> shadow-sm">
                                <?php echo htmlspecialchars($header['label']); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (!empty($history_data)): ?>
                        <?php foreach ($history_data as $data): ?>
                            <tr class="hover:bg-indigo-50 transition duration-150">
                                <td class="py-3 px-6 whitespace-nowrap font-medium text-left">
                                    <div class="text-gray-900 font-bold"><?php echo htmlspecialchars($data['code']); ?></div>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-left text-gray-600">
                                    <?php echo htmlspecialchars($data['supplier']); ?>
                                    <span class="text-xs text-gray-400 block"><?php echo htmlspecialchars($data['supplier_code']); ?></span>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right text-gray-700 font-mono">
                                    <?php echo number_format($data['rate'], 2); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right font-semibold text-purple-600 font-mono">
                                    <?php echo number_format($data['total_distance'], 2); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-right text-blue-700 text-base font-extrabold font-mono">
                                    <?php echo number_format($data['monthly_payment'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="py-4 text-center text-gray-500 font-medium">
                                <i class="fas fa-folder-open text-4xl mb-3 block text-gray-300"></i>
                                No history data found for this period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="bg-gray-50 border-t border-gray-200 px-6 py-3 flex justify-between items-center text-xs text-gray-500">
            <span>Showing <?php echo count($history_data); ?> entries</span>
            <span class="italic">System generated history report</span>
        </div>
    </div>
</main>

<script>
    const loader = document.getElementById("pageLoader");
    function showLoader(text = "Loading...") {
        if(loader.querySelector("p")) loader.querySelector("p").innerText = text;
        loader.classList.remove("hidden");
        loader.classList.add("flex");
    }

    document.querySelectorAll("a").forEach(link => {
        link.addEventListener("click", function (e) {
            if (link.target !== "_blank" && !link.classList.contains("no-loader") && !link.href.includes('#')) {
                showLoader("Loading page...");
            }
        });
    });

    document.querySelectorAll("form").forEach(form => {
        form.addEventListener("submit", function () {
            showLoader("Updating history...");
        });
    });
</script>

</body>
</html>