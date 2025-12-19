<?php
require_once '../../includes/session_check.php';
// Start the session (ensure it's started before accessing session variables)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}
$user_role = $_SESSION['user_role'] ?? 'guest'; 
$allowed_roles = ['super admin', 'manager', 'developer'];
$is_authorized_for_lock = in_array($user_role, $allowed_roles); 

include('../../includes/db.php');

// Get the logged-in user's ID
$logged_in_user_id = $_SESSION['user_id'] ?? 0;

$toast_message = null;
$toast_type = null;

// FIX: Define $view_mode early to avoid 'Undefined variable' warnings in includes
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'list';

// --- AUDIT LOG FUNCTION ---
function log_general_audit_entry(
    $conn, 
    $tableName, 
    $recordId, 
    $actionType, 
    $userId, 
    $fieldName = null,  
    $oldValue = null,   
    $newValue = null    
) {
    if (!isset($conn) || $conn->connect_error) {
        error_log("Audit Log: Database connection is not valid for logging.");
        return;
    }

    $log_sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id, field_name, old_value, new_value, change_time) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

    $log_stmt = $conn->prepare($log_sql);

    if ($log_stmt === false) {
        error_log("General Audit Log Preparation Error: " . $conn->error);
        return;
    }
    
    $log_stmt->bind_param(
        "sssssss", 
        $tableName, 
        $recordId, 
        $actionType,
        $userId, 
        $fieldName, 
        $oldValue, 
        $newValue
    );
    
    if (!$log_stmt->execute()) {
        error_log("General Audit Log Execution Error: " . $log_stmt->error);
    }
    $log_stmt->close();
}
// --- END AUDIT LOG FUNCTION ---

