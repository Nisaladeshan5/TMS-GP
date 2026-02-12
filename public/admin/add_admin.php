<?php
// add_admin.php
require_once '../../includes/session_check.php';

// 1. PHP LOGIC
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Start Output Buffering
ob_start();

include '../../includes/db.php'; 

$logged_in_user_role = strtolower($_SESSION['user_role'] ?? 'admin'); 

// Role Hierarchy
$role_hierarchy = [
    'admin' => 1,
    'super admin' => 2,
    'manager' => 3,
    'developer' => 4,
];

function canAccess($logged_in_role) {
    global $role_hierarchy;
    $logged_in_level = $role_hierarchy[$logged_in_role] ?? 0;
    return ($logged_in_level >= 2); 
}

// Access Control
if (!canAccess($logged_in_user_role)) {
    $_SESSION['message'] = "Permission denied. Only Super Admins, Managers, and Developers can add new admins.";
    $_SESSION['messageType'] = 'danger'; // Maps to 'error' in frontend
    header("Location: admin.php"); 
    exit();
}

$message = '';
$msg_type = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emp_id = trim($_POST['emp_id']);
    
    if (empty($emp_id)) {
        $message = "Employee ID is required.";
        $msg_type = 'error';
    } else {
        // Database connection check
        if (!isset($conn) || $conn->connect_error) {
            $_SESSION['message'] = "Database connection error.";
            $_SESSION['messageType'] = 'danger';
            header("Location: admin.php");
            exit();
        }

        // 1. Check valid employee
        $check_emp_sql = "SELECT emp_id FROM employee WHERE emp_id = ?";
        if ($check_stmt = $conn->prepare($check_emp_sql)) {
            $check_stmt->bind_param("s", $emp_id);
            $check_stmt->execute();
            $check_stmt->store_result();
            if ($check_stmt->num_rows === 0) {
                $message = "Employee ID not found in employee table.";
                $msg_type = 'error';
            }
            $check_stmt->close();
        }

        // 2. Check duplicate admin
        if (empty($message)) {
            $check_user_sql = "SELECT emp_id FROM admin WHERE emp_id = ?";
            if ($check_user_stmt = $conn->prepare($check_user_sql)) {
                $check_user_stmt->bind_param("s", $emp_id);
                $check_user_stmt->execute();
                $check_user_stmt->store_result();
                
                if ($check_user_stmt->num_rows > 0) {
                    $message = "Admin account already exists for ID: " . htmlspecialchars($emp_id);
                    $msg_type = 'error'; 
                }
                $check_user_stmt->close();
            }
        }

        // 3. Insert new admin
        if (empty($message)) {
            $password_plain = '12345678';
            $password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);
            $is_first_login = 1;
            $role = 'admin';

            $sql = "INSERT INTO admin (emp_id, password, role, is_first_login) VALUES (?, ?, ?, ?)";
            
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("sssi", $emp_id, $password_hashed, $role, $is_first_login);
                if ($stmt->execute()) {
                    // Success Redirect
                    $_SESSION['message'] = "Admin added successfully! Default password is '12345678'.";
                    $_SESSION['messageType'] = 'success';
                    $stmt->close();
                    $conn->close();
                    ob_clean();
                    header("Location: admin.php"); 
                    exit();
                } else {
                    $message = "Error adding admin: " . $stmt->error;
                    $msg_type = 'error';
                }
                $stmt->close();
            } else {
                $message = "Database prepare error.";
                $msg_type = 'error';
            }
        }
    }
}

// ----------------------------------------------------
// 2. HTML STRUCTURE
// ----------------------------------------------------
include '../../includes/header.php'; 
include '../../includes/navbar.php'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Admin User</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* Toast Notifications CSS */
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
            min-width: 300px;
        }
        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        .toast.success { background-color: #10b981; } 
        .toast.error { background-color: #ef4444; } 
        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }
        .readonly-field {
            background-color: #f3f4f6; /* Gray-100 */
            cursor: not-allowed;
        }
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
<body class="bg-gray-100 font-sans">

<div id="toast-container"></div>

<?php if ($message): ?>
    <div id="php-local-message" data-message="<?php echo htmlspecialchars($message); ?>" data-type="<?php echo $msg_type; ?>"></div>
<?php endif; ?>

<?php if (isset($_SESSION['message'])): ?>
    <div id="php-session-message" 
         data-message="<?php echo htmlspecialchars($_SESSION['message']); ?>" 
         data-type="<?php echo ($_SESSION['messageType'] == 'danger') ? 'error' : 'success'; ?>">
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['messageType']); ?>
<?php endif; ?>

