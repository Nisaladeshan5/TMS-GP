<?php
// sub_payments_done.php (Finalize Sub Route Payments with PIN Security)
// CRITICAL: Ensure no output occurs before headers in AJAX mode
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Start output buffering immediately
ob_start();

// Include necessary files
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
if (!isset($conn) || $conn->connect_error) {
    error_log("FATAL: Database connection failed.");
}

// =======================================================================
// 0. HELPER FUNCTIONS (Sub Route Calculation Logic)
// =======================================================================

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

// PIN STATE MANAGEMENT
if (isset($_POST['pin_submit']) && $is_pin_correct) {
     $_SESSION['pin_verified_sub'] = $today_pin; // Using unique session key for sub route
} else if (isset($_SESSION['pin_verified_sub']) && $_SESSION['pin_verified_sub'] === $today_pin) {
     $is_pin_correct = true;
}


// =======================================================================
// 2. BACKEND API FOR PAYMENT FINALIZATION (AJAX) - PRIORITY EXECUTION
// =======================================================================

if (isset($_POST['finalize_payments'])) {
    
    ob_end_clean(); 
    header('Content-Type: application/json');

    // Check 1: Security Validation
    if (!isset($_SESSION['pin_verified_sub']) || $_SESSION['pin_verified_sub'] !== $today_pin) {
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

        $payment_data = []; 

        // Fetch all ACTIVE sub routes
        $sub_route_sql = "
            SELECT 
                sub_route_code,
                route_code,
                supplier_code,
                per_day_rate
            FROM sub_route
            WHERE is_active = 1
        ";
        $sub_route_result = $conn->query($sub_route_sql);

        if (!$sub_route_result || $sub_route_result->num_rows == 0) {
             echo json_encode(['status' => 'error', 'message' => "No active sub routes found to process."]);
             exit;
        }

        while ($row = $sub_route_result->fetch_assoc()) {
            $sub_code = $row['sub_route_code'];
            $parent_route = $row['route_code'];
            $rate = (float)$row['per_day_rate'];

            // Calculate Days
            $base_days = get_parent_route_attendance_count($conn, $parent_route, $finalize_month, $finalize_year);
            $adj_days = get_sub_route_adjustments_count($conn, $sub_code, $finalize_month, $finalize_year);
            
            $final_days = $base_days + $adj_days;
            if ($final_days < 0) $final_days = 0;

            // Calculate Payment
            $total_payment = $final_days * $rate;

            // Only process if there is a payment or attendance to record
            if ($final_days > 0 || $total_payment > 0) {
                 $payment_data[] = [
                    'sub_route_code' => $sub_code,
                    'supplier_code' => $row['supplier_code'],
                    'month' => $finalize_month,
                    'year' => $finalize_year,
                    'monthly_payment' => $total_payment,
                    'no_of_attendance_days' => $final_days
                ];
            }
        }
        $sub_route_result->free();

        if (empty($payment_data)) {
            echo json_encode(['status' => 'error', 'message' => "No payable data (attendance) found for $target_month_name to finalize."]);
            exit;
        }

        // --- 2.2. Check for Duplicate Insertion ---
        $duplicate_check_sql = "SELECT COUNT(*) FROM monthly_payments_sub WHERE month = ? AND year = ?";
        $duplicate_check_stmt = $conn->prepare($duplicate_check_sql);
        $duplicate_check_stmt->bind_param("ii", $finalize_month, $finalize_year);
        $duplicate_check_stmt->execute();
        $count = (int)$duplicate_check_stmt->get_result()->fetch_row()[0];
        $duplicate_check_stmt->close();

        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => "$target_month_name payments are ALREADY finalized in the Sub Route history table. Aborting insertion."]);
            exit;
        }
        
        // --- 2.3. Insert Data into monthly_payments_sub ---
        $conn->begin_transaction();
        $success_count = 0;
        $error_occurred = false;
        $specific_error = "";

        $insert_sql = "INSERT INTO monthly_payments_sub (sub_route_code, supplier_code, month, year, monthly_payment, no_of_attendance_days) VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);

        if (!$insert_stmt) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => "SQL Insert Prepare failed: " . $conn->error]);
            exit;
        }

        foreach ($payment_data as $data) {
            $insert_stmt->bind_param("ssiidi", 
                $data['sub_route_code'], 
                $data['supplier_code'], 
                $data['month'], 
                $data['year'], 
                $data['monthly_payment'], 
                $data['no_of_attendance_days']
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
            echo json_encode(['status' => 'error', 'message' => "Error finalizing payments. DB Error: " . $specific_error]);
        } else {
            $conn->commit();
            unset($_SESSION['pin_verified_sub']); // Lock again after success
            echo json_encode(['status' => 'success', 'message' => "Successfully finalized $success_count Sub Route payments for $target_month_name!"]);
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
    $page_title = "Sub Route Finalization - Security Check";
    include('../../../../includes/header.php');
    include('../../../../includes/navbar.php');
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

            <form method="post" action="sub_payments_done.php">
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
$check_done_sql = "SELECT COUNT(*) FROM monthly_payments_sub WHERE month = ? AND year = ? LIMIT 1";
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
$data_exists_sql = "SELECT 1 FROM factory_transport_vehicle_register WHERE MONTH(date) = ? AND YEAR(date) = ? LIMIT 1";
$data_exists_stmt = $conn->prepare($data_exists_sql);
$data_exists_stmt->bind_param("ii", $available_month, $available_year);
$data_exists_stmt->execute();
$data_exists = $data_exists_stmt->get_result()->num_rows > 0;
$data_exists_stmt->close();


$page_title = "Sub Route Payments - FINALIZATION";
include('../../../../includes/header.php');
include('../../../../includes/navbar.php');
ob_end_flush(); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sub Route Payments Finalization</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="../../payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="../factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Sub Route</p>
            <a href="../../DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a>
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
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
                               <i class="fas fa-exclamation-triangle mr-2"></i> No Attendance data found for <?php echo htmlspecialchars($available_month_name); ?> to process.
                            </span>
                    <?php else: ?>
                        <span class="bg-blue-100 text-blue-800 block p-3 rounded-lg">
                            <i class="fas fa-calendar-alt mr-2"></i> Ready to finalize payments for <?php echo htmlspecialchars($available_month_name); ?>.
                            <br>Click the button below to save the calculated records.
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

                <a href="sub_route_payments.php" 
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
                    const confirmAction = confirm("Are you sure you want to finalize and save Sub Route payments for " + targetMonth + "? This action cannot be reversed.");
                    
                    if (confirmAction) {
                        statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-blue-100 text-blue-800';
                        statusMessage.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-2"></i> Processing... Please wait.';
                        finalizeButton.disabled = true;

                        fetch('sub_payments_done.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'finalize_payments=true'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-green-100 text-green-800';
                                statusMessage.innerHTML = '<i class="fas fa-check-circle mr-2"></i> ' + data.message + ' Redirecting...';
                                
                                setTimeout(() => {
                                    window.location.href = `sub_payments_history.php?month=${availableMonth}&year=${availableYear}`;
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