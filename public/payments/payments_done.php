<?php
// CRITICAL: Ensure no output occurs before headers in AJAX mode
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Start output buffering immediately
ob_start();

// Include necessary files
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

date_default_timezone_set('Asia/Colombo');

// Ensure db.php inclusion is checked for success if possible, 
// though failure here usually leads to a quick crash.
include('../../includes/db.php'); 
if (!isset($conn) || $conn->connect_error) {
    // If we fail here, we must rely on error logging or external tools.
    error_log("FATAL: Database connection failed.");
    // Do NOT echo JSON here unless you are in the AJAX block
}

// =======================================================================
// 0. HELPER FUNCTIONS (REQUIRED for Calculation/Insertion Logic)
// =======================================================================

// A. Fetch Fuel Price changes within the selected Month and Year
function get_fuel_price_changes_in_month($conn, $rate_id, $month, $year)
{
    // Ensure $rate_id is treated as an integer for the bind_param
    $rate_id = (int)$rate_id;

    $start_date = "$year-$month-01";
    $end_date = date('Y-m-t', strtotime($start_date)); 

    $sql = "
        SELECT date, rate
        FROM fuel_rate
        WHERE rate_id = ? 
        AND date <= ? 
        ORDER BY date DESC, id DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Fuel Rate Prepare failed: " . $conn->error);
        return [];
    }
    
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
    // Explicitly cast core input variables
    $route_distance = (float)$route_distance;
    $fixed_amount = (float)$fixed_amount;
    $with_fuel = (int)$with_fuel;
    
    // Fetch trip counts per day
    $trips_sql = "
        SELECT date, COUNT(id) AS daily_trips 
        FROM staff_transport_vehicle_register 
        WHERE route = ? AND supplier_code = ? 
        AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1
        GROUP BY date
    ";
    $trips_stmt = $conn->prepare($trips_sql);
    if (!$trips_stmt) {
         error_log("Trips SQL Prepare failed: " . $conn->error);
         return ['total_payment' => 0, 'total_trips' => 0, 'effective_trip_rate' => 0];
    }
    
    $trips_stmt->bind_param("ssii", $route_code, $supplier_code, $month, $year);
    $trips_stmt->execute();
    $trips_result = $trips_stmt->get_result();
    $daily_trip_counts = $trips_result->fetch_all(MYSQLI_ASSOC);
    $trips_stmt->close();
    
    $total_calculated_payment = 0.00;
    $total_trip_count = 0;
    $trip_rate = 0.00; 

    if (empty($daily_trip_counts)) {
        return ['total_payment' => 0, 'total_trips' => 0, 'effective_trip_rate' => 0];
    }
    
    $price_slabs = [];
    // Only fetch price slabs if fuel is involved and rate_id is present
    if ($with_fuel === 1 && $rate_id !== null) {
        $price_slabs = get_fuel_price_changes_in_month($conn, $rate_id, $month, $year);
    }
    
    // Get km_per_liter, ensure it's a float, and prevent zero division
    $km_per_liter = (float)($consumption_rates[$consumption_id] ?? $default_km_per_liter);
    if ($km_per_liter <= 0) {
        $km_per_liter = $default_km_per_liter;
    }

    foreach ($daily_trip_counts as $daily_data) {
        $trip_date = $daily_data['date'];
        $daily_trips = (int)$daily_data['daily_trips'];
        $total_trip_count += $daily_trips;

        // Find fuel price for this date
        $latest_fuel_price = 0.00;
        if ($with_fuel === 1 && !empty($price_slabs)) {
            foreach ($price_slabs as $change_date => $rate) {
                if (strtotime($trip_date) >= strtotime($change_date)) {
                    $latest_fuel_price = (float)$rate;
                }
            }
        }
        
        // Calculate Fuel Cost per KM
        $calculated_fuel_amount_per_km = 0.00;
        if ($with_fuel === 1 && $consumption_id !== null && $latest_fuel_price > 0) {
            $calculated_fuel_amount_per_km = $latest_fuel_price / $km_per_liter;
        }

        // Total Rate per KM
        $rate_per_km = $fixed_amount + $calculated_fuel_amount_per_km;

        // Rate per TRIP (Ensure division by 2 doesn't result in NaN if distance is 0)
        $trip_rate = ($route_distance > 0) ? ($rate_per_km * ($route_distance / 2)) : 0.00; 

        // Daily Payment
        $daily_payment = $trip_rate * $daily_trips;
        $total_calculated_payment += $daily_payment;
    }

    return [
        'total_payment' => $total_calculated_payment, 
        'total_trips' => $total_trip_count,
        'effective_trip_rate' => $trip_rate 
    ];
}

