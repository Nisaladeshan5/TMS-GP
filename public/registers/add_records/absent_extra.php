<?php
// Ensure this path is correct
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');
include('../../../includes/config.php');

// Function to get the latest fuel rate
function getLatestFuelRate($conn) {
    $sql = "SELECT rate FROM fuel_rate ORDER BY date DESC LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['rate'];
    }
    return null; // Return null if no rate is found
}

// Function to get distance consumption values
function getDistanceConsumption($conn) {
    $sql = "SELECT c_type, distance FROM consumption";
    $result = $conn->query($sql);
    $distances = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $distances[$row['c_type']] = $row['distance'];
        }
    }
    return $distances;
}

// Function to get vehicle and route details
function getVehicleDetails($conn) {
    $sql = "SELECT vehicle_no FROM vehicle";
    $result = $conn->query($sql);
    $vehicles = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $vehicles[] = $row;
        }
    }
    return $vehicles;
}

function getRouteCodes($conn) {
    $sql = "SELECT route_code, route FROM route";
    $result = $conn->query($sql);
    $routes = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $routes[] = $row;
        }
    }
    return $routes;
}

// Handle form submission
$message = null;
$status = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $vehicleNo = $_POST['vehicle_no'];
    $date = $_POST['date'];
    $amount = $_POST['amount'];
    $reason = $_POST['reason'];
    $routeCode = $_POST['route_code'];
    $absentType = $_POST['absent_type'];

    // --- NEW LOGIC: Fetch all required data for the calculation ---

    // 1. Get worked_days count from staff_transport_vehicle_register
    $stmt_worked = $conn->prepare("SELECT COUNT(*) as count FROM staff_transport_vehicle_register WHERE route = ? AND MONTH(date) = MONTH(?) AND YEAR(date) = YEAR(?)");
    $stmt_worked->bind_param('sss', $routeCode, $date, $date);
    $stmt_worked->execute();
    $result_worked = $stmt_worked->get_result();
    $worked_days = $result_worked->fetch_assoc()['count'];
    $stmt_worked->close();

    // 2. Get extra_vehicle_days count from extra_vehicle_register
    $stmt_extra = $conn->prepare("SELECT COUNT(*) as count FROM extra_vehicle_register WHERE route_code = ? AND MONTH(date) = MONTH(?) AND YEAR(date) = YEAR(?)");
    $stmt_extra->bind_param('sss', $routeCode, $date, $date);
    $stmt_extra->execute();
    $result_extra = $stmt_extra->get_result();
    $extra_vehicle_days = $result_extra->fetch_assoc()['count'];
    $stmt_extra->close();

    // 3. Get petty_cash_days count from petty_cash
    $stmt_petty = $conn->prepare("SELECT COUNT(*) as count FROM petty_cash WHERE route_code = ? AND MONTH(date) = MONTH(?) AND YEAR(date) = YEAR(?)");
    $stmt_petty->bind_param('sss', $routeCode, $date, $date);
    $stmt_petty->execute();
    $result_petty = $stmt_petty->get_result();
    $petty_cash_days = $result_petty->fetch_assoc()['count'];
    $stmt_petty->close();

    // 4. Get total working_days from the route table
    $stmt_route = $conn->prepare("SELECT working_days FROM route WHERE route_code = ?");
    $stmt_route->bind_param('s', $routeCode);
    $stmt_route->execute();
    $result_route = $stmt_route->get_result();
    $route_row = $result_route->fetch_assoc();
    $working_days = $route_row ? $route_row['working_days'] : 0;
    $stmt_route->close();

    // --- 5. Perform the final check and set status ---
    $status_value = 0;
    // We add 1 to `extra_vehicle_days` to include the new record being inserted
    $total_vehicle_days = $worked_days + ($extra_vehicle_days + 1) + $petty_cash_days;

    if ($total_vehicle_days > $working_days) {
        $status_value = 1;
    }

    // --- 6. Insert the new record with the calculated status ---
    $sql = "INSERT INTO extra_vehicle_register (vehicle_no, date, amount, reason, route_code, absent_type, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssdssii', $vehicleNo, $date, $amount, $reason, $routeCode, $absentType, $status_value);

    if ($stmt->execute()) {
        $message = "Extra vehicle record added successfully!";
        $status = "success";
        // Redirect to prevent form resubmission
        header("Location: " . BASE_URL . "registers/Staff%20transport%20vehicle%20register.php?status=success&message=" . urlencode($message));
        exit();
    } else {
        $message = "Error saving extra vehicle record: " . $stmt->error;
        $status = "error";
    }
    $stmt->close();
}

