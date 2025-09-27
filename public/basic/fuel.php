<?php
include('../../includes/db.php');

$toast_message = null;
$toast_type = null;

// Function to fetch current consumption rates
function getConsumptionRates($conn) {
    $rates = [];
    try {
        $stmt = $conn->prepare("SELECT c_type, distance FROM consumption");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rates[$row['c_type']] = $row['distance'];
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching consumption rates: " . $e->getMessage());
        return null;
    }
    return $rates;
}

// Handle form submissions (POST requests) for database updates
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- Update Fuel Rate ---
    if (isset($_POST['action']) && $_POST['action'] == 'update_fuel_rate') {
        $new_rate = $_POST['new_fuel_rate'];
        $fuel_type = $_POST['fuel_type'];
        $rate_id = $_POST['rate_id'] ?? null;

        if (empty($new_rate) || empty($fuel_type) || empty($rate_id)) {
            $toast_message = "Fuel rate, type, and ID are required for update.";
            $toast_type = "error";
        } else {
            try {
                // Update existing record
                $stmt_rate = $conn->prepare("UPDATE fuel_rate SET type = ?, rate = ?, date = NOW() WHERE rate_id = ?");
                $stmt_rate->bind_param("sdi", $fuel_type, $new_rate, $rate_id);
                
                if ($stmt_rate->execute()) {
                    $toast_message = "Fuel rate updated successfully!";
                    $toast_type = "success";
                } else {
                    throw new Exception("Error saving fuel rate: " . $stmt_rate->error);
                }
                $stmt_rate->close();
            } catch (Exception $e) {
                $toast_message = "Error: " . $e->getMessage();
                $toast_type = "error";
            }
        }
    }

    // --- Update Distance Values ---
    if (isset($_POST['action']) && $_POST['action'] == 'update_distance') {
        $non_ac_distance = $_POST['non_ac_distance'];
        $front_ac_distance = $_POST['front_ac_distance'];
        $dual_ac_distance = $_POST['dual_ac_distance'];
        if (empty($non_ac_distance) || empty($front_ac_distance) || empty($dual_ac_distance)) {
            $toast_message = "All distance fields are required.";
            $toast_type = "error";
        } else {
            try {
                $consumption_values = [
                    'Non A/C' => $non_ac_distance,
                    'Front A/C' => $front_ac_distance,
                    'Dual A/C' => $dual_ac_distance
                ];
                $stmt_consumption = $conn->prepare("INSERT INTO consumption (c_type, distance) VALUES (?, ?) ON DUPLICATE KEY UPDATE distance = VALUES(distance)");
                foreach ($consumption_values as $type => $distance) {
                    $stmt_consumption->bind_param("sd", $type, $distance);
                    if (!$stmt_consumption->execute()) {
                        throw new Exception("Error updating distance for " . $type . ": " . $stmt_consumption->error);
                    }
                }
                $stmt_consumption->close();
                $toast_message = "Distance values updated successfully!";
                $toast_type = "success";
            } catch (Exception $e) {
                $toast_message = "Error: " . $e->getMessage();
                $toast_type = "error";
            }
        }
    }
}

// Fetch all data needed for the page regardless of the view
$all_fuel_rates = [];
$sql_rates = "SELECT rate_id, rate, type, date FROM fuel_rate ORDER BY rate_id";
$result_rates = $conn->query($sql_rates);
if ($result_rates->num_rows > 0) {
    while ($row = $result_rates->fetch_assoc()) {
        $all_fuel_rates[] = $row;
    }
}

