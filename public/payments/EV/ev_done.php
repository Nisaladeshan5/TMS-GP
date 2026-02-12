<?php
// ev_done.php (Finalize Extra Vehicle Payments - STRICT SECURITY)
// CRITICAL: Ensure no output occurs before headers in AJAX mode
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Start output buffering immediately
ob_start();

// Include necessary files
require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

date_default_timezone_set('Asia/Colombo');

include('../../../includes/db.php'); 
if (!isset($conn) || $conn->connect_error) {
    error_log("FATAL: Database connection failed.");
}

// =======================================================================
// 0. HELPER FUNCTIONS (Extra Vehicle Calculation Logic)
// =======================================================================

function calculate_monthly_ev_data($conn, $month, $year) {
    
    // 1. Pre-fetch Data
    // A. Fuel Rate History
    $fuel_history = [];
    $fuel_res = $conn->query("SELECT rate_id, rate, date FROM fuel_rate ORDER BY date DESC");
    if ($fuel_res) {
        while ($row = $fuel_res->fetch_assoc()) {
            $fuel_history[$row['rate_id']][] = ['date' => $row['date'], 'rate' => (float)$row['rate']];
        }
    }

    // B. Op Rates
    $op_rates = [];
    $op_res = $conn->query("SELECT op_code, extra_rate_ac, extra_rate FROM op_services");
    if ($op_res) {
        while ($row = $op_res->fetch_assoc()) {
            $op_rates[$row['op_code']] = ['ac' => (float)$row['extra_rate_ac'], 'non_ac' => (float)$row['extra_rate']];
        }
    }

    // C. Vehicle Specs
    $vehicle_specs = [];
    $veh_res = $conn->query("SELECT v.vehicle_no, v.rate_id, c.distance AS km_per_liter FROM vehicle v LEFT JOIN consumption c ON v.fuel_efficiency = c.c_id");
    if ($veh_res) {
        while ($row = $veh_res->fetch_assoc()) {
            $vehicle_specs[$row['vehicle_no']] = ['rate_id' => $row['rate_id'], 'km_per_liter' => (float)$row['km_per_liter']];
        }
    }

    // D. Route Data
    $route_data = [];
    $rt_res = $conn->query("SELECT route_code, fixed_amount, vehicle_no, with_fuel FROM route");
    if ($rt_res) {
        while ($row = $rt_res->fetch_assoc()) {
            $route_data[$row['route_code']] = ['fixed_amount' => (float)$row['fixed_amount'], 'assigned_vehicle' => $row['vehicle_no'], 'with_fuel' => (int)$row['with_fuel']];
        }
    }

    // 2. Fetch Trips
    $sql = "
        SELECT 
            evr.*,
            s.supplier_code
        FROM 
            extra_vehicle_register evr
        JOIN 
            supplier s ON evr.supplier_code = s.supplier_code
        WHERE 
            MONTH(evr.date) = ? AND YEAR(evr.date) = ? AND evr.done = 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $summary = [];

    // Rate lookup helper
    $get_rate_for_date = function($rate_id, $trip_date) use ($fuel_history) {
        if (!isset($fuel_history[$rate_id])) return 0;
        foreach ($fuel_history[$rate_id] as $record) {
            if ($record['date'] <= $trip_date) return $record['rate'];
        }
        $last = end($fuel_history[$rate_id]);
        return $last ? $last['rate'] : 0;
    };

    while ($row = $result->fetch_assoc()) {
        $pay_amount = 0.00;
        $distance = (float)$row['distance'];
        $identifier = '';
        $type = '';
        $supplier_code = $row['supplier_code'];
        $trip_date = $row['date'];

        // Logic 1: Op Code
        if (!empty($row['op_code'])) {
            $identifier = $row['op_code'];
            $type = 'Operation';
            if (isset($op_rates[$identifier])) {
                $rate = ($row['ac_status'] == 1) ? $op_rates[$identifier]['ac'] : $op_rates[$identifier]['non_ac'];
                $pay_amount = $distance * $rate;
            }
        } 
        // Logic 2: Route Code
        elseif (!empty($row['route'])) {
            $identifier = $row['route'];
            $type = 'Route';
            if (isset($route_data[$identifier])) {
                $fixed_amount = $route_data[$identifier]['fixed_amount'];
                $assigned_vehicle = $route_data[$identifier]['assigned_vehicle'];
                $with_fuel = $route_data[$identifier]['with_fuel'];
                $fuel_cost_per_km = 0;
                
                if ($with_fuel == 1 && !empty($assigned_vehicle) && isset($vehicle_specs[$assigned_vehicle])) {
                    $v_spec = $vehicle_specs[$assigned_vehicle];
                    $km_l = $v_spec['km_per_liter'];
                    $rate_id = $v_spec['rate_id'];
                    $fuel_rate = $get_rate_for_date($rate_id, $trip_date);
                    
                    if ($km_l > 0) $fuel_cost_per_km = $fuel_rate / $km_l;
                }
                $pay_amount = $distance * ($fixed_amount + $fuel_cost_per_km);
            }
        }

        // Aggregate
        $key = $identifier . '_' . $supplier_code; 
        
        if (!isset($summary[$key])) {
            $summary[$key] = [
                'code' => $identifier, 
                'supplier_code' => $supplier_code,
                'total_distance' => 0.00,
                'monthly_payment' => 0.00
            ];
        }
        
        $summary[$key]['total_distance'] += $distance;
        $summary[$key]['monthly_payment'] += $pay_amount;
    }
    
    // --- CALCULATE FINAL RATE (Payment / Distance) ---
    foreach ($summary as $key => $data) {
        $dist = $data['total_distance'];
        $pay = $data['monthly_payment'];
        
        // Calculate average rate for the month
        // Avoid division by zero
        $summary[$key]['rate'] = ($dist > 0) ? ($pay / $dist) : 0.00;
    }
    
    return $summary;
}

