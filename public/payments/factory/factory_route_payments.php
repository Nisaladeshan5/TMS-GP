<?php
// payments_category.php (Staff Monthly Payments)
require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');

// =======================================================================
// 1. DYNAMIC DATE FILTER LOGIC
//    (Uses monthly_payments_sf ONLY to find where the history ends)
// =======================================================================

// A. Fetch Max Month and Year from history table
$max_payments_sql = "SELECT MAX(month) AS max_month, MAX(year) AS max_year FROM monthly_payments_f";
$max_payments_result = $conn->query($max_payments_sql);

$db_max_month = 0;
$db_max_year = 0;

if ($max_payments_result && $max_payments_result->num_rows > 0) {
    $max_data = $max_payments_result->fetch_assoc();
    $db_max_month = (int)($max_data['max_month'] ?? 0);
    $db_max_year = (int)($max_data['max_year'] ?? 0);
}

// B. Calculate the STARTING point for the dropdowns (Month after the Max Payment)
$start_month = 0;
$start_year = 0;

if ($db_max_month === 0 && $db_max_year === 0) {
    // Case 1: No data in the table, start from the current month/year
    $start_month = (int)date('n');
    $start_year = (int)date('Y');
} elseif ($db_max_month == 12) {
    // Case 2: Max month is December, start from January of the next year
    $start_month = 1;        
    $start_year = $db_max_year + 1; 
} else {
    // Case 3: Start from the next month in the same year
    $start_month = $db_max_month + 1;
    $start_year = $db_max_year;
}

// C. Determine the CURRENT (ENDING) point for the dropdowns
$current_month = (int)date('n');
$current_year = (int)date('Y');

// D. Set the variables for the HTML loops
$year_loop_start = $current_year;
$year_loop_end = $start_year; 
$month_loop_end = $current_month;


// =======================================================================
// 2. HELPER FUNCTIONS & SETUP
// =======================================================================

// Get selected month and year, default to current month/year
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$payment_data = [];

