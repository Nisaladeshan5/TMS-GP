<?php
include('../../includes/db.php'); // Your database connection file
include('../../includes/header.php');
include('../../includes/navbar.php');

$inspection_data = null;
$search_vehicle_no = '';
$message = '';
$vehicle_type_of_data = '';

// Initialize arrays for dropdowns
$vehicles = [];
$routes = [];
$suppliers = [];

// Fetch Vehicle Numbers and their types from the 'vehicle' table
if (isset($conn)) {
    $sql_vehicles = "SELECT vehicle_no, type FROM vehicle";
    $result_vehicles = $conn->query($sql_vehicles);
    if ($result_vehicles && $result_vehicles->num_rows > 0) {
        while ($row = $result_vehicles->fetch_assoc()) {
            $vehicles[] = $row;
        }
    } else {
        error_log("No vehicles found or query failed: " . $conn->error);
    }

    // Fetch Route Names from the 'route' table
    $sql_routes = "SELECT route FROM route";
    $result_routes = $conn->query($sql_routes);
    if ($result_routes && $result_routes->num_rows > 0) {
        while ($row = $result_routes->fetch_assoc()) {
            $routes[] = $row['route'];
        }
    } else {
        error_log("No routes found or query failed: " . $conn->error);
    }

    // Fetch Supplier Codes and Names from the 'supplier' table
    $sql_suppliers = "SELECT supplier_code, supplier FROM supplier";
    $result_suppliers = $conn->query($sql_suppliers);
    if ($result_suppliers && $result_suppliers->num_rows > 0) {
        while ($row = $result_suppliers->fetch_assoc()) {
            $suppliers[] = $row;
        }
    } else {
        error_log("No suppliers found or query failed: " . $conn->error);
    }
} else {
    error_log("Database connection (\$conn) not established in db.php.");
}

