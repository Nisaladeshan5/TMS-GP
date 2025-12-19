<?php
require_once '../../includes/session_check.php';
// Start a session to store and retrieve messages for the toast notification.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Ensure this path is correct
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');
include('../../includes/config.php');

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $vehicleNo = $_POST['vehicle_no'];
    $date = $_POST['date'];
    $amount = $_POST['amount'];
    $reason = $_POST['reason'];

    // The line for `route_code` has been removed as it was not in the form and caused the "Undefined array key" warning.

    $sql = "INSERT INTO extra_vehicle_register (vehicle_no, date, amount, reason)
             VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssds', $vehicleNo, $date, $amount, $reason);

    if ($stmt->execute()) {
        // Set a success message in the session
        $_SESSION['message_type'] = 'success';
        $_SESSION['message'] = 'Record saved successfully!';
        
        // Redirect to the same page to show the toast notification
        header("Location: " . BASE_URL . "registers/extra_transport_vehicle_register.php");
        exit();
    } else {
        // Set an error message in the session
        $_SESSION['message_type'] = 'error';
        $_SESSION['message'] = 'Error saving extra distance record.';
        
        // Redirect to the same page to show the toast notification
        header("Location: " . BASE_URL . "registers/extra_transport_vehicle_register.php");
        exit();
    }
}

