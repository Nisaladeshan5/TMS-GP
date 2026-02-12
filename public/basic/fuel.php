<?php
require_once '../../includes/session_check.php';
// Start the session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

$user_role = $_SESSION['user_role'] ?? 'guest'; 
$allowed_roles = ['admin', 'super admin', 'manager', 'developer'];
$is_authorized_for_lock = in_array($user_role, $allowed_roles); 

include('../../includes/db.php');

$logged_in_user_id = $_SESSION['user_id'] ?? 0;
$toast_message = null;
$toast_type = null;
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'list';

// --- AUDIT LOG FUNCTION ---
function log_general_audit_entry($conn, $tableName, $recordId, $actionType, $userId, $fieldName = null, $oldValue = null, $newValue = null) {
    if (!isset($conn) || $conn->connect_error) return;
    $log_sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id, field_name, old_value, new_value, change_time) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    if ($log_stmt) {
        $log_stmt->bind_param("sssssss", $tableName, $recordId, $actionType, $userId, $fieldName, $oldValue, $newValue);
        $log_stmt->execute();
        $log_stmt->close();
    }
}

// Handle Form Submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1. ADD NEW FUEL TYPE
    if (isset($_POST['action']) && $_POST['action'] == 'add_new_fuel_type') {
        $new_type_name = trim($_POST['new_type_name']);
        if (empty($new_type_name)) {
            $toast_message = "Fuel Type Name is required.";
            $toast_type = "error";
        } else {
            try {
                $max_id_result = $conn->query("SELECT MAX(rate_id) AS max_id FROM fuel_rate");
                $max_id_row = $max_id_result->fetch_assoc();
                $next_rate_id = ($max_id_row['max_id'] === null) ? 1 : $max_id_row['max_id'] + 1;

                $stmt_new_type = $conn->prepare("INSERT INTO fuel_rate (rate_id, type, rate, date) VALUES (?, ?, ?, ?)");
                $initial_rate = 0.00;
                $current_date = date('Y-m-d H:i:s');
                $stmt_new_type->bind_param("isds", $next_rate_id, $new_type_name, $initial_rate, $current_date);
                
                if ($stmt_new_type->execute()) {
                    $new_record_id = $conn->insert_id;
                    log_general_audit_entry($conn, 'fuel_rate', (string)$new_record_id, 'CREATE', $logged_in_user_id, 'new_type', null, htmlspecialchars($new_type_name));
                    
                    $toast_message = "New Fuel Type '" . htmlspecialchars($new_type_name) . "' added successfully!";
                    $toast_type = "success";
                } else {
                    if ($conn->errno == 1062) throw new Exception("Fuel Type might already exist.");
                    throw new Exception($stmt_new_type->error);
                }
                $stmt_new_type->close();
            } catch (Exception $e) {
                $toast_message = "Error: " . $e->getMessage();
                $toast_type = "error";
            }
        }
    }

    // 2. UPDATE FUEL RATE
    if (isset($_POST['action']) && $_POST['action'] == 'update_fuel_rate') {
        $new_rate = $_POST['new_fuel_rate'];
        $fuel_type = $_POST['fuel_type_name']; 
        $fuel_rate_id = $_POST['fuel_rate_id']; 
        $rate_date = $_POST['rate_date'] ?? date('Y-m-d'); 
        $is_valid_to_proceed = true;

        // Check Lock
        try {
            $check_year = date('Y', strtotime($rate_date));
            $check_month = date('n', strtotime($rate_date)); 
            $check_lock_stmt = $conn->prepare("SELECT is_locked FROM month_locks WHERE year = ? AND month = ? AND is_locked = 1");
            $check_lock_stmt->bind_param("ii", $check_year, $check_month);
            $check_lock_stmt->execute();
            if ($check_lock_stmt->get_result()->num_rows > 0) {
                $toast_message = "Error: Month is LOCKED.";
                $toast_type = "error";
                $is_valid_to_proceed = false;
            }
            $check_lock_stmt->close();
        } catch (Exception $e) { $is_valid_to_proceed = false; }

        if ($is_valid_to_proceed) {
            try {
                $stmt_rate = $conn->prepare("INSERT INTO fuel_rate (rate_id, type, rate, date) VALUES (?, ?, ?, ?)");
                $stmt_rate->bind_param("isds", $fuel_rate_id, $fuel_type, $new_rate, $rate_date);
                if ($stmt_rate->execute()) {
                    $new_record_id = $conn->insert_id; 
                    log_general_audit_entry($conn, 'fuel_rate', (string)$new_record_id, 'CREATE', $logged_in_user_id, 'rate', null, (string)$new_rate);
                    $toast_message = "New rate set successfully for " . htmlspecialchars($fuel_type);
                    $toast_type = "success";
                } else { throw new Exception($stmt_rate->error); }
                $stmt_rate->close();
            } catch (Exception $e) {
                $toast_message = "Error: " . $e->getMessage();
                $toast_type = "error";
            }
        }
    }
}

