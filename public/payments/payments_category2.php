<?php
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Get selected month, year, default to current month/year
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$payment_type = '1';

$payment_data = [];
$table_headers = [];
$page_title = "";

// --- MONTHLY PAYMENT LOGIC ---
$page_title = "Staff Monthly Payments Summary";
$table_headers = [
    "Route", "Monthly Rental", "Working Days", "Total Distance (km)",
    "Extra Distance (km)", "Fuel Amount (LKR)", "Extra Days",
    "Extra Days Amount (LKR)", "Other Amount (LKR)", "Total Payments (LKR)",
    "PDF" // New header for the PDF download button
];

// 1. Fetch all routes with the monthly payment type
$routes_sql = "SELECT route_code, route, monthly_fixed_rental, working_days, distance FROM route WHERE payment_type = '1' AND purpose = 'staff' ORDER BY route ASC";
$routes_result = $conn->query($routes_sql);

if ($routes_result && $routes_result->num_rows > 0) {
    while ($route_row = $routes_result->fetch_assoc()) {
        $route_code = $route_row['route_code'];
        $route_name = $route_row['route'];
        $monthly_fixed_rental = $route_row['monthly_fixed_rental'];
        $working_days_quota = $route_row['working_days'];
        $daily_distance = $route_row['distance'];

        $total_extra_distance = 0;
        $actual_days_worked = 0;
        $km_per_liter = 10;
        $price_per_liter = 0;

        // 2. Calculate Total Extra Distance
        $extra_dist_sql = "SELECT SUM(distance) AS total_distance FROM extra_distance WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $extra_dist_stmt = $conn->prepare($extra_dist_sql);
        $extra_dist_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $extra_dist_stmt->execute();
        $extra_dist_result = $extra_dist_stmt->get_result();
        if ($extra_dist_row = $extra_dist_result->fetch_assoc()) {
            $total_extra_distance = $extra_dist_row['total_distance'] ?? 0;
        }
        $extra_dist_stmt->close();

        // 3. Calculate Actual Days Worked and fetch rates
        $register_sql = "SELECT vehicle_no FROM staff_transport_vehicle_register WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $register_stmt = $conn->prepare($register_sql);
        $register_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $register_stmt->execute();
        $register_result = $register_stmt->get_result();
        $actual_days_worked = $register_result->num_rows;

        if ($actual_days_worked > 0) {
            $register_result->data_seek(0);
            $first_entry = $register_result->fetch_assoc();
            $first_vehicle_no = $first_entry['vehicle_no'];

            $vehicle_info_sql = "
                SELECT
                    c.distance,
                    fr.rate
                FROM
                    vehicle v
                JOIN
                    consumption c ON v.condition_type = c.c_type
                JOIN
                    fuel_rate fr ON v.rate_id = fr.rate_id
                WHERE
                    v.vehicle_no = ?
                ORDER BY
                    fr.date DESC
                LIMIT 1";
            $vehicle_info_stmt = $conn->prepare($vehicle_info_sql);
            $vehicle_info_stmt->bind_param("s", $first_vehicle_no);
            $vehicle_info_stmt->execute();
            $vehicle_info_result = $vehicle_info_stmt->get_result();

            if ($vehicle_info_row = $vehicle_info_result->fetch_assoc()) {
                $km_per_liter = $vehicle_info_row['distance'];
                $price_per_liter = $vehicle_info_row['rate'];
            }
            $vehicle_info_stmt->close();
        }
        $register_stmt->close();

        $trip_amount = 0;
        $extra_vehicle_amount = 0;
        $total_extra_absent_count = 0;
        $petty_cash_amount = 0;
        $petty_cash_absent_count = 0;

        // Fetch Other Amounts (Trip, Extra Vehicle, Petty Cash)
        $trip_sql = "SELECT SUM(amount) AS total_trip_amount FROM trip WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $trip_stmt = $conn->prepare($trip_sql);
        $trip_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $trip_stmt->execute();
        $trip_result = $trip_stmt->get_result();
        if ($trip_row = $trip_result->fetch_assoc()) {
            $trip_amount = $trip_row['total_trip_amount'] ?? 0;
        }
        $trip_stmt->close();

        $extra_vehicle_sql = "SELECT SUM(amount) AS total_extra_vehicle, SUM(absent_type) AS total_extra_absent_count FROM extra_vehicle_register WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ? AND status = 0";
        $extra_vehicle_stmt = $conn->prepare($extra_vehicle_sql);
        $extra_vehicle_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $extra_vehicle_stmt->execute();
        $extra_vehicle_result = $extra_vehicle_stmt->get_result();
        if ($extra_vehicle_row = $extra_vehicle_result->fetch_assoc()) {
            $extra_vehicle_amount = $extra_vehicle_row['total_extra_vehicle'] ?? 0;
            $total_extra_absent_count = $extra_vehicle_row['total_extra_absent_count'] ?? 0;
        }
        $extra_vehicle_stmt->close();

        $petty_cash_sql = "SELECT SUM(amount) AS total_petty_cash, SUM(absent_type) AS total_petty_cash_absent_count FROM petty_cash WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ? AND status = 0";
        $petty_cash_stmt = $conn->prepare($petty_cash_sql);
        $petty_cash_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $petty_cash_stmt->execute();
        $petty_cash_result = $petty_cash_stmt->get_result();
        if ($petty_cash_row = $petty_cash_result->fetch_assoc()) {
            $petty_cash_amount = $petty_cash_row['total_petty_cash'] ?? 0;
            $petty_cash_absent_count = $petty_cash_row['total_petty_cash_absent_count'] ?? 0;
        }
        $petty_cash_stmt->close();

        $other_amount = $trip_amount - $extra_vehicle_amount - $petty_cash_amount;

        // 4. Perform the main calculations
        $working_days_limit = ($working_days_quota ?? 0) * 2;
        $days_for_fuel = min($actual_days_worked + $total_extra_absent_count + $petty_cash_absent_count, $working_days_limit);
        $total_distance_for_fuel = (($daily_distance ?? 0) / 2) * $days_for_fuel;

        $fuel_amount = 0;
        if ($km_per_liter > 0 && $price_per_liter > 0) {
            $fuel_amount = (($total_distance_for_fuel + $total_extra_distance) / $km_per_liter) * $price_per_liter;
        }

        $extra_day_rate = 0;
        if ($km_per_liter > 0 && $price_per_liter > 0) {
            $extra_day_rate = (($monthly_fixed_rental ?? 0) / ($working_days_quota > 0 ? $working_days_quota : 1)) + ((($daily_distance ?? 0) / $km_per_liter) * $price_per_liter);
        }

        $extra_days_worked = max(0, $actual_days_worked - $working_days_limit);
        $extra_days = $extra_days_worked / 2;
        $extra_days_amount = $extra_days * $extra_day_rate;

        $total_payments = 0;
        $current_month = date('m');
        $current_year = date('Y');
        if ($selected_year > $current_year || ($selected_year == $current_year && $selected_month > $current_month)) {
            $total_payments = 0;
        } else {
            $total_payments = ($monthly_fixed_rental ?? 0) + $fuel_amount + $extra_days_amount + $other_amount;
        }

        // Store the data
        $payment_data[] = [
            'route_code' => $route_code, // Added to pass to the PDF script
            'route' => $route_name,
            'monthly_rental' => $monthly_fixed_rental,
            'working_days' => $working_days_quota,
            'total_distance' => $total_distance_for_fuel,
            'extra_distance' => $total_extra_distance,
            'fuel_amount' => $fuel_amount,
            'extra_days' => $extra_days,
            'extra_days_amount' => $extra_days_amount,
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
                <form method="get" action="payments_category.php" class="flex flex-col sm:flex-row items-center space-y-4 sm:space-y-0 sm:space-x-2">
                    <div class="flex space-x-2">
                        <p class="px-3 py-2 rounded-md font-semibold text-white bg-blue-600 hover:bg-blue-700">
                            Method 1
                        </p>
                        <a href="payment_staff2.php"
                           class="px-3 py-2 rounded-md font-semibold text-white bg-gray-400 hover:bg-gray-500">
                            Method 2
                        </a>
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
                                foreach ($data as $key => $value):
                                    if ($key === 'route_code') continue;

                                    $cell_class = "py-3 px-6 whitespace-nowrap";
                                    $formatted_value = htmlspecialchars($value ?? '');

                                    if (in_array($key, ['monthly_rental', 'fuel_amount', 'extra_days_amount', 'other_amount', 'payments'])) {
                                        $formatted_value = number_format($value ?? 0, 2);
                                        $cell_class .= " font-semibold";
                                        if ($key === 'payments') {
                                            $cell_class .= " text-blue-700 text-lg font-bold";
                                        } elseif ($key === 'other_amount') {
                                            $cell_class .= ($value ?? 0) >= 0 ? " text-green-600" : " text-red-600";
                                        } else {
                                            $cell_class .= " text-green-600";
                                        }
                                    } elseif (in_array($key, ['total_distance', 'extra_distance'])) {
                                        $formatted_value = number_format($value ?? 0);
                                    }
                                ?>
                                    <td class="<?php echo $cell_class; ?>">
                                        <?php echo $formatted_value; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="py-3 px-6 whitespace-nowrap">
                                    <a href="download_staff_pdf.php?route_code=<?php echo urlencode($data['route_code']); ?>&month=<?php echo urlencode($selected_month); ?>&year=<?php echo urlencode($selected_year); ?>"
                                       class="text-red-500 hover:text-red-700"
                                       title="Download Detailed PDF" target="_blank">
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
$conn->close();
?>