// =======================================================================
// 1. PIN VERIFICATION (STRICT MODE - NO PERSISTENCE)
// =======================================================================

$today_pin = date('dmY'); 
$is_pin_correct = false;
$pin_message = '';

// If GET request, unset the session variable to force re-entry
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    unset($_SESSION['pin_verified_ev']);
}

if (isset($_POST['pin_submit'])) {
    $entered_pin = filter_input(INPUT_POST, 'security_pin', FILTER_SANITIZE_SPECIAL_CHARS);
    if ($entered_pin === $today_pin) {
        $is_pin_correct = true;
        $_SESSION['pin_verified_ev'] = true; // Set only for this interaction chain
    } else {
        $pin_message = "Invalid PIN. Please try again.";
    }
} else {
    // For AJAX requests, check if session is set from the POST
    if (isset($_SESSION['pin_verified_ev']) && $_SESSION['pin_verified_ev'] === true) {
        $is_pin_correct = true;
    }
}

// =======================================================================
// 2. BACKEND API FOR PAYMENT FINALIZATION (AJAX)
// =======================================================================

if (isset($_POST['finalize_payments'])) {
    
    ob_end_clean(); 
    header('Content-Type: application/json');

    // Strict Security Check
    if (!isset($_SESSION['pin_verified_ev']) || $_SESSION['pin_verified_ev'] !== true) {
        echo json_encode(['status' => 'error', 'message' => "Security validation failed. Access denied."]);
        exit;
    }

    try {
        $target_date = new DateTime('first day of this month');
        $target_date->modify('-1 month'); 
        
        $finalize_month = (int)$target_date->format('m');
        $finalize_year = (int)$target_date->format('Y');
        $target_month_name = $target_date->format('F Y');

        // Calculate
        $payment_data = calculate_monthly_ev_data($conn, $finalize_month, $finalize_year);

        if (empty($payment_data)) {
            echo json_encode(['status' => 'error', 'message' => "No Extra Vehicle data found for $target_month_name."]);
            exit;
        }

        // Duplicate Check
        $check_sql = "SELECT COUNT(*) FROM monthly_payments_ev WHERE month = ? AND year = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $finalize_month, $finalize_year);
        $check_stmt->execute();
        $count = (int)$check_stmt->get_result()->fetch_row()[0];
        $check_stmt->close();

        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => "$target_month_name payments are ALREADY finalized."]);
            exit;
        }
        
        // Insert Data (Including RATE)
        $conn->begin_transaction();
        $success_count = 0;
        $error_occurred = false;
        $specific_error = "";

        $insert_sql = "INSERT INTO monthly_payments_ev (code, supplier_code, month, year, rate, total_distance, monthly_payment) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);

        if (!$insert_stmt) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => "SQL Prepare failed: " . $conn->error]);
            exit;
        }

        foreach ($payment_data as $data) {
            // Bind parameters: ssiiddd
            $insert_stmt->bind_param("ssiiddd", 
                $data['code'], 
                $data['supplier_code'], 
                $finalize_month, 
                $finalize_year, 
                $data['rate'],  
                $data['total_distance'], 
                $data['monthly_payment']
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
            echo json_encode(['status' => 'error', 'message' => "DB Error: " . $specific_error]);
        } else {
            $conn->commit();
            unset($_SESSION['pin_verified_ev']); // STRICT: Clear session immediately
            echo json_encode(['status' => 'success', 'message' => "Successfully finalized $success_count Extra Vehicle records for $target_month_name!"]);
        }

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => "System error: " . $e->getMessage()]);
    }

    if (isset($conn) && $conn->ping()) $conn->close();
    exit; 
}

