<?php
// add_admin.php
require_once '../../includes/session_check.php';
// ----------------------------------------------------
// 1. ALL LOGIC MUST BE BEFORE ANY HTML/INCLUDES
// ----------------------------------------------------

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Ensure includes are loaded AFTER session_start() and BEFORE any headers are sent
include '../../includes/db.php'; 

$logged_in_user_role = strtolower($_SESSION['user_role'] ?? 'admin'); 

// 🔑 Role Hierarchy and Access Check Function (Security Logic)
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

// Access Control Check
if (!canAccess($logged_in_user_role)) {
    $_SESSION['message'] = "Permission denied. Only Super Admins, Managers, and Developers can add new admins.";
    $_SESSION['messageType'] = 'danger';
    header("Location: admin.php"); 
    exit();
}


// Handle form submission for adding a new admin (This is where the headers were failing)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emp_id = trim($_POST['emp_id']);
    
    if (empty($emp_id)) {
        $_SESSION['message'] = "Employee ID is required.";
        $_SESSION['messageType'] = 'danger';
        header("Location: add_admin.php");
        exit();
    }

    // Database and duplicate checks... (Rest of the POST logic is assumed correct)
    if (!isset($conn) || $conn->connect_error) {
        $_SESSION['message'] = "Database connection error. Please try again later.";
        $_SESSION['messageType'] = 'danger';
        header("Location: admin.php");
        exit();
    }

    // 1. Check if the emp_id exists in the employee table
    $check_emp_sql = "SELECT emp_id FROM employee WHERE emp_id = ?";
    if ($check_stmt = $conn->prepare($check_emp_sql)) {
        $check_stmt->bind_param("s", $emp_id);
        $check_stmt->execute();
        $check_stmt->store_result();
        if ($check_stmt->num_rows === 0) {
            $_SESSION['message'] = "Employee ID not found in the employee table. A user must be a valid employee.";
            $_SESSION['messageType'] = 'danger';
            $check_stmt->close();
            header("Location: add_admin.php");
            exit();
        }
        $check_stmt->close();
    }

    // 2. CHECK FOR DUPLICATE USER (Prevents adding an existing user)
    $check_user_sql = "SELECT emp_id FROM admin WHERE emp_id = ?";
    if ($check_user_stmt = $conn->prepare($check_user_sql)) {
        $check_user_stmt->bind_param("s", $emp_id);
        $check_user_stmt->execute();
        $check_user_stmt->store_result();
        
        if ($check_user_stmt->num_rows > 0) {
            $_SESSION['message'] = "An admin account already exists for Employee ID: " . htmlspecialchars($emp_id);
            $_SESSION['messageType'] = 'warning'; 
            $check_user_stmt->close();
            header("Location: add_admin.php");
            exit();
        }
        $check_user_stmt->close();
    }


    // 3. Insert the new user
    $password_plain = '12345678';
    $password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);
    $is_first_login = 1;
    $role = 'admin';

    $sql = "INSERT INTO admin (emp_id, password, role, is_first_login) VALUES (?, ?, ?, ?)";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("sssi", $emp_id, $password_hashed, $role, $is_first_login);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Admin user added successfully! Default role: " . $role . ". Default password: '" . $password_plain . "'.";
            $_SESSION['messageType'] = 'success';
            $stmt->close();
            $conn->close();
            header("Location: admin.php"); // SUCCESSFUL REDIRECT!
            exit();
        } else {
            $_SESSION['message'] = "Error adding admin: " . $stmt->error;
            $_SESSION['messageType'] = 'danger';
            error_log("MySQLi Execute Error: " . $stmt->error);
        }
        $stmt->close();
    } else {
        $_SESSION['message'] = "Database prepare error: " . $conn->error;
        $_SESSION['messageType'] = 'danger';
        error_log("MySQLi Prepare Error: " . $conn->error);
    }
    
    $conn->close();
    header("Location: add_admin.php"); // ERROR/FAIL REDIRECT!
    exit();
}

// ----------------------------------------------------
// 2. HTML STRUCTURE STARTS HERE (AFTER ALL REDIRECTS)
// ----------------------------------------------------

// Now include header and navbar for display
include '../../includes/header.php'; 
include '../../includes/navbar.php'; 
?>

<link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Add New Admin User</div>
</div>
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
<main class="flex-grow p-6 pt-10" style="width: 85%; margin-left: 15%;">
    <div class="max-w-2xl mx-auto bg-white shadow-xl rounded-xl p-8">
        <h2 class="text-3xl font-bold text-gray-800 text-center mb-6">
            Add New Admin
        </h2>
        
        <div id="session-message-data" data-message="<?php echo htmlspecialchars($_SESSION['message'] ?? ''); ?>" data-type="<?php echo htmlspecialchars($_SESSION['messageType'] ?? ''); ?>"></div>
        
        <?php 
        // Clear the session messages immediately after storing them in the data attributes
        unset($_SESSION['message']);
        unset($_SESSION['messageType']);
        ?>
        
        <div class="row justify-content-center">
            <div class="w-full max-w-md">
                <form action="add_admin.php" method="post" id="addAdminForm">
                    <div class="mb-5">
                        <label for="emp_id" class="block text-sm font-medium text-gray-700 mb-1">Employee ID</label>
                        <input type="text" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm" 
                               id="emp_id" name="emp_id" maxlength="8" required>
                        <p class="mt-2 text-sm text-gray-500">
                            Enter the Employee ID to grant Admin access. Role will be set to admin by default.
                        </p>
                    </div>
                    
                    <div class="flex justify-end space-x-4">
                        <a href="admin.php" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none">
                            Cancel
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium !rounded-lg shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Add Admin User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<script>
// --- Custom Toast Function ---
function showToast(message, isSuccess = true) {
    let backgroundColor = isSuccess ? "#22c55e" : "#ef4444"; 
    let duration = isSuccess ? 3000 : 5000;

    const iconSVG = isSuccess
        ? `<svg class="w-5 h-5 mr-2 text-green-100" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
             <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
           </svg>`
        : `<svg class="w-5 h-5 mr-2 text-red-100" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
             <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
           </svg>`;

    Toastify({
        text: `<div style="display: flex; align-items: center;">${iconSVG}<span style="flex: 1;">${message}</span></div>`,
        duration: duration,
        close: true,
        gravity: "top",
        position: "right",
        stopOnFocus: true,
        escapeMarkup: false, 
        style: {
            background: backgroundColor,
            color: "#ffffff",
            padding: "12px 16px",
            borderRadius: "10px",
            fontSize: "15px",
            fontWeight: "500",
            boxShadow: "0 4px 14px rgba(0,0,0,0.1)", 
            display: "flex",
            alignItems: "center",
            gap: "8px"
        }
    }).showToast();
}

// Display messages that came from the PHP session redirect
$(document).ready(function() {
    const messageDataElement = $('#session-message-data');
    const message = messageDataElement.data('message');
    const messageType = messageDataElement.data('type');

    if (message) {
        const isSuccess = messageType === 'success';
        showToast(message, isSuccess);
    }
});
</script>
</html>