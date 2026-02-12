<?php
// CRITICAL: Ensure no output occurs before headers in AJAX mode
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Start output buffering immediately
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
if (!isset($conn) || $conn->connect_error) {
    error_log("FATAL: Database connection failed.");
}

// =======================================================================
// 0. HELPER FUNCTIONS (REQUIRED for Calculation/Insertion Logic)
// =======================================================================

// A. Fetch Fuel Price changes within the selected Month and Year
function get_fuel_price_changes_in_month($conn, $rate_id, $month, $year)
{
    $rate_id = (int)$rate_id;
    $start_date = "$year-$month-01";
    $end_date = date('Y-m-t', strtotime($start_date)); 

    $sql = "SELECT date, rate FROM fuel_rate WHERE rate_id = ? AND date <= ? ORDER BY date DESC, id DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    
    $stmt->bind_param("is", $rate_id, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $changes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $slabs = [];
    foreach ($changes as $change) {
        $change_date = $change['date'];
        $rate = (float)$change['rate'];
        if (strtotime($change_date) < strtotime($start_date)) {
            $slabs[date('Y-m-d', strtotime($start_date))] = $rate;
            break; 
        }
        $slabs[$change_date] = $rate;
    }
    ksort($slabs);
    return $slabs;
}

// B. Core Calculation Logic
function calculate_total_payment($conn, $route_code, $supplier_code, $month, $year, $route_distance, $fixed_amount, $with_fuel, $consumption_id, $rate_id, $consumption_rates, $default_km_per_liter)
{
    $route_distance = (float)$route_distance;
    $fixed_amount = (float)$fixed_amount;
    $with_fuel = (int)$with_fuel;
    
    $trips_sql = "SELECT date, COUNT(id) AS daily_trips FROM staff_transport_vehicle_register WHERE route = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1 GROUP BY date";
    $trips_stmt = $conn->prepare($trips_sql);
    if (!$trips_stmt) return ['total_payment' => 0, 'total_trips' => 0, 'effective_trip_rate' => 0];
    
    $trips_stmt->bind_param("ssii", $route_code, $supplier_code, $month, $year);
    $trips_stmt->execute();
    $daily_trip_counts = $trips_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $trips_stmt->close();
    
    $total_calculated_payment = 0.00;
    $total_trip_count = 0;
    $trip_rate = 0.00; 

    if (empty($daily_trip_counts)) return ['total_payment' => 0, 'total_trips' => 0, 'effective_trip_rate' => 0];
    
    $price_slabs = [];
    if ($with_fuel === 1 && $rate_id !== null) {
        $price_slabs = get_fuel_price_changes_in_month($conn, $rate_id, $month, $year);
    }
    
    $km_per_liter = (float)($consumption_rates[$consumption_id] ?? $default_km_per_liter);
    if ($km_per_liter <= 0) $km_per_liter = $default_km_per_liter;

    foreach ($daily_trip_counts as $daily_data) {
        $trip_date = $daily_data['date'];
        $daily_trips = (int)$daily_data['daily_trips'];
        $total_trip_count += $daily_trips;

        $latest_fuel_price = 0.00;
        if ($with_fuel === 1 && !empty($price_slabs)) {
            foreach ($price_slabs as $change_date => $rate) {
                if (strtotime($trip_date) >= strtotime($change_date)) $latest_fuel_price = (float)$rate;
            }
        }
        
        $calculated_fuel_amount_per_km = 0.00;
        if ($with_fuel === 1 && $consumption_id !== null && $latest_fuel_price > 0) {
            $calculated_fuel_amount_per_km = $latest_fuel_price / $km_per_liter;
        }

        $rate_per_km = $fixed_amount + $calculated_fuel_amount_per_km;
        $trip_rate = ($route_distance > 0) ? ($rate_per_km * ($route_distance / 2)) : 0.00; 
        $daily_payment = $trip_rate * $daily_trips;
        $total_calculated_payment += $daily_payment;
    }

    return ['total_payment' => $total_calculated_payment, 'total_trips' => $total_trip_count, 'effective_trip_rate' => $trip_rate];
}

// C. Get Total Reduction Amount
function get_total_adjustment_amount($conn, $route_code, $supplier_code, $month, $year)
{
    $total_adjustment = 0.00;
    $reduction_sql = "SELECT SUM(amount) AS total_adjustment_amount FROM reduction WHERE route_code = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
    $reduction_stmt = $conn->prepare($reduction_sql);
    
    if (!$reduction_stmt) return 0.00; 
    
    $reduction_stmt->bind_param("ssii", $route_code, $supplier_code, $month, $year);
    if ($reduction_stmt->execute()) {
        $row = $reduction_stmt->get_result()->fetch_assoc();
        $total_adjustment = (float)($row['total_adjustment_amount'] ?? 0); 
    }
    $reduction_stmt->close();
    return $total_adjustment;
}


// =======================================================================
// 1. PIN VERIFICATION & AJAX CHECK SETUP
// =======================================================================

$today_pin = date('dmY');
$is_pin_correct = false;
$pin_message = '';

if (isset($_POST['pin_submit'])) {
    $entered_pin = filter_input(INPUT_POST, 'security_pin', FILTER_SANITIZE_SPECIAL_CHARS);
    $entered_pin = (string)$entered_pin;
    if ($entered_pin === $today_pin) {
        $is_pin_correct = true;
    } else {
        $pin_message = "Invalid PIN. Please try again.";
    }
}

// =======================================================================
// 2. BACKEND API FOR PAYMENT FINALIZATION (AJAX) - PRIORITY EXECUTION
// =======================================================================

if (isset($_POST['finalize_payments'])) {
    
    ob_end_clean(); 
    header('Content-Type: application/json');

    try {
        $target_date = new DateTime('first day of this month');
        $target_date->modify('-1 month'); 
        
        $finalize_month = (int)$target_date->format('m');
        $finalize_year = (int)$target_date->format('Y');

        $selected_month = $finalize_month;
        $selected_year = $finalize_year;
        $payment_data = []; 

        $consumption_rates = [];
        $consumption_result = $conn->query("SELECT c_id, distance FROM consumption"); 
        if ($consumption_result) {
            while ($row = $consumption_result->fetch_assoc()) {
                $consumption_rates[$row['c_id']] = (float)$row['distance']; 
            }
        }
        $default_km_per_liter = 1.00;

        $payments_sql = "
            SELECT stvr.route AS route_code, stvr.supplier_code, r.route, r.fixed_amount, r.distance AS route_distance, r.with_fuel, v.fuel_efficiency, v.rate_id 
            FROM staff_transport_vehicle_register stvr 
            JOIN route r ON stvr.route = r.route_code 
            LEFT JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
            WHERE MONTH(stvr.date) = ? AND YEAR(stvr.date) = ? AND r.purpose = 'staff'
            GROUP BY stvr.route, stvr.supplier_code
        ";

        $payments_stmt = $conn->prepare($payments_sql);
        if (!$payments_stmt) { echo json_encode(['status' => 'error', 'message' => "SQL Error: " . $conn->error]); exit; }
        
        $payments_stmt->bind_param("ii", $selected_month, $selected_year);
        if (!$payments_stmt->execute()) { echo json_encode(['status' => 'error', 'message' => "SQL Exec Error: " . $payments_stmt->error]); exit; }
        
        $payments_result = $payments_stmt->get_result();

        if ($payments_result && $payments_result->num_rows > 0) {
            while ($payment_row = $payments_result->fetch_assoc()) {
                $route_code = $payment_row['route_code'];
                $supplier_code = $payment_row['supplier_code'];
                $route_distance = (float)($payment_row['route_distance'] ?? 0.0); 
                $fixed_amount = (float)($payment_row['fixed_amount'] ?? 0.0); 
                $with_fuel = (int)($payment_row['with_fuel'] ?? 0);
                
                if ($route_distance <= 0) continue; 
                
                $calculation_results = calculate_total_payment(
                    $conn, $route_code, $supplier_code, $selected_month, $selected_year,
                    $route_distance, $fixed_amount, $with_fuel, $payment_row['fuel_efficiency'] ?? null, $payment_row['rate_id'] ?? null,
                    $consumption_rates, $default_km_per_liter
                );

                if ($calculation_results['total_trips'] === 0) continue; 

                $adjustment_vs_db = get_total_adjustment_amount($conn, $route_code, $supplier_code, $selected_month, $selected_year) * -1; 
                $calculated_total_payment = $calculation_results['total_payment'] + $adjustment_vs_db; 

                $total_trip_count = $calculation_results['total_trips'];
                $trip_rate = $calculation_results['effective_trip_rate']; 
                $total_distance_calculated = ($route_distance / 2) * $total_trip_count;
                
                $fixed_payment_per_trip = $fixed_amount * ((float)$route_distance / 2);
                $fuel_payment_per_trip = $trip_rate - $fixed_payment_per_trip;

                $one_way_distance = (float)$route_distance / 2;
                $fuel_payment_per_km = ($one_way_distance > 0) ? ($fuel_payment_per_trip / $one_way_distance) : 0.00;

                $payment_data[] = [
                    'route_code' => $route_code, 'supplier_code' => $supplier_code, 
                    'fixed_amount' => $fixed_amount, 'fuel_amount' => $fuel_payment_per_km,
                    'route_distance' => $route_distance, 'total_distance' => $total_distance_calculated, 
                    'monthly_payment' => $calculated_total_payment, 
                    'month' => $finalize_month, 'year' => $finalize_year,
                ];
            }
            $payments_stmt->close();
        }
        
        if (empty($payment_data)) { echo json_encode(['status' => 'error', 'message' => "No trips found for " . $target_date->format('F Y')]); exit; }

        // Check Duplicates
        $duplicate_check_sql = "SELECT COUNT(*) FROM monthly_payments_sf WHERE month = ? AND year = ?";
        $duplicate_check_stmt = $conn->prepare($duplicate_check_sql);
        $duplicate_check_stmt->bind_param("ii", $finalize_month, $finalize_year);
        $duplicate_check_stmt->execute();
        $count = (int)$duplicate_check_stmt->get_result()->fetch_row()[0];
        $duplicate_check_stmt->close();

        if ($count > 0) { echo json_encode(['status' => 'error', 'message' => "Payments for " . $target_date->format('F Y') . " already finalized."]); exit; }
        
        // Insert Data
        $conn->begin_transaction();
        $success_count = 0;
        $error_occurred = false;
        $specific_error = "";

        $insert_sql = "INSERT INTO monthly_payments_sf (route_code, supplier_code, month, year, fixed_amount, fuel_amount, route_distance, monthly_payment, total_distance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);

        foreach ($payment_data as $data) {
            $insert_stmt->bind_param("ssiiddidd", 
                $data['route_code'], $data['supplier_code'], $data['month'], $data['year'], 
                $data['fixed_amount'], $data['fuel_amount'], $data['route_distance'], 
                $data['monthly_payment'], $data['total_distance']
            );

            if (!$insert_stmt->execute()) {
                $error_occurred = true;
                $specific_error = $insert_stmt->error;
                break; 
            }
            $success_count++;
        }
        $insert_stmt->close();

        if ($error_occurred) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => "Transaction Failed: " . $specific_error]);
        } else {
            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => "Finalized $success_count records for " . $target_date->format('F Y') . "!"]);
        }

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => "System Error: " . $e->getMessage()]);
    }
    exit; 
}

