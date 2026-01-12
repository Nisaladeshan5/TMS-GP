<?php
// day_heldup_done.php (Finalize Day Heldup Payments with PIN Security)
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
// 0. HELPER FUNCTION (Day Heldup Calculation Logic)
// =======================================================================

function calculate_monthly_dh_data($conn, $month, $year) {
    // 1. Fetch Attendance Records (to get slab & rates)
    // We join 'dh_attendance' with 'op_services'
    $attendance_sql = "
        SELECT 
            dha.op_code, 
            dha.date,
            dha.ac, 
            os.slab_limit_distance,
            os.extra_rate_ac,
            os.extra_rate AS extra_rate_nonac,
            os.supplier_code
        FROM 
            dh_attendance dha
        JOIN 
            op_services os ON dha.op_code = os.op_code
        WHERE 
            DATE_FORMAT(dha.date, '%Y-%m') = ?
    ";
    
    $stmt = $conn->prepare($attendance_sql);
    $filter_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT);
    $stmt->bind_param("s", $filter_date);
    $stmt->execute();
    $attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $summary = [];

    foreach ($attendance_records as $record) {
        $date = $record['date'];
        $op_code = $record['op_code'];
        $supplier_code = $record['supplier_code'];

        // 2. Sum Actual Distance from register
        $dist_sql = "SELECT SUM(distance) AS total_dist FROM day_heldup_register WHERE op_code = ? AND date = ? AND done = 1";
        $d_stmt = $conn->prepare($dist_sql);
        $d_stmt->bind_param("ss", $op_code, $date);
        $d_stmt->execute();
        $actual_dist = (float)($d_stmt->get_result()->fetch_assoc()['total_dist'] ?? 0);
        $d_stmt->close();

        // 3. Calculate Payment
        $slab = (float)$record['slab_limit_distance'];
        $is_ac = ($record['ac'] == 1);
        $rate = $is_ac ? (float)$record['extra_rate_ac'] : (float)$record['extra_rate_nonac'];
        
        $pay_dist = max($actual_dist, $slab);
        $payment = $pay_dist * $rate;

        // 4. Aggregate by Op Code
        if (!isset($summary[$op_code])) {
            $summary[$op_code] = [
                'op_code' => $op_code,
                'supplier_code' => $supplier_code,
                'total_distance' => 0.00, // Sum of ACTUAL distance
                'monthly_payment' => 0.00
            ];
        }
        
        $summary[$op_code]['total_distance'] += $actual_dist;
        $summary[$op_code]['monthly_payment'] += $payment;
    }

    return $summary;
}

// =======================================================================
// 1. PIN VERIFICATION & AJAX CHECK SETUP
// =======================================================================

$today_pin = date('dmY'); 
$is_pin_correct = false;
$pin_message = '';

// Handle PIN Submission
if (isset($_POST['pin_submit'])) {
    $entered_pin = filter_input(INPUT_POST, 'security_pin', FILTER_SANITIZE_SPECIAL_CHARS);
    $entered_pin = (string)$entered_pin;
    if ($entered_pin === $today_pin) {
        $is_pin_correct = true;
        // Store in session strictly for this request cycle if needed, 
        // but since we want to lock every time, we rely on immediate POST.
        $_SESSION['pin_verified_dh_temp'] = true; 
    } else {
        $pin_message = "Invalid PIN. Please try again.";
    }
}

// Ensure clean state if not submitting PIN or Finalizing
if (!isset($_POST['pin_submit']) && !isset($_POST['finalize_payments'])) {
    unset($_SESSION['pin_verified_dh_temp']);
}

// Check verification status
if (isset($_SESSION['pin_verified_dh_temp']) && $_SESSION['pin_verified_dh_temp'] === true) {
    $is_pin_correct = true;
}


// =======================================================================
// 2. BACKEND API FOR PAYMENT FINALIZATION (AJAX) - PRIORITY EXECUTION
// =======================================================================