// Fetch Rates
$all_fuel_rates = [];
$sql_rates = "SELECT fr1.rate_id, fr1.type, fr1.rate, fr1.date FROM fuel_rate fr1 INNER JOIN (SELECT rate_id, MAX(date) AS max_date FROM fuel_rate GROUP BY rate_id) fr2 ON fr1.rate_id = fr2.rate_id AND fr1.date = fr2.max_date ORDER BY fr1.rate_id"; 
$result_rates = $conn->query($sql_rates);
if ($result_rates->num_rows > 0) { while ($row = $result_rates->fetch_assoc()) { $all_fuel_rates[] = $row; } }

// Setup for Edit View
$rate_id_to_set = null;
$type_name_to_set = null;
if ($view_mode == 'set_new_rate' && isset($_GET['rate_id'])) {
    foreach ($all_fuel_rates as $rate) {
        if ($rate['rate_id'] == (int)$_GET['rate_id']) {
            $rate_id_to_set = $rate['rate_id'];
            $type_name_to_set = $rate['type'];
            break;
        }
    }
}

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Management</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
        
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; color: white; min-width: 300px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
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
            Fuel
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="fuel_history.php" class="text-gray-300 hover:text-white transition group relative" title="History">
            <i class="fas fa-history text-lg"></i>
            <span class="absolute -bottom-8 left-1/2 transform -translate-x-1/2 w-max bg-gray-800 text-white text-xs rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity">History</span>
        </a>
        
        <a href="distance_per_liter.php" class="text-gray-300 hover:text-white transition group relative" title="Update Distance">
            <i class="fas fa-road text-lg"></i>
            <span class="absolute -bottom-8 left-1/2 transform -translate-x-1/2 w-max bg-gray-800 text-white text-xs rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity">Distance</span>
        </a>

        <?php if ($is_authorized_for_lock): ?>
            <a href="month_locks.php" class="text-gray-300 hover:text-red-400 transition group relative" title="Month Locks">
                <i class="fas fa-lock text-lg"></i>
                <span class="absolute -bottom-8 left-1/2 transform -translate-x-1/2 w-max bg-gray-800 text-white text-xs rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity">Locks</span>
            </a>
        <?php endif; ?>

        <span class="text-gray-600">|</span>

        <a href="fuel.php?view=add_new_type" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-blue-500">
            Add Fuel Type
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 px-2 min-h-screen flex flex-col items-center">
    
    <div class="w-full">
        
        <?php if ($view_mode == 'list'): ?>
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                
                <?php if ($all_fuel_rates): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-blue-600 text-white uppercase text-xs tracking-wider">
                                <tr>
                                    <th class="px-6 py-3 font-semibold text-center w-24">ID</th>
                                    <th class="px-6 py-3 font-semibold">Fuel Type</th>
                                    <th class="px-6 py-3 font-semibold text-right">Current Rate</th>
                                    <th class="px-6 py-3 font-semibold text-center">Effective From</th>
                                    <th class="px-6 py-3 font-semibold text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($all_fuel_rates as $rate): ?>
                                    <tr class="hover:bg-indigo-50/60 transition duration-150 group">
                                        <td class="px-6 py-4 text-center font-mono text-gray-400 group-hover:text-blue-500">
                                            #<?php echo htmlspecialchars($rate['rate_id']); ?>
                                        </td>
                                        <td class="px-6 py-4 font-medium text-gray-800 flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                                <i class="fas fa-gas-pump text-sm"></i>
                                            </div>
                                            <?php echo htmlspecialchars($rate['type']); ?>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <span class="font-bold text-emerald-600 font-mono text-base bg-emerald-50 px-2 py-1 rounded border border-emerald-100">
                                                Rs. <?php echo number_format((float)$rate['rate'], 2); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-center text-gray-500 text-xs">
                                            <i class="far fa-calendar-alt mr-1"></i> 
                                            <?php echo date('Y-M-d', strtotime($rate['date'])); ?>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <a href="fuel.php?view=set_new_rate&rate_id=<?php echo $rate['rate_id']; ?>" 
                                               class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 hover:bg-yellow-50 hover:border-yellow-300 hover:text-yellow-700 px-3 py-1.5 rounded-md shadow-sm text-xs font-semibold transition-all">
                                                <i class="fas fa-edit"></i> Update
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-16 text-center text-gray-400 flex flex-col items-center">
                        <div class="bg-gray-100 p-4 rounded-full mb-4">
                            <i class="fas fa-gas-pump text-4xl text-gray-300"></i>
                        </div>
                        <p class="text-lg font-medium text-gray-600">No fuel rates found.</p>
                        <p class="text-sm text-gray-400 mb-6">Get started by adding your first fuel type.</p>
                        <a href="fuel.php?view=add_new_type" class="bg-blue-600 text-white px-5 py-2 rounded-lg font-bold shadow hover:bg-blue-700 transition">
                            Add Fuel Type
                        </a>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($view_mode == 'add_new_type'): ?>
            <div class="max-w-xl mx-auto bg-white rounded-xl shadow-xl border border-gray-200 mt-10 overflow-hidden">
                <div class="px-8 py-6 border-b border-gray-100 bg-gray-50">
                    <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-plus-circle text-blue-500"></i> Add New Fuel Type
                    </h3>
                    <p class="text-sm text-gray-500 mt-1 ml-7">Create a new fuel category for the system.</p>
                </div>
                
                <form action="fuel.php" method="POST" class="p-8">
                    <input type="hidden" name="action" value="add_new_fuel_type">
                    
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="new_type_name">
                            Fuel Type Name <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-tag text-gray-400"></i>
                            </div>
                            <input type="text" id="new_type_name" name="new_type_name" required
                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition text-gray-700"
                                   placeholder="e.g., Super Diesel / 95 Octane">
                        </div>
                        <p class="text-xs text-gray-400 mt-2 flex items-center gap-1">
                            <i class="fas fa-info-circle"></i> Initial rate will be set to 0.00 automatically.
                        </p>
                    </div>
                    
                    <div class="flex justify-between gap-3 pt-4 border-t border-gray-100">
                        <a href="fuel.php?view=list" class="px-5 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-lg font-semibold hover:bg-gray-50 transition shadow-sm">Cancel</a>
                        <button type="submit" class="px-5 py-2.5 bg-blue-600 text-white rounded-lg font-bold shadow-md hover:bg-blue-700 transition transform hover:scale-105 flex items-center gap-2">
                            Save Type
                        </button>
                    </div>
                </form>
            </div>

        <?php elseif ($view_mode == 'set_new_rate'): ?>
            <div class="max-w-xl mx-auto bg-white rounded-xl shadow-xl border border-gray-200 mt-10 overflow-hidden">
                <div class="px-8 py-6 border-b border-gray-100 bg-gray-50">
                    <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-coins text-yellow-500"></i> Update Fuel Price
                    </h3>
                    <p class="text-sm text-gray-500 mt-1 ml-7">Set a new effective rate for the selected fuel type.</p>
                </div>
                
                <?php if ($rate_id_to_set): ?>
                    <form action="fuel.php" method="POST" class="p-8">
                        <input type="hidden" name="action" value="update_fuel_rate">
                        <input type="hidden" name="fuel_rate_id" value="<?php echo htmlspecialchars($rate_id_to_set); ?>">
                        <input type="hidden" name="fuel_type_name" value="<?php echo htmlspecialchars($type_name_to_set); ?>">
                        
                        <div class="mb-5">
                            <label class="block text-gray-700 text-sm font-bold mb-2">Fuel Type</label>
                            <div class="w-full px-4 py-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-600 font-medium flex items-center gap-2">
                                <i class="fas fa-gas-pump text-gray-400"></i>
                                <?php echo htmlspecialchars($type_name_to_set); ?>
                            </div>
                        </div>

                        <div class="mb-5">
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="rate_date">Effective Date <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-calendar text-gray-400"></i>
                                </div>
                                <input type="date" id="rate_date" name="rate_date" required value="<?php echo date('Y-m-d'); ?>"
                                       class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 outline-none transition text-gray-700">
                            </div>
                        </div>

                        <div class="mb-8">
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="new_fuel_rate">New Rate (Rs.) <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500 font-bold">Rs.</span>
                                </div>
                                <input type="number" step="0.01" id="new_fuel_rate" name="new_fuel_rate" required placeholder="0.00"
                                       class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 outline-none font-mono text-lg font-bold text-gray-800">
                            </div>
                        </div>
                        
                        <div class="flex justify-between gap-3 pt-4 border-t border-gray-100">
                            <a href="fuel.php?view=list" class="px-5 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-lg font-semibold hover:bg-gray-50 transition shadow-sm">Cancel</a>
                            <button type="submit" class="px-5 py-2.5 bg-yellow-500 text-white rounded-lg font-bold shadow-md hover:bg-yellow-600 transition transform hover:scale-105 flex items-center gap-2">
                                Update Rate
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="p-8 text-center">
                        <div class="bg-red-50 p-4 rounded-full mb-3 inline-block">
                            <i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i>
                        </div>
                        <p class="text-red-600 font-medium">Invalid Fuel Type selection.</p>
                        <a href="fuel.php" class="text-blue-600 hover:underline mt-2 inline-block text-sm">Go Back to List</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<div id="toast-container"></div>

<script>
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const icon = type === 'success' 
            ? '<i class="fas fa-check-circle toast-icon"></i>' 
            : '<i class="fas fa-exclamation-circle toast-icon"></i>';
            
        toast.innerHTML = `${icon} <span class="font-medium">${message}</span>`;
        
        container.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    <?php if (isset($toast_message) && isset($toast_type)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showToast("<?php echo htmlspecialchars($toast_message); ?>", "<?php echo htmlspecialchars($toast_type); ?>");
            <?php if ($toast_type === 'success' && $view_mode !== 'list'): ?>
                setTimeout(() => window.location.href = 'fuel.php?view=list', 2000);
            <?php endif; ?>
        });
    <?php endif; ?>
</script>

</body>
</html>

<?php $conn->close(); ?>