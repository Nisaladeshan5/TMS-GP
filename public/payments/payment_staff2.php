<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Note: This script assumes the included files exist and the database connection is valid.
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Get selected month and year, default to current month/year
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$payment_type = '2'; // Hardcode payment type to '2' (Daily Payment)

$payment_data = [];
$table_headers = [];
$page_title = "";

// --- DAILY PAYMENT LOGIC ---
$page_title = "Staff Monthly Payments Summary";
$table_headers = [
    "Route", "Price per 1km (LKR)", "Total Trip Count", "Total Distance (km)", "Extra Distance (km)", "Other Amount (LKR)", "Total Payments (LKR)", "PDF"
];

// Fetch all routes with the daily payment type
$routes_sql = "SELECT route_code, route, fixed_amount, distance FROM route WHERE payment_type = '2' AND purpose = 'staff' ORDER BY route ASC";
$routes_result = $conn->query($routes_sql);

if ($routes_result && $routes_result->num_rows > 0) {
    while ($route_row = $routes_result->fetch_assoc()) {
        $route_code = $route_row['route_code'];
        $route_name = $route_row['route'];
        $price_per_1km = $route_row['fixed_amount'];
        $route_distance = $route_row['distance']; // Fetch the fixed distance for the route

        // 1. Calculate the number of working days
        $days_sql = "SELECT COUNT(*) AS total_days FROM staff_transport_vehicle_register WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $days_stmt = $conn->prepare($days_sql);
        $days_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $days_stmt->execute();
        $days_result = $days_stmt->get_result();
        $days_row = $days_result->fetch_assoc();
        $total_working_days = $days_row['total_days'] ?? 0;
        $days_stmt->close();
        
        // Calculate Total Distance based on working days * fixed route distance
        $total_distance = $total_working_days * $route_distance/2;

        // 2. Calculate Total Extra Distance from extra_distance
        $extra_dist_sql = "SELECT SUM(distance) AS total_extra_distance FROM extra_distance WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $extra_dist_stmt = $conn->prepare($extra_dist_sql);
        $extra_dist_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $extra_dist_stmt->execute();
        $extra_dist_result = $extra_dist_stmt->get_result();
        $extra_dist_row = $extra_dist_result->fetch_assoc();
        $total_extra_distance = $extra_dist_row['total_extra_distance'] ?? 0;
        $extra_dist_stmt->close();

        // 3. Calculate Other Amounts (Trip, Extra Vehicle, Petty Cash)
        $trip_amount = 0;
        $extra_vehicle_amount = 0;
        $total_extra_absent_count = 0;
        $petty_cash_amount = 0;

        $trip_sql = "SELECT SUM(amount) AS total_trip_amount FROM trip WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $trip_stmt = $conn->prepare($trip_sql);
        $trip_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $trip_stmt->execute();
        $trip_result = $trip_stmt->get_result();
        if ($trip_row = $trip_result->fetch_assoc()) {
            $trip_amount = $trip_row['total_trip_amount'] ?? 0;
        }
        $trip_stmt->close();

        $other_amount = $trip_amount;

        // 4. Calculate Total Payments
        $total_payments = 0;
        $current_month = date('m');
        $current_year = date('Y');
        
        if ($selected_year > $current_year || ($selected_year == $current_year && $selected_month > $current_month)) {
             $total_payments = 0;
        } else {
             $total_payments = ($total_distance + $total_extra_distance) * $price_per_1km + $other_amount;
        }
        
        $payment_data[] = [
            // Added route_code to the data array to fix the PDF link bug
            'route_code' => $route_code, 
            'route' => $route_name,
            'price_per_1km' => $price_per_1km,
            'total_working_days' => $total_working_days,
            'total_distance' => $total_distance,
            'extra_distance' => $total_extra_distance,
            'other_amount' => $other_amount,
            'payments' => $total_payments
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-50 text-gray-800 h-screen">
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Staff</p>
            <a href="" class="hover:text-yellow-600">Workers</a>
            <a href="" class="hover:text-yellow-600">Day Heldup</a>
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
            <a href="night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-4 h-[95%]">
        <div class="flex justify-between items-center mb-2">
            <h2 class="text-3xl font-bold text-gray-800"><?php echo htmlspecialchars($page_title); ?></h2>
            <div class="flex justify-end p-2 rounded-lg mb-2">
                <form method="get" action="payment_staff2.php" class="flex flex-col sm:flex-row items-center space-y-4 sm:space-y-0 sm:space-x-2">
                    <div class="flex space-x-2">
                        <a href="payments_category.php"
                           class="px-3 py-2 rounded-md font-semibold text-white bg-gray-400 hover:bg-gray-500">
                            Method 1
                        </a>
                        <p href=""
                           class="px-3 py-2 rounded-md font-semibold text-white bg-blue-600 hover:bg-blue-700">
                            Method 2
                        </p>
                    </div>
                    
                    <a href="download_route_payments.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&payment_type=<?php echo htmlspecialchars($payment_type); ?>" 
                        class="w-full sm:w-auto px-3 py-2 bg-green-600 text-white font-semibold rounded-md shadow-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200 ease-in-out text-center">
                        <i class="fas fa-download"></i>
                    </a>
                    
                    <div class="relative border border-gray-400 rounded-lg">
                        <select name="month" id="month" class="w-full pl-3 pr-10 py-2 text-base border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 ease-in-out appearance-none">
                            <?php for ($m=1; $m<=12; $m++): ?>
                                <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($selected_month == sprintf('%02d', $m)) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <div class="relative border border-gray-400 rounded-lg">
                        <select name="year" id="year" class="w-full pl-3 pr-10 py-2 text-base border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 ease-in-out appearance-none">
                            <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 bg-blue-600 text-white font-semibold rounded-md shadow-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 ease-in-out">
                        <i class="fas fa-filter mr-2"></i> Filter
                    </button>
                    <input type="hidden" name="payment_type" value="<?php echo htmlspecialchars($payment_type); ?>">
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-lg shadow-xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-semibold tracking-wider">
                        <?php foreach ($table_headers as $header): ?>
                            <th class="py-2 px-6 text-left"><?php echo htmlspecialchars($header); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($payment_data)): ?>
                        <?php foreach ($payment_data as $data): ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-150 ease-in-out">
                                <?php 
                                // Dynamic table cell rendering based on the keys in the data array
                                foreach ($data as $key => $value): 
                                    if ($key === 'route_code') {
                                        continue; // Skip printing the route_code column
                                    }
                                    $cell_class = "py-3 px-6 whitespace-nowrap";
                                    $formatted_value = htmlspecialchars($value);

                                    // Apply specific formatting for monetary values and distances
                                    if (in_array($key, ['price_per_1km', 'other_amount', 'payments'])) {
                                        $formatted_value = number_format($value, 2);
                                        $cell_class .= " font-semibold";
                                        if ($key === 'payments') {
                                            $cell_class .= " text-blue-700 text-lg font-bold";
                                        } elseif ($key === 'other_amount') {
                                            $cell_class .= $value >= 0 ? " text-green-600" : " text-red-600";
                                        } else {
                                            $cell_class .= " text-green-600";
                                        }
                                    } elseif (in_array($key, ['total_distance', 'extra_distance'])) {
                                        $formatted_value = number_format($value);
                                    }
                                ?>
                                    <td class="<?php echo $cell_class; ?>">
                                        <?php echo $formatted_value; ?>
                                    </td>
                                <?php endforeach; ?>
                                <!-- New cell for the PDF download button -->
                                <td class="py-3 px-6 whitespace-nowrap">
                                    <a href="download_staff2_pdf.php?route_code=<?php echo htmlspecialchars($data['route_code']); ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>"
                                       class="text-red-500 hover:text-red-700"
                                       title="Download Detailed PDF">
                                        <i class="fas fa-file-pdf fa-lg"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo count($table_headers); ?>" class="py-6 text-center text-gray-500 text-base">No staff route payment data available for this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>

<?php
// Close the database connection
$conn->close();
?>
