<?php
require_once '../../includes/session_check.php';
// Start the session (ensure it's started before accessing session variables)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php"); 
    exit();
}

include('../../includes/db.php'); // Include database connection


// Get the logged-in user's ID and Role
$logged_in_user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['user_role'] ?? 'guest'; // Assuming 'user_role' is set in the session

$allowed_roles = ['super admin', 'manager', 'developer'];
$is_authorized = in_array($user_role, $allowed_roles);

if (!$is_authorized) {
    // Redirect or display an error if the user is not authorized
    header("Location: fuel.php"); 
    exit();
}

$toast_message = null;
$toast_type = null;

// --- Determine available years (Current and Previous Year only) ---
$current_year = (int)date('Y');
$previous_year = $current_year - 1;

// Generate the array in descending order
$available_years = [$current_year, $previous_year];

// SET SELECTED YEAR: Prioritize GET parameter, otherwise default to current year
$selected_year = $current_year;
if (isset($_GET['year'])) {
    $requested_year = (int)$_GET['year'];
    // Only use the requested year if it is in our available list.
    if (in_array($requested_year, $available_years)) {
        $selected_year = $requested_year;
    }
}

// --- POST Request Handling for LOCK Action ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'lock_month') {
    $lock_year = (int)$_POST['lock_year'];
    $lock_month = (int)$_POST['lock_month'];

    if ($lock_year > 0 && $lock_month >= 1 && $lock_month <= 12) {
        try {
            // 1. Check if it's already locked (optional, but good practice)
            $check_stmt = $conn->prepare("SELECT id, is_locked FROM month_locks WHERE year = ? AND month = ?");
            $check_stmt->bind_param("ii", $lock_year, $lock_month);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $existing_lock = $result->fetch_assoc();
            $check_stmt->close();

            if ($existing_lock && $existing_lock['is_locked'] == 1) {
                $toast_message = "Month " . $lock_month . "/" . $lock_year . " is already locked.";
                $toast_type = "error";
            } else {
                // 2. Lock the month (INSERT OR UPDATE/UPSERT)
                $lock_sql = "INSERT INTO month_locks (year, month, is_locked, locked_by_user_id, locked_at) 
                             VALUES (?, ?, 1, ?, NOW())
                             ON DUPLICATE KEY UPDATE 
                                is_locked = 1, 
                                locked_by_user_id = VALUES(locked_by_user_id), 
                                locked_at = NOW()";

                $lock_stmt = $conn->prepare($lock_sql);
                $lock_stmt->bind_param("iii", $lock_year, $lock_month, $logged_in_user_id);
                
                if ($lock_stmt->execute()) {
                    
                    // 3. --- AUDIT LOG ---
                    $month_name = date("F", mktime(0, 0, 0, $lock_month, 10)); // Get month name

                    $toast_message = $month_name . " " . $lock_year . " has been successfully locked.";
                    $toast_type = "success";
                } else {
                    throw new Exception("Error locking month: " . $lock_stmt->error);
                }
                $lock_stmt->close();
            }
        } catch (Exception $e) {
            $toast_message = "Error: " . $e->getMessage();
            $toast_type = "error";
        }
    } else {
        $toast_message = "Invalid year or month data.";
        $toast_type = "error";
    }
}

// --- Fetch Lock Data for the Selected Year (UPDATED QUERY) ---
$months = [];

// Prepare the list of months for the current selected year
for ($m = 1; $m <= 12; $m++) {
    $month_name = date("F", mktime(0, 0, 0, $m, 10)); // Get month name
    $months[$m] = [
        'name' => $month_name, 
        'is_locked' => false, 
        'locked_at' => null, 
        'locked_by_user_id' => null,
        'emp_id' => null, 
        'calling_name' => null 
    ];
}

