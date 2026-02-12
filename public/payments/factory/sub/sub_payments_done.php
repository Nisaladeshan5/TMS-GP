<?php
// sub_payments_done.php (Finalize Sub Route Payments)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

ob_start();

require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

date_default_timezone_set('Asia/Colombo');
include('../../../../includes/db.php'); 

// =======================================================================
// 0. HELPER FUNCTIONS (Sub Route Logic & Fuel Calculation)
// =======================================================================

function get_latest_fuel_price_by_rate_id($conn, $rate_id) {
    $sql = "SELECT rate FROM fuel_rate WHERE rate_id = ? ORDER BY date DESC, id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0; 
    $stmt->bind_param("i", $rate_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (float)($row['rate'] ?? 0);
}

function calculate_fuel_cost_per_km($conn, $vehicle_no) {
    if (empty($vehicle_no)) return 0;

    // Consumption rates mapping
    $consumption_rates = [];
    $res_c = $conn->query("SELECT c_id, distance FROM consumption");
    if ($res_c) while ($r = $res_c->fetch_assoc()) $consumption_rates[$r['c_id']] = (float)$r['distance'];

    $stmt = $conn->prepare("SELECT fuel_efficiency, rate_id FROM vehicle WHERE vehicle_no = ?");
    $stmt->bind_param("s", $vehicle_no);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$v) return 0;
    $price = get_latest_fuel_price_by_rate_id($conn, $v['rate_id']);
    $km_per_l = $consumption_rates[$v['fuel_efficiency']] ?? 1.0;
    return ($price > 0) ? ($price / $km_per_l) : 0;
}

function get_parent_route_attendance_count($conn, $parent_route_code, $month, $year) {
    $sql = "SELECT COUNT(DISTINCT date) as days_run FROM factory_transport_vehicle_register WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $parent_route_code, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)($row['days_run'] ?? 0);
}

function get_sub_route_adjustments_count($conn, $sub_route_code, $month, $year) {
    $sql = "SELECT SUM(adjustment_days) as total_adj FROM sub_route_adjustments WHERE sub_route_code = ? AND month = ? AND year = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $sub_route_code, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)($row['total_adj'] ?? 0);
}

// =======================================================================
// 1. PIN VERIFICATION
// =======================================================================
$today_pin = date('dmY'); 
$is_pin_correct = false;
$pin_message = '';

if (isset($_POST['pin_submit'])) {
    $entered_pin = filter_input(INPUT_POST, 'security_pin', FILTER_SANITIZE_SPECIAL_CHARS);
    if ($entered_pin === $today_pin) {
        $is_pin_correct = true;
    } else {
        $pin_message = "Invalid PIN. Please try again.";
    }
}

// =======================================================================
// 2. BACKEND API FOR PAYMENT FINALIZATION (AJAX)
// =======================================================================

