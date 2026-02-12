<?php
// own_vehicle_payments.php - Fuel Allowance Payments (Updated Fix)

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

$page_title = "Fuel Allowance Payments";

// =======================================================================
// 1. FILTER LOGIC
// =======================================================================

$max_payments_sql = "SELECT MAX(month) AS max_month, MAX(year) AS max_year FROM own_vehicle_payments";
$max_payments_result = $conn->query($max_payments_sql);
$db_max_month = 0; $db_max_year = 0;

if ($max_payments_result && $max_payments_result->num_rows > 0) {
    $max_data = $max_payments_result->fetch_assoc();
    $db_max_month = (int)($max_data['max_month'] ?? 0);
    $db_max_year = (int)($max_data['max_year'] ?? 0);
}

if ($db_max_month === 0 && $db_max_year === 0) {
    $start_month = 1; $start_year = 0; 
} elseif ($db_max_month == 12) {
    $start_month = 1; $start_year = $db_max_year + 1; 
} else {
    $start_month = $db_max_month + 1; $start_year = $db_max_year;
}

$current_month_sys = (int)date('n');
$current_year_sys = (int)date('Y');

// =======================================================================
// 2. HELPER FUNCTIONS & SELECTION LOGIC
// =======================================================================

$selected_month = str_pad($current_month_sys, 2, '0', STR_PAD_LEFT);
$selected_year = $current_year_sys;

if (isset($_GET['month_year']) && !empty($_GET['month_year'])) {
    $parts = explode('-', $_GET['month_year']);
    if (count($parts) == 2) {
        $selected_year = (int)$parts[0];
        $selected_month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
    }
}

function get_applicable_fuel_price($conn, $rate_id, $datetime) { 
    $sql = "SELECT rate FROM fuel_rate WHERE rate_id = ? AND date <= ? ORDER BY date DESC LIMIT 1"; 
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return 0;
    $stmt->bind_param("ss", $rate_id, $datetime);
    $stmt->execute();
    $price = $stmt->get_result()->fetch_assoc()['rate'] ?? 0;
    $stmt->close();
    return (float)$price;
}