// C. Get Total Reduction Amount (from the 'reduction' table)
function get_total_adjustment_amount($conn, $route_code, $supplier_code, $month, $year)
{
    // Existing logic is sound, explicitly ensuring return is float
    $total_adjustment = 0.00;
    $reduction_sql = "
        SELECT SUM(amount) AS total_adjustment_amount 
        FROM reduction 
        WHERE route_code = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ?
    ";
    $reduction_stmt = $conn->prepare($reduction_sql);
    
    if (!$reduction_stmt) {
        error_log("SQL Prepare failed for reduction table: " . $conn->error);
        return 0.00; 
    }
    
    $reduction_stmt->bind_param("ssii", $route_code, $supplier_code, $month, $year);
    if ($reduction_stmt->execute()) {
        $reduction_result = $reduction_stmt->get_result();
        $row = $reduction_result->fetch_assoc();
        $total_adjustment = (float)($row['total_adjustment_amount'] ?? 0); 
        $reduction_result->free();
    } else {
         error_log("Reduction Query execution failed: " . $reduction_stmt->error);
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
    
    // CRITICAL: Clear the buffer one last time before echoing JSON
    ob_end_clean(); 
    header('Content-Type: application/json');

    // --- 2.1. Determine the Month/Year to finalize (The PREVIOUS Month) ---
    try {
        $target_date = new DateTime('first day of this month');
        $target_date->modify('-1 month'); 
        
        $finalize_month = (int)$target_date->format('m');
        $finalize_year = (int)$target_date->format('Y');

        $selected_month = $finalize_month;
        $selected_year = $finalize_year;
        $payment_data = []; 

        // Fetch consumption rates needed for calculation
        $consumption_rates = [];
        $consumption_sql = "SELECT c_id, distance FROM consumption"; 
        $consumption_result = $conn->query($consumption_sql);
        if ($consumption_result) {
            while ($row = $consumption_result->fetch_assoc()) {
                $consumption_rates[$row['c_id']] = (float)$row['distance']; 
            }
        }
        $default_km_per_liter = 1.00;

        // Fetch all unique route/supplier combinations that ran trips last month
        $payments_sql = "
            SELECT 
                stvr.route AS route_code, 
                stvr.supplier_code, 
                r.route, 
                r.fixed_amount, 
                r.distance AS route_distance, 
                r.with_fuel, 
                v.fuel_efficiency, 
                v.rate_id 
            FROM staff_transport_vehicle_register stvr 
            JOIN route r ON stvr.route = r.route_code 
            LEFT JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
            WHERE MONTH(stvr.date) = ? 
            AND YEAR(stvr.date) = ? 
            AND r.purpose = 'staff'
            GROUP BY stvr.route, stvr.supplier_code
        ";

        $payments_stmt = $conn->prepare($payments_sql);
        
        if (!$payments_stmt) {
            error_log("SQL Fetch Prepare failed (Section 2): " . $conn->error);
            echo json_encode(['status' => 'error', 'message' => "SQL Fetch Prepare failed (Section 2): " . $conn->error]);
            exit;
        }
        
        $payments_stmt->bind_param("ii", $selected_month, $selected_year);
        if (!$payments_stmt->execute()) {
             error_log("SQL Fetch Execute failed (Section 2): " . $payments_stmt->error);
             echo json_encode(['status' => 'error', 'message' => "SQL Fetch Execute failed (Section 2): " . $payments_stmt->error]);
             exit;
        }
        
        $payments_result = $payments_stmt->get_result();

        if ($payments_result && $payments_result->num_rows > 0) {
            
            while ($payment_row = $payments_result->fetch_assoc()) {
                $route_code = $payment_row['route_code'];
                $supplier_code = $payment_row['supplier_code'];
                
                // CRITICAL: Handle NULLs from DB by providing default numeric values
                $route_distance = (float)($payment_row['route_distance'] ?? 0.0); 
                $fixed_amount = (float)($payment_row['fixed_amount'] ?? 0.0); 
                $with_fuel = (int)($payment_row['with_fuel'] ?? 0);
                
                $consumption_id = $payment_row['fuel_efficiency'] ?? null;
                $rate_id = $payment_row['rate_id'] ?? null;
                
                // If route distance is 0, skip calculation
                if ($route_distance <= 0) continue; 
                
                $calculation_results = calculate_total_payment(
                    $conn, $route_code, $supplier_code, $selected_month, $selected_year,
                    $route_distance, $fixed_amount, $with_fuel, $consumption_id, $rate_id,
                    $consumption_rates, $default_km_per_liter
                );

                // Skip insertion if no trips recorded (total_trips == 0)
                if ($calculation_results['total_trips'] === 0) {
                     continue; 
                }

                $adjustment_vs_db = get_total_adjustment_amount($conn, $route_code, $supplier_code, $selected_month, $selected_year);
                $adjustment_vs_db = $adjustment_vs_db * -1; 

                $calculated_total_payment = $calculation_results['total_payment'] + $adjustment_vs_db; 

                $total_trip_count = $calculation_results['total_trips'];
                $trip_rate = $calculation_results['effective_trip_rate']; 
                $total_distance_calculated = ($route_distance / 2) * $total_trip_count;
                
                $fixed_payment_per_trip = $fixed_amount * ((float)$route_distance / 2);
                $fuel_payment_per_trip = $trip_rate - $fixed_payment_per_trip;

                $one_way_distance = (float)$route_distance / 2;
                if ($one_way_distance > 0) {
                    $fuel_payment_per_km = $fuel_payment_per_trip / $one_way_distance;
                } else {
                    $fuel_payment_per_km = 0.00; 
                }

                $monthly_fixed_amount = $fixed_payment_per_trip * $total_trip_count;
                $monthly_fuel_amount = $fuel_payment_per_trip * $total_trip_count;
                $monthly_payment_before_adjustment = $monthly_fixed_amount + $monthly_fuel_amount;
                $final_monthly_payment = $calculated_total_payment; 

                $payment_data[] = [
                    'route_code' => $route_code, 'supplier_code' => $supplier_code, 
                    'fixed_amount' => $fixed_amount, 'fuel_amount' => $fuel_payment_per_km,
                    'route_distance' => $route_distance, 'total_distance' => $total_distance_calculated, 
                    'monthly_payment' => $final_monthly_payment, 
                    'month' => $finalize_month, 'year' => $finalize_year,
                ];
            }
            $payments_stmt->close();
        }
        
        if (empty($payment_data)) {
            echo json_encode(['status' => 'error', 'message' => "No trips found for " . $target_date->format('F Y') . " to finalize."]);
            exit;
        }

        // --- 2.2. Check for Duplicate Insertion (Prevent double finalization) ---
        $duplicate_check_sql = "SELECT COUNT(*) FROM monthly_payments_sf WHERE month = ? AND year = ?";
        $duplicate_check_stmt = $conn->prepare($duplicate_check_sql);
        $duplicate_check_stmt->bind_param("ii", $finalize_month, $finalize_year);
        $duplicate_check_stmt->execute();
        $count = (int)$duplicate_check_stmt->get_result()->fetch_row()[0];
        $duplicate_check_stmt->close();

        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => $target_date->format('F Y') . " payments are ALREADY finalized in the history table. Aborting insertion."]);
            exit;
        }
        
        // --- 2.3. Insert Data into monthly_payments_sf ---
        $conn->begin_transaction();
        $success_count = 0;
        $error_occurred = false;
        $specific_error = "";

        $insert_sql = "INSERT INTO monthly_payments_sf (route_code, supplier_code, month, year, fixed_amount, fuel_amount, route_distance, monthly_payment, total_distance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);

        if (!$insert_stmt) {
            $conn->rollback();
            error_log("SQL Insert Prepare failed: " . $conn->error);
            echo json_encode(['status' => 'error', 'message' => "SQL Insert Prepare failed: " . $conn->error]);
            exit;
        }

        foreach ($payment_data as $data) {
            $insert_stmt->bind_param("ssiiddidd", 
                $data['route_code'], $data['supplier_code'], $data['month'], $data['year'], 
                $data['fixed_amount'], $data['fuel_amount'], $data['route_distance'], 
                $data['monthly_payment'], $data['total_distance']
            );

            if (!$insert_stmt->execute()) {
                $error_occurred = true;
                $specific_error = $insert_stmt->error;
                error_log("Payment insertion failed for {$data['route_code']} / {$data['supplier_code']}: " . $specific_error);
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
            echo json_encode(['status' => 'success', 'message' => "Successfully finalized and saved $success_count payments for " . $target_date->format('F Y') . "!"]);
        }

    } catch (Exception $e) {
        // Catch any PHP exceptions that weren't SQL errors (e.g., date errors, severe logic failure)
        $conn->rollback();
        error_log("FATAL EXCEPTION during finalization: " . $e->getMessage() . " on line " . $e->getLine());
        echo json_encode(['status' => 'error', 'message' => "A severe system error occurred during processing. Please check logs. Error: " . $e->getMessage()]);
    }

    exit; // EXIT after JSON response
}

