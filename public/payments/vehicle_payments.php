<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Define a constant for the current fuel rate per liter
$sql_rate = "SELECT rate, date FROM fuel_rate ORDER BY date DESC LIMIT 1";
$result_rate = $conn->query($sql_rate);
$latest_fuel_rate = $result_rate && $result_rate->num_rows > 0 ? $result_rate->fetch_assoc() : null;

$payment_data = [];

// Get selected month and year, default to current month/year
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Get current month and year
$current_month = date('m');
$current_year = date('Y');

// 1. Fetch all routes and their details from the 'route' table
$routes_sql = "SELECT route_code, route, vehicle_no, monthly_fixed_rental, working_days, distance, extra_day_rate FROM route ORDER BY route ASC";
$routes_result = $conn->query($routes_sql);

if ($routes_result && $routes_result->num_rows > 0) {
    while ($route_row = $routes_result->fetch_assoc()) {
        $route_code = $route_row['route_code'];
        $route_name = $route_row['route'];
        $vehicle_no = $route_row['vehicle_no'];
        $monthly_fixed_rental = $route_row['monthly_fixed_rental'];
        $working_days = $route_row['working_days'];
        $daily_distance = $route_row['distance'];
        $extra_day_rate = $route_row['extra_day_rate'];

        $total_extra_distance = 0;
        $actual_days_worked = 0;
        $total_reduce = 0;
        $km_per_liter = 0;

        // 2. Calculate Total Extra Distance for the selected month/year
        $extra_dist_sql = "SELECT SUM(distance) AS total_distance FROM extra_distance WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $extra_dist_stmt = $conn->prepare($extra_dist_sql);
        $extra_dist_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $extra_dist_stmt->execute();
        $extra_dist_result = $extra_dist_stmt->get_result();

        if ($extra_dist_row = $extra_dist_result->fetch_assoc()) {
            $total_extra_distance = $extra_dist_row['total_distance'] ?? 0;
        }
        $extra_dist_stmt->close();
        
        // 3. Calculate Actual Days Worked and get vehicle's fuel efficiency for the selected month/year
        $register_sql = "SELECT vehicle_no, date FROM staff_transport_vehicle_register WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $register_stmt = $conn->prepare($register_sql);
        $register_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $register_stmt->execute();
        $register_result = $register_stmt->get_result();
        $actual_days_worked = $register_result->num_rows;

        if ($actual_days_worked > 0) {
            // Get the vehicle's fuel efficiency (km per liter)
            $first_entry = $register_result->fetch_assoc();
            $first_vehicle_no = $first_entry['vehicle_no'];

            $efficiency_sql = "SELECT c.distance FROM vehicle v JOIN consumption c ON v.condition_type = c.c_type WHERE v.vehicle_no = ?";
            $efficiency_stmt = $conn->prepare($efficiency_sql);
            $efficiency_stmt->bind_param("s", $first_vehicle_no);
            $efficiency_stmt->execute();
            $efficiency_result = $efficiency_stmt->get_result();

            if ($efficiency_row = $efficiency_result->fetch_assoc()) {
                $km_per_liter = $efficiency_row['distance'];
            }
            $efficiency_stmt->close();
        }
        $register_stmt->close();

        $ruduce_sql = "SELECT sum(amount) AS total_reduce FROM extra_vehicle_register WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
        $ruduce_stmt = $conn->prepare($ruduce_sql);
        $ruduce_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $ruduce_stmt->execute();
        $ruduce_result = $ruduce_stmt->get_result();
        if ($reduce_row = $ruduce_result->fetch_assoc()) {
            $total_reduce = $reduce_row['total_reduce'] ?? 0;
        }
        $ruduce_stmt->close();
        
        // 4. Perform the main calculations
        $total_distance = $daily_distance * $working_days;
        
        $fuel_amount = 0;
        if ($km_per_liter > 0) {
            $fuel_amount = (($total_distance + $total_extra_distance) / $km_per_liter) * $latest_fuel_rate['rate'];
        }

        $extra_days = max(0, $actual_days_worked - $working_days);
        $extra_days_amount = $extra_days * $extra_day_rate;
        $total_payments = 0;

        // Check if the selected month and year are in the future
        if ($selected_year > $current_year || ($selected_year == $current_year && $selected_month > $current_month)) {
            $total_payments = 0;
        } else {
            $total_payments = $monthly_fixed_rental + $fuel_amount + $extra_days_amount - $total_reduce;
        }

        // Store the data in an array
        $payment_data[] = [
            'vehicle_no' => $vehicle_no,
            'route' => $route_name,
            'monthly_rental' => $monthly_fixed_rental,
            'working_days' => $working_days,
            'total_distance' => $total_distance,
            'extra_distance' => $total_extra_distance,
            'fuel_amount' => $fuel_amount,
            'total_reduce' => $total_reduce,
            'extra_days' => $extra_days,
            'extra_days_amount' => $extra_days_amount,
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
                <a href="payments_category.php" class="hover:text-yellow-600">Route</a>
                <p class="hover:text-yellow-600 text-yellow-500 font-bold">Vehicle</p>
            </div>
        </div>
    
    <main class="w-[85%] ml-[15%] p-8 h-[95%]">
        <div class="flex justify-between items-center mb-2">
            <h2 class="text-3xl font-bold text-gray-800">Vehicle Payments Summary</h2>
            <div class="text-right text-gray-600">
                <p class="text-sm font-semibold">Fuel Rate: LKR <?php echo number_format($latest_fuel_rate['rate'], 2); ?> per liter</p>
                <p class="text-xs">As of <?php echo date('F j, Y', strtotime($latest_fuel_rate['date'])); ?></p>
            </div>
        </div>
        
        <div class="flex justify-end p-2 rounded-lg mb-2 w-full">
            <form method="get" action="" class="flex flex-col sm:flex-row items-center space-y-4 sm:space-y-0 sm:space-x-2">
                <!-- Download Button -->
            <a href="download_vehicle_payments.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
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
            </form>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-lg shadow-xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-semibold tracking-wider">
                        <th class="py-2 px-6 text-left rounded-tl-lg">Vehicle No</th>
                        <th class="py-2 px-6 text-left rounded-tl-lg">Route</th>
                        <th class="py-2 px-6 text-left">Monthly Rental</th>
                        <th class="py-2 px-6 text-left">Working Days</th>
                        <th class="py-2 px-6 text-left">Total Distance (km)</th>
                        <th class="py-2 px-6 text-left">Extra Distance (km)</th>
                        <th class="py-2 px-6 text-left">Fuel Amount (LKR)</th>
                        <th class="py-2 px-6 text-left">Reduce Amount (LKR)</th>
                        <th class="py-2 px-6 text-left">Extra Days Amount (LKR)</th>
                        <th class="py-2 px-6 text-left rounded-tr-lg">Total Payments (LKR)</th>
                        <th class="py-2 px-6 text-center rounded-tr-lg">Bill</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($payment_data)): ?>
                        <?php foreach ($payment_data as $data): ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-150 ease-in-out">
                                <td class="py-3 px-6 whitespace-nowrap font-medium text-gray-900"><?php echo htmlspecialchars($data['vehicle_no']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap font-medium text-gray-900"><?php echo htmlspecialchars($data['route']); ?></td>
                                <td class="py-3 px-6 text-green-600 font-semibold"><?php echo number_format($data['monthly_rental'], 2); ?></td>
                                <td class="py-3 px-6"><?php echo htmlspecialchars($data['working_days']); ?></td>
                                <td class="py-3 px-6"><?php echo number_format($data['total_distance']); ?></td>
                                <td class="py-3 px-6"><?php echo number_format($data['extra_distance']); ?></td>
                                <td class="py-3 px-6 text-green-600 font-semibold"><?php echo number_format($data['fuel_amount'], 2); ?></td>
                                <td class="py-3 px-6 text-red-600 font-semibold"><?php echo number_format($data['total_reduce'], 2); ?></td>
                                <td class="py-3 px-6 text-green-600 font-semibold"><?php echo number_format($data['extra_days_amount'], 2); ?></td>
                                <td class="py-3 px-6 font-bold text-lg text-blue-700"><?php echo number_format($data['payments'], 2); ?></td>
                                <td class="py-3 px-6 text-center">
                                    <a href="generate_payment_bill.php?vehicle_no=<?php echo urlencode($data['vehicle_no']); ?>&route_code=<?php echo urlencode($route_code); ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" class="text-blue-600 hover:text-blue-800 transition-colors duration-150 ease-in-out">
                                        <i class="fas fa-file-pdf"></i>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="py-6 text-center text-gray-500 text-base">No route payment data available for this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>