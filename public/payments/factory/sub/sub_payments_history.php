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

// --- 1. FETCH AVAILABLE HISTORY DATES ---
$dates_sql = "SELECT DISTINCT year, month FROM monthly_payments_sub ORDER BY year DESC, month DESC";
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

$history_data = [];

// --- 3. FETCH HISTORY DATA (Fetching fixed_rate, fuel_rate, distance separately) ---
$history_sql = "
    SELECT 
        mps.sub_route_code,
        mps.supplier_code,
        mps.no_of_attendance_days,
        mps.monthly_payment,
        mps.fixed_rate,
        mps.fuel_rate,
        mps.distance,
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

// --- 4. TEMPLATE SETUP (Columns 3k widiyata wenas kala) ---
$page_title = "Sub Route Payments History";
$table_headers = [
    "Sub Route (Vehicle No)", 
    "Supplier",
    "Fixed",
    "Fuel",
    "Distance",
    "Days",      
    "Payment (LKR)" 
];

include('../../../../includes/header.php');
include('../../../../includes/navbar.php'); 
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
    </style>
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
            <a href="sub_route_payments.php" class="text-md font-bold bg-gradient-to-r from-yellow-200 to-yellow-400 bg-clip-text text-transparent">Sub Route Payments</a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider">History</span>
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        <form method="get" action="sub_payments_history.php">
            <select name="period" onchange="this.form.submit()" class="bg-gray-800 text-white border border-gray-600 rounded py-1 px-3 text-xs outline-none focus:ring-1 focus:ring-yellow-500 cursor-pointer">
                <?php foreach ($available_dates as $date): 
                    $val = $date['year'] . '-' . str_pad($date['month'], 2, '0', STR_PAD_LEFT);
                    $lbl = date('F Y', mktime(0, 0, 0, $date['month'], 10, $date['year']));
                    $sel = ($selected_year == $date['year'] && $selected_month == $date['month']) ? 'selected' : '';
                    echo "<option value='$val' $sel>$lbl</option>";
                endforeach; ?>
            </select>
        </form>
        <a href="download_sub_history_excel.php?month=<?= $selected_month ?>&year=<?= $selected_year ?>" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded shadow text-xs transition no-loader"><i class="fas fa-file-excel"></i> Excel</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-2">
    <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
        <div class="overflow-auto max-h-[87vh]">
            <table class="min-w-full text-sm text-left border-collapse">
                <thead class="bg-blue-600 text-white uppercase text-xs tracking-wider sticky top-0 z-10">
                    <tr>
                        <?php foreach ($table_headers as $index => $header): 
                            $align = ($index >= 2) ? 'text-right' : 'text-left';
                        ?>
                            <th class="py-3 px-4 font-semibold border-b border-blue-500 <?php echo $align; ?> shadow-sm"><?php echo $header; ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (!empty($history_data)): ?>
                        <?php foreach ($history_data as $data): ?>
                            <tr class="hover:bg-indigo-50 transition duration-150 group">
                                <td class="py-3 px-4">
                                    <div class="text-gray-900 font-bold"><?php echo htmlspecialchars($data['sub_route_name']); ?></div>
                                    <div class="text-[10px] text-gray-500"><?php echo htmlspecialchars($data['sub_route_code']); ?> | <?= htmlspecialchars($data['vehicle_no']) ?></div>
                                </td>
                                <td class="py-3 px-4 text-xs text-gray-600"><?php echo htmlspecialchars($data['supplier_name'] ?? 'N/A'); ?></td>
                                <td class="py-3 px-4 text-right font-mono text-gray-700"><?php echo number_format($data['fixed_rate'], 2); ?></td>
                                <td class="py-3 px-4 text-right font-mono text-blue-600"><?php echo number_format($data['fuel_rate'], 2); ?></td>
                                <td class="py-3 px-4 text-right font-mono text-gray-500"><?php echo number_format($data['distance'], 2); ?></td>
                                <td class="py-3 px-4 text-right font-bold"><?php echo $data['no_of_attendance_days']; ?></td>
                                <td class="py-3 px-4 text-right text-blue-700 font-black text-base"><?php echo number_format($data['monthly_payment'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="py-6 text-center text-gray-400 italic">No history records found for the selected period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const loader = document.getElementById("pageLoader");
    function showLoader(text) { 
        if(loader.querySelector("p")) loader.querySelector("p").innerText = text;
        loader.classList.remove("hidden"); loader.classList.add("flex"); 
    }
    document.querySelectorAll("a").forEach(link => {
        link.addEventListener("click", function () {
            if (!link.classList.contains("no-loader") && link.href.includes("php")) showLoader("Loading...");
        });
    });
</script>
</body>
</html>