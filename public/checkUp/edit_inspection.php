<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

include('../../includes/db.php'); // DB connection
include('../../includes/header.php');
include('../../includes/navbar.php');

$inspection_data = null;
$search_vehicle_no = '';
$message = '';
$vehicle_type_of_data = '';

// Initialize arrays
$vehicles = [];
$routes = [];
$suppliers = [];

if (isset($conn)) {
    // Fetch Vehicles
    $sql_vehicles = "SELECT vehicle_no, type FROM vehicle";
    $result_vehicles = $conn->query($sql_vehicles);
    if ($result_vehicles && $result_vehicles->num_rows > 0) {
        while ($row = $result_vehicles->fetch_assoc()) { $vehicles[] = $row; }
    }

    // Fetch Routes
    $sql_routes = "SELECT route FROM route";
    $result_routes = $conn->query($sql_routes);
    if ($result_routes && $result_routes->num_rows > 0) {
        while ($row = $result_routes->fetch_assoc()) { $routes[] = $row['route']; }
    }

    // Fetch Suppliers
    $sql_suppliers = "SELECT supplier_code, supplier FROM supplier";
    $result_suppliers = $conn->query($sql_suppliers);
    if ($result_suppliers && $result_suppliers->num_rows > 0) {
        while ($row = $result_suppliers->fetch_assoc()) { $suppliers[] = $row; }
    }
}

// Criteria Mapping
$criteria_mapping = [
    'Revenue License' => 'revenue_license', 'Driver License' => 'driver_license', 'Insurance' => 'insurance',
    'Driver Data sheet' => 'driver_data_sheet', 'Driver NIC' => 'driver_nic', 'Break' => 'break',
    'Tires' => 'tires', 'Spare Wheel' => 'spare_wheel', 'Lights (Head/Signal/Break)' => 'lights',
    'Revers lights/ tones' => 'revers_lights', 'Horns' => 'horns', 'Windows and shutters' => 'windows',
    'Door locks' => 'door_locks', 'No oil leaks' => 'no_oil_leaks', 'No high smoke' => 'no_high_smoke',
    'Seat condition' => 'seat_condition', 'Seat Gap' => 'seat_gap', 'Body condition' => 'body_condition',
    'Roof leek' => 'roof_leek', 'Air Conditions' => 'air_conditions', 'Noise' => 'noise'
];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_vehicle'])) {
    if (isset($_POST['search_vehicle_no']) && !empty($_POST['search_vehicle_no'])) {
        $search_vehicle_no = $conn->real_escape_string($_POST['search_vehicle_no']);
        $sql = "SELECT * FROM checkUp WHERE vehicle_no = ? ORDER BY date DESC, id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $search_vehicle_no);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $inspection_data = $result->fetch_assoc();
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
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Customizing Select2 to match Tailwind */
        .select2-container .select2-selection--single {
            height: 42px !important;
            border: 1px solid #d1d5db !important; /* gray-300 */
            border-radius: 0.5rem !important; /* rounded-lg */
            display: flex;
            align-items: center;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #374151 !important; /* gray-700 */
            padding-left: 12px;
            font-size: 0.875rem; /* text-sm */
        }
        .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
            background-color: #2563eb !important; /* blue-600 */
            color: white !important;
        }
        .select2-dropdown {
            border: 1px solid #d1d5db !important;
            border-radius: 0.5rem !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
        }
    </style>
    
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 
        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>