<div class="w-[85%] ml-[15%]">
    <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
        
        <div class="flex items-center justify-between mb-6 border-b pb-2">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900">Add New Admin</h1>
            <a href="admin.php" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-xl"></i>
            </a>
        </div>

        <form action="add_admin.php" method="post" id="addAdminForm" class="space-y-6">
            
            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-blue-500"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-blue-700">
                            The new user will be assigned the <strong>Admin</strong> role by default. 
                            Default password: <strong>12345678</strong>.
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="emp_id" class="block text-sm font-medium text-gray-700">Employee ID <span class="text-red-500">*</span></label>
                    <input type="text" id="emp_id" name="emp_id" required maxlength="15" autofocus
                           class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 transition duration-150 ease-in-out"
                           placeholder="Enter ID to auto-fill">
                </div>
                
                <div>
                    <label for="calling_name" class="block text-sm font-medium text-gray-700">Calling Name</label>
                    <input type="text" id="calling_name" name="calling_name" readonly
                           class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm sm:text-sm p-2 bg-gray-100 cursor-not-allowed text-gray-600"
                           placeholder="Auto-filled">
                    <p id="name_status" class="mt-1 text-xs text-gray-500">Auto-fills from employee records.</p>
                </div>
            </div>

            <div class="flex justify-between mt-8 pt-6 border-t border-gray-200">
                <a href="admin.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" 
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105 flex items-center gap-2">
                    <i class="fas fa-user-shield"></i> Add Admin
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    // --- Custom Toast Function ---
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container'); 
        const toast = document.createElement('div'); 
        
        // Map 'danger' to 'error' if needed
        if(type === 'danger') type = 'error';

        toast.className = `toast ${type}`; 
        
        let iconPath = '';
        if (type === 'success') {
             iconPath = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />';
        } else {
             iconPath = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />';
        }

        toast.innerHTML = ` 
            <svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                ${iconPath}
            </svg>
            <span class="font-medium">${message}</span> 
        `; 
        
        toastContainer.appendChild(toast); 
        setTimeout(() => toast.classList.add('show'), 10); 
        setTimeout(() => { 
            toast.classList.remove('show'); 
            toast.addEventListener('transitionend', () => toast.remove(), { once: true }); 
        }, 4000); 
    } 

    $(document).ready(function() {
        // 1. Check local PHP messages
        const localMsgDiv = $('#php-local-message');
        if (localMsgDiv.length) {
            showToast(localMsgDiv.data('message'), localMsgDiv.data('type'));
        }

        // 2. Check session PHP messages
        const sessionMsgDiv = $('#php-session-message');
        if (sessionMsgDiv.length) {
            showToast(sessionMsgDiv.data('message'), sessionMsgDiv.data('type'));
        }

        // 3. AJAX Logic for Auto-Populating Name (Logic updated to check length >= 8)
        let typingTimer;
        const doneTypingInterval = 500; // 0.5 seconds

        $('#emp_id').on('input', function() {
            clearTimeout(typingTimer);
            const empId = $(this).val().trim();
            
            // Check if length is >= 8 before searching
            if (empId.length >= 8) {
                typingTimer = setTimeout(function() {
                    fetchEmployeeName(empId);
                }, doneTypingInterval);
            } else {
                resetFields();
            }
        });

        function resetFields() {
            $('#calling_name').val('');
            $('#name_status').text('Auto-fills from employee records.');
        }

        function fetchEmployeeName(empId) {
            $.ajax({
                url: 'fetch_employee_name.php', // Uses the same backend file as user.php
                method: 'GET',
                data: { emp_id: empId },
                dataType: 'json',
                beforeSend: function() {
                    $('#name_status').text('Searching...');
                },
                success: function(response) {
                    if (response.calling_name) {
                        $('#calling_name').val(response.calling_name);
                        $('#name_status').text('Employee found.');
                        showToast("Employee found: " + response.calling_name, 'success');
                    } else {
                        $('#calling_name').val('');
                        $('#name_status').text('Not found.');
                        showToast("Employee ID not found in records.", 'error');
                    }
                },
                error: function() {
                    resetFields();
                    $('#name_status').text('Network error.');
                    showToast("Could not connect to employee database.", 'error');
                }
            });
        }
    });
</script>

</body>
<?php ob_end_flush(); ?>
</html>