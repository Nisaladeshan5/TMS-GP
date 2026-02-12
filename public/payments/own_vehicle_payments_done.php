<?php
// own_vehicle_payments_done.php (Finalize Own Vehicle Payments - BUTTON LOADING MECHANISM)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

ob_start();

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

date_default_timezone_set('Asia/Colombo');
include('../../includes/db.php'); 

// =======================================================================
// 0. HELPER FUNCTIONS
// =======================================================================

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

function calculate_own_vehicle_payment($conn, $emp_id, $vehicle_no, $consumption, $daily_distance, $fixed_amount_orig, $rate_id, $month, $year, $is_paid) { 
    $consumption = (float)$consumption;
    $daily_distance = (float)$daily_distance;
    $fixed_amount = ($is_paid === 1) ? (float)$fixed_amount_orig : 0.00;
    
    $total_monthly_payment = $fixed_amount; 
    $total_attendance_days = 0;
    $total_calculated_distance = 0.00;
    
    // Attendance Records
    $attendance_sql = "SELECT date, time FROM own_vehicle_attendance WHERE emp_id = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ?";
    $att_stmt = $conn->prepare($attendance_sql);
    $att_stmt->bind_param("ssii", $emp_id, $vehicle_no, $month, $year);
    $att_stmt->execute();
    $attendance_records = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $att_stmt->close();

    // Extra Trip Records
    $extra_sql = "SELECT date, out_time, distance FROM own_vehicle_extra WHERE emp_id = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ? AND done = 1 AND distance IS NOT NULL";
    $extra_stmt = $conn->prepare($extra_sql);
    $extra_stmt->bind_param("ssii", $emp_id, $vehicle_no, $month, $year);
    $extra_stmt->execute();
    $extra_records = $extra_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $extra_stmt->close();
    
    foreach ($attendance_records as $record) {
        $datetime = $record['date'] . ' ' . $record['time']; 
        $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
        if ($fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
             if ($is_paid === 1) {
                $day_rate = ($consumption / 100) * $daily_distance * $fuel_price;
                $total_monthly_payment += $day_rate;
             }
             $total_calculated_distance += $daily_distance;
             $total_attendance_days++;
        }
    }
    
    foreach ($extra_records as $record) {
        $datetime = $record['date'] . ' ' . $record['out_time'];
        $extra_dist = (float)$record['distance'];
        $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
        if ($fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
            if ($is_paid === 1) {
                $day_rate_base = ($consumption / 100) * $daily_distance * $fuel_price;
                $rate_per_km = $day_rate_base / $daily_distance; 
                $total_monthly_payment += ($rate_per_km * $extra_dist);
            }
            $total_calculated_distance += $extra_dist;
        }
    }

    return [
        'monthly_payment' => $total_monthly_payment, 
        'attendance_days' => $total_attendance_days,
        'total_distance' => $total_calculated_distance,
        'fixed_amount' => $fixed_amount,
    ];
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
    ob_end_clean(); 
    header('Content-Type: application/json');

    try {
        $target_date = new DateTime('first day of this month');
        $target_date->modify('-1 month'); 
        $finalize_month = (int)$target_date->format('m');
        $finalize_year = (int)$target_date->format('Y');

        $vehicle_sql = "SELECT emp_id, vehicle_no, fuel_efficiency, fixed_amount, distance, rate_id, paid FROM own_vehicle WHERE is_active = 1";
        $vehicle_result = $conn->query($vehicle_sql);

        $payment_data = []; 
        while ($ov_row = $vehicle_result->fetch_assoc()) {
            $results = calculate_own_vehicle_payment(
                $conn, $ov_row['emp_id'], $ov_row['vehicle_no'],
                $ov_row['fuel_efficiency'], $ov_row['distance'], 
                $ov_row['fixed_amount'], $ov_row['rate_id'], 
                $finalize_month, $finalize_year, (int)$ov_row['paid']
            );
            
            if ($results['attendance_days'] > 0 || $results['total_distance'] > 0 || $results['fixed_amount'] > 0) {
                 $payment_data[] = [
                    'emp_id' => $ov_row['emp_id'], 
                    'vehicle_no' => $ov_row['vehicle_no'],
                    'month' => $finalize_month, 'year' => $finalize_year,
                    'no_of_attendance' => $results['attendance_days'],
                    'distance' => $results['total_distance'], 
                    'monthly_payment' => $results['monthly_payment'],
                    'fixed_amount' => $results['fixed_amount'],
                ];
            }
        }

        if (empty($payment_data)) {
            echo json_encode(['status' => 'error', 'message' => "No records found for processing."]);
            exit;
        }

        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM own_vehicle_payments WHERE month = ? AND year = ?");
        $check_stmt->bind_param("ii", $finalize_month, $finalize_year);
        $check_stmt->execute();
        if ((int)$check_stmt->get_result()->fetch_row()[0] > 0) {
            echo json_encode(['status' => 'error', 'message' => "Payments for this month already finalized."]);
            exit;
        }

        $conn->begin_transaction();
        $insert_sql = "INSERT INTO own_vehicle_payments (emp_id, vehicle_no, month, year, monthly_payment, no_of_attendance, distance, fixed_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);

        foreach ($payment_data as $data) {
            $insert_stmt->bind_param("ssiididd", 
                $data['emp_id'], $data['vehicle_no'], $data['month'], $data['year'], 
                $data['monthly_payment'], $data['no_of_attendance'], $data['distance'], 
                $data['fixed_amount']
            );
            $insert_stmt->execute();
        }
        $insert_stmt->close();
        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => "Successfully finalized " . count($payment_data) . " records!"]);

    } catch (Exception $e) {
        if ($conn->in_transaction) $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit; 
}