<body class="bg-gray-100">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="checkUp_category.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Vehicle Inspection
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Edit
            </span>
        </div>
    </div>
    
    <div class="flex items-center gap-6 text-sm font-medium">
        <?php if ($user_role === 'admin' || $user_role === 'super admin' || $user_role === 'developer'): ?>
            <a href="checkUp_category.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
                <i class="fas fa-plus-circle"></i> Add New
            </a>
            <a href="view_supplier.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
                <i class="fas fa-users"></i> Suppliers
            </a>
        <?php endif; ?>
        
        <span class="text-gray-600 text-lg font-thin">|</span>

        <a href="generate_report_checkup.php" class="flex items-center gap-2 bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-file-alt"></i> Report
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex flex-col items-center">
    
    <div class="w-full max-w-5xl">
        
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-search text-blue-600"></i> Search Inspection
            </h2>
            
            <form action="" method="post" class="flex flex-col md:flex-row items-center gap-4">
                <div class="relative flex-grow w-full">
                    <select id="search_vehicle_no" name="search_vehicle_no" class="w-full select2-enable" required>
                        <option value="">Select Vehicle No.</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>" <?php echo ($search_vehicle_no == $vehicle['vehicle_no']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($vehicle['vehicle_no']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="search_vehicle" class="w-full md:w-auto px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg shadow-md transition transform hover:scale-[1.02] flex items-center justify-center gap-2">
                    Search
                </button>
            </form>

            <?php if (!empty($message)): ?>
                <div class="mt-4 p-3 bg-red-50 text-red-600 border border-red-200 rounded-lg text-sm text-center font-medium">
                    <i class="fas fa-exclamation-circle mr-1"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($inspection_data): ?>
            
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden mb-10">
                <div class="px-8 py-6 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="bg-indigo-100 text-indigo-600 p-2 rounded-full shadow-sm">
                            <i class="fas fa-edit text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-gray-800">Edit Inspection Details</h2>
                            <p class="text-xs text-gray-500 font-mono">Vehicle: <?php echo htmlspecialchars($inspection_data['vehicle_no']); ?></p>
                        </div>
                    </div>
                    <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded border border-blue-200">ID: <?php echo htmlspecialchars($inspection_data['id']); ?></span>
                </div>

                <form action="update_inspection.php" method="post" class="p-8">
                    <input type="hidden" name="inspection_id" value="<?php echo htmlspecialchars($inspection_data['id']); ?>">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Supplier</label>
                            <select id="supplier_code" name="supplier_code" class="w-full select2-enable" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>" <?php echo ($inspection_data['supplier_code'] == $supplier['supplier_code']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier['supplier']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Vehicle No.</label>
                            <select id="vehicle_no" name="vehicle_no" class="w-full select2-enable" required>
                                <option value="">Select Vehicle</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>" data-type="<?php echo htmlspecialchars($vehicle['type']); ?>" <?php echo ($inspection_data['vehicle_no'] == $vehicle['vehicle_no']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vehicle['vehicle_no']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Route Name</label>
                            <select id="route_name" name="route_name" class="w-full select2-enable" required>
                                <option value="">Select Route</option>
                                <?php foreach ($routes as $route): ?>
                                    <option value="<?php echo htmlspecialchars($route); ?>" <?php echo ($inspection_data['route'] == $route) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($route); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Transport Type</label>
                            <select id="transport_type" name="transport_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition bg-white" required>
                                <option value="Staff Transport" <?php echo ($inspection_data['transport_type'] == 'Staff Transport') ? 'selected' : ''; ?>>Staff Transport</option>
                                <option value="Factory Transport" <?php echo ($inspection_data['transport_type'] == 'Factory Transport') ? 'selected' : ''; ?>>Factory Transport</option>
                                <option value="Service provider" <?php echo ($inspection_data['transport_type'] == 'Service provider') ? 'selected' : ''; ?>>Service provider</option>
                                <option value="Other" <?php echo ($inspection_data['transport_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Inspector Name</label>
                            <input type="text" id="inspector_name" name="inspector_name" value="<?php echo htmlspecialchars($inspection_data['inspector']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition" required>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Date</label>
                            <input type="date" id="inspection_date" name="inspection_date" value="<?php echo htmlspecialchars($inspection_data['date']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition" required>
                        </div>
                    </div>

                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b border-gray-200 pb-2">Updated Checklist</h3>
                    
                    <div class="overflow-hidden border border-gray-200 rounded-lg mb-8">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Criteria</th>
                                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider w-24">Pass (✓)</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Remark</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100 text-sm">
                                <?php
                                foreach ($criteria_mapping as $item_display_name => $db_column_prefix) {
                                    $status_column = $db_column_prefix . '_status';
                                    $remark_column = $db_column_prefix . '_remark';
                                    $is_checked = isset($inspection_data[$status_column]) && $inspection_data[$status_column] == 1;
                                    $remark_value = isset($inspection_data[$remark_column]) ? htmlspecialchars($inspection_data[$remark_column]) : '';

                                    echo "<tr class='hover:bg-gray-50 transition'>";
                                    echo "<td class='px-6 py-3 font-medium text-gray-700'>{$item_display_name}</td>";
                                    echo "<td class='px-6 py-3 text-center'>";
                                    echo "<input type='checkbox' name='{$db_column_prefix}_status' class='h-5 w-5 text-indigo-600 rounded focus:ring-indigo-500 border-gray-300 cursor-pointer' " . ($is_checked ? 'checked' : '') . ">";
                                    echo "</td>";
                                    echo "<td class='px-6 py-3'>";
                                    echo "<input type='text' name='{$db_column_prefix}_remark' value='{$remark_value}' class='w-full px-3 py-1.5 border border-gray-300 rounded text-xs focus:ring-1 focus:ring-indigo-500 outline-none transition bg-white' placeholder='Add remark...'>";
                                    echo "</td>";
                                    echo "</tr>";
                                }
                                ?>
                                
                                <tr id="fitness_certificate_row" class="<?php echo (strtolower($vehicle_type_of_data) === 'bus') ? 'bg-indigo-50/50 border-l-4 border-indigo-500' : 'hidden'; ?>">
                                    <td class="px-6 py-3 font-bold text-indigo-800">Vehicle Fitness Certificate</td>
                                    <td class="px-6 py-3 text-center">
                                        <input type="checkbox" name="vehicle_fitness_certificate_status" class="h-5 w-5 text-indigo-600 rounded focus:ring-indigo-500 border-gray-300 cursor-pointer" <?php echo (isset($inspection_data['vehicle_fitness_certificate_status']) && $inspection_data['vehicle_fitness_certificate_status'] == 1) ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="px-6 py-3">
                                        <input type="text" name="vehicle_fitness_certificate_remark" value="<?php echo isset($inspection_data['vehicle_fitness_certificate_remark']) ? htmlspecialchars($inspection_data['vehicle_fitness_certificate_remark']) : ''; ?>" class="w-full px-3 py-1.5 border border-indigo-200 rounded text-xs focus:ring-1 focus:ring-indigo-500 outline-none transition bg-white" placeholder="Required for buses">
                                    </td>
                                </tr>

                                <tr>
                                    <td class="px-6 py-4 font-bold text-gray-800 align-top pt-5">Other Observations</td>
                                    <td colspan="2" class="px-6 py-4">
                                        <textarea name="other_observations" class="w-full p-3 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition" rows="3"><?php echo htmlspecialchars($inspection_data['other_observations']); ?></textarea>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end pt-4 border-t border-gray-100">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-8 rounded-lg shadow-md transition transform hover:scale-[1.02] flex items-center gap-2">
                            <i class="fas fa-save"></i> Update Changes
                        </button>
                    </div>

                </form>
            </div>

        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    $(document).ready(function() {
        // Initialize Select2 on elements with class 'select2-enable'
        $('.select2-enable').select2({
            width: '100%',
            placeholder: 'Search and Select...',
            allowClear: true
        });

        // Vehicle Change Logic (Fitness Row)
        $('#vehicle_no').on('change', function() {
            // Since we are using select2, we need to access the DOM element to get attributes
            const selectedOption = $(this).find(':selected');
            const vehicleType = selectedOption.attr('data-type');
            const fitnessRow = document.getElementById('fitness_certificate_row');

            if (vehicleType && vehicleType.toLowerCase() === 'bus') {
                fitnessRow.classList.remove('hidden');
                fitnessRow.classList.add('bg-indigo-50/50', 'border-l-4', 'border-indigo-500');
            } else {
                fitnessRow.classList.add('hidden');
                fitnessRow.classList.remove('bg-indigo-50/50', 'border-l-4', 'border-indigo-500');
            }
        });
    });
</script>

</body>
</html>