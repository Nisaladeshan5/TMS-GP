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

$logged_in_user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['user_role'] ?? 'guest';

$allowed_roles = ['admin', 'super admin', 'manager', 'developer'];
$is_authorized = in_array($user_role, $allowed_roles);

if (!$is_authorized) {
    header("Location: fuel.php"); 
    exit();
}

$toast_message = null;
$toast_type = null;

// Available years
$current_year = (int)date('Y');
$previous_year = $current_year - 1;
$available_years = [$current_year, $previous_year];

$selected_year = $current_year;
if (isset($_GET['year'])) {
    $requested_year = (int)$_GET['year'];
    if (in_array($requested_year, $available_years)) {
        $selected_year = $requested_year;
    }
}

// Lock Action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'lock_month') {
    $lock_year = (int)$_POST['lock_year'];
    $lock_month = (int)$_POST['lock_month'];

    if ($lock_year > 0 && $lock_month >= 1 && $lock_month <= 12) {
        try {
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
                $lock_sql = "INSERT INTO month_locks (year, month, is_locked, locked_by_user_id, locked_at) 
                             VALUES (?, ?, 1, ?, NOW())
                             ON DUPLICATE KEY UPDATE 
                                is_locked = 1, 
                                locked_by_user_id = VALUES(locked_by_user_id), 
                                locked_at = NOW()";

                $lock_stmt = $conn->prepare($lock_sql);
                $lock_stmt->bind_param("iii", $lock_year, $lock_month, $logged_in_user_id);
                
                if ($lock_stmt->execute()) {
                    $month_name = date("F", mktime(0, 0, 0, $lock_month, 10)); 
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

// Fetch Data
$months = [];
for ($m = 1; $m <= 12; $m++) {
    $month_name = date("F", mktime(0, 0, 0, $m, 10));
    $months[$m] = [
        'name' => $month_name, 
        'is_locked' => false, 
        'locked_at' => null, 
        'locked_by_user_id' => null,
        'emp_id' => null, 
        'calling_name' => null 
    ];
}

try {
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
        WHERE ml.year = ? AND ml.is_locked = 1"; 

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
    <title>Month Locks</title>
    
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
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; color: white; min-width: 300px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }

        /* Modal Backdrop */
        .modal-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 9999; display: none; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
        .modal-content { background-color: white; padding: 2rem; border-radius: 1rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); width: 90%; max-width: 450px; transform: scale(0.95); transition: transform 0.2s ease-out; }
        .modal-show .modal-content { transform: scale(1); }
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
            Month Lock Management
        </div> -->
        <div class="flex items-center space-x-2 w-fit">
                <a href="" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Fuel
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Month Lock Management
                </span>
            </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="fuel.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            <i class="fas fa-gas-pump text-lg"></i> Fuel Rates
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex flex-col items-center">
    
    <div class="w-full max-w-6xl">
        
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-4 mb-8 flex flex-col sm:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-3">
                <div class="bg-red-100 text-red-600 p-2 rounded-full">
                    <i class="fas fa-lock"></i>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-800">Lock Periods</h2>
                    <p class="text-xs text-gray-500">Prevent changes to historical data.</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <label for="year_select" class="text-sm font-semibold text-gray-600">Year:</label>
                <div class="relative">
                    <select id="year_select" onchange="window.location.href='month_locks.php?year=' + this.value"
                            class="appearance-none bg-gray-50 border border-gray-300 text-gray-700 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-32 p-2.5 pr-8">
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $year == $selected_year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                        <i class="fas fa-chevron-down text-xs"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-8 rounded-r-lg shadow-sm flex items-start gap-3">
            <i class="fas fa-info-circle text-yellow-500 text-xl mt-0.5"></i>
            <div>
                <p class="text-sm text-yellow-800 font-semibold">Important Note:</p>
                <p class="text-xs text-yellow-700 mt-1">
                    Locking a month is a <strong>permanent action</strong> on this screen. Once locked, users cannot edit fuel rates or data for that month. To unlock, please contact a Database Administrator.
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php foreach ($months as $month_num => $month_data): 
                $is_locked = $month_data['is_locked'];
                // Styles based on status
                $card_bg = $is_locked ? 'bg-red-50 border-red-200' : 'bg-white border-gray-200 hover:border-blue-300 hover:shadow-lg cursor-pointer';
                $icon_bg = $is_locked ? 'bg-red-100 text-red-500' : 'bg-green-100 text-green-500';
                $icon_class = $is_locked ? 'fa-lock' : 'fa-lock-open';
                $status_text = $is_locked ? 'LOCKED' : 'OPEN';
                $status_color = $is_locked ? 'text-red-600' : 'text-green-600';
                $btn_class = $is_locked ? 'bg-gray-300 text-gray-500 cursor-not-allowed hidden' : 'bg-red-500 hover:bg-red-600 text-white shadow-md transform hover:scale-105';
            ?>
                <div class="relative rounded-xl border <?php echo $card_bg; ?> p-5 transition-all duration-300 flex flex-col justify-between h-full group"
                     <?php if (!$is_locked): ?> onclick="openLockModal(<?php echo $selected_year; ?>, <?php echo $month_num; ?>, '<?php echo htmlspecialchars($month_data['name']); ?>')" <?php endif; ?>>
                    
                    <div>
                        <div class="flex justify-between items-start mb-4">
                            <h4 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($month_data['name']); ?></h4>
                            <div class="<?php echo $icon_bg; ?> p-2 rounded-full shadow-sm">
                                <i class="fas <?php echo $icon_class; ?>"></i>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2 mb-4">
                            <span class="w-2 h-2 rounded-full <?php echo $is_locked ? 'bg-red-500' : 'bg-green-500'; ?>"></span>
                            <span class="text-xs font-bold tracking-wider <?php echo $status_color; ?>"><?php echo $status_text; ?></span>
                        </div>

                        <?php if ($is_locked && $month_data['locked_at']): 
                            $emp_info = !empty($month_data['calling_name']) ? htmlspecialchars($month_data['calling_name']) : "User ID: " . htmlspecialchars($month_data['locked_by_user_id']);
                        ?>
                            <div class="bg-white/50 rounded-lg p-2 text-xs text-gray-500 border border-gray-100 mt-2">
                                <p class="flex items-center gap-1 mb-1"><i class="fas fa-user-circle text-gray-400"></i> <span class="font-medium text-gray-700"><?php echo $emp_info; ?></span></p>
                                <p class="flex items-center gap-1"><i class="fas fa-clock text-gray-400"></i> <?php echo date('M d, Y h:i A', strtotime($month_data['locked_at'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$is_locked): ?>
                        <div class="mt-4 pt-4 border-t border-dashed border-gray-200">
                            <button class="w-full py-2 rounded-lg text-xs font-bold uppercase tracking-wide transition <?php echo $btn_class; ?>">
                                Lock Month
                            </button>
                        </div>
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
        <div class="text-center mb-6">
            <div class="bg-red-100 text-red-500 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-lock text-3xl"></i>
            </div>
            <h4 class="text-xl font-bold text-gray-800">Confirm Month Lock</h4>
            <p class="text-gray-500 mt-2 text-sm">Are you sure you want to lock <strong id="modalMonthName" class="text-gray-800"></strong>?</p>
        </div>
        
        <div class="bg-red-50 p-3 rounded-lg mb-6 border border-red-100">
            <p class="text-xs text-red-600 flex items-start gap-2 text-left">
                <i class="fas fa-exclamation-circle mt-0.5"></i>
                This action cannot be undone from the dashboard. Only admins with database access can unlock it.
            </p>
        </div>
        
        <div class="flex justify-center gap-3">
            <button onclick="closeLockModal()" 
                    class="px-5 py-2.5 bg-gray-100 text-gray-600 font-semibold rounded-lg hover:bg-gray-200 transition focus:outline-none focus:ring-2 focus:ring-gray-300">
                Cancel
            </button>
            <button onclick="confirmLockAction()" 
                    class="px-5 py-2.5 bg-red-600 text-white font-semibold rounded-lg hover:bg-red-700 transition shadow-lg shadow-red-200 focus:outline-none focus:ring-2 focus:ring-red-500">
                Yes, Lock Month
            </button>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script>
    // --- Logic Variables ---
    let currentLockYear = 0;
    let currentLockMonth = 0;

    // --- Modal Functions ---
    function openLockModal(year, month, monthName) {
        currentLockYear = year;
        currentLockMonth = month;
        document.getElementById('modalMonthName').textContent = `${monthName} ${year}`;
        const modal = document.getElementById('customLockModal');
        modal.style.display = 'flex';
        // Small delay for animation
        setTimeout(() => modal.classList.add('modal-show'), 10);
    }

    function closeLockModal() {
        const modal = document.getElementById('customLockModal');
        modal.classList.remove('modal-show');
        setTimeout(() => modal.style.display = 'none', 200);
    }
    
    function confirmLockAction() {
        closeLockModal();
        document.getElementById('lock_year').value = currentLockYear;
        document.getElementById('lock_month').value = currentLockMonth;
        document.getElementById('lockForm').submit();
    }

    // --- Toast Function ---
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
            <?php if ($toast_type === 'success'): ?>
                setTimeout(() => window.location.href = 'month_locks.php?year=<?php echo $selected_year; ?>', 2000);
            <?php endif; ?>
        });
    <?php endif; ?>
</script>

</body>
</html>

<?php $conn->close(); ?>