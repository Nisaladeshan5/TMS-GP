<?php
// own_vehicle_payments.php - Fuel Allowance Payments (Single Dropdown Updated)

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

$page_title = "Fuel Allowance Payments Summary";

// =======================================================================
// 1. FILTER LOGIC (DATE RANGE CALCULATION)
// =======================================================================

// A. Get the Last Finalized Payment Month/Year from own_vehicle_payments
$max_payments_sql = "SELECT MAX(month) AS max_month, MAX(year) AS max_year FROM own_vehicle_payments";
$max_payments_result = $conn->query($max_payments_sql);

$db_max_month = 0;
$db_max_year = 0;

if ($max_payments_result && $max_payments_result->num_rows > 0) {
    $max_data = $max_payments_result->fetch_assoc();
    $db_max_month = (int)($max_data['max_month'] ?? 0);
    $db_max_year = (int)($max_data['max_year'] ?? 0);
}

// B. Calculate the STARTING point (Next Due Month)
$start_month = 0;
$start_year = 0;

if ($db_max_month === 0 && $db_max_year === 0) {
    // Case 1: No data, start from current year Jan or specific default
    $start_month = 1;
    $start_year = 0; // 0 means no limit yet
} elseif ($db_max_month == 12) {
    // Case 2: Max month is Dec, start from Jan next year
    $start_month = 1;        
    $start_year = $db_max_year + 1; 
} else {
    // Case 3: Start from next month same year
    $start_month = $db_max_month + 1;
    $start_year = $db_max_year;
}

// C. Determine the ENDING point (Current System Date)
$current_month_sys = (int)date('n');
$current_year_sys = (int)date('Y');


// =======================================================================
// 2. HELPER FUNCTIONS & SELECTION LOGIC
// =======================================================================

// --- [CHANGED] NEW LOGIC FOR HANDLING SINGLE DROPDOWN INPUT ---
$selected_month = $current_month_sys;
$selected_year = $current_year_sys;

// Check if 'month_year' is passed (e.g., "2025-12")
if (isset($_GET['month_year']) && !empty($_GET['month_year'])) {
    $parts = explode('-', $_GET['month_year']);
    if (count($parts) == 2) {
        $selected_year = (int)$parts[0];
        $selected_month = (int)$parts[1];
    }
} elseif (isset($_GET['month']) && isset($_GET['year'])) {
    // Fallback for old links
    $selected_month = (int)$_GET['month'];
    $selected_year = (int)$_GET['year'];
}
// -------------------------------------------------------------


// --- Database Functions ---

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