if (isset($_POST['finalize_payments'])) {
    
    ob_end_clean(); 
    header('Content-Type: application/json');

    // Check 1: Security Validation (Must be verified in current session)
    if (!isset($_SESSION['pin_verified_dh_temp']) || $_SESSION['pin_verified_dh_temp'] !== true) {
        echo json_encode(['status' => 'error', 'message' => "Security validation failed. Access denied."]);
        exit;
    }

    try {
        // --- 2.1. Determine the Month/Year to finalize (The PREVIOUS Month) ---
        $target_date = new DateTime('first day of this month');
        $target_date->modify('-1 month'); 
        
        $finalize_month = (int)$target_date->format('m');
        $finalize_year = (int)$target_date->format('Y');
        $target_month_name = $target_date->format('F Y');

        // --- 2.2. Calculate Data ---
        $payment_data = calculate_monthly_dh_data($conn, $finalize_month, $finalize_year);

        if (empty($payment_data)) {
            echo json_encode(['status' => 'error', 'message' => "No payable Day Heldup data found for $target_month_name to finalize."]);
            exit;
        }

        // --- 2.3. Check for Duplicate Insertion ---
        $check_sql = "SELECT COUNT(*) FROM monthly_payments_dh WHERE month = ? AND year = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $finalize_month, $finalize_year);
        $check_stmt->execute();
        $count = (int)$check_stmt->get_result()->fetch_row()[0];
        $check_stmt->close();

        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => "$target_month_name payments are ALREADY finalized. Aborting."]);
            exit;
        }
        
        // --- 2.4. Insert Data into monthly_payments_dh ---
        $conn->begin_transaction();
        $success_count = 0;
        $error_occurred = false;
        $specific_error = "";

        $insert_sql = "INSERT INTO monthly_payments_dh (op_code, supplier_code, month, year, total_distance, monthly_payment) VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);

        if (!$insert_stmt) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => "SQL Insert Prepare failed: " . $conn->error]);
            exit;
        }

        foreach ($payment_data as $data) {
            $insert_stmt->bind_param("ssiidd", 
                $data['op_code'], 
                $data['supplier_code'], 
                $finalize_month, 
                $finalize_year, 
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

        // Final AJAX Response 
        if ($error_occurred) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => "DB Error: " . $specific_error]);
        } else {
            $conn->commit();
            unset($_SESSION['pin_verified_dh_temp']); // Lock again after success
            echo json_encode(['status' => 'success', 'message' => "Successfully finalized $success_count Day Heldup records for $target_month_name!"]);
        }

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => "System error: " . $e->getMessage()]);
    }

    if (isset($conn) && $conn->ping()) $conn->close();
    exit; 
}


// =======================================================================
// 3. HTML DISPLAY LOGIC (If NOT AJAX)
// =======================================================================

if (!$is_pin_correct) {
    ob_end_clean();
    ob_start();
    
    // HTML for PIN Entry Form
    $page_title = "Day Heldup Finalization - Security Check";
    include('../../../includes/header.php');
    include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

            <form method="post" action="day_heldup_done.php">
                <div class="mb-6">
                    <label for="security_pin" class="block text-sm font-medium text-gray-700 mb-2">Security PIN</label>
                    <input type="password" name="security_pin" id="security_pin" maxlength="8" required 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-lg text-center tracking-widest"
                           placeholder="********" autocomplete="off">
                </div>
                <button type="submit" name="pin_submit" 
                        class="w-full bg-blue-600 text-white font-bold py-3 rounded-lg shadow-lg hover:bg-blue-700 transition duration-200">
                    Verify PIN <i class="fas fa-key ml-2"></i>
                </button>
            </form>
        </div>
    </main>
</body>
</html>
<?php
    exit(); 
}

// --- MAIN BUTTON DISPLAY (PIN WAS CORRECT) ---

// A. Payment Availability Check (Previous Month)
$payment_available_date = new DateTime('first day of this month');
$payment_available_date->modify('-1 month'); 
$available_month = (int)$payment_available_date->format('m');
$available_year = (int)$payment_available_date->format('Y');
$available_month_name = $payment_available_date->format('F Y');

// Check if already finalized
$is_payment_already_done = false;
$check_done_sql = "SELECT COUNT(*) FROM monthly_payments_dh WHERE month = ? AND year = ? LIMIT 1";
$check_done_stmt = $conn->prepare($check_done_sql);
if ($check_done_stmt) {
    $check_done_stmt->bind_param("ii", $available_month, $available_year);
    $check_done_stmt->execute();
    $count = $check_done_stmt->get_result()->fetch_row()[0];
    if ((int)$count > 0) {
        $is_payment_already_done = true;
    }
    $check_done_stmt->close();
}

