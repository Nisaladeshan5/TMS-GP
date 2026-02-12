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

include('../../includes/db.php');

// Get the logged-in user's ID
$logged_in_user_id = $_SESSION['user_id'] ?? 0;

$toast_message = null;
$toast_type = null;

// --- AUDIT LOG FUNCTION ---
function log_general_audit_entry($conn, $tableName, $recordId, $actionType, $userId, $fieldName = null, $oldValue = null, $newValue = null) {
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
    
    // Convert vars to string for binding
    $record_id_str = (string)$recordId;
    $user_id_str = (string)$userId;

    $log_stmt->bind_param(
        "sssssss", 
        $tableName, 
        $record_id_str, 
        $actionType,
        $user_id_str, 
        $fieldName, 
        $oldValue, 
        $newValue
    );
    
    if (!$log_stmt->execute()) {
        error_log("General Audit Log Execution Error: " . $log_stmt->error);
    }
    $log_stmt->close();
}

// Fetch all consumption rows
function getAllConsumption($conn) {
    $rows = [];
    try {
        $sql = "SELECT c_id, c_type, distance FROM consumption ORDER BY c_id";
        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
    } catch (Throwable $e) {
        error_log("Error fetching consumption rows: " . $e->getMessage());
    }
    return $rows;
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // --- Handle individual distance update ---
    if (isset($_POST['action']) && $_POST['action'] === 'update_distance_individual') {
        $c_id = filter_var($_POST['c_id'] ?? '', FILTER_VALIDATE_INT);
        $distance = filter_var($_POST['distance'] ?? '', FILTER_VALIDATE_FLOAT);

        if ($c_id !== false && $distance !== false) {
            try {
                // Fetch OLD rate for audit
                $old_distance = null;
                $c_type = null;
                $stmt_fetch_old = $conn->prepare("SELECT distance, c_type FROM consumption WHERE c_id = ?");
                $stmt_fetch_old->bind_param("i", $c_id);
                $stmt_fetch_old->execute();
                $result_old = $stmt_fetch_old->get_result();
                if ($row_old = $result_old->fetch_assoc()) {
                    $old_distance = $row_old['distance'];
                    $c_type = $row_old['c_type'];
                }
                $stmt_fetch_old->close();

                // Update record
                $stmt = $conn->prepare("UPDATE consumption SET distance = ? WHERE c_id = ?");
                if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
                $stmt->bind_param("di", $distance, $c_id);

                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        // Audit Log
                        if ($old_distance !== null && $old_distance != $distance) {
                             log_general_audit_entry($conn, 'consumption', (string)$c_id, 'UPDATE', $logged_in_user_id, 'distance (' . $c_type . ')', (string)$old_distance, (string)$distance);
                        }
                        $toast_message = "Distance updated successfully!";
                        $toast_type = "success";
                    } else {
                        if ($c_type === null) { 
                            $toast_message = "No record found to update.";
                            $toast_type = "error";
                        } else {
                            $toast_message = "No changes detected.";
                            $toast_type = "success";
                        }
                    }
                } else {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                $stmt->close();
            } catch (Throwable $e) {
                $toast_message = "Error: " . $e->getMessage();
                $toast_type = "error";
            }
        } else {
            $toast_message = "Invalid input.";
            $toast_type = "error";
        }
    // --- Handle insertion ---
    } elseif (isset($_POST['action']) && $_POST['action'] === 'add_new_consumption') {
        $c_type_raw = trim($_POST['new_c_type'] ?? '');
        $distance_raw = $_POST['new_distance'] ?? '';

        $c_type = filter_var($c_type_raw, FILTER_SANITIZE_STRING);
        $distance = filter_var($distance_raw, FILTER_VALIDATE_FLOAT);

        if (empty($c_type) || $distance === false || $distance < 0) {
            $toast_message = "Invalid input.";
            $toast_type = "error";
        } else {
            try {
                // Check exists
                $chk_stmt = $conn->prepare("SELECT c_id FROM consumption WHERE c_type = ?");
                $chk_stmt->bind_param("s", $c_type);
                $chk_stmt->execute();
                $chk_stmt->store_result();
                
                if ($chk_stmt->num_rows > 0) {
                    $toast_message = "Vehicle Type '{$c_type}' already exists.";
                    $toast_type = "error";
                    $chk_stmt->close();
                } else {
                    $chk_stmt->close();
                    // Insert
                    $stmt = $conn->prepare("INSERT INTO consumption (c_type, distance) VALUES (?, ?)");
                    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
                    $stmt->bind_param("sd", $c_type, $distance);

                    if ($stmt->execute()) {
                        $new_c_id = $conn->insert_id;
                        log_general_audit_entry($conn, 'consumption', (string)$new_c_id, 'INSERT', $logged_in_user_id, 'new_record', 'N/A', 'Type: ' . $c_type . ' | Dist: ' . $distance);
                        $toast_message = "New rate added successfully!";
                        $toast_type = "success";
                    } else {
                        throw new Exception("Execute failed: " . $stmt->error);
                    }
                    $stmt->close();
                }
            } catch (Throwable $e) {
                $toast_message = "Error: " . $e->getMessage();
                $toast_type = "error";
            }
        }
    }

    header("Location: distance_per_liter.php?toast_message=" . urlencode($toast_message) . "&toast_type=" . urlencode($toast_type));
    exit();
}

$consumptions = getAllConsumption($conn);

