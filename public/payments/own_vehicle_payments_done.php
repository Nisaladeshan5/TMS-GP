<?php
// own_vehicle_payments_done.php
// CRITICAL: Ensure no output occurs before headers in AJAX mode
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Start output buffering immediately
ob_start();

// Include necessary files
require_once '../../includes/session_check.php';
// Start session if not started (it should be started by session_check.php)
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
// 0. HELPER FUNCTIONS (Omitted for brevity, assumed correct)
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

function calculate_own_vehicle_payment($conn, $emp_id, $consumption, $daily_distance, $fixed_amount, $rate_id, $month, $year) { 
    $consumption = (float)$consumption;
    $daily_distance = (float)$daily_distance;
    $fixed_amount = (float)$fixed_amount;
    $total_monthly_payment = $fixed_amount; 
    $total_attendance_days = 0;
    $total_calculated_distance = 0.00;
    
    // Fetch and calculate logic (omitted complex loops for brevity, assumed correct)
    
    $attendance_sql = "SELECT date, time FROM own_vehicle_attendance WHERE emp_id = ? AND MONTH(date) = ? AND YEAR(date) = ?";
    $att_stmt = $conn->prepare($attendance_sql);
    $att_stmt->bind_param("sii", $emp_id, $month, $year);
    $att_stmt->execute();
    $attendance_records = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $att_stmt->close();

    $extra_sql = "SELECT date, out_time, distance FROM own_vehicle_extra WHERE emp_id = ? AND MONTH(date) = ? AND YEAR(date) = ? AND done = 1 AND distance IS NOT NULL";
    $extra_stmt = $conn->prepare($extra_sql);
    $extra_stmt->bind_param("sii", $emp_id, $month, $year);
    $extra_stmt->execute();
    $extra_records = $extra_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $extra_stmt->close();
    
    foreach ($attendance_records as $record) {
        $datetime = $record['date'] . ' ' . $record['time']; 
        $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
        
        if ($fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
             $day_rate = ($consumption / 100) * $daily_distance * $fuel_price;
             $total_monthly_payment += $day_rate;
             $total_calculated_distance += $daily_distance;
             $total_attendance_days++;
        }
    }
    
    foreach ($extra_records as $record) {
        $datetime = $record['date'] . ' ' . $record['out_time'];
        $extra_distance_km = (float)$record['distance'];

        $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
        
        if ($fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
            $day_rate_base = ($consumption / 100) * $daily_distance * $fuel_price;
            $rate_per_km = $day_rate_base / $daily_distance; 
            $extra_payment = $rate_per_km * $extra_distance_km;
            
            $total_monthly_payment += $extra_payment;
            $total_calculated_distance += $extra_distance_km;
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

// --- PIN CHECK LOGIC FIX (VERIFIED STATE MANAGEMENT) ---
// This is the core area responsible for tracking the verification state across page loads/AJAX calls.
if (isset($_POST['pin_submit']) && $is_pin_correct) {
     // Scenario 1: User submitted correct PIN via form
     $_SESSION['pin_verified'] = $today_pin;
} else if (isset($_SESSION['pin_verified']) && $_SESSION['pin_verified'] === $today_pin) {
     // Scenario 2: PIN was previously verified (used by AJAX or page refresh)
     $is_pin_correct = true;
}


// =======================================================================
// 2. BACKEND API FOR PAYMENT FINALIZATION (AJAX) - PRIORITY EXECUTION
// =======================================================================

if (isset($_POST['finalize_payments'])) {
    
    ob_end_clean(); 
    header('Content-Type: application/json');

    // Check 1: Security Validation (This should now pass if the PIN was entered correctly moments before)
    if (!isset($_SESSION['pin_verified']) || $_SESSION['pin_verified'] !== $today_pin) {
        error_log("PIN Access Denied: Session verified pin missing or expired. Pin Check: " . ($_SESSION['pin_verified'] ?? 'NONE') . ", Expected: " . $today_pin); 
        echo json_encode(['status' => 'error', 'message' => "Security validation failed. Access denied."]);
        exit;
    }

    try {
        // --- 2.1. Determine the Month/Year to finalize (The PREVIOUS Month) ---
        $target_date = new DateTime('first day of this month');
        $target_date->modify('-1 month'); 
        
        $finalize_month = (int)$target_date->format('m');
        $finalize_year = (int)$target_date->format('Y');

        $payment_data = []; 
        $target_month_name = $target_date->format('F Y');

        // Fetch all employees with an Own Vehicle
        $employees_sql = "
            SELECT 
                e.emp_id, 
                ov.fuel_efficiency AS consumption, 
                ov.fixed_amount,
                ov.distance, 
                ov.rate_id
            FROM 
                own_vehicle ov
            JOIN 
                employee e ON ov.emp_id = e.emp_id
            ORDER BY 
                e.emp_id ASC;
        ";
        $employees_result = $conn->query($employees_sql);

        if (!$employees_result || $employees_result->num_rows == 0) {
             echo json_encode(['status' => 'error', 'message' => "No employees with own vehicles found to process."]);
             exit;
        }

        while ($employee_row = $employees_result->fetch_assoc()) {
            $emp_id = $employee_row['emp_id'];
            
            // Perform the complex calculation for each employee
            $calculation_results = calculate_own_vehicle_payment(
                $conn, $emp_id, $employee_row['consumption'], $employee_row['distance'], 
                $employee_row['fixed_amount'], $employee_row['rate_id'], $finalize_month, $finalize_year
            );
            
            // Only process if the employee had payable data
            if ($calculation_results['attendance_days'] > 0 || $calculation_results['total_distance'] > 0 || $calculation_results['fixed_amount'] > 0) {
                 $payment_data[] = [
                    'emp_id' => $emp_id, 
                    'month' => $finalize_month, 
                    'year' => $finalize_year,
                    'no_of_attendance' => $calculation_results['attendance_days'],
                    'distance' => $calculation_results['total_distance'], 
                    'monthly_payment' => $calculation_results['monthly_payment'],
                    'fixed_amount' => $calculation_results['fixed_amount'],
                ];
            }
        }
        $employees_result->free();

        if (empty($payment_data)) {
            echo json_encode(['status' => 'error', 'message' => "No payable trips/data found for $target_month_name to finalize."]);
            exit;
        }

        // --- 2.2. Check for Duplicate Insertion ---
        $duplicate_check_sql = "SELECT COUNT(*) FROM own_vehicle_payments WHERE month = ? AND year = ?";
        $duplicate_check_stmt = $conn->prepare($duplicate_check_sql);
        $duplicate_check_stmt->bind_param("ii", $finalize_month, $finalize_year);
        $duplicate_check_stmt->execute();
        $count = (int)$duplicate_check_stmt->get_result()->fetch_row()[0];
        $duplicate_check_stmt->close();

        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => "$target_month_name payments are ALREADY finalized in the Own Vehicle history table. Aborting insertion."]);
            exit;
        }
        
        // --- 2.3. Insert Data into own_vehicle_payments ---
        $conn->begin_transaction();
        $success_count = 0;
        $error_occurred = false;
        $specific_error = "";

        $insert_sql = "INSERT INTO own_vehicle_payments (emp_id, month, year, monthly_payment, no_of_attendance, distance, fixed_amount) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);

        if (!$insert_stmt) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => "SQL Insert Prepare failed: " . $conn->error]);
            exit;
        }

        foreach ($payment_data as $data) {
            $insert_stmt->bind_param("siididd", 
                $data['emp_id'], $data['month'], $data['year'], 
                $data['monthly_payment'], $data['no_of_attendance'], $data['distance'], 
                $data['fixed_amount']
            );

            if (!$insert_stmt->execute()) {
                $error_occurred = true;
                $specific_error = $insert_stmt->error;
                error_log("Payment insertion failed for {$data['emp_id']}: " . $specific_error);
                break; 
            }
            $success_count++;
        }
        $insert_stmt->close();

        // Final AJAX Response 
        if ($error_occurred) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => "Error finalizing payments. Transaction rolled back. DB Error: " . $specific_error]);
        } else {
            $conn->commit();
            // Crucial: Clear PIN verification status after successful finalization
            unset($_SESSION['pin_verified']); 
            echo json_encode(['status' => 'success', 'message' => "Successfully finalized and saved $success_count Own Vehicle payments for $target_month_name!"]);
        }

    } catch (Exception $e) {
        $conn->rollback();
        error_log("FATAL EXCEPTION during finalization: " . $e->getMessage() . " on line " . $e->getLine());
        echo json_encode(['status' => 'error', 'message' => "A severe system error occurred during processing. Error: " . $e->getMessage()]);
    }

    if (isset($conn) && $conn->ping()) $conn->close();
    exit; 
}