// Get the latest fuel rate and distance consumption for the form
$latest_fuel_rate = getLatestFuelRate($conn);
$distance_consumptions = getDistanceConsumption($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Extra Distance</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100">

    <?php
    if (isset($_SESSION['message']) && isset($_SESSION['message_type'])) {
        echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    showToast('" . addslashes($_SESSION['message']) . "', '" . $_SESSION['message_type'] . "');
                });
              </script>";
        // Unset the session variables after displaying the message to prevent it from showing again on refresh
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }
    ?>

    <div class="flex justify-center items-center w-[85%] ml-[15%] h-screen">
        <div class="p-6 bg-white rounded-lg shadow-md w-xl">
            <h2 class="text-2xl text-blue-800 font-bold text-center">Add Extra vehicle Record</h2>
            <form method="POST" class="bg-white p-8 space-y-3">
                <div class="mb-1 flex gap-4">
                    <div class="w-1/2">
                        <label for="vehicle_no" class="block text-gray-700 font-semibold">Vehicle No</label>
                        <input type="text" name="vehicle_no" id="vehicle_no" required
                            class="w-full border p-2 rounded-md" />
                    </div>

                    <div class="w-1/2">
                        <label for="date" class="block text-gray-700 font-semibold">Date</label>
                        <input type="date" name="date" id="date" required
                            value="<?php echo date('Y-m-d'); ?>"
                            class="w-full border p-2 rounded-md" />
                    </div>
                </div>
                <div class="mb-1 flex gap-4">
                    
                    <div class="w-full">
                        <label for="amount" class="block text-gray-700 font-semibold">Amount</label>
                        <input type="number" step="0.01" name="amount" id="amount" required
                            class="w-full border p-2 rounded-md" />
                    </div>
                </div>

                <div>
                    <label for="reason" class="block text-gray-700 font-semibold">Reason</label>
                    <input type="text" name="reason" id="reason" required
                        class="w-full border p-2 rounded-md" />
                </div>
                
                <hr class="my-6" />

                <div class="bg-gray-50 p-6 rounded-lg border border-gray-200 space-y-3">
                    <p class="text-xl font-bold text-center text-gray-700">Optional: Amount Calculator</p>
                    <p class="text-sm text-center text-gray-500">Use this to automatically fill the Amount field.</p>
                    
                    <div class="mb-1 flex gap-4">
                        <div class="w-1/2">
                            <label for="distance_calc" class="block text-gray-700 font-semibold">Distance (in km)</label>
                            <input type="number" step="0.01" name="distance_calc" id="distance_calc"
                                class="w-full border p-2 rounded-md" />
                        </div>
                        <div class="w-1/2">
                            <label for="vehicle_type" class="block text-gray-700 font-semibold">Vehicle Type (A/C)</label>
                            <select name="vehicle_type" id="vehicle_type"
                                           class="w-full border p-2 rounded-md">
                                <option value="">Select A/C Type</option>
                                <option value="Non A/C">Non A/C</option>
                                <option value="Front A/C">Front A/C</option>
                                <option value="Dual A/C">Dual A/C</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-1 flex gap-4">
                        <div class="w-full">
                            <label for="fixed_distance" class="block text-gray-700 font-semibold">Fixed Amount for ≤ <input type="number" step="1" name="fixed_distance" id="fixed_distance" value="25" class="w-16 border p-1 rounded-md text-center inline-block" /> km</label>
                            <input type="number" step="0.01" name="fixed_amount_25km" id="fixed_amount_25km"
                                class="w-full border p-2 rounded-md" />
                        </div>
                    </div>
                    <div class="mb-1 flex gap-4">
                        <div class="w-full flex items-end">
                             <button type="button" id="calculate_btn"
                                     class="w-full bg-green-500 text-white px-4 py-2 rounded-md hover:bg-green-600 transition">
                                 Calculate Amount
                             </button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-center gap-4 mt-4">
                    <a href="<?= BASE_URL ?>registers/Staff%20transport%20vehicle%20register.php"
                    class="bg-gray-300 text-gray-800 px-6 py-2 rounded hover:bg-gray-400 transition">
                        Cancel
                    </a>
                    
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="toast-container" class="fixed top-5 right-5 z-50"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const calculateBtn = document.getElementById('calculate_btn');
            const distanceInput = document.getElementById('distance_calc');
            const fixedDistanceInput = document.getElementById('fixed_distance');
            const fixedAmountInput = document.getElementById('fixed_amount_25km');
            const amountInput = document.getElementById('amount');
            const vehicleTypeSelect = document.getElementById('vehicle_type');

            const fuelRate = <?php echo json_encode($latest_fuel_rate); ?>;
            const distanceConsumptions = <?php echo json_encode($distance_consumptions); ?>;

            function calculateAndSetAmount() {
                const distance = parseFloat(distanceInput.value);
                const fixedDistance = parseFloat(fixedDistanceInput.value);
                const fixedAmount = parseFloat(fixedAmountInput.value);
                const vehicleType = vehicleTypeSelect.value;

                if (!vehicleType) {
                    showToast('Please select a vehicle type to use the calculator.', 'warning');
                    return;
                }
                
                if (isNaN(distance) || distance <= 0) {
                    showToast('Please enter a valid distance to use the calculator.', 'warning');
                    return;
                }
                
                if (distance <= fixedDistance) {
                    if (isNaN(fixedAmount) || fixedAmount <= 0) {
                         showToast('Please enter a valid fixed amount for distances.', 'warning');
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
                    const distancePerLiter = distanceConsumptions[vehicleType];
                    const fuelNeeded = (distance-fixedDistance) / distancePerLiter;
                    const calculatedAmount = fixedAmount + (fuelNeeded * fuelRate);
                    amountInput.value = calculatedAmount.toFixed(2);
                }
            }

            calculateBtn.addEventListener('click', calculateAndSetAmount);
        });

        function showToast(message, type = 'success') {
            const toastContainer = document.getElementById('toast-container');
            if (!toastContainer) return;

            const toast = document.createElement('div');
            toast.className = 'flex items-center w-full max-w-xs p-4 mb-4 text-gray-500 bg-white rounded-lg shadow-lg dark:text-gray-400 dark:bg-gray-800 transform transition-transform duration-500 ease-in-out translate-x-full';
            
            let iconHtml = '';
            if (type === 'success') {
                toast.classList.add('border-l-4', 'border-green-500');
                iconHtml = `<div class="inline-flex items-center justify-center flex-shrink-0 w-8 h-8 text-green-500 bg-green-100 rounded-lg dark:bg-green-800 dark:text-green-200"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg></div>`;
            } else if (type === 'error') {
                toast.classList.add('border-l-4', 'border-red-500');
                iconHtml = `<div class="inline-flex items-center justify-center flex-shrink-0 w-8 h-8 text-red-500 bg-red-100 rounded-lg dark:bg-red-800 dark:text-red-200"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg></div>`;
            } else if (type === 'warning') {
                toast.classList.add('border-l-4', 'border-yellow-500');
                iconHtml = `<div class="inline-flex items-center justify-center flex-shrink-0 w-8 h-8 text-yellow-500 bg-yellow-100 rounded-lg dark:bg-yellow-800 dark:text-yellow-200"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.365 2.503-1.365 3.268 0l7.288 12.915C19.537 17.568 18.067 19 16.495 19H3.505C1.933 19 .463 17.568 1.212 16.014l7.288-12.915zM10 5a1 1 0 011 1v4a1 1 0 01-2 0V6a1 1 0 011-1zm0 9a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd"></path></svg></div>`;
            }

            toast.innerHTML = `
                ${iconHtml}
                <div class="ml-3 text-sm font-normal">${message}</div>
                <button type="button" class="ml-auto -mx-1.5 -my-1.5 bg-white text-gray-400 hover:text-gray-900 rounded-lg focus:ring-2 focus:ring-gray-300 p-1.5 hover:bg-gray-100 inline-flex items-center justify-center h-8 w-8 dark:text-gray-500 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700" data-dismiss-target="#toast-success" aria-label="Close">
                    <span class="sr-only">Close</span>
                    <svg class="w-3 h-3" aria-hidden="true" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 1l6 6m0 0l6 6M7 7l6-6M7 7L1 13"></path></svg>
                </button>
            `;

            toastContainer.appendChild(toast);

            // Animate the toast in
            setTimeout(() => {
                toast.classList.remove('translate-x-full');
            }, 100);

            // Automatically hide the toast after 5 seconds
            setTimeout(() => {
                toast.classList.add('opacity-0', 'scale-90');
                setTimeout(() => {
                    toast.remove();
                }, 500); // Wait for the transition to finish before removing
            }, 2300);
        }
    </script>
</body>
</html>