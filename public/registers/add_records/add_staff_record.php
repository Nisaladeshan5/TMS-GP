<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');
include('../../../includes/config.php');

// Initialize variables
$errorMessage = '';
$successMessage = '';
$staff_transport_gl_code = '623400'; // Fixed GL Code for Staff Transport
$staff_transport_supplier_code = ''; // Will be fetched from the database

// Fetch routes, vehicles, and drivers
$sqlRoutes = "SELECT route_code, route FROM route";
$resultRoutes = $conn->query($sqlRoutes);
$routes = [];
if ($resultRoutes) {
    while ($row = $resultRoutes->fetch_assoc()) {
        $routes[] = $row;
    }
}

$sqlDrivers = "SELECT driver_NIC, calling_name FROM driver";
$resultDrivers = $conn->query($sqlDrivers);
$drivers = [];
if ($resultDrivers) {
    while ($row = $resultDrivers->fetch_assoc()) {
        $drivers[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Collect form data
    $route = $_POST['route']; // This is the route_code
    $actualVehicleNo = $_POST['actual_vehicle_no'];
    $driver = $_POST['driver'];
    $date = $_POST['date'];
    $shift = $_POST['shift'];
    $inTime = $_POST['in_time'];
    $outTime = $_POST['out_time'];
    
    // --- Start Transaction ---
    $conn->autocommit(false); 

    try {
        // 1. Fetch the assigned vehicle, driver NIC, COST PARAMETERS, AND SUPPLIER CODE
        $sqlAssignedDetails = "
            SELECT 
                r.vehicle_no, 
                v.driver_NIC, 
                v.supplier_code,   /* <-- ADDED supplier_code  */
                r.fixed_amount, 
                r.fuel_amount, 
                r.distance 
            FROM route r 
            JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
            WHERE r.route_code = ?";
        $stmtAssignedDetails = $conn->prepare($sqlAssignedDetails);
        $stmtAssignedDetails->bind_param('s', $route);
        $stmtAssignedDetails->execute();
        $resultAssignedDetails = $stmtAssignedDetails->get_result();
        $routeDetails = $resultAssignedDetails->fetch_assoc();
        $stmtAssignedDetails->close();
        
        if (!$routeDetails) {
            throw new Exception("Route details not found.");
        }

        $assignedVehicle = $routeDetails['vehicle_no'];
        $assignedDriver = $routeDetails['driver_NIC'];
        $staff_transport_supplier_code = $routeDetails['supplier_code']; // <-- FETCHED HERE
        $fixed_amount = (float)$routeDetails['fixed_amount'];
        $fuel_amount = (float)$routeDetails['fuel_amount'];
        $distance = (float)$routeDetails['distance'];
        
        // Validation for critical data
        if (empty($staff_transport_supplier_code)) {
            throw new Exception("Supplier code is missing for the assigned vehicle.");
        }


        // Calculate Cost Per Trip (Assuming one entry = one half-day trip)
        $trip_rate = (($fixed_amount + $fuel_amount) * $distance / 2);
        $distance_per_trip = $distance / 2;
        $current_month = date('m', strtotime($date));
        $current_year = date('Y', strtotime($date));

        // Determine vehicle_status and driver_status
        $vehicleStatus = ($assignedVehicle === $actualVehicleNo) ? 1 : 0;
        $driverStatus = ($assignedDriver === $driver) ? 1 : 0;

        // 2. Check for duplicate record based on route, date, and shift
        $checkSql = "SELECT COUNT(*) FROM staff_transport_vehicle_register WHERE route = ? AND date = ? AND shift = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param('sss', $route, $date, $shift);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_row();
        $recordCount = $row[0];
        $checkStmt->close();

        if ($recordCount > 0) {
            throw new Exception("A record for this route, date, and shift already exists. Cannot add a duplicate.");
        }
        
        // --- All checks passed. Proceed with insertions/updates ---
        
        // 3. Insert into staff_transport_vehicle_register
        $sql = "INSERT INTO staff_transport_vehicle_register 
                (vehicle_no, actual_vehicle_no, vehicle_status, driver_NIC, driver_status, date, shift, route, in_time, out_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssisssssss', $assignedVehicle, $actualVehicleNo, $vehicleStatus, $driver, $driverStatus, $date, $shift, $route, $inTime, $outTime);

        if (!$stmt->execute()) {
            throw new Exception("Database error (Register Insert): " . $stmt->error);
        }
        $stmt->close();

        // 4. Update monthly_payments_sf (Contractor Payment)
        $update_sf_sql = "
            INSERT INTO monthly_payments_sf 
            (route_code, supplier_code, month, year, monthly_payment, total_distance) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            monthly_payment = monthly_payment + VALUES(monthly_payment), 
            total_distance = total_distance + VALUES(total_distance)
        ";
        $stmt_sf = $conn->prepare($update_sf_sql);
        $stmt_sf->bind_param("ssisdd", $route, $staff_transport_supplier_code, $current_month, $current_year, $trip_rate, $distance_per_trip);
        
        if (!$stmt_sf->execute()) {
             throw new Exception("Database error (SF Payment Update): " . $stmt_sf->error);
        }
        $stmt_sf->close();
        
        
        // 5. Cost Allocation (Consolidated: GL, DI, & Supplier in monthly_cost_allocation)
        
        // 5a. Get headcount for allocation
        $employee_details_sql = "
            SELECT 
                department, 
                direct, 
                COUNT(emp_id) AS headcount 
            FROM 
                employee 
            WHERE 
                LEFT(route, 10) = ? 
            GROUP BY 
                department, direct
        ";
        $stmt_emp = $conn->prepare($employee_details_sql);
        $stmt_emp->bind_param("s", $route);
        $stmt_emp->execute();
        $employee_results = $stmt_emp->get_result();

        $total_route_headcount = 0;
        $aggregated_allocations = []; // Array to hold [department, direct] => cost
        $temp_results = [];

        while ($row = $employee_results->fetch_assoc()) {
            $total_route_headcount += (int)$row['headcount'];
            $temp_results[] = $row;
        }
        $stmt_emp->close(); 

        if ($total_route_headcount > 0 && $trip_rate > 0) {
            $cost_per_employee = $trip_rate / $total_route_headcount;

            foreach ($temp_results as $row) {
                $department = $row['department'];
                $di_status = $row['direct']; 
                $headcount = (int)$row['headcount'];
                $allocated_cost = $cost_per_employee * $headcount;

                if ($allocated_cost > 0) {
                    // Key is a combination of department and direct status
                    $key = $department . '-' . $di_status; 
                    $aggregated_allocations[$key] = ($aggregated_allocations[$key] ?? 0.00) + $allocated_cost;
                }
            }

            // 5b. Update monthly_cost_allocation (Consolidated GL/DI/Supplier)
            $allocation_update_sql = "
                INSERT INTO monthly_cost_allocation
                (supplier_code, gl_code, department, direct, month, year, monthly_allocation) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                monthly_allocation = monthly_allocation + VALUES(monthly_allocation)
            ";
            $allocation_stmt = $conn->prepare($allocation_update_sql);
            
            foreach ($aggregated_allocations as $dept_di_key => $cost) {
                list($department, $di_status) = explode('-', $dept_di_key);
                
                // Bind parameters for the new structure
                $allocation_stmt->bind_param(
                    "ssssssd", 
                    $staff_transport_supplier_code,  // <-- NOW USES FETCHED VALUE
                    $staff_transport_gl_code, 
                    $department, 
                    $di_status, 
                    $current_month, 
                    $current_year, 
                    $cost
                );
                
                if (!$allocation_stmt->execute()) {
                    throw new Exception("Database error (Consolidated Cost Allocation Update): " . $allocation_stmt->error);
                }
            }
            $allocation_stmt->close();
        }


        // --- COMMIT TRANSACTION ---
        $conn->commit();
        header("Location: " . BASE_URL . "registers/Staff%20transport%20vehicle%20register.php?status=success&message=" . urlencode("Record and financial allocations added successfully!"));
        exit();

    } catch (Exception $e) {
        // --- ROLLBACK TRANSACTION ---
        $conn->rollback();
        $errorMessage = "Transaction Failed: " . $e->getMessage();
    }
    
    // Restore default autocommit mode
    $conn->autocommit(true); 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Staff Transport Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .toast {
            display: flex;
            align-items: center;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            color: white;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            background-color: #4CAF50;
        }

        .toast.error {
            background-color: #F44336;
        }

        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    
<div id="toast-container"></div>

<?php if (!empty($errorMessage)): ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        showToast("<?php echo htmlspecialchars($errorMessage); ?>", 'error');
    });
</script>
<?php endif; ?>

<div class="w-[85%] ml-[15%]">
    <div class="container max-w-3xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add Staff Transport Record</h1>

        <form method="POST" class="space-y-6" onsubmit="return validateTimeRange()">
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="route" class="block text-sm font-medium text-gray-700">Route:</label>
                    <select id="route" name="route" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" onchange="fetchVehicleAndDriver()">
                        <option value="" disabled selected>Select a Route</option>
                        <?php foreach ($routes as $routeOption): ?>
                            <option value="<?php echo htmlspecialchars($routeOption['route_code']); ?>"><?php echo htmlspecialchars($routeOption['route']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Assigned Vehicle No:</label>
                    <input type="text" id="vehicle_no" name="vehicle_no" readonly class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 bg-gray-200">
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="actual_vehicle_no" class="block text-sm font-medium text-gray-700">Actual Vehicle No:</label>
                    <input type="text" id="actual_vehicle_no" name="actual_vehicle_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
                <div>
                    <label for="driver" class="block text-sm font-medium text-gray-700">Driver:</label>
                    <select id="driver" name="driver" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        <option value="" disabled selected>Select Driver</option>
                        <?php foreach ($drivers as $driverOption): ?>
                            <option value="<?php echo htmlspecialchars($driverOption['driver_NIC']); ?>"><?php echo htmlspecialchars($driverOption['calling_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                    <input type="date" id="date" name="date" required value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
                <div>
                    <label for="shift" class="block text-sm font-medium text-gray-700">Shift:</label>
                    <select id="shift" name="shift" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" onchange="updateTimeLimits()">
                        <option value="" disabled selected>Select Shift</option>
                        <option value="Morning">Morning</option>
                        <option value="Evening">Evening</option>
                    </select>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="in_time" class="block text-sm font-medium text-gray-700">In Time:</label>
                    <input type="time" id="in_time" name="in_time" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
                <div>
                    <label for="out_time" class="block text-sm font-medium text-gray-700">Out Time:</label>
                    <input type="time" id="out_time" name="out_time" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                </div>
            </div>
            
            <p id="timeWarning" class="text-red-500 text-sm mb-4 hidden">Time must be between 04:00–11:59 for Morning or 12:00–23:59 for Evening.</p>

            <div class="flex justify-end gap-4 mt-6">
                <a href="<?= BASE_URL ?>/registers/Staff%20transport%20vehicle%20register.php"
                   class="inline-flex items-center px-6 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition duration-300">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Add Record
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                ${type === 'success'
                    ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />'
                    : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />'
                }
            </svg>
            <span>${message}</span>
        `;
        
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 2000);
    }

    // Function to fetch assigned vehicle and driver and populate form fields
    function fetchVehicleAndDriver() {
        const routeCode = document.getElementById('route').value;
        const actualVehicleInput = document.getElementById('actual_vehicle_no');
        const driverSelect = document.getElementById('driver');

        // Reset fields immediately
        document.getElementById('vehicle_no').value = '';
        actualVehicleInput.value = '';
        driverSelect.value = '';

        if (routeCode) {
            fetch(`get_vehicle_driver.php?route_code=${routeCode}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    // 1. Set the ASSIGNED vehicle number (always read-only)
                    if (data.vehicle_no) {
                        document.getElementById('vehicle_no').value = data.vehicle_no;

                        // 2. Set the ACTUAL vehicle number (only if user hasn't typed anything)
                        if (actualVehicleInput.value === '') {
                            actualVehicleInput.value = data.vehicle_no;
                        }

                        // 3. Set the DRIVER NIC (***USES data.driver key***)
                        // Check if data.driver exists AND the field is currently empty/default
                        if (data.driver && 
                            (driverSelect.value === '' || driverSelect.value === 'Select Driver')) {
                            
                            // ✅ FIX: Setting the selected option using the NIC returned by the PHP key 'driver'
                            driverSelect.value = data.driver; 
                        }
                    } else {
                        // Log error if vehicle data is missing for debugging
                        console.warn("No vehicle details found for route:", routeCode);
                    }
                })
                .catch(error => {
                    console.error('Error fetching route data:', error);
                    showToast('Could not fetch route details. Check server logs.', 'error');
                });
        }
    }

    function updateTimeLimits() {
        const shift = document.getElementById('shift').value;
        const inTime = document.getElementById('in_time');
        const outTime = document.getElementById('out_time');

        if (shift === 'Morning') {
            inTime.min = '04:00';
            inTime.max = '11:59';
            outTime.min = '04:00';
            outTime.max = '11:59';
        } else if (shift === 'Evening') {
            inTime.min = '12:00';
            inTime.max = '23:59';
            outTime.min = '12:00';
            outTime.max = '23:59';
        } else {
            inTime.removeAttribute('min');
            inTime.removeAttribute('max');
            outTime.removeAttribute('min');
            outTime.removeAttribute('max');
        }
    }

    function validateTimeRange() {
        const shift = document.getElementById('shift').value;
        const inTime = document.getElementById('in_time').value;
        const outTime = document.getElementById('out_time').value;
        const warning = document.getElementById('timeWarning');

        const toMinutes = timeStr => {
            const [h, m] = timeStr.split(':');
            return parseInt(h) * 60 + parseInt(m);
        }

        const inMins = toMinutes(inTime);
        const outMins = toMinutes(outTime);

        let isValid = true;

        if (shift === 'Morning') {
            // 04:00 (240 mins) to 11:59 (719 mins)
            isValid = inMins >= 240 && inMins <= 719 && outMins >= 240 && outMins <= 719;
        } else if (shift === 'Evening') {
            // 12:00 (720 mins) to 23:59 (1439 mins)
            isValid = inMins >= 720 && inMins <= 1439 && outMins >= 720 && outMins <= 1439;
        }

        if (!isValid) {
            warning.classList.remove('hidden');
            return false;
        }

        warning.classList.add('hidden');
        return true;
    }

    // Initial checks on page load
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const status = urlParams.get('status');
        const message = urlParams.get('message');
        if (status && message) {
            showToast(decodeURIComponent(message), status);
            // Clear URL parameters after showing toast
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        updateTimeLimits(); // Set initial time limits if a shift is pre-selected
    });
</script>

</body>
</html>