// B. Check if data exists (Attendance data)
$data_exists_sql = "SELECT 1 FROM dh_attendance WHERE MONTH(date) = ? AND YEAR(date) = ? LIMIT 1";
$data_exists_stmt = $conn->prepare($data_exists_sql);
$data_exists_stmt->bind_param("ii", $available_month, $available_year);
$data_exists_stmt->execute();
$data_exists = $data_exists_stmt->get_result()->num_rows > 0;
$data_exists_stmt->close();


$page_title = "Day Heldup Payments - FINALIZATION";
include('../../../includes/header.php');
include('../../../includes/navbar.php');
ob_end_flush(); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Day Heldup Payments Finalization</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="../../payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="../factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <a href="../factory/sub/sub_route_payments.php" class="hover:text-yellow-600">Sub Route</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Day Heldup</p>
            <a href="../../NH/nh_payments.php" class="hover:text-yellow-600">Night Heldup</a>
            <a href="../../night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="../../own_vehicle_payments.php" class="hover:text-yellow-600">Manager</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-4 mt-[5%] flex justify-center">
        <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-lg">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-6 text-center">
                <?php echo htmlspecialchars($page_title); ?>
            </h2>

            <div class="flex flex-col gap-4 items-center">
                <div id="statusMessage" class="px-3 py-2 text-base font-semibold rounded-lg w-full text-center">
                    <?php if ($is_payment_already_done): ?>
                        <span class="bg-yellow-500 text-white block p-3 rounded-lg">
                            <i class="fas fa-info-circle mr-2"></i> Payments for <?php echo htmlspecialchars($available_month_name); ?> are Already Finalized.
                        </span>
                    <?php elseif (!$data_exists): ?>
                            <span class="bg-red-500 text-white block p-3 rounded-lg">
                               <i class="fas fa-exclamation-triangle mr-2"></i> No Attendance data found for <?php echo htmlspecialchars($available_month_name); ?>.
                            </span>
                    <?php else: ?>
                        <span class="bg-blue-100 text-blue-800 block p-3 rounded-lg">
                            <i class="fas fa-calendar-alt mr-2"></i> Ready to finalize Day Heldup payments for <?php echo htmlspecialchars($available_month_name); ?>.
                            <br>Click the button below to save records.
                        </span>
                    <?php endif; ?>
                </div>

                <?php 
                if (!$is_payment_already_done && $data_exists): ?>
                    <button id="finalizeButton" 
                            class="w-full mt-4 px-4 py-3 bg-green-600 text-white font-bold text-lg rounded-lg shadow-md hover:bg-green-700 transition duration-200">
                        <i class="fas fa-check-double mr-2"></i> Mark as Payments Done (Save History)
                    </button>
                <?php endif; ?>

                <a href="day_heldup_payments.php" 
                   class="mt-4 px-3 py-2 bg-teal-600 text-white font-semibold rounded-lg shadow-md hover:bg-teal-700 transition duration-200 text-center" 
                   title="Go back to Calculation View">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Live Calculation
                </a>
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
                    const confirmAction = confirm("Are you sure you want to finalize Day Heldup payments for " + targetMonth + "? This action cannot be reversed.");
                    
                    if (confirmAction) {
                        statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-blue-100 text-blue-800';
                        statusMessage.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-2"></i> Processing... Please wait.';
                        finalizeButton.disabled = true;

                        fetch('day_heldup_done.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'finalize_payments=true'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-green-100 text-green-800';
                                statusMessage.innerHTML = '<i class="fas fa-check-circle mr-2"></i> ' + data.message + ' Redirecting...';
                                
                                setTimeout(() => {
                                    window.location.href = `day_heldup_history.php?month=${availableMonth}&year=${availableYear}`;
                                }, 3000);
                            } else {
                                statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-red-100 text-red-800';
                                statusMessage.innerHTML = '<i class="fas fa-times-circle mr-2"></i> Failed: ' + data.message;
                                finalizeButton.disabled = false;
                            }
                        })
                        .catch(error => {
                            console.error('Fetch Error:', error);
                            statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-red-100 text-red-800';
                            statusMessage.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i> Connection Error. Please try again.'; 
                            finalizeButton.disabled = false;
                        });
                    }
                });
            }
        });
    </script>
</body>
</html>

<?php
if (isset($conn) && $conn->ping()) {
    $conn->close();
}
?>