// Get data for the form
$latest_fuel_rate = getLatestFuelRate($conn);
$distance_consumptions = getDistanceConsumption($conn);
$vehicles = getVehicleDetails($conn);
$routes = getRouteCodes($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Extra Vehicle Record</title>
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
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-6">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add Absent Extra Vehicle Record</h1>
            <form method="POST" class="space-y-3">
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No:</label>
                        <select name="vehicle_no" id="vehicle_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">Select a vehicle</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>">
                                    <?php echo htmlspecialchars($vehicle['vehicle_no']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                        <input type="date" name="date" id="date" required value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" />
                    </div>
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label for="route_code" class="block text-sm font-medium text-gray-700">Route:</label>
                        <select name="route_code" id="route_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">Select a Route</option>
                            <?php foreach ($routes as $route): ?>
                                <option value="<?php echo htmlspecialchars($route['route_code']); ?>">
                                    <?php echo htmlspecialchars($route['route']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700">Amount (LKR):</label>
                        <input type="number" step="0.01" name="amount" id="amount" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" />
                    </div>
                    <div>
                        <label for="absent_type" class="block text-sm font-medium text-gray-700">Absent Type:</label>
                        <select name="absent_type" id="absent_type" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">Select a Type</option>
                            <option value="2">Full Day</option>
                            <option value="1">Half Day</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="reason" class="block text-sm font-medium text-gray-700">Reason:</label>
                    <textarea name="reason" id="reason" rows="3" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"></textarea>
                </div>
                
                <hr class="border-gray-300" />

                <div class="bg-gray-50 p-6 rounded-lg border border-gray-200 space-y-4">
                    <p class="text-xl font-bold text-center text-gray-700">Optional: Amount Calculator</p>
                    <p class="text-sm text-center text-gray-500">Use this to automatically fill the Amount field.</p>
                    
                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="distance_calc" class="block text-sm font-medium text-gray-700">Distance (km):</label>
                            <input type="number" step="0.01" name="distance_calc" id="distance_calc" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" />
                        </div>
                        <div>
                            <label for="vehicle_type_calc" class="block text-sm font-medium text-gray-700">Vehicle Type:</label>
                            <select name="vehicle_type_calc" id="vehicle_type_calc" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                                <option value="">Select a Type</option>
                                <?php foreach ($distance_consumptions as $type => $distance): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>">
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-6 items-end">
                        <div>
                            <label for="fixed_amount_calc" class="block text-sm font-medium text-gray-700">Fixed Amount for ≤ <input type="number" step="1" name="fixed_distance" id="fixed_distance" value="25" class="w-16 border p-1 rounded-md text-center inline-block" /> km</label>
                            <input type="number" step="0.01" name="fixed_amount_calc" id="fixed_amount_calc" value="0.00" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" />
                        </div>
                        <button type="button" id="calculate_btn" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                            Calculate Amount
                        </button>
                    </div>
                </div>

                <div class="flex justify-end gap-4 mt-6">
                    <a href="<?= BASE_URL ?>registers/Staff%20transport%20vehicle%20register.php"
                       class="inline-flex items-center px-6 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition duration-300">
                        Cancel
                    </a>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        /**
         * Displays a toast notification.
         * @param {string} message The message to display.
         * @param {string} type The type of toast ('success' or 'error').
         */
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

        document.addEventListener('DOMContentLoaded', function() {
            const calculateBtn = document.getElementById('calculate_btn');
            const distanceInput = document.getElementById('distance_calc');
            const fixedDistanceInput = document.getElementById('fixed_distance');
            const fixedAmountInput = document.getElementById('fixed_amount_calc');
            const amountInput = document.getElementById('amount');
            const vehicleTypeCalcSelect = document.getElementById('vehicle_type_calc');

            const fuelRate = <?php echo json_encode($latest_fuel_rate); ?>;
            const distanceConsumptions = <?php echo json_encode($distance_consumptions); ?>;

            function calculateAndSetAmount() {
                const distance = parseFloat(distanceInput.value);
                const fixedDistance = parseFloat(fixedDistanceInput.value);
                const fixedAmount = parseFloat(fixedAmountInput.value);
                const vehicleType = vehicleTypeCalcSelect.value;

                if (!vehicleType) {
                    showToast('Please select a vehicle type to use the calculator.', 'error');
                    return;
                }
                
                if (isNaN(distance) || distance <= 0) {
                    showToast('Please enter a valid distance to use the calculator.', 'error');
                    return;
                }
                
                if (distance <= fixedDistance) {
                    if (isNaN(fixedAmount)) {
                        showToast('Please enter a valid fixed amount.', 'error');
                        return;
                    }
                    amountInput.value = fixedAmount.toFixed(2);
                } else {
                    if (!fuelRate) {
                        showToast('Fuel rate not found. Cannot calculate amount.', 'error');
                        return;
                    }
                    if (!distanceConsumptions[vehicleType]) {
                        showToast('Distance consumption for the selected vehicle type not found. Cannot calculate amount.', 'error');
                        return;
                    }
                    if (isNaN(fixedAmount)) {
                        showToast('Please enter a valid fixed amount.', 'error');
                        return;
                    }

                    const distancePerLiter = distanceConsumptions[vehicleType];
                    const fuelNeeded = (distance-fixedDistance) / distancePerLiter;
                    const calculatedAmount = fixedAmount + (fuelNeeded * fuelRate);
                    amountInput.value = calculatedAmount.toFixed(2);
                }
                showToast('Amount calculated successfully!', 'success');
            }

            calculateBtn.addEventListener('click', calculateAndSetAmount);
        });

        const urlParams = new URLSearchParams(window.location.search);
        const status = urlParams.get('status');
        const message = urlParams.get('message');
        if (status && message) {
            showToast(decodeURIComponent(message), status);
        }
    </script>
</body>
</html>