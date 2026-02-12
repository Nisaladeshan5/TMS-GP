<?php
require_once '../../includes/session_check.php';
// add_user.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Start Output Buffering
ob_start(); 

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Helper Functions
function generate_pin() {
    return str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generate_qr_token() {
    return bin2hex(random_bytes(8)); 
}

$message = '';
$msg_type = ''; // 'success' or 'error'

// --- POST SUBMISSION LOGIC ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $emp_id = trim($_POST['emp_id']);
    $route_code = trim($_POST['route_code']);
    $purpose = trim($_POST['purpose']);
    $calling_name = trim($_POST['calling_name']); 

    // Generate Credentials
    $pin = generate_pin(); 
    $qr_token = generate_qr_token(); 

    // Database Insertion
    $sql_insert = "INSERT INTO `user` (emp_id, route_code, purpose, pin, qr_token, calling_name) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql_insert);

    if ($stmt) {
        $stmt->bind_param("ssssss", $emp_id, $route_code, $purpose, $pin, $qr_token, $calling_name);
        
        if ($stmt->execute()) {
            // Success
            ob_clean(); 
            header("Location: add_user.php?success=1&pin=" . $pin);
            exit();
        } else {
            $message = "Error adding user: " . $stmt->error;
            $msg_type = 'error';
            if ($conn->errno == 1062) { 
                $message = "Error: Employee ID or Route Code already exists.";
            }
        }
        $stmt->close();
    } else {
        $message = "Database prepare error: " . $conn->error;
        $msg_type = 'error';
    }
}

// Check for success flag from redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $new_pin = isset($_GET['pin']) ? htmlspecialchars($_GET['pin']) : '****';
    $message = "User added successfully! Generated PIN: {$new_pin}";
    $msg_type = 'success';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New User</title>
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
        .toast.success {
            background-color: #10b981; /* Green-500 */
        }
        .toast.error {
            background-color: #ef4444; /* Red-500 */
        }
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
    <div id="php-toast-data" data-message="<?php echo htmlspecialchars($message); ?>" data-type="<?php echo $msg_type; ?>" style="display:none;"></div>
<?php endif; ?>

<div class="w-[85%] ml-[15%]">
    <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
        
        <div class="flex items-center justify-between mb-6 border-b pb-2">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900">Add New User</h1>
        </div>
        
        <form method="POST" action="add_user.php" class="space-y-6">
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="emp_id" class="block text-sm font-medium text-gray-700">Employee ID <span class="text-red-500">*</span></label>
                    <input type="text" id="emp_id" name="emp_id" required maxlength="15" autofocus
                           class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 transition duration-150 ease-in-out"
                           placeholder="Enter ID to auto-fill">
                </div>
                
                <div>
                    <label for="calling_name" class="block text-sm font-medium text-gray-700">Calling Name <span class="text-red-500">*</span></label>
                    <input type="text" id="calling_name" name="calling_name" required maxlength="50"
                           class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 transition duration-150 ease-in-out"
                           placeholder="Auto-filled">
                    <p id="name_status" class="mt-1 text-xs text-gray-500">Auto-fills from employee records.</p>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="route_code" class="block text-sm font-medium text-gray-700">Route Code <span class="text-red-500">*</span></label>
                    <input type="text" id="route_code" name="route_code" required maxlength="10"
                           class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 transition duration-150 ease-in-out"
                           placeholder="Auto-filled">
                    <p id="route_status" class="mt-1 text-xs text-gray-500">Auto-fills from employee records.</p>
                </div>

                <div>
                    <label for="purpose" class="block text-sm font-medium text-gray-700">Purpose</label>
                    <select id="purpose" name="purpose" class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 bg-white">
                        <option value="Staff">Staff</option>
                        <option value="Factory">Factory</option>
                    </select>
                </div>
            </div>

            <div class="flex justify-between mt-8 pt-6 border-t border-gray-200">
                <a href="user.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" name="add_user"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105 flex items-center gap-2">
                    <i class="fas fa-user-plus"></i> Add User
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // --- Custom Toast Function (Matches requested style) ---
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container'); 
        const toast = document.createElement('div'); 
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

        // Show the toast
        setTimeout(() => toast.classList.add('show'), 10); 

        // Hide and remove
        setTimeout(() => { 
            toast.classList.remove('show'); 
            toast.addEventListener('transitionend', () => toast.remove(), { once: true }); 
        }, 4000); 
    } 

    $(document).ready(function() {
        // 1. Check for PHP Toast Message on Load
        const phpToast = $('#php-toast-data');
        if (phpToast.length) {
            const msg = phpToast.data('message');
            const type = phpToast.data('type');
            showToast(msg, type);

            // Clean URL
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('success');
                url.searchParams.delete('pin');
                window.history.replaceState({path: url.href}, '', url.href);
            }
        }

        // 2. AJAX Logic for Auto-Populating Fields
        let typingTimer;
        const doneTypingInterval = 500; // Time in ms (0.5 seconds)

        $('#emp_id').on('input', function() {
            clearTimeout(typingTimer);
            const empId = $(this).val().trim();
            
            // වෙනස්කම මෙතනයි:
            // කලින් තිබුනේ > 0 (ඒ කියන්නේ එක අකුරක් ගැහුවත් හොයනවා)
            // දැන් දාලා තියෙන්නේ >= 8 (ඒ කියන්නේ අකුරු 8ක් හෝ ඊට වැඩි නම් විතරයි හොයන්නේ)
            
            if (empId.length >= 8) { 
                typingTimer = setTimeout(function() {
                    fetchEmployeeData(empId);
                }, doneTypingInterval);
            } else {
                // අකුරු 8 ට අඩු නම් තියෙන ඩේටා ක්ලියර් කරනවා (Not Found එන්නේ නෑ)
                resetFields();
            }
        });

        function resetFields() {
            $('#calling_name').val('').prop('readonly', false).removeClass('readonly-field');
            $('#route_code').val('').prop('readonly', false).removeClass('readonly-field');
            $('#name_status').text('Auto-fills from employee records.');
            $('#route_status').text('Auto-fills from employee records.');
        }

        function fetchEmployeeData(empId) {
            $.ajax({
                url: 'fetch_employee_name.php', 
                method: 'GET',
                data: { emp_id: empId },
                dataType: 'json', 
                beforeSend: function() {
                    $('#name_status').text('Searching...');
                    $('#route_status').text('Searching...');
                },
                success: function(response) {
                    // Handle Calling Name
                    if (response.calling_name) {
                        $('#calling_name').val(response.calling_name).prop('readonly', true).addClass('readonly-field');
                        $('#name_status').text('Name loaded (Read-only).');
                        showToast("Employee found: " + response.calling_name, 'success');
                    } else {
                        $('#calling_name').val('').prop('readonly', false).removeClass('readonly-field');
                        $('#name_status').text('Not found. Enter manually.');
                        showToast("Employee ID not found in records.", 'error');
                    }
                    
                    // Handle Route
                    if (response.route) {
                        const shortRoute = response.route.substring(0, 10); 
                        $('#route_code').val(shortRoute).prop('readonly', true).addClass('readonly-field');
                        $('#route_status').text('Route loaded (Read-only).');
                    } else {
                        $('#route_code').val('').prop('readonly', false).removeClass('readonly-field');
                        $('#route_status').text('Route not found. Enter manually.');
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