// Define the mapping from display text to database column prefix
$criteria_mapping = [
    'Revenue License' => 'revenue_license',
    'Driver License' => 'driver_license',
    'Insurance' => 'insurance',
    'Driver Data sheet' => 'driver_data_sheet',
    'Driver NIC' => 'driver_nic',
    'Break' => 'break',
    'Tires' => 'tires',
    'Spare Wheel' => 'spare_wheel',
    'Lights (Head Lights/Signal Lights, Break Lights)' => 'lights',
    'Revers lights/ tones' => 'revers_lights',
    'Horns' => 'horns',
    'Windows and shutters' => 'windows',
    'Door locks' => 'door_locks',
    'No oil leaks' => 'no_oil_leaks',
    'No high smoke (Black smoke)' => 'no_high_smoke',
    'Seat condition' => 'seat_condition',
    'Seat Gap' => 'seat_gap',
    'Body condition' => 'body_condition',
    'Roof leek' => 'roof_leek',
    'Air Conditions' => 'air_conditions',
    'Noise' => 'noise'
];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_vehicle'])) {
    if (isset($_POST['search_vehicle_no']) && !empty($_POST['search_vehicle_no'])) {
        $search_vehicle_no = $conn->real_escape_string($_POST['search_vehicle_no']);

        // Fetch the latest inspection for the given vehicle number
        $sql = "SELECT * FROM checkUp WHERE vehicle_no = ? ORDER BY date DESC, id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $search_vehicle_no);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $inspection_data = $result->fetch_assoc();
                // Find the vehicle type for the fetched inspection data
                foreach ($vehicles as $vehicle) {
                    if ($vehicle['vehicle_no'] === $inspection_data['vehicle_no']) {
                        $vehicle_type_of_data = $vehicle['type'];
                        break;
                    }
                }
            } else {
                $message = "No inspection found for Vehicle No.: " . htmlspecialchars($search_vehicle_no);
            }
            $stmt->close();
        } else {
            $message = "Database query error: " . $conn->error;
            error_log($message);
        }
    } else {
        $message = "Please select a Vehicle No. to search.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Vehicle Inspection</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%] mb-6">
        <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg ">
            <div class="text-lg font-semibold ml-3">Inspection</div>
            <div class="flex gap-4">
                <a href="checkUp_category.php" class="hover:text-yellow-600">Add Inspection</a>
                <a href="" class="text-yellow-600">Edit inspection</a>
                <a href="view_supplier.php" class="hover:text-yellow-600">View Supplier</a>
                <a href="generate_report_checkup.php" class="hover:text-yellow-600">Report</a>
            </div>
        </div>

        <div class="container mx-auto p-6 bg-white shadow-lg rounded-lg mt-6 max-w-4xl">
            <h2 class="text-3xl font-bold text-center mb-6 text-gray-800">Edit Vehicle Inspection</h2>

            <!-- Search Form -->
            <form action="" method="post" class="mb-6 p-3 border border-gray-200 rounded-md bg-gray-50">
                <div class="flex items-center space-x-4">
                    <label for="search_vehicle_no" class="block text-gray-700 font-semibold">Select Vehicle No.:</label>
                    <select id="search_vehicle_no" name="search_vehicle_no" class="flex-grow p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <option value="">Select Vehicle</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>" <?php echo ($search_vehicle_no == $vehicle['vehicle_no']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($vehicle['vehicle_no']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="search_vehicle"
                             class="px-5 py-2 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Search
                    </button>
                </div>
                <?php if (!empty($message)): ?>
                    <p class="mt-4 text-center text-red-600"><?php echo htmlspecialchars($message); ?></p>
                <?php endif; ?>
            </form>

            <?php if ($inspection_data): ?>
                <h3 class="text-2xl font-bold text-gray-800 mb-4 text-center">Editing Inspection for <?php echo htmlspecialchars($inspection_data['vehicle_no']); ?></h3>

                <form action="update_inspection.php" method="post">
                    <input type="hidden" name="inspection_id" value="<?php echo htmlspecialchars($inspection_data['id']); ?>">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                        <div class="form-group">
                            <label for="supplier_code" class="block text-gray-700 font-semibold mb-1">Supplier</label>
                            <select id="supplier_code" name="supplier_code" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>" <?php echo ($inspection_data['supplier_code'] == $supplier['supplier_code']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier['supplier']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="block text-gray-700 font-semibold mb-1" for="vehicle_no">Vehicle No.</label>
                            <select id="vehicle_no" name="vehicle_no" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                <option value="">Select Vehicle No.</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>" data-type="<?php echo htmlspecialchars($vehicle['type']); ?>" <?php echo ($inspection_data['vehicle_no'] == $vehicle['vehicle_no']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vehicle['vehicle_no']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="block text-gray-700 font-semibold mb-1" for="route_name">Route Name</label>
                            <select id="route_name" name="route_name" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                <option value="">Select Route</option>
                                <?php foreach ($routes as $route): ?>
                                    <option value="<?php echo htmlspecialchars($route); ?>" <?php echo ($inspection_data['route'] == $route) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($route); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="block text-gray-700 font-semibold mb-1" for="transport_type">Transport services type</label>
                            <select id="transport_type" name="transport_type" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                <option value="">Select...</option>
                                <option value="Staff Transport" <?php echo ($inspection_data['transport_type'] == 'Staff Transport') ? 'selected' : ''; ?>>Staff Transport</option>
                                <option value="Factory Transport" <?php echo ($inspection_data['transport_type'] == 'Factory Transport') ? 'selected' : ''; ?>>Factory Transport</option>
                                <option value="Service provider" <?php echo ($inspection_data['transport_type'] == 'Service provider') ? 'selected' : ''; ?>>Service provider</option>
                                <option value="Other" <?php echo ($inspection_data['transport_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="block text-gray-700 font-semibold mb-1" for="inspector_name">Name of Inspector</label>
                            <input type="text" id="inspector_name" name="inspector_name" value="<?php echo htmlspecialchars($inspection_data['inspector']); ?>" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        </div>
                        <div class="form-group">
                            <label class="block text-gray-700 font-semibold mb-1" for="inspection_date">Inspection Date</label>
                            <input type="date" id="inspection_date" name="inspection_date" value="<?php echo htmlspecialchars($inspection_data['date']); ?>" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        </div>
                    </div>

                    <h3 class="text-xl font-bold mb-4 text-gray-700">Inspection Criteria</h3>
                    <div class="overflow-x-auto rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Inspection Criteria</th>
                                    <th class="px-6 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status (✓)</th>
                                    <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remark</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php
                                foreach ($criteria_mapping as $item_display_name => $db_column_prefix) {
                                    $status_column = $db_column_prefix . '_status';
                                    $remark_column = $db_column_prefix . '_remark';

                                    $is_checked = isset($inspection_data[$status_column]) && $inspection_data[$status_column] == 1;
                                    $remark_value = isset($inspection_data[$remark_column]) ? htmlspecialchars($inspection_data[$remark_column]) : '';

                                    echo "<tr>";
                                    echo "<td class='px-6 py-2 whitespace-nowrap text-sm font-medium text-gray-900'>{$item_display_name}</td>";
                                    echo "<td class='px-6 py-2 whitespace-nowrap text-center'>";
                                    echo "<input type='checkbox' name='{$db_column_prefix}_status' class='form-checkbox h-5 w-5 text-blue-600 rounded-md focus:ring-blue-500'" . ($is_checked ? ' checked' : '') . ">";
                                    echo "</td>";
                                    echo "<td class='px-6 py-2 whitespace-nowrap'>";
                                    echo "<input type='text' name='{$db_column_prefix}_remark' placeholder='Remark' value='{$remark_value}' class='w-full p-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500'>";
                                    echo "</td>";
                                    echo "</tr>";
                                }
                                ?>
                                <tr id="fitness_certificate_row" class="<?php echo (strtolower($vehicle_type_of_data) === 'bus') ? '' : 'hidden'; ?>">
                                    <td class="px-6 py-2 whitespace-nowrap text-sm font-medium text-gray-900">Vehicle Fitness Certificate</td>
                                    <td class="px-6 py-2 whitespace-nowrap text-center">
                                        <input type="checkbox" name="vehicle_fitness_certificate_status" class="form-checkbox h-5 w-5 text-blue-600 rounded-md focus:ring-blue-500" <?php echo (isset($inspection_data['vehicle_fitness_certificate_status']) && $inspection_data['vehicle_fitness_certificate_status'] == 1) ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="px-6 py-2 whitespace-nowrap">
                                        <input type="text" name="vehicle_fitness_certificate_remark" placeholder="Remark" value="<?php echo isset($inspection_data['vehicle_fitness_certificate_remark']) ? htmlspecialchars($inspection_data['vehicle_fitness_certificate_remark']) : ''; ?>" class="w-full p-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm font-medium text-gray-900">Any other observations</td>
                                    <td colspan="2" class="px-6 py-3 whitespace-nowrap text-sm">
                                        <textarea name="other_observations" class="w-full p-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" rows="3"><?php echo htmlspecialchars($inspection_data['other_observations']); ?></textarea>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="text-center mt-8">
                        <button type="submit" class="inline-flex items-center px-6 py-3 bg-green-600 text-white font-bold rounded-md shadow-md hover:bg-green-700 focus:outline-none focus:ring-4 focus:ring-green-500 focus:ring-opacity-50 transition ease-in-out duration-150">
                            Save Changes
                        </button>
                    </div>
                </form>

            <?php elseif (!empty($search_vehicle_no) && empty($message)): ?>
                <p class="text-center text-lg text-gray-600 mt-8">No inspection found for Vehicle No. "<?php echo htmlspecialchars($search_vehicle_no); ?>".</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('vehicle_no').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const vehicleType = selectedOption.getAttribute('data-type');
            const fitnessRow = document.getElementById('fitness_certificate_row');

            if (vehicleType && vehicleType.toLowerCase() === 'bus') {
                fitnessRow.classList.remove('hidden');
            } else {
                fitnessRow.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
