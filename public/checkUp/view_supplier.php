<?php
include('../../includes/db.php'); // Your database connection file
include('../../includes/header.php');
include('../../includes/navbar.php');

$vehicles_inspections_summary = []; // Will store latest inspection data for all vehicles of a supplier
$search_supplier_name = '';
$inspection_data = null; // To hold detailed inspection data if a vehicle card is clicked
$message = '';

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

// Fetch distinct supplier names for the dropdown
$suppliers = [];
if (isset($conn)) {
    // Assuming 'vehicle_inspections' is the table where supplier data is stored
    $sql_suppliers = "SELECT DISTINCT supplier_code FROM checkUp ORDER BY supplier_code ASC";
    $result_suppliers = $conn->query($sql_suppliers);
    if ($result_suppliers && $result_suppliers->num_rows > 0) {
        while ($row = $result_suppliers->fetch_assoc()) {
            $suppliers[] = $row['supplier_code'];
        }
    } else {
        error_log("No suppliers found or query failed: " . $conn->error);
    }
} else {
    error_log("Database connection (\$conn) not established in db.php.");
}

// Handle search by supplier
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_supplier'])) {
    if (isset($_POST['search_supplier_name']) && !empty($_POST['search_supplier_name'])) {
        $search_supplier_name = $conn->real_escape_string($_POST['search_supplier_name']);

        // Get all unique vehicles associated with this supplier from vehicle_inspections
        $sql_vehicle_nos = "SELECT DISTINCT vehicle_no FROM checkUp WHERE supplier_code = ?";
        $stmt_vehicles = $conn->prepare($sql_vehicle_nos);
        
        if ($stmt_vehicles) {
            $stmt_vehicles->bind_param("s", $search_supplier_name);
            $stmt_vehicles->execute();
            $result_vehicle_nos = $stmt_vehicles->get_result();
            $stmt_vehicles->close();

            if ($result_vehicle_nos->num_rows > 0) {
                while ($row_vehicle = $result_vehicle_nos->fetch_assoc()) {
                    $vehicle_no = $row_vehicle['vehicle_no'];
                    
                    // For each vehicle, fetch its latest inspection details, including the type from the vehicle table
                    $sql_latest_inspection = "SELECT c.*, v.type FROM checkUp c 
                                             INNER JOIN vehicle v ON c.vehicle_no = v.vehicle_no
                                             WHERE c.vehicle_no = ? AND c.supplier_code = ? 
                                             ORDER BY c.date DESC, c.id DESC LIMIT 1";
                    $stmt_inspection = $conn->prepare($sql_latest_inspection);
                    
                    if ($stmt_inspection) {
                        $stmt_inspection->bind_param("ss", $vehicle_no, $search_supplier_name);
                        $stmt_inspection->execute();
                        $result_inspection = $stmt_inspection->get_result();
                        
                        if ($result_inspection->num_rows > 0) {
                            $latest_inspection = $result_inspection->fetch_assoc();
                            
                            // Determine if there are any "failed" items for card display
                            $has_problems = false;
                            foreach ($criteria_mapping as $item_display_name => $db_column_prefix) {
                                $status_column = $db_column_prefix . '_status';
                                if (isset($latest_inspection[$status_column]) && $latest_inspection[$status_column] == 0) {
                                    $has_problems = true;
                                    break;
                                }
                            }
                            // Only count failed vehicle fitness as a problem if the vehicle type is 'Bus'
                            $vehicle_type = $latest_inspection['type'] ?? '';
                            if (strcasecmp($vehicle_type, 'bus') == 0 && isset($latest_inspection['vehicle_fitness_certificate_status']) && $latest_inspection['vehicle_fitness_certificate_status'] == 0) {
                                $has_problems = true;
                            }
                            $latest_inspection['has_problems'] = $has_problems;
                            $vehicles_inspections_summary[] = $latest_inspection;

                        }
                        $stmt_inspection->close();
                    } else {
                        $message = "Database query error for inspection details: " . $conn->error;
                        error_log($message);
                        break; // Stop processing if there's an error
                    }
                }
            } else {
                $message = "No vehicles found for Supplier: " . htmlspecialchars($search_supplier_name);
            }
        } else {
            $message = "Database query error for vehicle numbers: " . $conn->error;
            error_log($message);
        }
    } else {
        $message = "Please enter a Supplier Name to search.";
    }
}
// Handle direct vehicle search if specific vehicle details are requested (e.g., from a card click)
elseif (isset($_GET['view_vehicle_no']) && !empty($_GET['view_vehicle_no'])) {
    $search_vehicle_no_direct = $conn->real_escape_string($_GET['view_vehicle_no']);
    
    // Join with vehicle and supplier tables to get all necessary details
    $sql = "SELECT c.*, s.supplier, v.type FROM checkUp AS c 
            INNER JOIN supplier AS s ON c.supplier_code = s.supplier_code 
            INNER JOIN vehicle AS v ON c.vehicle_no = v.vehicle_no
            WHERE c.vehicle_no = ? ORDER BY c.date DESC, c.id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $search_vehicle_no_direct);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $inspection_data = $result->fetch_assoc(); // This will be used for the detailed view
            // Set the supplier name from the fetched data to pre-fill the search box if desired
            $search_supplier_name = $inspection_data['supplier_code']; 

            // Determine if there are any "failed" items for detailed view
            $has_problems_for_detail = false;
            foreach ($criteria_mapping as $item_display_name => $db_column_prefix) {
                $status_column = $db_column_prefix . '_status';
                if (isset($inspection_data[$status_column]) && $inspection_data[$status_column] == 0) {
                    $has_problems_for_detail = true;
                    break;
                }
            }
            // Only count failed vehicle fitness as a problem if the vehicle type is 'Bus'
            $vehicle_type = $inspection_data['type'] ?? '';
            if (strcasecmp($vehicle_type, 'bus') == 0 && isset($inspection_data['vehicle_fitness_certificate_status']) && $inspection_data['vehicle_fitness_certificate_status'] == 0) {
                $has_problems_for_detail = true;
            }
            $inspection_data['has_problems'] = $has_problems_for_detail; // Add this flag to inspection_data

        } else {
            $message = "No detailed inspection found for Vehicle No.: " . htmlspecialchars($search_vehicle_no_direct);
        }
        $stmt->close();
    } else {
        $message = "Database query error for detailed view: " . $conn->error;
        error_log($message);
    }
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Inspection Checklist</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%] mb-6">
        <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg ">
            <div class="text-lg font-semibold ml-3">Inspection</div>
            <div class="flex gap-4">
                <a href="checkUp_category.php" class="hover:text-yellow-600">Add Inspection</a>
                <a href="edit_inspection.php" class="hover:text-yellow-600">Edit Inspection</a>
                <a href="" class="text-yellow-600">View Supplier</a>
            </div>
        </div>

        <div class="container mx-auto p-6 bg-white shadow-lg rounded-lg mt-10 max-w-4xl">
            <h2 class="text-3xl font-bold text-center mb-4 text-gray-800">View Supplier Vehicles and Inspections</h2>

            <!-- Search by Supplier Form -->
            <form action="" method="post" class="mb-8 p-4 border border-gray-200 rounded-md bg-gray-50">
                <div class="flex flex-col md:flex-row items-center space-y-4 md:space-y-0 md:space-x-4">
                    <label for="search_supplier_name" class="block text-gray-700 font-semibold flex-shrink-0">Select Supplier:</label>
                    <select id="search_supplier_name" name="search_supplier_name" 
                            class="flex-grow p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo htmlspecialchars($supplier); ?>" 
                                    <?php echo ($supplier == $search_supplier_name) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="search_supplier" 
                            class="px-5 py-2 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Search Supplier
                    </button>
                </div>
                <?php if (!empty($message)): ?>
                    <p class="mt-4 text-center text-red-600"><?php echo htmlspecialchars($message); ?></p>
                <?php endif; ?>
            </form>

            <?php if (!empty($vehicles_inspections_summary)): ?>
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-800">Vehicles for <?php echo htmlspecialchars($search_supplier_name); ?></h3>
                    <a href="export_inspections.php?supplier_name=<?php echo urlencode($search_supplier_name); ?>" 
                       class="inline-flex items-center px-4 py-2 bg-green-600 text-white font-semibold rounded-md shadow-md hover:bg-green-700 focus:outline-none focus:ring-4 focus:ring-green-500 focus:ring-opacity-50 transition ease-in-out duration-150">
                        Download as Excel
                    </a>
                </div>
                <h3 class="text-2xl font-bold text-gray-800 mb-6 text-center">Vehicles for <?php echo htmlspecialchars($search_supplier_name); ?></h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($vehicles_inspections_summary as $vehicle_summary): ?>
                        <div class="bg-white rounded-lg shadow-md p-6 flex flex-col justify-between 
                                    <?php echo $vehicle_summary['has_problems'] ? 'border-l-4 border-red-500' : 'border-l-4 border-green-500'; ?>">
                            <div>
                                <h4 class="text-xl font-bold mb-2 text-gray-800"><?php echo htmlspecialchars($vehicle_summary['vehicle_no']); ?></h4>
                                <p class="text-gray-700 mb-1"><strong>Route:</strong> <?php echo htmlspecialchars($vehicle_summary['route']); ?></p>
                                <p class="text-gray-700 mb-4"><strong>Last Inspected:</strong> <?php echo htmlspecialchars($vehicle_summary['date']); ?></p>
                                
                                <p class="text-lg font-bold 
                                        <?php echo $vehicle_summary['has_problems'] ? 'text-red-600' : 'text-green-600'; ?>">
                                    <?php echo $vehicle_summary['has_problems'] ? 'Problems Detected ⚠️' : 'Vehicle is Fine ✅'; ?>
                                </p>
                            </div>
                            <div class="mt-6">
                                <a href="?view_vehicle_no=<?php echo htmlspecialchars($vehicle_summary['vehicle_no']); ?>&search_supplier_name=<?php echo htmlspecialchars($search_supplier_name); ?>" 
                                   class="inline-flex items-center px-4 py-2 bg-blue-500 text-white font-semibold rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    View Full Details
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($inspection_data) && $inspection_data): // Display detailed inspection if a specific vehicle was clicked/searched ?>
                <hr class="my-4 border-gray-300">
                <h3 class="text-2xl font-bold text-gray-800 mb-6 text-center">Detailed Inspection for <?php echo htmlspecialchars($inspection_data['vehicle_no']); ?></h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="form-group">
                        <label class="block text-gray-700 font-semibold mb-2">Supplier</label>
                        <input type="text" value="<?php echo htmlspecialchars($inspection_data['supplier'] ?? ''); ?>" class="w-full p-3 border border-gray-300 rounded-md bg-gray-100" readonly>
                    </div>
                    <div class="form-group">
                        <label class="block text-gray-700 font-semibold mb-2">Vehicle No.</label>
                        <input type="text" value="<?php echo htmlspecialchars($inspection_data['vehicle_no']); ?>" class="w-full p-3 border border-gray-300 rounded-md bg-gray-100" readonly>
                    </div>
                    <div class="form-group">
                        <label class="block text-gray-700 font-semibold mb-2">Route Name</label>
                        <input type="text" value="<?php echo htmlspecialchars($inspection_data['route']); ?>" class="w-full p-3 border border-gray-300 rounded-md bg-gray-100" readonly>
                    </div>
                    <div class="form-group">
                        <label class="block text-gray-700 font-semibold mb-2">Transport services type</label>
                        <input type="text" value="<?php echo htmlspecialchars($inspection_data['transport_type']); ?>" class="w-full p-3 border border-gray-300 rounded-md bg-gray-100" readonly>
                    </div>
                    <div class="form-group">
                        <label class="block text-gray-700 font-semibold mb-2">Name & Signature of Inspector</label>
                        <input type="text" value="<?php echo htmlspecialchars($inspection_data['inspector']); ?>" class="w-full p-3 border border-gray-300 rounded-md bg-gray-100" readonly>
                    </div>
                    <div class="form-group">
                        <label class="block text-gray-700 font-semibold mb-2">Inspection Date</label>
                        <input type="date" value="<?php echo htmlspecialchars($inspection_data['date']); ?>" class="w-full p-3 border border-gray-300 rounded-md bg-gray-100" readonly>
                    </div>
                </div>

                <h3 class="text-xl font-bold mb-4 text-gray-700">Inspection Criteria</h3>
                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <?php if ($inspection_data['has_problems']): ?>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Inspection Criteria</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status (✓)</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remark</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php
                                foreach ($criteria_mapping as $item_display_name => $db_column_prefix) {
                                    $status_column = $db_column_prefix . '_status';
                                    $remark_column = $db_column_prefix . '_remark';
                                    
                                    $status_text = isset($inspection_data[$status_column]) && $inspection_data[$status_column] == 1 ? 'Passed ✅' : 'Failed ❌';
                                    $remark = isset($inspection_data[$remark_column]) ? htmlspecialchars($inspection_data[$remark_column]) : '';

                                    // Only show if status is 'Failed/N/A' or if there's a remark (even if passed)
                                    if ($inspection_data[$status_column] == 0 || !empty($remark)) {
                                        echo "<tr>";
                                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>{$item_display_name}</td>";
                                        echo "<td class='px-6 py-4 whitespace-nowrap text-center text-sm'>";
                                        echo $status_text;
                                        echo "</td>";
                                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm'>";
                                        echo (!empty($remark) ? $remark : '-');
                                        echo "</td>";
                                        echo "</tr>";
                                    }
                                }
                                ?>
                                <!-- Add a new row for the Vehicle Fitness Certificate -->
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Vehicle Fitness Certificate</td>
                                    <?php
                                        $fitness_status = isset($inspection_data['vehicle_fitness_certificate_status']) ? $inspection_data['vehicle_fitness_certificate_status'] : null;
                                        $fitness_remark = isset($inspection_data['vehicle_fitness_certificate_remark']) ? htmlspecialchars($inspection_data['vehicle_fitness_certificate_remark']) : '';

                                        $status_text = ($fitness_status === 1) ? 'Passed ✅' : 'Failed ❌';
                                        
                                        $status_class = '';
                                        // Per user request, highlight vehicle fitness failure only if the vehicle type is 'Bus'.
                                        $vehicle_type = $inspection_data['type'] ?? '';
                                        if (strcasecmp($vehicle_type, 'bus') == 0 && $fitness_status == 0) {
                                            $status_class = 'text-red-600';
                                        }
                                    ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-bold <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php echo (!empty($fitness_remark) ? $fitness_remark : '-'); ?>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Any other observations</td>
                                    <td colspan="2" class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php echo (!empty($inspection_data['other_observations']) ? htmlspecialchars($inspection_data['other_observations']) : '-'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="p-6 text-center text-lg text-green-600 bg-green-50 rounded-lg">
                            ✅ This vehicle is fine, no problems detected in this inspection.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="text-center mt-8">
                    <a href="#top" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white font-bold rounded-md shadow-md hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500 focus:ring-opacity-50 transition ease-in-out duration-150">
                        Go to Top
                    </a>
                </div>

            <?php endif; // End if ($inspection_data) ?>
        </div>
    </div>
</body>
</html>
