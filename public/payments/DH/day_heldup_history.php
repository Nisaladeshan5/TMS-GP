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

// --- 1. FETCH AVAILABLE HISTORY DATES (DISTINCT MONTHS) ---
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
    "Total Distance (km)",      
    "Monthly Payment (LKR)" 
];

include('../../../includes/header.php');
include('../../../includes/navbar.php'); 
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
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
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
        <div class="flex items-center space-x-2 w-fit">
            <a href="day_heldup_payments.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Day Heldup Payments
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                History
            </span>
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <form method="get" action="day_heldup_history.php" class="flex items-center gap-2">
            <div class="relative">
                <select name="period" id="period" onchange="this.form.submit()" 
                        class="appearance-none bg-gray-800 text-white border border-gray-600 rounded-md py-1.5 pl-3 pr-8 text-xs focus:outline-none focus:ring-1 focus:ring-yellow-500 cursor-pointer hover:bg-gray-700 transition font-mono min-w-[160px]">
                    <?php if (empty($available_dates)): ?>
                        <option value="<?php echo date('Y-m'); ?>" selected>
                            <?php echo date('F Y'); ?> (No Data)
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
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-400">
                    <i class="fas fa-chevron-down text-[10px]"></i>
                </div>
            </div>
        </form>

        <a href="download_dh_history_excel.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
           class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-green-500 no-loader">
            <i class="fas fa-file-excel"></i> Excel
        </a>

        <span class="text-gray-600 text-lg font-thin">|</span>

        <a href="day_heldup_payments.php" class="text-gray-300 hover:text-white transition flex items-center gap-2 text-xs uppercase tracking-wide font-bold">
            Current Payments
        </a>

    </div>
</div>

<div class="flex flex-col items-center mt-2 w-[85%] ml-[15%] p-2">
    
    <div class="w-full">
        
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="overflow-auto max-h-[87vh]">
                <table class="min-w-full text-sm text-left border-collapse">
                    <thead class="text-white uppercase text-xs tracking-wider">
                        <tr>
                            <?php foreach ($table_headers as $index => $header): 
                                $align = ($index >= 1) ? 'text-right' : 'text-left';
                            ?>
                                <th class="sticky top-0 z-10 bg-blue-600 py-3 px-6 font-semibold border-b border-blue-500 <?php echo $align; ?> shadow-sm">
                                    <?php echo $header; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!empty($history_data)): ?>
                            <?php foreach ($history_data as $data): ?>
                                <tr class="hover:bg-indigo-50 transition duration-150 group">
                                    
                                    <td class="py-3 px-6 whitespace-nowrap font-medium text-left">
                                        <div class="text-gray-900 font-semibold"><?php echo htmlspecialchars($data['op_code']); ?></div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <span class="text-indigo-600"><?php echo htmlspecialchars($data['vehicle_no'] ?? 'N/A'); ?></span>
                                        </div>
                                    </td>

                                    <td class="py-3 px-6 whitespace-nowrap text-right font-medium text-purple-600">
                                        <?php echo number_format($data['total_distance'], 2); ?>
                                    </td>

                                    <td class="py-3 px-6 whitespace-nowrap text-right text-blue-700 text-base font-extrabold">
                                        <?php echo number_format($data['monthly_payment'], 2); ?>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="py-12 text-center text-gray-500">
                                    <div class="flex flex-col items-center justify-center">
                                        <p class="text-lg font-medium">No history data found.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
    const loader = document.getElementById("pageLoader");

    function showLoader(text = "Loading data…") {
        if(loader.querySelector("p")) loader.querySelector("p").innerText = text;
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

    // 🔹 All forms
    document.querySelectorAll("form").forEach(form => {
        form.addEventListener("submit", function () {
            showLoader("Applying filter…");
        });
    });

    const periodSelect = document.getElementById('period'); 
    if (periodSelect) {
        periodSelect.addEventListener("change", function () {
            showLoader("Loading history data…");
        });
    }
</script>

</body>
</html>