<?php
// day_heldup_done.php
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
// 0. HELPER FUNCTIONS (Day Heldup Calculation Logic)
// =======================================================================

function calculate_monthly_dh_data($conn, $month, $year) {
    // 1. Fetch Attendance Records (to get slab & rates)
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
                'total_distance' => 0.00,
                'monthly_payment' => 0.00
            ];
        }
        
        $summary[$op_code]['total_distance'] += $actual_dist;
        $summary[$op_code]['monthly_payment'] += $payment;
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
    $entered_pin = (string)$entered_pin;
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

        $selected_month = $finalize_month;
        $selected_year = $finalize_year;
        
        // --- CALCULATION LOGIC ---
        $payment_data = calculate_monthly_dh_data($conn, $finalize_month, $finalize_year);
        
        if (empty($payment_data)) { 
            echo json_encode(['status' => 'error', 'message' => "No payable data found for " . $target_date->format('F Y')]); 
            exit; 
        }

        // --- CHECK DUPLICATES ---
        $duplicate_check_sql = "SELECT COUNT(*) FROM monthly_payments_dh WHERE month = ? AND year = ?";
        $duplicate_check_stmt = $conn->prepare($duplicate_check_sql);
        $duplicate_check_stmt->bind_param("ii", $finalize_month, $finalize_year);
        $duplicate_check_stmt->execute();
        $count = (int)$duplicate_check_stmt->get_result()->fetch_row()[0];
        $duplicate_check_stmt->close();

        if ($count > 0) { 
            echo json_encode(['status' => 'error', 'message' => "Payments for " . $target_date->format('F Y') . " already finalized."]); 
            exit; 
        }
        
        // --- INSERT DATA ---
        $conn->begin_transaction();
        $success_count = 0;
        $error_occurred = false;
        $specific_error = "";

        $insert_sql = "INSERT INTO monthly_payments_dh (op_code, supplier_code, month, year, total_distance, monthly_payment) VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);

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
    include('../../../includes/header.php');
    include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Day Heldup PIN Access</title>
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
            <a href="day_heldup_payments.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Day Heldup
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Finalize Payments
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="day_heldup_payments.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
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

        <form method="post" action="day_heldup_done.php">
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

// Check History
$is_payment_already_done = false;
$check_done_stmt = $conn->prepare("SELECT COUNT(*) FROM monthly_payments_dh WHERE month = ? AND year = ? LIMIT 1");
if ($check_done_stmt) {
    $check_done_stmt->bind_param("ii", $available_month, $available_year);
    $check_done_stmt->execute();
    if ((int)$check_done_stmt->get_result()->fetch_row()[0] > 0) $is_payment_already_done = true;
    $check_done_stmt->close();
}

// Check if Data Exists
$data_exists = false;
$data_exists_stmt = $conn->prepare("SELECT 1 FROM dh_attendance WHERE MONTH(date) = ? AND YEAR(date) = ? LIMIT 1");
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
    <title>Finalize Day Heldup Payments</title>
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
            <a href="day_heldup_payments.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Day Heldup
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Finalize Payments
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="day_heldup_payments.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            <i class="fas fa-calculator"></i> Current Calculations
        </a>
    </div>
</div>

<main class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex justify-center items-start mt-10">
    <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200 w-full max-w-lg text-center">
        
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Month End Process</h2>
        <p class="text-sm text-gray-500 mb-8">Finalize <strong>Day Heldup</strong> payments for the previous month.</p>

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
                    <p class="text-yellow-700 text-sm mt-1">No Day Heldup records found for <strong><?php echo htmlspecialchars($available_month_name); ?></strong>.</p>
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
            <a href="day_heldup_history.php" class="inline-flex items-center justify-center gap-2 w-full py-3 bg-gray-800 text-white font-semibold rounded-lg hover:bg-gray-900 transition shadow-md">
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
                if (confirm("Confirm Day Heldup Finalization for " + targetMonth + "?\n\nData will be permanently saved to history.")) {
                    
                    finalizeButton.disabled = true;
                    finalizeButton.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Processing...';
                    finalizeButton.classList.add('opacity-75', 'cursor-not-allowed');

                    fetch('day_heldup_done.php', {
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
                            // Redirect to history page
                            window.location.href = `day_heldup_history.php?month=${availableMonth}&year=${availableYear}`;
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
if (isset($conn)) {
    $conn->close();
}
?>