// Fetch a single fuel rate for editing
$rate_to_edit = null;
if (isset($_GET['view']) && $_GET['view'] == 'edit_fuel' && isset($_GET['rate_id'])) {
    $id = $_GET['rate_id'];
    $stmt = $conn->prepare("SELECT rate_id, rate, type, date FROM fuel_rate WHERE rate_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rate_to_edit = $result->fetch_assoc();
    $stmt->close();
}

$current_distances = getConsumptionRates($conn);

$view_mode = isset($_GET['view']) ? $_GET['view'] : 'list';

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel & Distance</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
<body class="bg-gray-100 text-gray-800">
    <div class="flex justify-center items-center w-[85%] ml-[15%] h-screen ">
        <div class="w-full max-w-4xl mx-auto p-6 bg-white rounded-lg shadow-md">
            <h2 class="text-3xl font-bold mb-6 text-center text-blue-600">Fuel & Distance Rates</h2>

            <div class="flex justify-center gap-4 mb-6">
                <p class="bg-gray-300 text-white font-bold py-2 px-4 rounded-lg shadow-md transition-colors flex items-center justify-center">
                    View All Fuel Rates
                </p>
                <a href="distance_per_liter.php" class="bg-green-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-green-700 transition-colors flex items-center justify-center">
                    Update Distance
                </a>
            </div>

            <hr class="my-6">

            <?php if ($view_mode == 'list'): ?>
                <div class="current-rates-display p-6 border border-blue-200 rounded-lg bg-blue-50">
                    <h3 class="text-xl font-semibold mb-4 text-blue-800">All Fuel Rates</h3>
                    <?php if ($all_fuel_rates): ?>
                        <div class="overflow-x-auto rounded-lg">
                            <table class="min-w-full bg-white border border-gray-200 shadow-sm">
                                <thead class="bg-gray-200">
                                    <tr>
                                        <th class="py-2 px-4 border-b text-left">Type</th>
                                        <th class="py-2 px-4 border-b text-left">Rate (Rs.)</th>
                                        <th class="py-2 px-4 border-b text-left">Date</th>
                                        <th class="py-2 px-4 border-b text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_fuel_rates as $rate): ?>
                                        <tr class="hover:bg-gray-100 transition-colors">
                                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($rate['type']); ?></td>
                                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($rate['rate']); ?></td>
                                            <td class="py-2 px-4 border-b"><?php echo date('Y-m-d H:i', strtotime($rate['date'])); ?></td>
                                            <td class="py-2 px-4 border-b text-center">
                                                <a href="fuel.php?view=edit_fuel&rate_id=<?php echo $rate['rate_id']; ?>" class="bg-green-500 text-white px-3 py-1 rounded-md text-sm hover:bg-yellow-600">Edit</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-500">No fuel rates found.</p>
                    <?php endif; ?>
                </div>

            <?php elseif ($view_mode == 'edit_fuel'): ?>
                <div class="update-form p-6 rounded-lg border border-gray-300">
                    <h3 class="text-xl font-semibold mb-2 text-gray-800">Edit Fuel Rate</h3>
                    <?php if ($rate_to_edit): ?>
                        <form action="fuel.php" method="POST">
                            <input type="hidden" name="action" value="update_fuel_rate">
                            <input type="hidden" name="rate_id" value="<?php echo htmlspecialchars($rate_to_edit['rate_id']); ?>">
                            
                            <div class="mb-4">
                                <label for="fuel_type" class="block text-gray-700 font-medium mb-1">Fuel Type:</label>
                                <input type="text" id="fuel_type" name="fuel_type" 
                                    class="w-full px-4 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                    value="<?php echo htmlspecialchars($rate_to_edit['type']); ?>" readonly>
                            </div>
                            <div class="mb-4">
                                <label for="new_fuel_rate" class="block text-gray-700 font-medium mb-1">Fuel Rate (Rs.):</label>
                                <input type="number" step="0.01" id="new_fuel_rate" name="new_fuel_rate" required
                                    class="w-full px-4 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    value="<?php echo htmlspecialchars($rate_to_edit['rate']); ?>">
                            </div>
                            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-blue-700 transition-colors">
                                Save Changes
                            </button>
                        </form>
                    <?php else: ?>
                        <p class="text-red-500">Error: Fuel rate not found.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
        </div>
    </div>
    
    <div id="toast-container"></div>
    <script>
        function showToast(message, type = 'success') {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16">
                                ${type === 'success' ? 
                                    '<path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.293 12.5a1.003 1.003 0 0 1-1.417 0L2.354 8.7a.733.733 0 0 1 1.047-1.05l3.245 3.246 6.095-6.094z"/>' :
                                    '<path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/> <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>'
                                }
                            </svg>
                            <p class="font-semibold">${message}</p>`;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => toast.classList.remove('show'), 3000);
            setTimeout(() => toast.remove(), 3500);
        }

        <?php if (isset($toast_message) && isset($toast_type)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast("<?php echo htmlspecialchars($toast_message, ENT_QUOTES, 'UTF-8'); ?>", "<?php echo htmlspecialchars($toast_type, ENT_QUOTES, 'UTF-8'); ?>");
            });
        <?php endif; ?>
    </script>
</body>
</html>