function get_monthly_attendance_records($conn, $emp_id, $vehicle_no, $month, $year) {
    $sql = "SELECT date, time FROM own_vehicle_attendance WHERE emp_id = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return [];
    $stmt->bind_param("ssii", $emp_id, $vehicle_no, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = [];
    while ($row = $result->fetch_assoc()) { $records[] = $row; }
    $stmt->close();
    return $records;
}

function get_monthly_extra_records($conn, $emp_id, $vehicle_no, $month, $year) {
    $sql = "SELECT date, out_time, distance FROM own_vehicle_extra WHERE emp_id = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ? AND done = 1 AND distance IS NOT NULL";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return [];
    $stmt->bind_param("ssii", $emp_id, $vehicle_no, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = [];
    while ($row = $result->fetch_assoc()) { $records[] = $row; }
    $stmt->close();
    return $records;
}

// --- MAIN DATA FETCH ---
$payment_data = [];
$employees_sql = "
    SELECT e.emp_id, e.calling_name, ov.vehicle_no, ov.fuel_efficiency AS consumption, ov.fixed_amount, ov.distance, ov.paid, ov.rate_id
    FROM own_vehicle ov
    JOIN employee e ON ov.emp_id = e.emp_id
    WHERE ov.is_active = 1
    ORDER BY e.calling_name ASC, ov.vehicle_no ASC;
";

$result = $conn->query($employees_sql);

if ($result && $result->num_rows > 0) {
    while ($employee_row = $result->fetch_assoc()) {
        $emp_id = $employee_row['emp_id'];
        $vehicle_no = $employee_row['vehicle_no']; 
        $rate_id = $employee_row['rate_id'];
        $is_paid_status = (int)$employee_row['paid'];
        $consumption = (float)$employee_row['consumption']; 
        $daily_distance = (float)$employee_row['distance'];
        $fixed_amount_val = (float)$employee_row['fixed_amount']; 

        $total_monthly_payment = 0.00; 
        $total_attendance_days = 0;
        $total_calculated_distance = 0.00;
        
        // පේමන්ට් එක එකතු කරන්නේ paid = 1 නම් පමණි
        if ($is_paid_status === 1) {
            $total_monthly_payment += $fixed_amount_val;
        }
        
        // 1. Attendance Processing
        $attendance_records = get_monthly_attendance_records($conn, $emp_id, $vehicle_no, (int)$selected_month, (int)$selected_year);
        foreach ($attendance_records as $record) {
            $total_attendance_days++; 
            $total_calculated_distance += $daily_distance; 

            if ($is_paid_status === 1) { 
                $datetime = $record['date'] . ' ' . $record['time']; 
                $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
                if ($fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
                    $day_rate = ($consumption / 100) * $daily_distance * $fuel_price;
                    $total_monthly_payment += $day_rate;
                }
            }
        }
        
        // 2. Extra Trips Processing
        $extra_records = get_monthly_extra_records($conn, $emp_id, $vehicle_no, (int)$selected_month, (int)$selected_year);
        foreach ($extra_records as $record) {
            $extra_dist = (float)$record['distance'];
            $total_calculated_distance += $extra_dist; 

            if ($is_paid_status === 1) {
                $datetime = $record['date'] . ' ' . $record['out_time'];
                $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
                if ($fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
                    $day_rate_base = ($consumption / 100) * $daily_distance * $fuel_price;
                    $rate_per_km = $day_rate_base / $daily_distance; 
                    $total_monthly_payment += ($rate_per_km * $extra_dist);
                }
            }
        }

        $payment_data[] = [
            'emp_id' => $emp_id,
            'vehicle_no' => $vehicle_no, 
            'is_paid' => $is_paid_status,
            'display_name' => $emp_id . ' - ' . $employee_row['calling_name'] . " (" . $vehicle_no . ")",
            'fixed_amount' => ($is_paid_status === 1) ? $fixed_amount_val : 0.00,
            'attendance_days' => $total_attendance_days,
            'total_distance' => $total_calculated_distance,
            'payments' => $total_monthly_payment,
        ];
    }
}

$table_headers = ["Employee (Vehicle No)", "Attendance Days", "Total Distance (km)", "Fixed Allowance (LKR)", "Total Payment (LKR)", "PDF"];

include('../../includes/header.php');
include('../../includes/navbar.php');
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
        .dropdown-menu { display: none; position: absolute; right: 0; top: 120%; z-index: 50; min-width: 220px; background-color: white; border: 1px solid #e5e7eb; border-radius: 0.75rem; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15); overflow: hidden; animation: slideDown 0.2s ease-out; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-gray-100">
<div id="pageLoader" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-gray-900 bg-opacity-90">
    <div class="flex flex-col items-center gap-4"><div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-yellow-400"></div><p class="text-gray-300 text-sm tracking-wide">Loading...</p></div>
</div>
<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3"><div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">Fuel Allowance Payments</div></div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <form method="get" action="own_vehicle_payments.php" class="flex items-center">
            <div class="relative">
                <select name="month_year" onchange="this.form.submit()" class="appearance-none bg-gray-800 text-white border border-gray-600 rounded-md py-1.5 pl-3 pr-8 text-xs focus:ring-1 focus:ring-yellow-500 cursor-pointer font-mono">
                    <?php 
                    $ly = $current_year_sys; $lm = $current_month_sys;
                    $limit_y = ($start_year > 0) ? $start_year : $current_year_sys - 2;
                    $limit_m = ($start_year > 0) ? $start_month : 1;
                    while (true) {
                        if ($ly < $limit_y || ($ly == $limit_y && $lm < $limit_m)) break;
                        $val = sprintf('%04d-%02d', $ly, $lm);
                        $lbl = date('F Y', mktime(0, 0, 0, $lm, 10, $ly));
                        $sel = ($selected_year == $ly && $selected_month == sprintf('%02d', $lm)) ? 'selected' : '';
                        echo "<option value='$val' $sel>$lbl</option>";
                        $lm--; if ($lm == 0) { $lm = 12; $ly--; }
                    }
                    ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-400"><i class="fas fa-chevron-down text-[10px]"></i></div>
            </div>
        </form>
        <span class="text-gray-600">|</span>
        <a href="download_own_vehicle_excel.php?month=<?= $selected_month ?>&year=<?= $selected_year ?>" class="bg-green-600 text-white px-3 py-1.5 rounded-md text-xs no-loader"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="own_vehicle_payments_done.php" class="bg-teal-600 text-white px-3 py-1.5 rounded-md text-xs"><i class="fas fa-check-circle"></i> Done</a>
        <a href="own_vehicle_payments_history.php" class="bg-yellow-600 text-white px-3 py-1.5 rounded-md text-xs"><i class="fas fa-history"></i> History</a>
    </div>
</div>

<div class="flex flex-col items-center mt-2 w-[85%] ml-[15%] p-2">
    <div class="w-full bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto max-h-[87vh] relative">
            <table class="min-w-full text-sm text-left">
                <thead class="bg-blue-600 text-white uppercase text-[11px] tracking-wider sticky top-0 z-10">
                    <tr>
                        <?php foreach ($table_headers as $index => $header): 
                            $align = ($index >= 1 && $index <= 4) ? 'text-right' : (($index == 5) ? 'text-center' : 'text-left'); ?>
                            <th class="py-3 px-6 font-semibold border-b border-blue-500 <?= $align ?>"><?= $header ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (!empty($payment_data)): ?>
                        <?php foreach ($payment_data as $data): ?>
                            <tr class="<?= ($data['is_paid'] === 0) ? 'bg-red-50' : 'hover:bg-indigo-50' ?> transition duration-150 group">
                                <td class="py-3 px-6 font-medium text-gray-800">
                                    <?= htmlspecialchars($data['display_name']) ?>
                                    <?php if($data['is_paid'] === 0): ?><span class="ml-2 text-[9px] bg-red-200 text-red-700 px-1.5 py-0.5 rounded font-bold uppercase italic">Unpaid</span><?php endif; ?>
                                </td>
                                <td class="py-3 px-6 text-right font-semibold text-gray-600"><?= number_format($data['attendance_days'], 0) ?></td>
                                <td class="py-3 px-6 text-right font-mono text-purple-600"><?= number_format($data['total_distance'], 2) ?></td>
                                <td class="py-3 px-6 text-right text-gray-500"><?= number_format($data['fixed_amount'], 2) ?></td>
                                <td class="py-3 px-6 text-right font-extrabold text-blue-700 text-base"><?= number_format($data['payments'], 2) ?></td>
                                <td class="py-3 px-6 text-center"> 
                                    <a href="download_own_vehicle_payments_pdf.php?emp_id=<?= $data['emp_id'] ?>&vehicle_no=<?= urlencode($data['vehicle_no']) ?>&month=<?= $selected_month ?>&year=<?= $selected_year ?>" class="text-red-500 p-2 no-loader" target="_blank"><i class="fas fa-file-pdf fa-lg"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="py-4 text-center text-gray-500">No payment data available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    const loader = document.getElementById("pageLoader");
    function showLoader(text = "Loading...") { loader.querySelector("p").innerText = text; loader.classList.remove("hidden"); loader.classList.add("flex"); }
    document.querySelectorAll("a").forEach(link => { link.addEventListener("click", () => { if (link.target !== "_blank" && !link.classList.contains("no-loader")) showLoader(); }); });
</script>
</body>
</html>
<?php $conn->close(); ?>