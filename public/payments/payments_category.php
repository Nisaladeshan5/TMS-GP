<?php
// Note: This script assumes the included files exist and the database connection is valid.
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Get selected month and year, default to current month/year
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

$payment_data = [];
$table_headers = [];
$page_title = "";

// --- STAFF PAYMENT LOGIC (Day Rate and DB Authority Based) ---
$page_title = "Staff Monthly Payments Summary ";
$table_headers = [
    "Route (Vehicle)", // MODIFIED: To show Route and Vehicle Code
    "Trip Rate (LKR)",
    "Total Trip Count",
    "Total Distance (km)",
    "Reductions (LKR)",
    "Total Payments (LKR)",
    "PDF"
];

// Fetch all routes with the staff payment type
$routes_sql = "SELECT route_code, route, fixed_amount, fuel_amount, distance FROM route WHERE purpose = 'staff' ORDER BY route ASC";
$routes_result = $conn->query($routes_sql);

if ($routes_result && $routes_result->num_rows > 0) {
    while ($route_row = $routes_result->fetch_assoc()) {
        $route_code = $route_row['route_code'];
        $route_name = $route_row['route'];
        $rate_per_km = $route_row['fixed_amount'] + $route_row['fuel_amount'];
        $route_distance = $route_row['distance']; 

        // 1. Find all distinct vehicles that operated on this route this month/year, AND fetch their supplier_code
        $vehicles_sql = "
            SELECT DISTINCT stvr.vehicle_no, v.supplier_code 
            FROM staff_transport_vehicle_register stvr
            JOIN vehicle v ON stvr.vehicle_no = v.vehicle_no /* <-- JOIN added to get supplier code */
            WHERE stvr.route = ? 
              AND MONTH(stvr.date) = ? 
              AND YEAR(stvr.date) = ?
        ";
        $vehicles_stmt = $conn->prepare($vehicles_sql);
        $vehicles_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
        $vehicles_stmt->execute();
        $vehicles_result = $vehicles_stmt->get_result();

        // If no vehicle activity is found, we still want to show the route with 0s (Optional, but better for completeness)
        // If a vehicle is found, loop through each one separately
        if ($vehicles_result->num_rows > 0) {
            
            while ($vehicle_row = $vehicles_result->fetch_assoc()) {
                $vehicle_no = $vehicle_row['vehicle_no'];
                $supplier_code = $vehicle_row['supplier_code']; // <-- Supplier code fetched
                
                // Initialized variables (now per-vehicle)
                $total_working_days = 0; 
                $total_distance = 0; 
                $monthly_payment_from_db = 0; // The authoritative total payment
                $calculated_base_payment = 0; 
                $other_amount = 0;
                $total_payments = 0;

                // 2. Calculate the number of working days (Total Trip Count) FOR THIS SPECIFIC VEHICLE
                // This correctly uses the vehicle_no from the register table.
                $days_sql = "
                    SELECT COUNT(*) AS total_days 
                    FROM staff_transport_vehicle_register 
                    WHERE route = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ?
                ";
                $days_stmt = $conn->prepare($days_sql);
                $days_stmt->bind_param("ssii", $route_code, $vehicle_no, $selected_month, $selected_year);
                $days_stmt->execute();
                $days_result = $days_stmt->get_result();
                $days_row = $days_result->fetch_assoc();
                $total_working_days = $days_row['total_days'] ?? 0;
                $days_stmt->close();
                
                // --- Day Rate Calculation ---
                // Since this report is at the vehicle level, we use the trip count and rate.
                $day_rate = ($rate_per_km * $route_distance) / 2;

                // Calculated Base Payment = Trip Rate * Total Trip Count
                $calculated_base_payment = $day_rate * $total_working_days;

                // 3. Fetch DB Total Payment (monthly_payment) and Total Distance 
                // *** MODIFIED to use supplier_code as requested ***
                $distance_sql = "
                    SELECT total_distance, monthly_payment 
                    FROM monthly_payments_sf 
                    WHERE route_code = ? AND supplier_code = ? AND month = ? AND year = ?
                ";
                $distance_stmt = $conn->prepare($distance_sql);
                // NOTE: Binding to supplier_code instead of vehicle_no
                $distance_stmt->bind_param("ssii", $route_code, $supplier_code, $selected_month, $selected_year); 
                $distance_stmt->execute();
                $distance_result = $distance_stmt->get_result();
                
                if ($distance_row = $distance_result->fetch_assoc()) {
                    // NOTE: If the monthly_payments_sf aggregates distance by supplier, 
                    // this distance figure will be the *TOTAL* distance for the route
                    // covered by *all* vehicles belonging to this supplier.
                    $total_distance = $distance_row['total_distance'] ?? 0;
                    // AUTHORITATIVE SOURCE FOR TOTAL PAYMENT
                    $monthly_payment_from_db = $distance_row['monthly_payment'] ?? 0; 
                } else {
                    // Fallback distance calculation using vehicle activity
                    $total_distance = ($route_distance / 2) * $total_working_days; 
                }
                $distance_stmt->close();
                
                
                // 4. Calculate Total Payments (uses DB authority value) and Other Amount
                $current_month = date('m');
                $current_year = date('Y');

                if ($selected_year > $current_year || ($selected_year == $current_year && $selected_month > $current_month)) {
                    // Future month, set payments to 0
                    $total_payments = 0;
                    $other_amount = 0;
                } else {
                    // Set Total Payment to the authoritative DB value
                    $total_payments = $monthly_payment_from_db;
                    
                    // Other Amount = Total Payments (DB) - Base Payment (Calculated)
                    $other_amount = $total_payments - $calculated_base_payment;
                }
                
                $payment_data[] = [
                    'route_code' => $route_code, 
                    'vehicle_no' => $vehicle_no, // Keep vehicle code for PDF drill-down
                    'supplier_code' => $supplier_code, // Store supplier code for context
                    'route' => $route_name . " (" . $vehicle_no . ")", // Display route + vehicle
                    'price_per_1km' => $day_rate, 
                    'total_working_days' => $total_working_days,
                    'total_distance' => $total_distance, // IMPORTANT: This is the distance *per supplier* or *calculated* now.
                    'other_amount' => $other_amount, // The difference/adjustment
                    'payments' => $total_payments // The definitive total from DB
                ];
            }
            $vehicles_stmt->close();
        } 
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
    <style>
        /* Custom scrollbar for better visibility */
        .overflow-x-auto::-webkit-scrollbar {
            height: 8px;
        }
        .overflow-x-auto::-webkit-scrollbar-thumb {
            background-color: #a0aec0; /* gray-400 */
            border-radius: 4px;
        }
        .overflow-x-auto::-webkit-scrollbar-track {
            background-color: #edf2f7; /* gray-100 */
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Staff</p>
            <a href="" class="hover:text-yellow-600">Factory</a>
            <a href="" class="hover:text-yellow-600">Day Heldup</a>
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
            <a href="night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="own_vehicle_payments.php" class="hover:text-yellow-600">Own Vehicle</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-4 mt-[1%]">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 mt-4">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0"><?php echo htmlspecialchars($page_title); ?></h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="payments_category.php" class="flex flex-wrap gap-2 items-center">
                    
                    <a href="download_route_payments.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
                        class="px-3 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 text-center">
                        <i class="fas fa-download"></i>
                    </a>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="month" id="month" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php for ($m=1; $m<=12; $m++): ?>
                                <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($selected_month == sprintf('%02d', $m)) ? 'selected' : ''; ?>>
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
                            <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200">
                        <i class="fas fa-filter mr-1"></i> Filter
                    </button>
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
                                // Define the order of keys to ensure table alignment
                                $display_keys = ['route', 'price_per_1km', 'total_working_days', 'total_distance', 'other_amount', 'payments'];

                                foreach ($display_keys as $key): 
                                    $value = $data[$key];
                                    $cell_class = "py-3 px-6 whitespace-nowrap";
                                    $formatted_value = htmlspecialchars($value);

                                    // Apply specific formatting
                                    if (in_array($key, ['price_per_1km', 'other_amount', 'payments'])) {
                                        $formatted_value = number_format($value, 2);
                                        $cell_class .= " font-semibold text-left"; 
                                        
                                        if ($key === 'payments') {
                                            // Final Total Payment (from DB)
                                            $cell_class .= " text-blue-700 text-base font-extrabold";
                                        } elseif ($key === 'other_amount') {
                                            // The adjustment/difference
                                            $cell_class .= $value >= 0 ? " text-green-600" : " text-red-600";
                                        } elseif ($key === 'price_per_1km') {
                                            // Day Rate
                                            $cell_class .= " text-purple-600";
                                        }
                                    } elseif (in_array($key, ['total_distance', 'total_working_days'])) {
                                        $formatted_value = number_format($value, $key === 'total_working_days' ? 0 : 2);
                                        $cell_class .= " text-left";
                                    } else {
                                        // Default for 'route'
                                        $cell_class .= " font-medium";
                                    }
                                ?>
                                    <td class="<?php echo $cell_class; ?>">
                                        <?php echo $formatted_value; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="py-3 px-6 whitespace-nowrap text-center">
                                    <a href="download_staff2_pdf.php?route_code=<?php echo htmlspecialchars($data['route_code']); ?>&vehicle_no=<?php echo htmlspecialchars($data['vehicle_no']); ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>"
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
// Close the database connection
$conn->close();
?>