// --- PIN FORM DISPLAY ---
if (!$is_pin_correct) {
    ob_end_clean(); ob_start();
    include('../../includes/header.php'); include('../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PIN Access</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">
<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2">
            <a href="own_vehicle_payments.php" class="text-md font-bold bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">Own Vehicle</a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider">Finalize</span>
        </div>
    </div>
    <a href="own_vehicle_payments.php" class="text-gray-300 hover:text-white transition">Back</a>
</div>
<main class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex justify-center items-center">
    <div class="bg-white p-8 rounded-xl shadow-lg border w-full max-w-md">
        <div class="text-center mb-6">
            <div class="bg-blue-100 text-blue-600 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl"><i class="fas fa-shield-alt"></i></div>
            <h2 class="text-2xl font-bold">Security Check</h2>
        </div>
        <?php if (!empty($pin_message)): ?><div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-3 rounded mb-6 text-sm"><?php echo $pin_message; ?></div><?php endif; ?>
        <form method="post" action="own_vehicle_payments_done.php">
            <input type="password" name="security_pin" maxlength="8" required class="w-full px-4 py-3 border rounded-lg text-center text-xl tracking-[0.5em] font-mono outline-none focus:ring-2 focus:ring-blue-500 mb-6" placeholder="••••••••">
            <button type="submit" name="pin_submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-lg hover:bg-blue-700 transition">Verify Access</button>
        </form>
    </div>
</main>
</body>
</html>
<?php exit(); }

// --- MAIN BUTTON DISPLAY ---
$payment_available_date = new DateTime('first day of this month');
$payment_available_date->modify('-1 month'); 
$available_month_name = $payment_available_date->format('F Y');
$available_month = (int)$payment_available_date->format('m');
$available_year = (int)$payment_available_date->format('Y');

$is_payment_already_done = false;
$check_done_stmt = $conn->prepare("SELECT COUNT(*) FROM own_vehicle_payments WHERE month = ? AND year = ?");
$check_done_stmt->bind_param("ii", $available_month, $available_year);
$check_done_stmt->execute();
if ((int)$check_done_stmt->get_result()->fetch_row()[0] > 0) $is_payment_already_done = true;

$data_exists = false;
$data_exists_stmt = $conn->prepare("SELECT 1 FROM own_vehicle_attendance WHERE MONTH(date) = ? AND YEAR(date) = ? LIMIT 1");
$data_exists_stmt->bind_param("ii", $available_month, $available_year);
$data_exists_stmt->execute();
if ($data_exists_stmt->get_result()->num_rows > 0) $data_exists = true;

include('../../includes/header.php'); include('../../includes/navbar.php');
ob_end_flush(); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Finalize Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">
<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 z-50 border-b border-gray-700 shadow-lg">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2">
            <a href="own_vehicle_payments.php" class="text-md font-bold bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">Own Vehicle</a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">Finalize Payments</span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="own_vehicle_payments.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            <i class="fas fa-calculator"></i> Current Calculations
        </a>
    </div>
</div>

<main class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex justify-center items-start mt-10">
    <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200 w-full max-w-lg text-center">
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Month End Process</h2>
        <p class="text-sm text-gray-500 mb-8">Process for <strong><?php echo $available_month_name; ?></strong></p>

        <div id="statusMessage" class="mb-8">
            <?php if ($is_payment_already_done): ?>
                <div class="bg-green-50 border border-green-200 rounded-xl p-6">
                    <div class="bg-green-100 text-green-600 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3 text-xl"><i class="fas fa-check"></i></div>
                    <h3 class="text-lg font-bold text-green-800">Completed</h3>
                    <p class="text-green-700 text-sm mt-1">Payments already finalized for <strong><?php echo $available_month_name; ?></strong>.</p>
                </div>
            <?php elseif (!$data_exists): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">
                    <div class="bg-yellow-100 text-yellow-600 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3 text-xl"><i class="fas fa-search"></i></div>
                    <h3 class="text-lg font-bold text-yellow-800">No Data</h3>
                    <p class="text-yellow-700 text-sm mt-1">No attendance found for <strong><?php echo $available_month_name; ?></strong>.</p>
                </div>
            <?php else: ?>
                <div class="bg-blue-50 border border-blue-200 rounded-xl p-6">
                    <div class="bg-blue-100 text-blue-600 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3 text-xl"><i class="fas fa-file-invoice-dollar"></i></div>
                    <h3 class="text-lg font-bold text-blue-800">Ready to Finalize</h3>
                    <p class="text-blue-700 text-sm mt-1">Confirm to save payments for <strong><?php echo $available_month_name; ?></strong></p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$is_payment_already_done && $data_exists): ?>
            <button id="finalizeButton" class="w-full py-3.5 bg-green-600 text-white font-bold text-lg rounded-lg shadow-md hover:bg-green-700 transition transform hover:scale-[1.02] flex justify-center items-center gap-2">
                <i class="fas fa-save"></i> <span>Save & Finalize</span>
            </button>
            <p class="text-xs text-gray-400 mt-3 italic">Permanent action. Historical data will be generated.</p>
        <?php else: ?>
            <a href="own_vehicle_payments_history.php" class="inline-flex items-center justify-center gap-2 w-full py-3 bg-gray-800 text-white font-semibold rounded-lg hover:bg-gray-900 transition shadow-md">
                <i class="fas fa-history"></i> View History
            </a>
        <?php endif; ?>
    </div>
</main>

<script>
    const finalizeButton = document.getElementById('finalizeButton');
    if (finalizeButton) {
        finalizeButton.addEventListener('click', function() {
            if (confirm("Confirm Finalization for <?php echo $available_month_name; ?>?")) {
                
                // --- Staff payments mechanism: Update button state ---
                finalizeButton.disabled = true;
                finalizeButton.classList.add('opacity-75', 'cursor-not-allowed');
                finalizeButton.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Processing...</span>';

                fetch('own_vehicle_payments_done.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'finalize_payments=true'
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload(); 
                    } else {
                        alert("Failed: " + data.message);
                        finalizeButton.disabled = false;
                        finalizeButton.innerHTML = '<i class="fas fa-save"></i> <span>Save & Finalize</span>';
                        finalizeButton.classList.remove('opacity-75', 'cursor-not-allowed');
                    }
                })
                .catch(err => {
                    alert("Error processing request.");
                    finalizeButton.disabled = false;
                    finalizeButton.innerHTML = '<i class="fas fa-save"></i> <span>Save & Finalize</span>';
                    finalizeButton.classList.remove('opacity-75', 'cursor-not-allowed');
                });
            }
        });
    }
</script>
</body>
</html>
<?php if (isset($conn)) $conn->close(); ?>