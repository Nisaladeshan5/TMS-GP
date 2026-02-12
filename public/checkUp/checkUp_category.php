<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

include('../../includes/db.php'); 
include('../../includes/header.php');
include('../../includes/navbar.php');

// Initialize arrays
$vehicles = [];
$routes = [];
$suppliers = [];

if ($user_role !== 'manager' && isset($conn)) {
    // Fetch Vehicles
    $sql_vehicles = "SELECT v.vehicle_no, v.type, v.supplier_code, v.purpose, r.route AS route_name FROM vehicle v LEFT JOIN route r ON v.vehicle_no = r.vehicle_no";
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Inspection Checklist</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        select:disabled, input:disabled { background-color: #f3f4f6; color: #6b7280; cursor: not-allowed; }
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
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Vehicle Inspection
        </div>
    </div>
    
    <div class="flex items-center gap-6 text-sm font-medium">
        <?php 
        $full_access_roles = ['super admin', 'admin', 'developer'];
        if (in_array($user_role, $full_access_roles)) {
        ?>
            <a href="edit_inspection.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
                <i class="fas fa-edit"></i> Edit
            </a>
            <a href="view_supplier.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
                <i class="fas fa-users"></i> Suppliers
            </a>
        <?php } ?>
        
        <span class="text-gray-600 text-lg font-thin">|</span>

        <a href="generate_report_checkup.php" class="flex items-center gap-2 bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-file-alt"></i> Report
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex flex-col items-center">
    
    <div class="w-full max-w-5xl">
        
        <?php if ($user_role === 'manager'): ?>
            <div class="bg-white rounded-xl shadow-lg border-l-4 border-red-500 p-8 text-center mt-10">
                <div class="bg-red-100 text-red-600 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">
                    <i class="fas fa-lock"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-800 mb-2">Access Restricted</h2>
                <p class="text-gray-600">You have limited access to this module. Please use the <a href="generate_report_checkup.php" class="text-blue-600 font-bold hover:underline">Report</a> section to view data.</p>
            </div>
        <?php else: ?>

            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="px-8 py-6 border-b border-gray-100 bg-gray-50 flex items-center gap-3">
                    <div class="bg-indigo-100 text-indigo-600 p-2 rounded-full shadow-sm">
                        <i class="fas fa-clipboard-check text-xl"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-800">New Inspection Checklist</h2>
                        <p class="text-xs text-gray-500">Conduct a vehicle inspection.</p>
                    </div>
                </div>

                <form action="process_inspection.php" method="post" class="p-8">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <div>
                            <label for="vehicle_no" class="block text-sm font-semibold text-gray-700 mb-2">Vehicle No.</label>
                            <select id="vehicle_no" name="vehicle_no" required class="w-full pl-3 pr-8 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition bg-white cursor-pointer">
                                <option value="">Select Vehicle No.</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>" 
                                            data-type="<?php echo htmlspecialchars($vehicle['type']); ?>"
                                            data-supplier-code="<?php echo htmlspecialchars($vehicle['supplier_code']); ?>"
                                            data-route="<?php echo htmlspecialchars($vehicle['route_name'] ?? ''); ?>"
                                            data-transport-type="<?php echo htmlspecialchars($vehicle['purpose'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($vehicle['vehicle_no']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="supplier_code" class="block text-sm font-semibold text-gray-700 mb-2">Supplier</label>
                            <select id="supplier_code" name="supplier_code" required disabled class="w-full pl-3 pr-8 py-2 border border-gray-300 rounded-lg focus:ring-0 outline-none transition bg-gray-100 cursor-not-allowed">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>"><?php echo htmlspecialchars($supplier['supplier']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="route_name" class="block text-sm font-semibold text-gray-700 mb-2">Route Name</label>
                            <select id="route_name" name="route_name_display" required disabled class="w-full pl-3 pr-8 py-2 border border-gray-300 rounded-lg focus:ring-0 outline-none transition bg-gray-100 cursor-not-allowed">
                                <option value="">Auto-set on Vehicle selection</option>
                                <?php foreach ($routes as $route): ?>
                                    <option value="<?php echo htmlspecialchars($route); ?>"><?php echo htmlspecialchars($route); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" id="hidden_route_name" name="route_name">
                        </div>

                        <div>
                            <label for="transport_type" class="block text-sm font-semibold text-gray-700 mb-2">Transport Type</label>
                            <input type="text" id="transport_type" required readonly disabled 
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-0 outline-none transition bg-gray-100 cursor-not-allowed text-gray-500"
                                   placeholder="Auto-set on selection">
                            <input type="hidden" id="hidden_transport_type" name="transport_type">
                        </div>

                        <div>
                            <label for="inspector_name" class="block text-sm font-semibold text-gray-700 mb-2">Inspector Name</label>
                            <input type="text" id="inspector_name" name="inspector_name" required placeholder="Enter name"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition">
                        </div>

                        <div>
                            <label for="inspection_date" class="block text-sm font-semibold text-gray-700 mb-2">Date</label>
                            <input type="date" id="inspection_date" name="inspection_date" required value="<?php echo date('Y-m-d'); ?>"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition">
                        </div>
                    </div>

                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b border-gray-200 pb-2">Inspection Checklist</h3>
                    
                    <div class="overflow-hidden border border-gray-200 rounded-lg mb-8">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Criteria</th>
                                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider w-24">Pass (✓)</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Remarks</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100 text-sm">
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
                                    $name = preg_replace('/_+/', '_', $name); 
                                    $name = trim($name, '_'); 
                                    
                                    echo "<tr class='hover:bg-gray-50 transition'>";
                                    echo "<td class='px-6 py-3 font-medium text-gray-700'>{$item}</td>";
                                    echo "<td class='px-6 py-3 text-center'>";
                                    echo "<input type='checkbox' name='{$name}_status' class='h-5 w-5 text-indigo-600 rounded focus:ring-indigo-500 border-gray-300 cursor-pointer'>";
                                    echo "</td>";
                                    echo "<td class='px-6 py-3'>";
                                    echo "<input type='text' name='{$name}_remark' placeholder='Add remark if any...' class='w-full px-3 py-1.5 border border-gray-300 rounded text-xs focus:ring-1 focus:ring-indigo-500 outline-none transition'>";
                                    echo "</td>";
                                    echo "</tr>";
                                }
                                ?>
                                
                                <tr id="fitness_certificate_row" class="hidden bg-indigo-50/50 border-l-4 border-indigo-500">
                                    <td class="px-6 py-3 font-bold text-indigo-800">Vehicle Fitness Certificate</td>
                                    <td class="px-6 py-3 text-center">
                                        <input type="checkbox" name="vehicle_fitness_certificate_status" class="h-5 w-5 text-indigo-600 rounded focus:ring-indigo-500 border-gray-300 cursor-pointer">
                                    </td>
                                    <td class="px-6 py-3">
                                        <input type="text" name="vehicle_fitness_certificate_remark" placeholder="Required for buses" class="w-full px-3 py-1.5 border border-indigo-200 rounded text-xs focus:ring-1 focus:ring-indigo-500 outline-none transition bg-white">
                                    </td>
                                </tr>

                                <tr>
                                    <td class="px-6 py-4 font-bold text-gray-800 align-top pt-5">Other Observations</td>
                                    <td colspan="2" class="px-6 py-4">
                                        <textarea name="other_observations" class="w-full p-3 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition" rows="3" placeholder="Enter any additional notes here..."></textarea>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end pt-4 border-t border-gray-100">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-8 rounded-lg shadow-md transition transform hover:scale-[1.02] flex items-center gap-2">
                            <i class="fas fa-save"></i> Submit Inspection
                        </button>
                    </div>

                </form>
            </div>

        <?php endif; ?>
    </div>
</div>

<script>
    function disableAndSet(selectElement, hiddenElement, value) {
        if (selectElement) {
            selectElement.value = value || '';
            // Ensure disabled state is maintained
            if (!selectElement.hasAttribute('disabled')) {
                 selectElement.setAttribute('disabled', 'disabled');
            }
        }
        if (hiddenElement) {
            hiddenElement.value = value || '';
        }
    }

    const vehicleNoSelect = document.getElementById('vehicle_no');
    
    if (vehicleNoSelect) {
        vehicleNoSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            const vehicleType = selectedOption.getAttribute('data-type');
            const supplierCode = selectedOption.getAttribute('data-supplier-code');
            const routeName = selectedOption.getAttribute('data-route');
            const transportType = selectedOption.getAttribute('data-transport-type');

            const supplierSelect = document.getElementById('supplier_code');
            const routeSelect = document.getElementById('route_name');
            const transportInput = document.getElementById('transport_type');
            const hiddenRouteInput = document.getElementById('hidden_route_name');
            const hiddenTransportInput = document.getElementById('hidden_transport_type');
            const fitnessRow = document.getElementById('fitness_certificate_row');
            
            // 1. Auto-fill Supplier
            if (supplierSelect) {
                supplierSelect.value = supplierCode || '';
            }

            // 2. Auto-fill Route
            disableAndSet(routeSelect, hiddenRouteInput, routeName);
            
            // 3. Auto-fill Transport Type
            if (transportInput) {
                transportInput.value = transportType || '';
            }
            if (hiddenTransportInput) {
                hiddenTransportInput.value = transportType || '';
            }

            // 4. Toggle Fitness Certificate
            if (fitnessRow) {
                if (vehicleType === 'bus') {
                    fitnessRow.classList.remove('hidden');
                } else {
                    fitnessRow.classList.add('hidden');
                    // Reset checkbox and input if hidden
                    const fitCheckbox = document.querySelector('input[name="vehicle_fitness_certificate_status"]');
                    const fitRemark = document.querySelector('input[name="vehicle_fitness_certificate_remark"]');
                    if(fitCheckbox) fitCheckbox.checked = false;
                    if(fitRemark) fitRemark.value = '';
                }
            }
        });
        
        // Initial setup
        document.getElementById('hidden_route_name').value = '';
        document.getElementById('hidden_transport_type').value = '';
    }
</script>

</body>
</html>