// =======================================================================
// 3. HTML DISPLAY LOGIC
// =======================================================================

// --- PIN FORM DISPLAY ---
if (!$is_pin_correct) {
    ob_end_clean();
    ob_start();
    include('../../includes/header.php');
    include('../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PIN Access</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">
<div id="pageLoader" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-gray-900 bg-opacity-90">
    <div class="flex flex-col items-center gap-4">
        <div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-yellow-400"></div>
        <p class="text-gray-300 text-sm tracking-wide">Loading...</p>
    </div>
</div>
<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="payments_category.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Staff Payments
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Finalize Payments
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="payments_category.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            Back
        </a>
    </div>
</div>

<main class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex justify-center items-center">
    <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200 w-full max-w-md">
        <div class="text-center mb-6">
            <div class="bg-blue-100 text-blue-600 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-800">Security Check</h2>
            <p class="text-sm text-gray-500 mt-2">Enter today's PIN to access finalization.</p>
        </div>
        
        <?php if (!empty($pin_message)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-3 rounded mb-6 text-sm flex items-center gap-2">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($pin_message); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="payments_done.php">
            <div class="mb-6">
                <input type="password" name="security_pin" id="security_pin" maxlength="8" required 
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-center text-xl tracking-[0.5em] font-mono transition"
                       placeholder="••••••••" autocomplete="off" autofocus>
            </div>
            <button type="submit" name="pin_submit" 
                    class="w-full bg-blue-600 text-white font-bold py-3 rounded-lg shadow-md hover:bg-blue-700 transition transform hover:scale-105 flex justify-center items-center gap-2">
                Verify Access <i class="fas fa-arrow-right"></i>
            </button>
        </form>
    </div>
</main>
<script>
    // 1. PIN Form එක Submit වෙද්දි Loader පෙන්නන්න
    document.querySelector("form").addEventListener("submit", function() {
        const loader = document.getElementById("pageLoader");
        loader.querySelector("p").innerText = "Verifying PIN...";
        loader.classList.remove("hidden");
        loader.classList.add("flex");
    });

    // 2. Back Button එක (හෝ වෙනත් Link) Click කරද්දි Loader පෙන්නන්න
    document.querySelectorAll("a").forEach(link => {
        link.addEventListener("click", function () {
            const loader = document.getElementById("pageLoader");
            loader.querySelector("p").innerText = "Going Back...";
            loader.classList.remove("hidden");
            loader.classList.add("flex");
        });
    });
</script>
</body>
</html>
<?php
    exit(); 
}

// --- MAIN BUTTON DISPLAY (PIN CORRECT) ---

$payment_available_date = new DateTime('first day of this month');
$payment_available_date->modify('-1 month'); 
$available_month = (int)$payment_available_date->format('m');
$available_year = (int)$payment_available_date->format('Y');
$available_month_name = $payment_available_date->format('F Y');

$is_payment_already_done = false;
$check_done_stmt = $conn->prepare("SELECT COUNT(*) FROM monthly_payments_sf WHERE month = ? AND year = ? LIMIT 1");
if ($check_done_stmt) {
    $check_done_stmt->bind_param("ii", $available_month, $available_year);
    $check_done_stmt->execute();
    if ((int)$check_done_stmt->get_result()->fetch_row()[0] > 0) $is_payment_already_done = true;
    $check_done_stmt->close();
}

$data_exists = false;
$data_exists_stmt = $conn->prepare("SELECT 1 FROM staff_transport_vehicle_register stvr JOIN route r ON stvr.route = r.route_code WHERE MONTH(stvr.date) = ? AND YEAR(stvr.date) = ? AND r.purpose = 'staff' LIMIT 1");
if ($data_exists_stmt) {
    $data_exists_stmt->bind_param("ii", $available_month, $available_year);
    $data_exists_stmt->execute();
    if ($data_exists_stmt->get_result()->num_rows > 0) $data_exists = true;
    $data_exists_stmt->close();
}

include('../../includes/header.php');
include('../../includes/navbar.php');
ob_end_flush(); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finalize Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">
<div id="pageLoader" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-gray-900 bg-opacity-90">
    <div class="flex flex-col items-center gap-4">
        <div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-yellow-400"></div>
        <p class="text-gray-300 text-sm tracking-wide">Loading...</p>
    </div>
</div>
<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="payments_category.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Staff Payments
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Finalize Payments
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="payments_category.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            <i class="fas fa-calculator"></i> Current Calculations
        </a>
    </div>
</div>

<main class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex justify-center items-start mt-10">
    <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200 w-full max-w-lg text-center">
        
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Month End Process</h2>
        <p class="text-sm text-gray-500 mb-8">Finalize staff route payments for the previous month.</p>

        <div id="statusMessage" class="mb-8">
            <?php if ($is_payment_already_done): ?>
                <div class="bg-green-50 border border-green-200 rounded-xl p-6">
                    <div class="bg-green-100 text-green-600 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3 text-xl">
                        <i class="fas fa-check"></i>
                    </div>
                    <h3 class="text-lg font-bold text-green-800">Completed</h3>
                    <p class="text-green-700 text-sm mt-1">Payments for <strong><?php echo htmlspecialchars($available_month_name); ?></strong> are already finalized.</p>
                </div>
            <?php elseif (!$data_exists): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">
                    <div class="bg-yellow-100 text-yellow-600 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3 text-xl">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3 class="text-lg font-bold text-yellow-800">No Data</h3>
                    <p class="text-yellow-700 text-sm mt-1">No trip records found for <strong><?php echo htmlspecialchars($available_month_name); ?></strong>.</p>
                </div>
            <?php else: ?>
                <div class="bg-blue-50 border border-blue-200 rounded-xl p-6">
                    <div class="bg-blue-100 text-blue-600 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3 text-xl">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <h3 class="text-lg font-bold text-blue-800">Ready to Finalize</h3>
                    <p class="text-blue-700 text-sm mt-1">
                        Please confirm to save payments for <br>
                        <strong class="text-lg"><?php echo htmlspecialchars($available_month_name); ?></strong>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$is_payment_already_done && $data_exists): ?>
            <button id="finalizeButton" 
                    class="w-full py-3.5 bg-green-600 text-white font-bold text-lg rounded-lg shadow-md hover:bg-green-700 transition transform hover:scale-[1.02] flex justify-center items-center gap-2">
                <i class="fas fa-save"></i> Save & Finalize
            </button>
            <p class="text-xs text-gray-400 mt-3">This action saves data to history and cannot be undone here.</p>
        <?php else: ?>
            <a href="payments_history.php" class="inline-flex items-center justify-center gap-2 w-full py-3 bg-gray-800 text-white font-semibold rounded-lg hover:bg-gray-900 transition shadow-md">
                <i class="fas fa-history"></i> View History
            </a>
        <?php endif; ?>

    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const finalizeButton = document.getElementById('finalizeButton');
        const statusMessage = document.getElementById('statusMessage');
        const targetMonth = "<?php echo htmlspecialchars($available_month_name); ?>";

        if (finalizeButton) {
            finalizeButton.addEventListener('click', function() {
                if (confirm("Confirm Finalization for " + targetMonth + "?\n\nData will be permanently saved to history.")) {
                    // Update UI
                    finalizeButton.disabled = true;
                    finalizeButton.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Processing...';
                    finalizeButton.classList.add('opacity-75', 'cursor-not-allowed');

                    fetch('payments_done.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'finalize_payments=true'
                    })
                    .then(response => {
                        if (!response.ok) throw new Error("Server Error: " + response.status);
                        return response.json().catch(() => { throw new Error("Invalid Server Response"); });
                    })
                    .then(data => {
                        if (data.status === 'success') {
                            alert(data.message);
                            location.reload(); 
                        } else {
                            alert("Failed: " + data.message);
                            finalizeButton.disabled = false;
                            finalizeButton.innerHTML = '<i class="fas fa-save"></i> Save & Finalize';
                            finalizeButton.classList.remove('opacity-75', 'cursor-not-allowed');
                        }
                    })
                    .catch(error => {
                        console.error(error);
                        alert("Critical Error: " + error.message);
                        finalizeButton.disabled = false;
                        finalizeButton.innerHTML = '<i class="fas fa-save"></i> Save & Finalize';
                        finalizeButton.classList.remove('opacity-75', 'cursor-not-allowed');
                    });
                }
            });
        }
    });

    const loader = document.getElementById("pageLoader");

    function showLoader(text = "Loading staff payments…") {
        loader.querySelector("p").innerText = text;
        loader.classList.remove("hidden");
        loader.classList.add("flex");
    }

    // 🔹 All normal links
    document.querySelectorAll("a").forEach(link => {
        link.addEventListener("click", function () {
            if (link.href.includes("payments_history.php")) {
                    showLoader("Loading History...");
                } else {
                    showLoader("Loading page...");
                }
        });
    });

    // 🔹 All forms (including month filter form)
    document.querySelectorAll("form").forEach(form => {
        form.addEventListener("submit", function () {
            showLoader("Applying filter…");
        });
    });

    // 🔹 Month-Year dropdown (important for onchange submit)
    const monthSelect = document.querySelector("select[name='month_year']");
    if (monthSelect) {
        monthSelect.addEventListener("change", function () {
            showLoader("Loading selected month…");
        });
    }
</script>

</body>
</html>

<?php
if (isset($conn)) {
    $conn->close();
}
?>