// *** MODIFICATION 1: Added $vehicle_no to filter attendance ***
function get_monthly_attendance_records($conn, $emp_id, $vehicle_no, $month, $year) {
    // Check both emp_id AND vehicle_no
    $sql = "SELECT date, time FROM own_vehicle_attendance 
            WHERE emp_id = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return [];
    $stmt->bind_param("ssii", $emp_id, $vehicle_no, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = [];
    while ($row = $result->fetch_assoc()) { $records[] = $row; }
    $stmt->close();
    return $records;
}

// *** MODIFICATION 2: Added $vehicle_no to filter extra trips ***
function get_monthly_extra_records($conn, $emp_id, $vehicle_no, $month, $year) {
    // Check both emp_id AND vehicle_no
    $sql = "SELECT date, out_time, distance FROM own_vehicle_extra 
            WHERE emp_id = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ? AND done = 1 AND distance IS NOT NULL";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return [];
    $stmt->bind_param("ssii", $emp_id, $vehicle_no, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = [];
    while ($row = $result->fetch_assoc()) { $records[] = $row; }
    $stmt->close();
    return $records;
}

// --- MAIN DATA FETCH ---
$payment_data = [];

// This query selects ALL vehicles assigned to employees.
// If an employee has 2 vehicles, this returns 2 rows (correct behavior).
$employees_sql = "
    SELECT 
        e.emp_id, 
        e.calling_name,
        ov.vehicle_no,
        ov.fuel_efficiency AS consumption, 
        ov.fixed_amount,
        ov.distance, 
        ov.rate_id
    FROM 
        own_vehicle ov
    JOIN 
        employee e ON ov.emp_id = e.emp_id
    ORDER BY 
        e.calling_name ASC, ov.vehicle_no ASC;
";

$result = $conn->query($employees_sql);

if ($result && $result->num_rows > 0) {
    while ($employee_row = $result->fetch_assoc()) {
        $emp_id = $employee_row['emp_id'];
        $vehicle_no = $employee_row['vehicle_no']; // *** Get the specific Vehicle No ***
        $rate_id = $employee_row['rate_id'];
        $consumption = (float)$employee_row['consumption']; 
        $daily_distance = (float)$employee_row['distance'];
        $fixed_amount = (float)$employee_row['fixed_amount']; 
        $fixed_amount_display = $fixed_amount; 

        $total_monthly_payment = 0.00; 
        $total_attendance_days = 0;
        $total_calculated_distance = 0.00;
        
        $total_monthly_payment += $fixed_amount;
        
        // 1. Process Attendance Payments (Pass specific vehicle_no)
        $attendance_records = get_monthly_attendance_records($conn, $emp_id, $vehicle_no, $selected_month, $selected_year);
        
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
        
        // 2. Process Extra Payments (Pass specific vehicle_no)
        $extra_records = get_monthly_extra_records($conn, $emp_id, $vehicle_no, $selected_month, $selected_year);
        
        foreach ($extra_records as $record) {
            $datetime = $record['date'] . ' ' . $record['out_time'];
            $extra_distance = (float)$record['distance'];

            $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
            
            if ($fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
                $day_rate_base = ($consumption / 100) * $daily_distance * $fuel_price;
                $rate_per_km = $day_rate_base / $daily_distance; 
                $extra_payment = $rate_per_km * $extra_distance;
                
                $total_monthly_payment += $extra_payment;
                $total_calculated_distance += $extra_distance;
            }
        }

        // Store Data
        $payment_data[] = [
            'emp_id' => $emp_id,
            'vehicle_no' => $vehicle_no, 
            'display_name' => $emp_id . ' - ' . $employee_row['calling_name'] . " (" . $vehicle_no . ")",
            'fixed_amount' => $fixed_amount_display,
            'attendance_days' => $total_attendance_days,
            'total_distance' => $total_calculated_distance,
            'payments' => $total_monthly_payment,
        ];
    }
}

// Table Headers
$table_headers = [
    "Employee (Vehicle No)",
    "Attendance Days",
    "Total Distance (km)",
    "Fixed Allowance (LKR)",
    "Total Payment (LKR)",
    "PDF"
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
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
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="factory/factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <a href="factory/sub/sub_route_payments.php" class="hover:text-yellow-600">Sub Route</a>
            <a href="DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a>
            <a href="NH/nh_payments.php" class="hover:text-yellow-600">Night Heldup</a>
            <a href="night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="EV/ev_payments.php" class="hover:text-yellow-600">Extra Vehicle</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Fuel Allowance</p> 
            <a href="all_payments_summary.php" class="hover:text-yellow-600">Summary</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-3 mt-[1%]"> 
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3 pt-4">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0"><?php echo htmlspecialchars($page_title); ?></h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="own_vehicle_payments.php" class="flex flex-wrap gap-2 items-center">
                    <a href="own_vehicle_excel.php?month_year=<?php echo sprintf('%04d-%02d', $selected_year, $selected_month); ?>" 
                       target="_blank" 
                       class="px-3 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 text-center"
                       title="Export Excel">
                       <i class="fas fa-file-excel fa-lg"></i>
                    </a>
                    <a href="download_own_vehicle_excel.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
                        class="px-3 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 text-center"
                        title="Download Monthly Report">
                        <i class="fas fa-download"></i>
                    </a>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm min-w-[200px]">
                        <select name="month_year" id="month_year" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php 
                            // 1. Loop setup
                            $loop_curr_year = $current_year_sys;
                            $loop_curr_month = $current_month_sys;

                            // 2. Limit Setup
                            $limit_year = ($start_year > 0) ? $start_year : $current_year_sys - 2;
                            $limit_month = ($start_year > 0) ? $start_month : 1;

                            // 3. Loop Backwards
                            while (true) {
                                if ($loop_curr_year < $limit_year) break;
                                if ($loop_curr_year == $limit_year && $loop_curr_month < $limit_month) break;

                                $option_value = sprintf('%04d-%02d', $loop_curr_year, $loop_curr_month);
                                $option_label = date('F Y', mktime(0, 0, 0, $loop_curr_month, 10, $loop_curr_year));
                                
                                $is_selected = ($selected_year == $loop_curr_year && $selected_month == $loop_curr_month) ? 'selected' : '';
                                ?>
                                
                                <option value="<?php echo $option_value; ?>" <?php echo $is_selected; ?>>
                                    <?php echo $option_label; ?>
                                </option>

                                <?php
                                $loop_curr_month--;
                                if ($loop_curr_month == 0) {
                                    $loop_curr_month = 12;
                                    $loop_curr_year--;
                                }
                            }
                            ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="px-3 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200" title="Filter">
                        <i class="fas fa-filter mr-1"></i> 
                    </button>
                    <a href="own_vehicle_payments_done.php" class="px-3 py-2 bg-teal-600 text-white font-semibold rounded-lg shadow-md hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-200 text-center">
                    <i class="fas fa-check-circle mr-1"></i>
                    </a>
                    <a href="own_vehicle_payments_history.php" class="px-3 py-2 bg-yellow-600 text-white font-semibold rounded-lg shadow-md hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-200 text-center" title="History"> 
                        <i class="fas fa-history mr-1"></i>
                    </a> 
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <?php 
                        $header_alignments = [
                            "Employee (Vehicle No)" => "text-left",
                            "Attendance Days"       => "text-right",
                            "Total Distance (km)"   => "text-right",
                            "Fixed Allowance (LKR)" => "text-right", 
                            "Total Payment (LKR)"   => "text-right",
                            "PDF"                   => "text-center"
                        ];
                        foreach ($header_alignments as $header => $class): ?>
                            <th class="py-3 px-6 border-b border-blue-500 <?php echo $class; ?>">
                                <?php echo htmlspecialchars($header); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($payment_data)): ?>
                        <?php foreach ($payment_data as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                <?php 
                                $display_keys = ['display_name', 'attendance_days', 'total_distance', 'fixed_amount', 'payments'];

                                foreach ($display_keys as $key): 
                                    $value = $data[$key];
                                    $cell_class = "py-3 px-6 whitespace-nowrap";
                                    $formatted_value = htmlspecialchars($value);

                                    if ($key === 'payments') {
                                        $formatted_value = number_format($value, 2);
                                        $cell_class .= " text-right text-blue-700 text-base font-extrabold";
                                    } elseif ($key === 'total_distance') {
                                        $formatted_value = number_format($value, 2);
                                        $cell_class .= " text-right text-purple-600";
                                    } elseif ($key === 'fixed_amount') {
                                        $formatted_value = number_format($value, 2);
                                        $cell_class .= " text-right font-semibold text-gray-700";
                                    } elseif ($key === 'attendance_days') {
                                        $formatted_value = number_format($value, 0);
                                        $cell_class .= " text-right font-semibold";
                                    } else {
                                        $cell_class .= " font-medium text-left";
                                    }
                                ?>
                                    <td class="<?php echo $cell_class; ?>">
                                        <?php echo $formatted_value; ?>
                                    </td>
                                <?php endforeach; ?>
                                
                                <td class="py-3 px-6 whitespace-nowrap text-center"> 
                                    <a href="download_own_vehicle_payments_pdf.php?emp_id=<?php echo htmlspecialchars($data['emp_id']); ?>&vehicle_no=<?php echo htmlspecialchars($data['vehicle_no']); ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>"
                                       class="text-red-500 hover:text-red-700 transition duration-150"
                                       title="Download Detailed PDF" target="_blank">
                                        <i class="fas fa-file-pdf fa-lg"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="py-12 text-center text-gray-500 text-base font-medium">No Own Vehicle payment data available for <?php echo date('F', mktime(0, 0, 0, $selected_month, 10)) . ", " . $selected_year; ?>.</td>
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