// Function to fetch current consumption rates (Remains the same)
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

    // --- NEW: ADD NEW FUEL TYPE ---
    if (isset($_POST['action']) && $_POST['action'] == 'add_new_fuel_type') {
        $new_type_name = trim($_POST['new_type_name']);

        if (empty($new_type_name)) {
            $toast_message = "Fuel Type Name is required.";
            $toast_type = "error";
        } else {
            try {
                // 1. Find the next available rate_id (MAX + 1)
                $max_id_result = $conn->query("SELECT MAX(rate_id) AS max_id FROM fuel_rate");
                $max_id_row = $max_id_result->fetch_assoc();
                // If table is empty, start from 1. Otherwise, MAX + 1.
                $next_rate_id = ($max_id_row['max_id'] === null) ? 1 : $max_id_row['max_id'] + 1;

                // 2. Insert the new type with rate 0 and current date
                // This creates the stable ID and the initial history row
                $stmt_new_type = $conn->prepare("INSERT INTO fuel_rate (rate_id, type, rate, date) VALUES (?, ?, ?, ?)");
                $initial_rate = 0.00;
                // Using NOW() for the actual insertion time in the database, 
                // but setting a date for the entry itself if needed, 
                // using current date for consistency
                $current_date = date('Y-m-d H:i:s');
                
                // Binding parameters: (Integer, String, Double, String)
                $stmt_new_type->bind_param("isds", $next_rate_id, $new_type_name, $initial_rate, $current_date);
                
                if ($stmt_new_type->execute()) {
                    
                    // 3. --- AUDIT LOG ---
                    $new_record_id = $conn->insert_id;
                    
                    log_general_audit_entry(
                        $conn, 
                        'fuel_rate', 
                        (string)$new_record_id, 
                        'CREATE',              
                        $logged_in_user_id,
                        'new_type', 
                        null, 
                        htmlspecialchars($new_type_name) . " (ID: " . $next_rate_id . ")"
                    );
                    // --- END AUDIT LOG ---

                    $toast_message = "New Fuel Type '" . htmlspecialchars($new_type_name) . "' added successfully with ID " . $next_rate_id . ". Please set the actual rate now using 'Set New Rate'.";
                    $toast_type = "success";
                } else {
                    // Check for potential duplicate entry (if a constraint is used on 'type')
                    if ($conn->errno == 1062) { 
                         throw new Exception("Error: Fuel Type '" . htmlspecialchars($new_type_name) . "' might already exist.");
                    }
                    throw new Exception("Error saving new fuel type: " . $stmt_new_type->error);
                }
                $stmt_new_type->close();
            } catch (Exception $e) {
                $toast_message = "Error: " . $e->getMessage();
                $toast_type = "error";
            }
        }
    }


    // --- INSERT NEW RATE FOR HISTORY (Use rate_id as stable type ID) ---
    if (isset($_POST['action']) && $_POST['action'] == 'update_fuel_rate') {
        $new_rate = $_POST['new_fuel_rate'];
        $fuel_type = $_POST['fuel_type_name']; // Get the Type Name (e.g., Lanka Auto Diesel)
        $fuel_rate_id = $_POST['fuel_rate_id']; // Get the Stable ID (e.g., 1, 2, 3, 4)
        $rate_date = $_POST['rate_date'] ?? date('Y-m-d'); 
        $is_valid_to_proceed = true;

        if (!is_numeric($fuel_rate_id) || empty($new_rate) || empty($fuel_type) || empty($rate_date)) {
            $toast_message = "All fields are required and Fuel Type ID must be valid.";
            $toast_type = "error";
            $is_valid_to_proceed = false;
        } else {
            // --- LOCK VALIDATION CHECK ---
            try {
                $check_year = date('Y', strtotime($rate_date));
                $check_month = date('n', strtotime($rate_date)); 

                $check_lock_stmt = $conn->prepare("SELECT is_locked FROM month_locks WHERE year = ? AND month = ? AND is_locked = 1");
                $check_lock_stmt->bind_param("ii", $check_year, $check_month);
                $check_lock_stmt->execute();
                $lock_result = $check_lock_stmt->get_result();
                
                if ($lock_result->num_rows > 0) {
                    $toast_message = "Error: You cannot set a new rate for " . date('F Y', strtotime($rate_date)) . " because the month is LOCKED. Please contact a system administrator.";
                    $toast_type = "error";
                    $is_valid_to_proceed = false;
                }
                $check_lock_stmt->close();

            } catch (Exception $e) {
                error_log("Lock validation error: " . $e->getMessage());
                $toast_message = "Internal validation error occurred.";
                $toast_type = "error";
                $is_valid_to_proceed = false;
            }
            // --- END LOCK VALIDATION CHECK ---
        }

        // Only proceed with INSERT if all validation checks passed
        if ($is_valid_to_proceed) {
            try {
                // 1. USE INSERT INTO to create a history record
                $stmt_rate = $conn->prepare("INSERT INTO fuel_rate (rate_id, type, rate, date) VALUES (?, ?, ?, ?)");
                
                // Binding parameters: (Integer, String, Double, String)
                $stmt_rate->bind_param("isds", $fuel_rate_id, $fuel_type, $new_rate, $rate_date);
                
                if ($stmt_rate->execute()) {
                    
                    // 2. --- AUDIT LOG: Insert New Rate ---
                    $new_record_id = $conn->insert_id; 
                    
                    log_general_audit_entry(
                        $conn, 
                        'fuel_rate', 
                        (string)$new_record_id, 
                        'CREATE',
                        $logged_in_user_id,
                        'rate', 
                        null, 
                        (string)$new_rate
                    );
                    // --- END AUDIT LOG ---

                    $toast_message = "New Fuel rate set successfully for " . htmlspecialchars($fuel_type) . " (Effective Date: " . $rate_date . ")!";
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

    // --- Update Distance Values (Remains unchanged) ---
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

// Fetch the LATEST rate for each unique fuel type (rate_id)
$all_fuel_rates = [];
// This query finds the MAX(date) for each unique rate_id (the fuel type ID)
$sql_rates = "SELECT fr1.rate_id, fr1.type, fr1.rate, fr1.date
              FROM fuel_rate fr1
              INNER JOIN (
                  SELECT rate_id, MAX(date) AS max_date
                  FROM fuel_rate
                  GROUP BY rate_id
              ) fr2 ON fr1.rate_id = fr2.rate_id AND fr1.date = fr2.max_date
              ORDER BY fr1.rate_id"; 
              
$result_rates = $conn->query($sql_rates);
if ($result_rates->num_rows > 0) {
    while ($row = $result_rates->fetch_assoc()) {
        $all_fuel_rates[] = $row;
    }
}

// Fetch Type data for setting a new rate (Used in the 'set_new_rate' view)
$rate_id_to_set = null;
$type_name_to_set = null;

if ($view_mode == 'set_new_rate' && isset($_GET['rate_id'])) {
    $id = (int)$_GET['rate_id'];
    // Find the type name and stable ID from the fetched list
    foreach ($all_fuel_rates as $rate) {
        if ($rate['rate_id'] == $id) {
            $rate_id_to_set = $id;
            $type_name_to_set = $rate['type'];
            break;
        }
    }
}

$current_distances = getConsumptionRates($conn);

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
<body class="bg-gray-100 text-gray-800">
    <div class="flex justify-center items-center w-[85%] ml-[15%] h-screen ">
        <div class="w-full max-w-5xl mx-auto p-6 bg-white rounded-lg shadow-md">
            <h2 class="text-3xl font-bold mb-6 text-center text-blue-600">Fuel & Distance Rates</h2>

            <div class="flex justify-center gap-4 mb-6">
                <a href="fuel.php?view=list" class="bg-blue-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-blue-700 transition-colors flex items-center justify-center">
                    View Current Fuel Rates
                </a>
                <a href="fuel.php?view=add_new_type" class="bg-yellow-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-yellow-700 transition-colors flex items-center justify-center">
                    Add New Fuel Type
                </a>
                <?php if ($is_authorized_for_lock): ?>
                    <a href="month_locks.php" class="bg-red-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-red-700 transition-colors flex items-center justify-center">
                        🔐 Month Lock Management
                    </a>
                <?php endif; ?>
                <a href="distance_per_liter.php" class="bg-green-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-green-700 transition-colors flex items-center justify-center">
                    Update Distance
                </a>
                
            </div>

            <hr class="my-6">

            <?php if ($view_mode == 'list'): ?>
                <div class="current-rates-display p-6 border border-blue-200 rounded-lg bg-blue-50">
                    <h3 class="text-xl font-semibold mb-4 text-blue-800">Current Fuel Rates</h3>
                    <?php if ($all_fuel_rates): ?>
                        <div class="overflow-x-auto rounded-lg">
                            <table class="min-w-full bg-white border border-gray-200 shadow-sm">
                                <thead class="bg-gray-200">
                                    <tr>
                                        <th class="py-2 px-4 border-b text-left">Type ID</th>
                                        <th class="py-2 px-4 border-b text-left">Type</th>
                                        <th class="py-2 px-4 border-b text-left">Rate (Rs.)</th>
                                        <th class="py-2 px-4 border-b text-left">Effective Date</th>
                                        <th class="py-2 px-4 border-b text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_fuel_rates as $rate): ?>
                                        <tr class="hover:bg-gray-100 transition-colors">
                                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($rate['rate_id']); ?></td>
                                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($rate['type']); ?></td>
                                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($rate['rate']); ?></td>
                                            <td class="py-2 px-4 border-b"><?php echo date('Y-m-d', strtotime($rate['date'])); ?></td>
                                            <td class="py-2 px-4 border-b text-center">
                                                <a href="fuel.php?view=set_new_rate&rate_id=<?php echo $rate['rate_id']; ?>" class="bg-purple-500 text-white px-3 py-1 rounded-md text-sm hover:bg-purple-600">Set New Rate</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-500">No fuel rates found. Please ensure your database is populated with initial fuel types and rates.</p>
                        <div class="mt-4 text-center">
                            <a href="fuel.php?view=add_new_type" class="bg-green-500 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-green-600 transition-colors">
                                Click here to add your first Fuel Type
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($view_mode == 'add_new_type'): ?>
                <div class="update-form p-6 rounded-lg border border-gray-300">
                    <h3 class="text-xl font-semibold mb-2 text-gray-800">Add New Fuel Type</h3>
                    <p class="text-sm text-gray-600 mb-4">Enter the name of the new fuel type. It will be assigned an initial rate of 0.00.</p>
                    <form action="fuel.php" method="POST">
                        <input type="hidden" name="action" value="add_new_fuel_type">
                        
                        <div class="mb-4">
                            <label for="new_type_name" class="block text-gray-700 font-medium mb-1">Fuel Type Name (e.g., Super Diesel):</label>
                            <input type="text" id="new_type_name" name="new_type_name" required
                                class="w-full px-4 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                placeholder="Enter the new fuel type name">
                        </div>
                        
                        <div class="flex justify-end space-x-4">
                            <a href="fuel.php?view=list" class="bg-gray-400 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-gray-500 transition-colors">Cancel</a>
                            <button type="submit" class="bg-yellow-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-yellow-700 transition-colors">
                                Save New Type
                            </button>
                        </div>
                    </form>
                </div>
                <?php elseif ($view_mode == 'set_new_rate'): ?>
                <div class="update-form p-6 rounded-lg border border-gray-300">
                    <h3 class="text-xl font-semibold mb-2 text-gray-800">Set New Rate for <?php echo htmlspecialchars($type_name_to_set); ?> (ID: <?php echo htmlspecialchars($rate_id_to_set); ?>)</h3>
                    <?php if ($rate_id_to_set): ?>
                        <form action="fuel.php" method="POST">
                            <input type="hidden" name="action" value="update_fuel_rate">
                            <input type="hidden" name="fuel_rate_id" value="<?php echo htmlspecialchars($rate_id_to_set); ?>">
                            <input type="hidden" name="fuel_type_name" value="<?php echo htmlspecialchars($type_name_to_set); ?>">
                            
                            <div class="mb-4">
                                <label for="rate_date" class="block text-gray-700 font-medium mb-1">Effective Date (yyyy-mm-dd):</label>
                                <input type="date" id="rate_date" name="rate_date" required
                                    class="w-full px-4 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    value="<?php echo date('Y-m-d'); ?>">
                                <p class="text-sm text-gray-500 mt-1">Select the date this new rate should be applied from.</p>
                            </div>
                            
                            <div class="mb-4">
                                <label for="fuel_type_display" class="block text-gray-700 font-medium mb-1">Fuel Type:</label>
                                <input type="text" id="fuel_type_display" 
                                    class="w-full px-4 py-2 border border-gray-400 rounded-lg bg-gray-100 focus:outline-none" 
                                    value="<?php echo htmlspecialchars($type_name_to_set); ?>" readonly>
                            </div>
                            <div class="mb-4">
                                <label for="new_fuel_rate" class="block text-gray-700 font-medium mb-1">New Fuel Rate (Rs.):</label>
                                <input type="number" step="0.01" id="new_fuel_rate" name="new_fuel_rate" required
                                    class="w-full px-4 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    placeholder="Enter the new rate">
                            </div>
                            <div class="flex justify-end space-x-4">
                                <a href="fuel.php?view=list" class="bg-gray-400 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-gray-500 transition-colors">Cancel</a>
                                <button type="submit" class="bg-blue-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-blue-700 transition-colors">
                                    Add New Rate
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="text-red-500">Error: Fuel type not specified or invalid ID.</p>
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
                
                // Redirect back to list view after a successful INSERT
                if ("<?php echo $toast_type; ?>" === 'success') {
                    setTimeout(() => window.location.href = 'fuel.php?view=list', 3000);
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>