// =======================================================================
// 3. HTML DISPLAY LOGIC
// =======================================================================

// --- PIN FORM DISPLAY ---
if (!$is_pin_correct) {
    ob_end_clean();
    ob_start();
    include('../../../includes/header.php');
    include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extra Vehicle PIN Access</title>
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
            <a href="ev_payments.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Extra Vehicle
            </a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Finalize Payments
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="ev_payments.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
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

        <form method="post" action="ev_done.php">
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

// Check Status
$is_payment_already_done = false;
$check_done_stmt = $conn->prepare("SELECT COUNT(*) FROM monthly_payments_ev WHERE month = ? AND year = ? LIMIT 1");
if ($check_done_stmt) {
    $check_done_stmt->bind_param("ii", $available_month, $available_year);
    $check_done_stmt->execute();
    if ((int)$check_done_stmt->get_result()->fetch_row()[0] > 0) $is_payment_already_done = true;
    $check_done_stmt->close();
}

// Check Data Exists
$data_exists_stmt = $conn->prepare("SELECT 1 FROM extra_vehicle_register WHERE MONTH(date) = ? AND YEAR(date) = ? AND done = 1 LIMIT 1");
$data_exists = false;
if ($data_exists_stmt) {
    $data_exists_stmt->bind_param("ii", $available_month, $available_year);
    $data_exists_stmt->execute();
    if ($data_exists_stmt->get_result()->num_rows > 0) $data_exists = true;
    $data_exists_stmt->close();
}

include('../../../includes/header.php');
include('../../../includes/navbar.php');
ob_end_flush(); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finalize Extra Vehicle Payments</title>
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
            <a href="ev_payments.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Extra Vehicle
            </a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Finalize Payments
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="ev_payments.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            <i class="fas fa-calculator"></i> Current Calculations
        </a>
    </div>
</div>

<main class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex justify-center items-start mt-10">
    <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200 w-full max-w-lg text-center">
        
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Month End Process</h2>
        <p class="text-sm text-gray-500 mb-8">Finalize <strong>Extra Vehicle</strong> payments for the previous month.</p>

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
                    <p class="text-yellow-700 text-sm mt-1">No completed Extra Vehicle records found for <strong><?php echo htmlspecialchars($available_month_name); ?></strong>.</p>
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
            <a href="ev_history.php" class="inline-flex items-center justify-center gap-2 w-full py-3 bg-gray-800 text-white font-semibold rounded-lg hover:bg-gray-900 transition shadow-md">
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
        const availableMonth = "<?php echo $available_month; ?>";
        const availableYear = "<?php echo $available_year; ?>";

        if (finalizeButton) {
            finalizeButton.addEventListener('click', function() {
                if (confirm("Confirm Extra Vehicle Finalization for " + targetMonth + "?\n\nData will be permanently saved to history.")) {
                    
                    finalizeButton.disabled = true;
                    finalizeButton.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Processing...';
                    finalizeButton.classList.add('opacity-75', 'cursor-not-allowed');

                    fetch('ev_done.php', {
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
                            // Redirect to history page on success
                            window.location.href = `ev_history.php?month=${availableMonth}&year=${availableYear}`;
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
    function showLoader(text = "Loading...") {
        loader.querySelector("p").innerText = text;
        loader.classList.remove("hidden");
        loader.classList.add("flex");
    }

    document.querySelectorAll("a").forEach(link => {
        link.addEventListener("click", function () {
            showLoader("Loading...");
        });
    });
</script>

</body>
</html>

<?php
if (isset($conn) && $conn->ping()) {
    $conn->close();
}
?>