<?php
include('../../includes/db.php'); // Your database connection file
include('../../includes/header.php');
include('../../includes/navbar.php');

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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Inspection Checklist</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%] mb-6">
        <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg ">
            <div class="text-lg font-semibold ml-3">Inspection</div>
            <div class="flex gap-4">
                <a href="" class="text-yellow-600">Add inspection</a>
                <a href="edit_inspection.php" class="hover:text-yellow-600">Edit Inspection</a>
                <a href="view_supplier.php" class="hover:text-yellow-600">View Supplier</a>
            </div>
        </div>

        <div class="container mx-auto p-6 bg-white shadow-lg rounded-lg mt-6 max-w-4xl">
            <h2 class="text-3xl font-bold text-center mb-6 text-gray-800">Vehicle Inspection Checklist</h2>
            <form action="process_inspection.php" method="post">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                    <div class="form-group">
                        <label for="supplier_code" class="block text-gray-700 font-semibold mb-1">Supplier</label>
                        <select id="supplier_code" name="supplier_code" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>"><?php echo htmlspecialchars($supplier['supplier']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="vehicle_no" class="block text-gray-700 font-semibold mb-1">Vehicle No.</label>
                        <select id="vehicle_no" name="vehicle_no" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <option value="">Select Vehicle No.</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>" data-type="<?php echo htmlspecialchars($vehicle['type']); ?>"><?php echo htmlspecialchars($vehicle['vehicle_no']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="route_name" class="block text-gray-700 font-semibold mb-1">Route Name</label>
                        <select id="route_name" name="route_name" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <option value="">Select Route</option>
                            <?php foreach ($routes as $route): ?>
                                <option value="<?php echo htmlspecialchars($route); ?>"><?php echo htmlspecialchars($route); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="transport_type" class="block text-gray-700 font-semibold mb-1">Transport services type</label>
                        <select id="transport_type" name="transport_type" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <option value="">Select...</option>
                            <option value="Staff Transport">Staff Transport</option>
                            <option value="Factory Transport">Factory Transport</option>
                            <option value="Service provider">Service provider</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="inspector_name" class="block text-gray-700 font-semibold mb-1">Name of Inspector</label>
                        <input type="text" id="inspector_name" placeholder="Enter Inspector Name" name="inspector_name" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    </div>
                    <div class="form-group">
                        <label for="inspection_date" class="block text-gray-700 font-semibold mb-1">Inspection Date</label>
                        <input type="date" id="inspection_date" name="inspection_date" value="<?php echo date('Y-m-d'); ?>" class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    </div>
                </div>

                <h3 class="text-xl font-bold mb-3 text-gray-700">Inspection Criteria</h3>
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
                            $criteria = [
                                'Revenue License', 'Driver License', 'Insurance', 'Driver Data sheet', 'Driver NIC',
                                'Break', 'Tires', 'Spare Wheel', 'Lights (Head Lights/Signal Lights, Break Lights)',
                                'Revers lights/ tones', 'Horns', 'Windows and shutters', 'Door locks', 'No oil leaks',
                                'No high smoke (Black smoke)', 'Seat condition', 'Seat Gap', 'Body condition',
                                'Roof leek', 'Air Conditions', 'Noise'
                            ];

                            foreach ($criteria as $item) {
                                $name = str_replace([' ', '/', '(', ')', '-'], '_', strtolower($item));
                                echo "<tr>";
                                echo "<td class='px-6 py-2 whitespace-nowrap text-sm font-medium text-gray-900'>{$item}</td>";
                                echo "<td class='px-6 py-2 whitespace-nowrap text-center'>";
                                echo "<input type='checkbox' name='{$name}_status' class='form-checkbox h-5 w-5 text-blue-600 rounded-md focus:ring-blue-500'>";
                                echo "</td>";
                                echo "<td class='px-6 py-2 whitespace-nowrap'>";
                                echo "<input type='text' name='{$name}_remark' placeholder='Remark' class='w-full p-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500'>";
                                echo "</td>";
                                echo "</tr>";
                            }
                            ?>
                            <tr id="fitness_certificate_row" class="hidden">
                                <td class="px-6 py-2 whitespace-nowrap text-sm font-medium text-gray-900">Vehicle Fitness Certificate</td>
                                <td class="px-6 py-2 whitespace-nowrap text-center">
                                    <input type="checkbox" name="vehicle_fitness_certificate_status" class="form-checkbox h-5 w-5 text-blue-600 rounded-md focus:ring-blue-500">
                                </td>
                                <td class="px-6 py-2 whitespace-nowrap">
                                    <input type="text" name="vehicle_fitness_certificate_remark" placeholder="Remark" class="w-full p-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </td>
                            </tr>
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap text-sm font-medium text-gray-900">Any other observations</td>
                                <td colspan="2" class="px-6 py-3 whitespace-nowrap">
                                    <textarea name="other_observations" class="w-full p-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" rows="3"></textarea>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="button-container text-center mt-8">
                    <button type="submit" class="inline-flex items-center px-8 py-3 bg-blue-600 text-white font-bold rounded-md shadow-md hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500 focus:ring-opacity-50 transition ease-in-out duration-150">
                        Submit Inspection
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('vehicle_no').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const vehicleType = selectedOption.getAttribute('data-type');
            const fitnessRow = document.getElementById('fitness_certificate_row');

            if (vehicleType === 'bus') {
                fitnessRow.classList.remove('hidden');
            } else {
                fitnessRow.classList.add('hidden');
            }
        });
    </script>
</body>
</html>