// =======================================================================
// 3. HTML DISPLAY LOGIC (If NOT AJAX and PIN is still required/was correct)
// =======================================================================

// --- PIN FORM DISPLAY ---
if (!$is_pin_correct) {
    // CRITICAL: Clean buffer before showing HTML content
    ob_end_clean();
    // Restart buffering for the HTML output
    ob_start();
    
    // HTML for PIN Entry Form (RETAINS ORIGINAL PIN FORM)
    $page_title = "Payments Finalization - PIN Access";
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

            <form method="post" action="payments_done.php">
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
    exit(); // Exit after PIN form submission if PIN was wrong or not yet entered
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
$check_done_sql = "SELECT COUNT(*) FROM monthly_payments_sf WHERE month = ? AND year = ? LIMIT 1";
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


// B. Check if there is data to process for the previous month
$data_exists_sql = "SELECT 1 FROM staff_transport_vehicle_register stvr JOIN route r ON stvr.route = r.route_code WHERE MONTH(stvr.date) = ? AND YEAR(stvr.date) = ? AND r.purpose = 'staff' LIMIT 1";
$data_exists_stmt = $conn->prepare($data_exists_sql);
$data_exists_stmt->bind_param("ii", $available_month, $available_year);
$data_exists_stmt->execute();
$data_exists_result = $data_exists_stmt->get_result();
$data_exists = $data_exists_result->num_rows > 0;
$data_exists_stmt->close();


$page_title = "Staff Monthly Payments - FINALIZATION";
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
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Staff</p>
            <a href="factory/factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
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
                               <i class="fas fa-exclamation-triangle mr-2"></i> No trip data found for <?php echo htmlspecialchars($available_month_name); ?> to process.
                           </span>
                    <?php else: ?>
                        <span class="bg-blue-100 text-blue-800 block p-3 rounded-lg">
                            <i class="fas fa-calendar-alt mr-2"></i> Ready to finalize payments for <?php echo htmlspecialchars($available_month_name); ?>.
                            <br>Click the button below to save the records.
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

                <a href="payments_category.php" 
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

            if (finalizeButton) {
                finalizeButton.addEventListener('click', function() {
                    const confirmAction = confirm("Are you sure you want to finalize and save payments for " + targetMonth + "? This action cannot be reversed (data will be written to history table).");
                    
                    if (confirmAction) {
                        // Display processing status
                        statusMessage.className = 'px-3 py-2 text-base font-semibold rounded-lg w-full text-center bg-blue-100 text-blue-800';
                        statusMessage.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-2"></i> Processing... Please wait.';
                        finalizeButton.disabled = true;

                        fetch('payments_done.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'finalize_payments=true'
                        })
                        .then(response => {
                             // IMPORTANT: Check for non-200 status (like 500)
                             if (!response.ok) {
                                 // Read the raw text for the server error message
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
                                statusMessage.innerHTML = '<i class="fas fa-check-circle mr-2"></i> ' + data.message;

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
if (isset($conn)) {
    $conn->close();
}
?>