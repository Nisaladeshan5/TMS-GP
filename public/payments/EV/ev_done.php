<?php
// ev_done.php (Finalize Extra Vehicle Payments with Rate Calculation)

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Start output buffering immediately
ob_start();

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
    die("Database connection failed.");
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
// 1. PIN VERIFICATION
// =======================================================================

$today_pin = date('dmY'); 
$is_pin_correct = false;
$pin_message = '';

if (isset($_POST['pin_submit'])) {
    $entered_pin = filter_input(INPUT_POST, 'security_pin', FILTER_SANITIZE_SPECIAL_CHARS);
    if ($entered_pin === $today_pin) {
        $is_pin_correct = true;
        $_SESSION['pin_verified_ev_temp'] = true; 
    } else {
        $pin_message = "Invalid PIN. Please try again.";
    }
}

if (!isset($_POST['pin_submit']) && !isset($_POST['finalize_payments'])) {
    unset($_SESSION['pin_verified_ev_temp']);
}

if (isset($_SESSION['pin_verified_ev_temp']) && $_SESSION['pin_verified_ev_temp'] === true) {
    $is_pin_correct = true;
}

// =======================================================================
// 2. BACKEND API FOR PAYMENT FINALIZATION (AJAX)
// =======================================================================

if (isset($_POST['finalize_payments'])) {
    
    ob_end_clean(); 
    header('Content-Type: application/json');

    if (!isset($_SESSION['pin_verified_ev_temp']) || $_SESSION['pin_verified_ev_temp'] !== true) {
        echo json_encode(['status' => 'error', 'message' => "Security validation failed."]);
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
            echo json_encode(['status' => 'error', 'message' => "No data found for $target_month_name."]);
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
            echo json_encode(['status' => 'error', 'message' => "Payments ALREADY finalized."]);
            exit;
        }
        
        // Insert Data (Including RATE)
        $conn->begin_transaction();
        $success_count = 0;
        $error_occurred = false;
        $specific_error = "";

        // Updated SQL to include 'rate'
        $insert_sql = "INSERT INTO monthly_payments_ev (code, supplier_code, month, year, rate, total_distance, monthly_payment) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);

        if (!$insert_stmt) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => "SQL Prepare failed: " . $conn->error]);
            exit;
        }

        foreach ($payment_data as $data) {
            // Bind parameters: ssiiddd (string, string, int, int, double, double, double)
            $insert_stmt->bind_param("ssiiddd", 
                $data['code'], 
                $data['supplier_code'], 
                $finalize_month, 
                $finalize_year, 
                $data['rate'],  // <--- NEW: Rate
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
            unset($_SESSION['pin_verified_ev_temp']); 
            echo json_encode(['status' => 'success', 'message' => "Successfully finalized $success_count records for $target_month_name!"]);
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

if (!$is_pin_correct) {
    ob_end_clean();
    ob_start();
    
    $page_title = "Extra Vehicle Finalization - Security";
    include('../../../includes/header.php');
    include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PIN Access</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <main class="w-[85%] ml-[15%] p-8 mt-[5%] flex justify-center items-center">
        <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-md">
            <h2 class="text-2xl font-bold text-center mb-6 text-blue-600">Secure Payment Finalization</h2>
            <?php if (!empty($pin_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">
                    <span class="block sm:inline"><?php echo htmlspecialchars($pin_message); ?></span>
                </div>
            <?php endif; ?>
            <form method="post" action="ev_done.php">
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Security PIN</label>
                    <input type="password" name="security_pin" maxlength="8" placeholder="********" required class="w-full px-4 py-3 border border-gray-300 rounded-lg text-lg text-center tracking-widest">
                </div>
                <button type="submit" name="pin_submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-lg shadow-lg hover:bg-blue-700">Verify PIN</button>
            </form>
        </div>
    </main>
</body>
</html>
<?php
    exit(); 
}

// --- MAIN BUTTON DISPLAY ---
$payment_available_date = new DateTime('first day of this month');
$payment_available_date->modify('-1 month'); 
$available_month = (int)$payment_available_date->format('m');
$available_year = (int)$payment_available_date->format('Y');
$available_month_name = $payment_available_date->format('F Y');

// Check status
$is_payment_already_done = false;
$check_done_sql = "SELECT COUNT(*) FROM monthly_payments_ev WHERE month = ? AND year = ? LIMIT 1";
$check_done_stmt = $conn->prepare($check_done_sql);
if ($check_done_stmt) {
    $check_done_stmt->bind_param("ii", $available_month, $available_year);
    $check_done_stmt->execute();
    if ((int)$check_done_stmt->get_result()->fetch_row()[0] > 0) $is_payment_already_done = true;
    $check_done_stmt->close();
}

$data_exists_sql = "SELECT 1 FROM extra_vehicle_register WHERE MONTH(date) = ? AND YEAR(date) = ? AND done = 1 LIMIT 1";
$data_exists_stmt = $conn->prepare($data_exists_sql);
$data_exists_stmt->bind_param("ii", $available_month, $available_year);
$data_exists_stmt->execute();
$data_exists = $data_exists_stmt->get_result()->num_rows > 0;
$data_exists_stmt->close();

$page_title = "Extra Vehicle - FINALIZATION";
include('../../../includes/header.php');
include('../../../includes/navbar.php');
ob_end_flush(); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Extra Vehicle Payments Finalization</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="../../payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="../../factory/factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <a href="../../factory/sub/sub_route_payments.php" class="hover:text-yellow-600">Sub Route</a>
            <a href="../../DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a>
            <a href="../../NH/nh_payments.php" class="hover:text-yellow-600">Night Heldup</a>
            <a href="../../night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Extra Vehicle</p>
            <a href="../../own_vehicle_payments.php" class="hover:text-yellow-600">Fuel Allowance</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-4 mt-[5%] flex justify-center">
        <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-lg">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-6 text-center"><?php echo htmlspecialchars($page_title); ?></h2>
            <div class="flex flex-col gap-4 items-center">
                <div id="statusMessage" class="px-3 py-2 text-base font-semibold rounded-lg w-full text-center">
                    <?php if ($is_payment_already_done): ?>
                        <span class="bg-yellow-500 text-white block p-3 rounded-lg"><i class="fas fa-info-circle mr-2"></i> Payments for <?php echo htmlspecialchars($available_month_name); ?> are Already Finalized.</span>
                    <?php elseif (!$data_exists): ?>
                        <span class="bg-red-500 text-white block p-3 rounded-lg"><i class="fas fa-exclamation-triangle mr-2"></i> No data found for <?php echo htmlspecialchars($available_month_name); ?>.</span>
                    <?php else: ?>
                        <span class="bg-blue-100 text-blue-800 block p-3 rounded-lg"><i class="fas fa-calendar-alt mr-2"></i> Ready to finalize Extra Vehicle payments for <?php echo htmlspecialchars($available_month_name); ?>.<br>Click below to save records.</span>
                    <?php endif; ?>
                </div>
                <?php if (!$is_payment_already_done && $data_exists): ?>
                    <button id="finalizeButton" class="w-full mt-4 px-4 py-3 bg-green-600 text-white font-bold text-lg rounded-lg shadow-md hover:bg-green-700 transition duration-200"><i class="fas fa-check-double mr-2"></i> Mark as Payments Done</button>
                <?php endif; ?>
                <a href="ev_payments.php" class="mt-4 px-3 py-2 bg-teal-600 text-white font-semibold rounded-lg shadow-md hover:bg-teal-700 transition duration-200 text-center"><i class="fas fa-arrow-left mr-1"></i> Back to Live Calculation</a>
            </div>
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
                    if (confirm("Finalize Extra Vehicle payments for " + targetMonth + "?\n\nThis cannot be undone.")) {
                        statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-blue-100 text-blue-800';
                        statusMessage.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-2"></i> Processing...';
                        finalizeButton.disabled = true;

                        fetch('ev_done.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'finalize_payments=true'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-green-100 text-green-800';
                                statusMessage.innerHTML = '<i class="fas fa-check-circle mr-2"></i> ' + data.message + ' Redirecting...';
                                setTimeout(() => window.location.href = `ev_history.php?month=${availableMonth}&year=${availableYear}`, 3000);
                            } else {
                                statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-red-100 text-red-800';
                                statusMessage.innerHTML = '<i class="fas fa-times-circle mr-2"></i> Failed: ' + data.message;
                                finalizeButton.disabled = false;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-red-100 text-red-800';
                            statusMessage.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i> Connection Error.'; 
                            finalizeButton.disabled = false;
                        });
                    }
                });
            }
        });
    </script>
</body>
</html>
<?php if (isset($conn) && $conn->ping()) $conn->close(); ?>