// =======================================================================
// 3. HTML DISPLAY LOGIC (If NOT AJAX and PIN is still required/was correct)
// =======================================================================

if (!$is_pin_correct) {
    // CRITICAL: Clean buffer before showing HTML content
    ob_end_clean();
    // Restart buffering for the HTML output
    ob_start();
    
    // HTML for PIN Entry Form
    $page_title = "Own Vehicle Payments Finalization - PIN Access";
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
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <main class="w-[85%] ml-[15%] p-8 mt-[5%] flex justify-center items-center">
        <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-md">
            <h2 class="text-2xl font-bold text-center mb-6 text-blue-600">Secure Payment Finalization</h2>
            
            <?php if (!empty($pin_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($pin_message); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="own_vehicle_payments_done.php">
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

// A. Payment Availability Check (Determine Previous Month's Status)
$payment_available_date = new DateTime('first day of this month');
$payment_available_date->modify('-1 month'); 
$available_month = (int)$payment_available_date->format('m');
$available_year = (int)$payment_available_date->format('Y');
$available_month_name = $payment_available_date->format('F Y');

// Check if this month/year combination already exists in the history table
$is_payment_already_done = false;
$check_done_sql = "SELECT COUNT(*) FROM own_vehicle_payments WHERE month = ? AND year = ? LIMIT 1";
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


// B. Check if there is data to process for the previous month (Checking attendance for any Own Vehicle employee)
$data_exists_sql = "SELECT 1 FROM own_vehicle_attendance WHERE MONTH(date) = ? AND YEAR(date) = ? LIMIT 1";
$data_exists_stmt = $conn->prepare($data_exists_sql);
$data_exists_stmt->bind_param("ii", $available_month, $available_year);
$data_exists_stmt->execute();
$data_exists = $data_exists_stmt->get_result()->num_rows > 0;
$data_exists_stmt->close();


$page_title = "Own Vehicle Payments - FINALIZATION";
include('../../includes/header.php');
include('../../includes/navbar.php');
// FINAL STEP: Flush and turn off buffering for the main HTML output
ob_end_flush(); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Payments Finalization</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="" class="hover:text-yellow-600">Factory</a>
            <a href="" class="hover:text-yellow-600">Day Heldup</a>
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
            <a href="night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Own Vehicle</p>
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
                               <i class="fas fa-exclamation-triangle mr-2"></i> No Own Vehicle Attendance data found for <?php echo htmlspecialchars($available_month_name); ?> to process.
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

                <a href="own_vehicle_payments.php" 
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
                    const confirmAction = confirm("Are you sure you want to finalize and save payments for " + targetMonth + "? This action cannot be reversed (data will be written to history table).");
                    
                    if (confirmAction) {
                        // Display processing status
                        statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-blue-100 text-blue-800';
                        statusMessage.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-2"></i> Processing... Please wait.';
                        finalizeButton.disabled = true;

                        fetch('own_vehicle_payments_done.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'finalize_payments=true'
                        })
                        .then(response => {
                             if (!response.ok) {
                                 // Try to read the error response for debugging
                                 return response.text().then(text => {
                                     throw new Error(`Server responded with status ${response.status}. Raw output: ${text.substring(0, 200)}...`);
                                 });
                             }
                            
                             const contentType = response.headers.get("content-type");
                             if (contentType && contentType.indexOf("application/json") !== -1) {
                                 return response.json();
                             } else {
                                 return response.text().then(text => {
                                     finalizeButton.disabled = false; 
                                     throw new Error("Received non-JSON content. Raw output: " + text.substring(0, 200) + "...");
                                 });
                             }
                        })
                        .then(data => {
                            if (data.status === 'success') {
                                statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-green-100 text-green-800';
                                statusMessage.innerHTML = '<i class="fas fa-check-circle mr-2"></i> ' + data.message + ' Redirecting...';
                                
                                // Redirect to the history page after success
                                setTimeout(() => {
                                    window.location.href = `own_vehicle_payments_history.php?month=${availableMonth}&year=${availableYear}`;
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
                            statusMessage.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i> Critical Error: ' + error.message; 
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