if (isset($_POST['finalize_payments'])) {
    
    // Header eka yawanna kalin buffering clean karanna
    if (ob_get_length()) ob_end_clean(); 
    header('Content-Type: application/json');

    try {
        $target_date = new DateTime('first day of this month');
        $target_date->modify('-1 month'); 
        
        $finalize_month = (int)$target_date->format('m');
        $finalize_year = (int)$target_date->format('Y');
        $target_month_name = $target_date->format('F Y');

        $payment_data = []; 

        // 1. Fetch ACTIVE sub routes
        // per_day_rate kiyana eka fixed_rate widiyata gannawa (DB eke per_day_rate nam thiyenne)
        $sub_route_sql = "SELECT sub_route_code, route_code, supplier_code, fixed_rate, with_fuel, distance, vehicle_no FROM sub_route WHERE is_active = 1";
        $sub_route_result = $conn->query($sub_route_sql);

        if (!$sub_route_result) {
            throw new Exception("Database query error: " . $conn->error);
        }

        while ($row = $sub_route_result->fetch_assoc()) {
            $sub_code = $row['sub_route_code'];
            $parent_route = $row['route_code'];
            $fixed_rate = (float)$row['fixed_rate'];
            $distance = (float)$row['distance'];
            $v_no = $row['vehicle_no'];

            // 2. Calculate Fuel Rate per KM
            $fuel_rate = 0;
            if ((int)$row['with_fuel'] === 1) {
                $fuel_rate = calculate_fuel_cost_per_km($conn, $v_no);
            }

            // Total Daily = (Fixed + Fuel) * Distance
            $day_total = ($fixed_rate + $fuel_rate) * $distance;

            // 3. Attendance & Adjustments
            $base_days = get_parent_route_attendance_count($conn, $parent_route, $finalize_month, $finalize_year);
            $adj_days = get_sub_route_adjustments_count($conn, $sub_code, $finalize_month, $finalize_year);
            
            $final_days = max(0, $base_days + $adj_days);
            $total_payment = $final_days * $day_total;

            if ($final_days > 0 || $total_payment > 0) {
                 $payment_data[] = [
                    'sub_route_code' => $sub_code,
                    'supplier_code' => $row['supplier_code'],
                    'month' => $finalize_month,
                    'year' => $finalize_year,
                    'monthly_payment' => $total_payment,
                    'no_of_attendance_days' => $final_days,
                    'fixed_rate' => $fixed_rate,
                    'fuel_rate' => $fuel_rate,
                    'distance' => $distance
                ];
            }
        }

        if (empty($payment_data)) {
            echo json_encode(['status' => 'error', 'message' => "No attendance data found for $target_month_name."]);
            exit;
        }

        // Duplicate Check
        $dup_stmt = $conn->prepare("SELECT COUNT(*) FROM monthly_payments_sub WHERE month = ? AND year = ?");
        $dup_stmt->bind_param("ii", $finalize_month, $finalize_year);
        $dup_stmt->execute();
        $is_dup = (int)$dup_stmt->get_result()->fetch_row()[0] > 0;
        $dup_stmt->close();

        if ($is_dup) {
            echo json_encode(['status' => 'error', 'message' => "$target_month_name payments already finalized."]);
            exit;
        }
        
        // 4. DB Transaction Start
        $conn->begin_transaction();
        
        $insert_sql = "INSERT INTO monthly_payments_sub 
                       (sub_route_code, supplier_code, month, year, monthly_payment, no_of_attendance_days, fixed_rate, fuel_rate, distance) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $conn->prepare($insert_sql);
        $success_count = 0;

        foreach ($payment_data as $data) {
            $insert_stmt->bind_param("ssiididdd", 
                $data['sub_route_code'], 
                $data['supplier_code'], 
                $data['month'], 
                $data['year'], 
                $data['monthly_payment'], 
                $data['no_of_attendance_days'],
                $data['fixed_rate'],
                $data['fuel_rate'],
                $data['distance']
            );

            if (!$insert_stmt->execute()) {
                throw new Exception("Insert failed: " . $insert_stmt->error);
            }
            $success_count++;
        }
        $insert_stmt->close();
        $conn->commit();

        echo json_encode(['status' => 'success', 'message' => "Successfully finalized $success_count payments for $target_month_name!"]);

    } catch (Exception $e) {
        if(isset($conn)) $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit; 
}

// =======================================================================
// 3. HTML DISPLAY LOGIC
// =======================================================================

if (!$is_pin_correct) {
    ob_end_clean();
    include('../../../../includes/header.php');
    include('../../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sub Route PIN Access</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100 font-sans">
<div id="pageLoader" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-gray-900 bg-opacity-90">
    <div class="flex flex-col items-center gap-4"><div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-yellow-400"></div><p class="text-gray-300">Loading...</p></div>
</div>

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3"><span class="text-sm font-bold uppercase tracking-wider">Finalize Sub Route Payments</span></div>
    <div class="flex items-center gap-4 text-sm font-medium"><a href="sub_route_payments.php" class="text-gray-300 hover:text-white">Back</a></div>
</div>

<main class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex justify-center items-center">
    <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200 w-full max-w-md">
        <div class="text-center mb-6">
            <div class="bg-blue-100 text-blue-600 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl"><i class="fas fa-shield-alt"></i></div>
            <h2 class="text-2xl font-bold">Security Check</h2>
            <p class="text-sm text-gray-500 mt-2">Enter today's PIN (DDMMYYYY).</p>
        </div>
        <?php if ($pin_message): ?><div class="bg-red-50 text-red-700 p-3 rounded mb-6 text-sm"><?= $pin_message ?></div><?php endif; ?>
        <form method="post">
            <input type="password" name="security_pin" maxlength="8" required class="w-full px-4 py-3 border rounded-lg text-center text-xl tracking-[0.5em] font-mono mb-6" placeholder="••••••••" autofocus>
            <button type="submit" name="pin_submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-lg hover:bg-blue-700 transition">Verify Access</button>
        </form>
    </div>
</main>
</body>
</html>
<?php exit(); }

// --- SUCCESS UI (AFTER PIN) ---
$target_date = new DateTime('first day of this month');
$target_date->modify('-1 month'); 
$avail_month = (int)$target_date->format('m');
$avail_year = (int)$target_date->format('Y');
$avail_name = $target_date->format('F Y');

// Status Checks
$is_done = $conn->query("SELECT 1 FROM monthly_payments_sub WHERE month=$avail_month AND year=$avail_year LIMIT 1")->num_rows > 0;
$has_data = $conn->query("SELECT 1 FROM factory_transport_vehicle_register WHERE MONTH(date)=$avail_month AND YEAR(date)=$avail_year LIMIT 1")->num_rows > 0;

include('../../../../includes/header.php');
include('../../../../includes/navbar.php');
ob_end_flush(); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Finalize Sub Route Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100 font-sans">
<div id="pageLoader" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-gray-900 bg-opacity-90">
    <div class="flex flex-col items-center gap-4"><div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-yellow-400"></div><p class="text-gray-300">Processing...</p></div>
</div>

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3"><span class="text-sm font-bold uppercase">Finalize Payments</span></div>
    <div class="flex items-center gap-4 text-sm font-medium"><a href="sub_route_payments.php" class="text-gray-300 hover:text-white">Cancel</a></div>
</div>

<main class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex justify-center items-start mt-10">
    <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200 w-full max-w-lg text-center">
        <h2 class="text-2xl font-bold mb-2">Month End Process</h2>
        <p class="text-sm text-gray-500 mb-8">Process for <strong><?= $avail_name ?></strong></p>

        <div class="mb-8 p-6 rounded-xl border <?= $is_done ? 'bg-green-50' : ($has_data ? 'bg-blue-50' : 'bg-yellow-50') ?>">
            <?php if ($is_done): ?>
                <i class="fas fa-check-circle text-green-500 text-4xl mb-3"></i>
                <h3 class="font-bold text-green-800">Finalized</h3>
                <p class="text-sm">Payments are already saved to history.</p>
            <?php elseif (!$has_data): ?>
                <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-3"></i>
                <h3 class="font-bold text-yellow-800">No Data</h3>
                <p class="text-sm">No attendance records found to process.</p>
            <?php else: ?>
                <i class="fas fa-file-invoice-dollar text-blue-500 text-4xl mb-3"></i>
                <h3 class="font-bold text-blue-800">Data Ready</h3>
                <p class="text-sm">Ready to finalize <strong><?= $avail_name ?></strong> payments.</p>
            <?php endif; ?>
        </div>

        <?php if (!$is_done && $has_data): ?>
            <button id="finalizeButton" class="w-full py-4 bg-green-600 text-white font-bold text-lg rounded-lg hover:bg-green-700 transition">Save & Finalize Now</button>
        <?php else: ?>
            <a href="sub_payments_history.php" class="block w-full py-3 bg-gray-800 text-white font-bold rounded-lg hover:bg-gray-900 transition">View History</a>
        <?php endif; ?>
    </div>
</main>

<script>
    const finalizeButton = document.getElementById('finalizeButton');
    if (finalizeButton) {
        finalizeButton.addEventListener('click', function() {
            if (confirm("Finalize all Sub Route payments for <?= $avail_name ?>?")) {
                document.getElementById("pageLoader").classList.remove("hidden");
                document.getElementById("pageLoader").classList.add("flex");
                
                fetch('sub_payments_done.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'finalize_payments=true'
                })
                .then(r => r.json())
                .then(data => {
                    alert(data.message);
                    if (data.status === 'success') window.location.href = 'sub_payments_history.php';
                    else document.getElementById("pageLoader").classList.add("hidden");
                });
            }
        });
    }
</script>
</body>
</html>
<?php if (isset($conn)) $conn->close(); ?>