if (isset($_GET['toast_message']) && isset($_GET['toast_type'])) {
    $toast_message = $_GET['toast_message'];
    $toast_type = $_GET['toast_type'];
}

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distance Rates</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 300px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }

        /* Modal specific styles */
        .modal { transition: opacity 0.25s ease; }
        body.modal-active { overflow-x: hidden; overflow-y: visible !important; }
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
        <!-- <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Distance Efficiency Rates
        </div> -->
        <div class="flex items-center space-x-2 w-fit">
            <a href="" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Fuel
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Distance Efficiency Rates
            </span>
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="fuel.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            <i class="fas fa-gas-pump text-lg"></i> Fuel Rates
        </a>
        <button onclick="toggleModal()"  
                    class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-blue-500">
                    <span>Add Record</span>
                </button>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 px-2 min-h-screen flex flex-col items-center">
    
    <div class="w-full">
        
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            
            <div class="overflow-x-auto max-h-[87vh] overflow-y-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-blue-600 text-white uppercase text-xs sticky top-0 z-10">
                        <tr>
                            <th class="px-6 py-3 font-semibold w-1/3">Vehicle Type</th>
                            <th class="px-6 py-3 font-semibold text-center w-1/3">Distance (km/L)</th>
                            <th class="px-6 py-3 font-semibold text-center w-1/3">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!empty($consumptions)): ?>
                            <?php foreach ($consumptions as $row): ?>
                                <tr class="hover:bg-indigo-50 transition duration-150">
                                    <form action="" method="POST" class="contents">
                                        <input type="hidden" name="action" value="update_distance_individual">
                                        <input type="hidden" name="c_id" value="<?php echo (int)$row['c_id']; ?>">
                                        
                                        <td class="px-6 py-4 font-medium text-gray-800 flex items-center gap-3">
                                            <div class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center text-gray-500">
                                                <i class="fas fa-car-side"></i>
                                            </div>
                                            <?php echo htmlspecialchars($row['c_type']); ?>
                                        </td>
                                        
                                        <td class="px-6 py-4 text-center">
                                            <div class="relative max-w-[150px] mx-auto">
                                                <input type="number" step="0.01" name="distance" required
                                                       value="<?php echo htmlspecialchars((string)$row['distance']); ?>"
                                                       class="w-full text-center px-3 py-1.5 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 outline-none font-mono font-bold text-gray-700 bg-white">
                                            </div>
                                        </td>
                                        
                                        <td class="px-6 py-4 text-center">
                                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded shadow-sm text-xs font-bold transition transform hover:scale-105 flex items-center gap-2 justify-center mx-auto">
                                                <i class="fas fa-sync-alt"></i> Update
                                            </button>
                                        </td>
                                    </form>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="px-6 py-10 text-center text-gray-500 italic">
                                    No consumption rates found. Please add a new one.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<div id="addModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-[100]">
    <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50" onclick="toggleModal()"></div>
    
    <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded-xl shadow-2xl z-50 overflow-y-auto transform scale-95 transition-transform duration-300" id="modalContent">
        
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <p class="text-lg font-bold text-gray-800">Add New Vehicle Type</p>
            </div>
            <div class="cursor-pointer z-50" onclick="toggleModal()">
                <i class="fas fa-times text-gray-500 hover:text-red-500 transition text-lg"></i>
            </div>
        </div>

        <div class="px-6 py-6 text-left">
            <form action="" method="POST">
                <input type="hidden" name="action" value="add_new_consumption">
                
                <div class="mb-4">
                    <label for="new_c_type" class="block text-sm font-semibold text-gray-700 mb-2">Vehicle Type</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-bus text-gray-400"></i>
                        </div>
                        <input type="text" name="new_c_type" id="new_c_type" required placeholder="e.g., Mini Van"
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none transition">
                    </div>
                </div>

                <div class="mb-6">
                    <label for="new_distance" class="block text-sm font-semibold text-gray-700 mb-2">Efficiency (km per Liter)</label>
                    <div class="relative">
                        <input type="number" step="0.01" name="new_distance" id="new_distance" required placeholder="0.00"
                               class="w-full pl-4 pr-12 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none transition">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <span class="text-gray-500 text-sm font-medium">km/L</span>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between gap-3">
                    <button type="button" onclick="toggleModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition text-sm font-semibold">Cancel</button>
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition shadow-md text-sm font-semibold flex items-center gap-2">
                         Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script>
    // --- Toast Logic ---
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
            
            // Clean URL params
            const url = new URL(window.location.href);
            url.searchParams.delete('toast_message');
            url.searchParams.delete('toast_type');
            window.history.replaceState({}, document.title, url.toString());
        });
    <?php endif; ?>

    // --- Modal Logic ---
    function toggleModal() {
        const body = document.querySelector('body');
        const modal = document.getElementById('addModal');
        const modalContent = document.getElementById('modalContent');
        
        modal.classList.toggle('opacity-0');
        modal.classList.toggle('pointer-events-none');
        body.classList.toggle('modal-active');
        
        // Simple animation for the box
        if (!modal.classList.contains('opacity-0')) {
             modalContent.classList.remove('scale-95');
             modalContent.classList.add('scale-100');
        } else {
             modalContent.classList.remove('scale-100');
             modalContent.classList.add('scale-95');
        }
    }
    
    // Close modal on escape key
    document.onkeydown = function(evt) {
        evt = evt || window.event;
        var isEscape = false;
        if ("key" in evt) {
            isEscape = (evt.key === "Escape" || evt.key === "Esc");
        } else {
            isEscape = (evt.keyCode === 27);
        }
        if (isEscape && document.body.classList.contains('modal-active')) {
            toggleModal();
        }
    };
</script>

</body>
</html>

<?php $conn->close(); ?>