// Fetch Fuel Price changes within the selected Month and Year
function get_fuel_price_changes_in_month($conn, $rate_id, $month, $year)
{
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

// Fetch Consumption Rates
$consumption_rates = [];
$consumption_sql = "SELECT c_id, distance FROM consumption"; 
$consumption_result = $conn->query($consumption_sql);
if ($consumption_result) {
    while ($row = $consumption_result->fetch_assoc()) {
        $consumption_rates[$row['c_id']] = $row['distance'];
    }
}
$default_km_per_liter = 1.00;

// Core Calculation Logic
function calculate_total_payment($conn, $route_code, $supplier_code, $month, $year, $route_distance, $fixed_amount, $with_fuel, $consumption_id, $rate_id, $consumption_rates, $default_km_per_liter)
{
    // Fetch trip counts per day
    $trips_sql = "
        SELECT date, COUNT(id) AS daily_trips 
        FROM factory_transport_vehicle_register 
        WHERE route = ? AND supplier_code = ? 
        AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1
        GROUP BY date
    ";
    $trips_stmt = $conn->prepare($trips_sql);
    $trips_stmt->bind_param("ssii", $route_code, $supplier_code, $month, $year);
    $trips_stmt->execute();
    $trips_result = $trips_stmt->get_result();
    $daily_trip_counts = $trips_result->fetch_all(MYSQLI_ASSOC);
    $trips_stmt->close();
    
    $total_calculated_payment = 0;
    $total_trip_count = 0;
    $trip_rate = 0; 

    if (empty($daily_trip_counts)) {
        return ['total_payment' => 0, 'total_trips' => 0, 'effective_trip_rate' => 0];
    }
    
    $price_slabs = [];
    if ($with_fuel === 1 && $rate_id !== null) {
        $price_slabs = get_fuel_price_changes_in_month($conn, $rate_id, $month, $year);
    }
    
    $km_per_liter = $consumption_rates[$consumption_id] ?? $default_km_per_liter;
    
    foreach ($daily_trip_counts as $daily_data) {
        $trip_date = $daily_data['date'];
        $daily_trips = (int)$daily_data['daily_trips'];
        $total_trip_count += $daily_trips;

        // Find fuel price for this date
        $latest_fuel_price = 0;
        if ($with_fuel === 1 && !empty($price_slabs)) {
            foreach ($price_slabs as $change_date => $rate) {
                if (strtotime($trip_date) >= strtotime($change_date)) {
                    $latest_fuel_price = $rate;
                }
            }
        }
        
        // Calculate Fuel Cost per KM
        $calculated_fuel_amount_per_km = 0;
        if ($with_fuel === 1 && $consumption_id !== null) {
            if ($latest_fuel_price > 0 && $km_per_liter > 0) {
                $calculated_fuel_amount_per_km = $latest_fuel_price / $km_per_liter;
            }
        }

        // Total Rate per KM
        $rate_per_km = $fixed_amount + $calculated_fuel_amount_per_km;

        // Rate per TRIP 
        $trip_rate = $rate_per_km * ($route_distance / 2); 

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


/**
 * ADJUSTED FUNCTION: Get Total Reduction Amount (from the 'reduction' table)
 * This replaces the previous combined Extra Vehicle/Petty Cash function.
 */
function get_total_adjustment_amount($conn, $route_code, $supplier_code, $month, $year)
{
    $total_adjustment = 0.00;

    // 1. Sum 'amount' from the 'reduction' table
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
        // Use the fetched sum
        $total_adjustment = (float)($row['total_adjustment_amount'] ?? 0);
        $reduction_result->free();
    }
    $reduction_stmt->close();

    return $total_adjustment; // This is the total sum from the reduction table.
}


// --- MAIN DATA FETCH (DECOUPLED FROM monthly_payments_sf) ---
// We fetch routes/suppliers that HAVE registered trips in the selected month/year.
$payments_sql = "
    SELECT DISTINCT 
        ftvr.route AS route_code, 
        ftvr.supplier_code, 
        r.route, 
        r.fixed_amount, 
        r.distance AS route_distance, 
        r.with_fuel,
        v.fuel_efficiency,  
        v.rate_id
    FROM factory_transport_vehicle_register ftvr 
    JOIN route r ON ftvr.route = r.route_code
    LEFT JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
    WHERE MONTH(ftvr.date) = ? 
    AND YEAR(ftvr.date) = ? 
    AND r.purpose = 'factory'
    ORDER BY CAST(SUBSTRING(ftvr.route, 7, 3) AS UNSIGNED) ASC;
";
$payments_stmt = $conn->prepare($payments_sql);
$payments_stmt->bind_param("ii", $selected_month, $selected_year);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();

if ($payments_result && $payments_result->num_rows > 0) {
    while ($payment_row = $payments_result->fetch_assoc()) {
        $route_code = $payment_row['route_code'];
        $supplier_code = $payment_row['supplier_code'];
        $route_name = $payment_row['route'];
        $route_distance = (float)$payment_row['route_distance']; 
        $fixed_amount = (float)$payment_row['fixed_amount']; 
        $with_fuel = (int)$payment_row['with_fuel'];
        $consumption_id = $payment_row['fuel_efficiency'] ?? null;
        $rate_id = $payment_row['rate_id'] ?? null;
        
        // 🎯 Prorated Calculation (Base Trip Payment)
        $calculation_results = calculate_total_payment(
            $conn, $route_code, $supplier_code, $selected_month, $selected_year,
            $route_distance, $fixed_amount, $with_fuel, $consumption_id, $rate_id,
            $consumption_rates, $default_km_per_liter
        );

        // --- START NEW ADJUSTMENT LOGIC ---
        // Calling the function that queries the 'reduction' table only.
        $adjustment_vs_db = get_total_adjustment_amount($conn, $route_code, $supplier_code, $selected_month, $selected_year);
        $adjustment_vs_db = $adjustment_vs_db * -1;
        // --- END NEW ADJUSTMENT LOGIC ---

        $calculated_total_payment = $calculation_results['total_payment'];
        $calculated_total_payment += $adjustment_vs_db;
        $total_trip_count = $calculation_results['total_trips'];
        $trip_rate = $calculation_results['effective_trip_rate']; // Last calculated trip rate

        

        // 6. Calculate Total Distance: (Route Distance / 2) * Total Trips
        $total_distance_calculated = ($route_distance / 2) * $total_trip_count;

        $route_suffix = substr($route_code, 6, 3);

        $payment_data[] = [
            'route_code' => $route_code, 
            'supplier_code' => $supplier_code, 
            'no' => $route_suffix,
            'route' => $route_name . " (" . $supplier_code . ")",
            'price_per_1km' => $trip_rate, // Display Trip Rate (based on the last calculated slab)
            'total_working_days' => $total_trip_count, 
            'total_distance' => $total_distance_calculated, 
            'other_amount' => $adjustment_vs_db, // NOW holds the total sum from 'reduction' table
            'payments' => $calculated_total_payment // NEW CALCULATED AMOUNT
        ];
    }
    $payments_stmt->close();
}
// --------------------------------------------------------------------------------

// Define global variables BEFORE including header/navbar
$page_title = $page_title ?? "Factory Monthly Payments Summary";
$table_headers = $table_headers ?? [
    "No",
    "Route (Supplier Code)", 
    "Trip Rate (LKR)",
    "Total Trip Count", 
    "Total Distance (km)",
    "Adjustment (LKR)",
    "Total Payments (LKR)",
    "PDF"
];

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .overflow-x-auto::-webkit-scrollbar { height: 8px; }
        .overflow-x-auto::-webkit-scrollbar-thumb { background-color: #a0aec0; border-radius: 4px; }
        .overflow-x-auto::-webkit-scrollbar-track { background-color: #edf2f7; }
    </style>
</head>
<script>
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

    setTimeout(function() {
        // Alert is used based on your provided code structure, replace with a custom modal if needed.
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="../payments_category.php">Staff</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold" class="hover:text-yellow-600">Factory</p>
            <a href="sub/sub_route_payments.php" class="hover:text-yellow-600">Sub Route</a>
            <a href="../DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a>
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
            <a href="../night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="../own_vehicle_payments.php" class="hover:text-yellow-600">Fuel Allowance</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-4 mt-[1%]">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 mt-4">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0"><?php echo htmlspecialchars($page_title); ?></h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="factory_route_payments.php" class="flex flex-wrap gap-2 items-center">
                    
                    <a href="download_factory_route_payments.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
                        class="px-3 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 text-center">
                        <i class="fas fa-download"></i>
                    </a>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="month" id="month" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php 
                            // Dynamic Month Dropdown Logic
                            $min_month_to_show = 1;
                            if ($start_year == $selected_year) {
                                $min_month_to_show = $start_month;
                            }
                            if ($selected_year < $start_year) {
                                $min_month_to_show = 13; 
                            }

                            $max_month_to_show = 12;
                            if ($selected_year == $current_year) {
                                $max_month_to_show = $month_loop_end;
                            }

                            for ($m = $min_month_to_show; $m <= $max_month_to_show; $m++): 
                            ?>
                                <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($selected_month == $m) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="year" id="year" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php 
                            // Dynamic Year Dropdown Logic
                            for ($y=$year_loop_start; $y>=$year_loop_end; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="px-3 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200" title="Filter">
                        <i class="fas fa-filter mr-1"></i> 
                    </button>
                    <a href="f_payments_done.php" class="px-3 py-2 bg-teal-600 text-white font-semibold rounded-lg shadow-md hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-200 text-center">
                    <i class="fas fa-check-circle mr-1"></i>
                </a>
                    <a href="f_payments_history.php" 
                    class="px-3 py-2 bg-yellow-600 text-white font-semibold rounded-lg shadow-md hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-200 text-center"
                    title="History"> <i class="fas fa-history mr-1"></i>
                    </a> 
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <?php foreach ($table_headers as $header): ?>
                            <th class="py-3 px-6 text-left border-b border-blue-500"><?php echo htmlspecialchars($header); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($payment_data)): ?>
                        <?php foreach ($payment_data as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                <?php 
                                $display_keys = ['no', 'route', 'price_per_1km', 'total_working_days', 'total_distance', 'other_amount', 'payments'];

                                foreach ($display_keys as $key): 
                                    $value = $data[$key];
                                    $cell_class = "py-3 px-6 whitespace-nowrap";
                                    $formatted_value = htmlspecialchars($value);

                                    if (in_array($key, ['price_per_1km', 'other_amount', 'payments'])) {
                                        $formatted_value = number_format($value, 2);
                                        $cell_class .= " font-semibold text-left"; 
                                        
                                        if ($key === 'payments') {
                                            $cell_class .= " text-blue-700 text-base font-extrabold";
                                        } elseif ($key === 'other_amount') {
                                            // Highlight positive amounts green (addition) and negative red (deduction)
                                            $cell_class .= $value >= 0 ? " text-green-600" : " text-red-600";
                                        } elseif ($key === 'price_per_1km') {
                                            $cell_class .= " text-purple-600";
                                        }
                                    } elseif (in_array($key, ['total_distance', 'total_working_days'])) {
                                        $formatted_value = number_format($value, $key === 'total_working_days' ? 0 : 2);
                                        $cell_class .= " text-left";
                                    } else {
                                        $cell_class .= " font-medium";
                                    }
                                ?>
                                    <td class="<?php echo $cell_class; ?>">
                                        <?php echo $formatted_value; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="py-3 px-6 whitespace-nowrap text-center">
                                    <?php 
                                        $calculated_payment_url = urlencode(number_format($data['payments'], 2, '.', ''));
                                        $total_distance_url = urlencode(number_format($data['total_distance'], 2, '.', ''));
                                    ?>
                                    <a href="download_factory_pdf.php?route_code=<?php echo htmlspecialchars($data['route_code']); ?>&supplier_code=<?php echo htmlspecialchars($data['supplier_code']); ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&calculated_payment=<?php echo $calculated_payment_url; ?>&total_distance_calc=<?php echo $total_distance_url; ?>"
                                        class="text-red-500 hover:text-red-700 transition duration-150"
                                        title="Download Detailed PDF">
                                            <i class="fas fa-file-pdf fa-lg"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo count($table_headers); ?>" class="py-12 text-center text-gray-500 text-base font-medium">No staff route payment data available for the selected period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>

<?php
$conn->close();
?>