// Fetch lock statuses from the DB for the selected year, joining tables to get employee info
try {
    // Corrected the JOIN to 'admin' and 'employee' tables
    $sql_locks = "
        SELECT 
            ml.month, 
            ml.is_locked, 
            ml.locked_at, 
            ml.locked_by_user_id,
            u.emp_id,                 
            e.calling_name            
        FROM month_locks ml
        LEFT JOIN admin u ON ml.locked_by_user_id = u.user_id 
        LEFT JOIN employee e ON u.emp_id = e.emp_id
        WHERE ml.year = ? AND ml.is_locked = 1"; // Only fetch locked months

    $stmt_locks = $conn->prepare($sql_locks);
    $stmt_locks->bind_param("i", $selected_year);
    $stmt_locks->execute();
    $result_locks = $stmt_locks->get_result();
    
    while ($row = $result_locks->fetch_assoc()) {
        $month_num = (int)$row['month'];
        if (isset($months[$month_num])) {
            $months[$month_num]['is_locked'] = (bool)$row['is_locked'];
            $months[$month_num]['locked_at'] = $row['locked_at'];
            $months[$month_num]['locked_by_user_id'] = $row['locked_by_user_id'];
            $months[$month_num]['emp_id'] = $row['emp_id'];         
            $months[$month_num]['calling_name'] = $row['calling_name']; 
        }
    }
    $stmt_locks->close();
} catch (Exception $e) {
    error_log("Error fetching month locks: " . $e->getMessage());
    $toast_message = "Error retrieving lock data: " . $e->getMessage();
    $toast_type = "error";
}

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Month Lock Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Toast CSS */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
        /* Custom styles for the month boxes */
        .month-box {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.2s ease-in-out;
            cursor: pointer;
            min-height: 120px; 
        }
        .month-box:hover:not(.locked) {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(239, 68, 68, 0.1), 0 4px 6px -2px rgba(239, 68, 68, 0.05);
        }
        .locked {
            cursor: default;
        }
        /* Custom Modal Styles */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 400px;
            animation: slide-down 0.3s ease-out;
        }
        @keyframes slide-down {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
    <div class="text-lg font-semibold ml-3">Fuel</div>
    <div class="flex gap-4">
        <a href="fuel.php" class="hover:text-yellow-600">Back</a>
    </div>
</div>
    <div class="flex justify-center w-[85%] ml-[15%] pt-6 pb-12">
        
        <div class="w-full max-w-4xl mx-auto p-4 bg-white rounded-lg shadow-md">
            
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-3 px-0">
                <h2 class="text-xl sm:text-2xl font-bold text-red-600 mb-2 sm:mb-0">🔐 Month Lock Management</h2>

                <div class="flex items-center space-x-2 p-1 rounded-lg">
                    <label for="year_select" class="font-semibold text-sm text-gray-700">Select Year:</label>
                    <select id="year_select" onchange="window.location.href='month_locks.php?year=' + this.value"
                        class="p-1 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $year == $selected_year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <hr class="my-3">
            
            <div class="p-3 bg-yellow-100 border border-yellow-400 rounded-lg text-sm mb-4">
                <p class="text-yellow-800 font-semibold">
                    ❗ Important: Locking a month is a one-way operation on this screen. To unlock, a database administrator must manually update the `month_locks` table.
                </p>
            </div>
            
            <div class="grid grid-cols-3 gap-5 p-4 bg-red-50 rounded-lg border border-red-200">
                <?php foreach ($months as $month_num => $month_data): 
                    $is_locked = $month_data['is_locked'];
                    $box_class = $is_locked ? 'bg-red-200 locked' : 'bg-white shadow-lg hover:bg-red-100';
                    $status_text = $is_locked ? 'LOCKED 🔒' : 'UNLOCKED';
                    $status_color = $is_locked ? 'text-red-700' : 'text-green-600';
                    $button_text = $is_locked ? 'Locked' : 'Click to Lock';
                ?>
                    <div class="month-box <?php echo $box_class; ?>" 
                        <?php if (!$is_locked): ?>
                            onclick="openLockModal(<?php echo $selected_year; ?>, <?php echo $month_num; ?>, '<?php echo htmlspecialchars($month_data['name']); ?>')"
                        <?php endif; ?>>
                        
                        <h4 class="text-base font-bold text-gray-800 mb-2"><?php echo htmlspecialchars($month_data['name']); ?></h4>
                        
                        <span class="text-xs font-semibold <?php echo $status_color; ?>"><?php echo $status_text; ?></span>
                        
                        <button class="mt-3 w-full py-1 text-xs rounded-md 
                            <?php echo $is_locked ? 'bg-gray-400 cursor-default text-gray-600' : 'bg-red-500 text-white hover:bg-red-600'; ?>"
                            <?php echo $is_locked ? 'disabled' : "onclick=\"event.stopPropagation(); openLockModal($selected_year, $month_num, '" . htmlspecialchars($month_data['name']) . "')\""; ?>>
                            <?php echo $button_text; ?>
                        </button>
                        
                        <?php if ($is_locked && $month_data['locked_at']): 
                            // Display the requested information
                            $emp_info = '';
                            if (!empty($month_data['calling_name'])) {
                                $emp_info .= htmlspecialchars($month_data['calling_name']);
                            }
                            if (!empty($month_data['emp_id'])) {
                                $emp_info .= " (" . htmlspecialchars($month_data['emp_id']) . ")";
                            }
                            if (empty($emp_info)) {
                                $emp_info = "User ID: " . htmlspecialchars($month_data['locked_by_user_id']);
                            }
                        ?>
                            <p class="text-xs text-gray-500 mt-2 text-center leading-tight">
                                Locked By: <?php echo $emp_info; ?><br>
                                On: <?php echo date('Y-m-d H:i', strtotime($month_data['locked_at'])); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <form id="lockForm" action="month_locks.php?year=<?php echo $selected_year; ?>" method="POST" style="display:none;">
                <input type="hidden" name="action" value="lock_month">
                <input type="hidden" name="lock_year" id="lock_year">
                <input type="hidden" name="lock_month" id="lock_month">
            </form>
            
        </div>
    </div>
    
    <div id="customLockModal" class="modal-backdrop">
        <div class="modal-content">
            <h4 class="text-xl font-bold text-red-600 mb-4">Confirm Month Lock</h4>
            <p class="text-gray-700 mb-6">Are you absolutely sure you want to lock <strong id="modalMonthName" class="text-red-700"></strong>?</p>
            <p class="text-sm text-yellow-700 mb-6">❗ This action is permanent on this screen and cannot be reversed by users.</p>
            
            <div class="flex justify-end space-x-3">
                <button onclick="closeLockModal()" 
                        class="px-4 py-2 bg-gray-300 text-gray-800 font-semibold rounded-lg hover:bg-gray-400 transition">
                    Cancel
                </button>
                <button onclick="confirmLockAction()" 
                        class="px-4 py-2 bg-red-600 text-white font-semibold rounded-lg hover:bg-red-700 transition">
                    Yes, Lock Month
                </button>
            </div>
        </div>
    </div>
    
    <div id="toast-container"></div>
    <script>
        // Store current lock data temporarily
        let currentLockYear = 0;
        let currentLockMonth = 0;

        // Session Timeout Script 
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
        
        // --- Modal Functions ---
        function openLockModal(year, month, monthName) {
            currentLockYear = year;
            currentLockMonth = month;
            document.getElementById('modalMonthName').textContent = `${monthName} ${year}`;
            document.getElementById('customLockModal').style.display = 'flex';
        }

        function closeLockModal() {
            document.getElementById('customLockModal').style.display = 'none';
        }
        
        function confirmLockAction() {
            // 1. Close the modal
            closeLockModal();
            
            // 2. Set the hidden form values
            document.getElementById('lock_year').value = currentLockYear;
            document.getElementById('lock_month').value = currentLockMonth;
            
            // 3. Submit the form
            document.getElementById('lockForm').submit();
        }
        // --- END Modal Functions ---
        
        // Toast Display Script
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
                
                if ("<?php echo $toast_type; ?>" === 'success') {
                    // Refresh the current page with the current year parameter
                    setTimeout(() => window.location.href = 'month_locks.php?year=<?php echo $selected_year; ?>', 3000); 
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>