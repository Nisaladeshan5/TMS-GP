<?php
// nh_done.php (Finalize Night Heldup Payments - STRICT SECURITY)
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
// 0. HELPER FUNCTION (Night Heldup Calculation Logic)
// =======================================================================

function calculate_monthly_nh_data($conn, $month, $year) {
    // 1. Fetch Aggregated Data (Grouped by Night Shift Date)
    // Logic: If time < 7AM, shift date is yesterday.
    
    $sql = "
        SELECT 
            nh.op_code,
            -- Effective Date Calculation
            IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date) as effective_date,
            SUM(nh.distance) AS daily_distance,
            os.slab_limit_distance,
            os.extra_rate AS rate_per_km,
            os.supplier_code
        FROM 
            nh_register nh
        JOIN 
            op_services os ON nh.op_code = os.op_code
        WHERE 
            nh.done = 1 
            -- Filter using the shift date logic
            AND DATE_FORMAT(IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date), '%Y-%m') = ?
        GROUP BY 
            nh.op_code, effective_date
        ORDER BY 
            nh.op_code ASC
    ";
    
    $stmt = $conn->prepare($sql);
    $filter_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT);
    $stmt->bind_param("s", $filter_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $summary = [];

    while ($row = $result->fetch_assoc()) {
        $op_code = $row['op_code'];
        $supplier_code = $row['supplier_code'];
        $daily_actual_dist = (float)$row['daily_distance'];
        $slab = (float)$row['slab_limit_distance'];
        $rate = (float)$row['rate_per_km'];
        
        $payable_dist = 0;

        // --- Logic Check (Applied on Daily Total) ---
        if (strpos($op_code, 'NH') === 0) {
            // NH: If Daily Total < Slab, Pay Slab. Else Pay Actual.
            $payable_dist = max($daily_actual_dist, $slab);
        } elseif (strpos($op_code, 'EV') === 0) {
            // EV: Always Pay Actual
            $payable_dist = $daily_actual_dist;
        } else {
            $payable_dist = $daily_actual_dist;
        }

        $daily_payment = $payable_dist * $rate;

        // --- Aggregate to Monthly Summary ---
        if (!isset($summary[$op_code])) {
            $summary[$op_code] = [
                'op_code' => $op_code,
                'supplier_code' => $supplier_code,
                'total_distance' => 0.00, // Sum of ACTUAL distance
                'monthly_payment' => 0.00
            ];
        }

        $summary[$op_code]['total_distance'] += $daily_actual_dist; 
        $summary[$op_code]['monthly_payment'] += $daily_payment; 
    }
    
    $stmt->close();
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

        // --- CALCULATION LOGIC ---
        $payment_data = calculate_monthly_nh_data($conn, $finalize_month, $finalize_year);
        
        if (empty($payment_data)) { 
            echo json_encode(['status' => 'error', 'message' => "No payable data found for " . $target_date->format('F Y')]); 
            exit; 
        }

        // --- CHECK DUPLICATES ---
        $duplicate_check_sql = "SELECT COUNT(*) FROM monthly_payments_nh WHERE month = ? AND year = ?";
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

        $insert_sql = "INSERT INTO monthly_payments_nh (op_code, supplier_code, month, year, total_distance, monthly_payment) VALUES (?, ?, ?, ?, ?, ?)";
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
    <title>Night Heldup PIN Access</title>
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
            <a href="nh_payments.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Night Heldup
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Finalize Payments
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="nh_payments.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
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

        <form method="post" action="nh_done.php">
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
    document.querySelector("form").addEventListener("submit", function() {
        const loader = document.getElementById("pageLoader");
        loader.querySelector("p").innerText = "Verifying PIN...";
        loader.classList.remove("hidden");
        loader.classList.add("flex");
    });

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
$check_done_stmt = $conn->prepare("SELECT COUNT(*) FROM monthly_payments_nh WHERE month = ? AND year = ? LIMIT 1");
if ($check_done_stmt) {
    $check_done_stmt->bind_param("ii", $available_month, $available_year);
    $check_done_stmt->execute();
    if ((int)$check_done_stmt->get_result()->fetch_row()[0] > 0) $is_payment_already_done = true;
    $check_done_stmt->close();
}

// Check if Data Exists (Logic: Shift Date)
$data_exists = false;
$check_exists_filter = "$available_year-" . str_pad($available_month, 2, '0', STR_PAD_LEFT);
$data_exists_sql = "
    SELECT 1 
    FROM nh_register 
    WHERE DATE_FORMAT(IF(time < '07:00:00', DATE_SUB(date, INTERVAL 1 DAY), date), '%Y-%m') = ? 
    LIMIT 1
";
$data_exists_stmt = $conn->prepare($data_exists_sql);
$data_exists_stmt->bind_param("s", $check_exists_filter);
$data_exists_stmt->execute();
if ($data_exists_stmt->get_result()->num_rows > 0) $data_exists = true;
$data_exists_stmt->close();

include('../../../includes/header.php');
include('../../../includes/navbar.php');
ob_end_flush(); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finalize Night Heldup Payments</title>
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
            <a href="nh_payments.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Night Heldup
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Finalize Payments
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="nh_payments.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            <i class="fas fa-calculator"></i> Current Calculations
        </a>
    </div>
</div>

<main class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex justify-center items-start mt-10">
    <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200 w-full max-w-lg text-center">
        
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Month End Process</h2>
        <p class="text-sm text-gray-500 mb-8">Finalize <strong>Night Heldup</strong> payments for the previous month.</p>

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
                    <p class="text-yellow-700 text-sm mt-1">No Night Heldup records found for <strong><?php echo htmlspecialchars($available_month_name); ?></strong>.</p>
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
            <a href="nh_history.php" class="inline-flex items-center justify-center gap-2 w-full py-3 bg-gray-800 text-white font-semibold rounded-lg hover:bg-gray-900 transition shadow-md">
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
                if (confirm("Confirm Night Heldup Finalization for " + targetMonth + "?\n\nData will be permanently saved to history.")) {
                    
                    finalizeButton.disabled = true;
                    finalizeButton.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Processing...';
                    finalizeButton.classList.add('opacity-75', 'cursor-not-allowed');

                    fetch('nh_done.php', {
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
                            window.location.href = `nh_history.php?month=${availableMonth}&year=${availableYear}`;
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