<?php

// add_user.php - Simplified version for data insertion only

// -----------------------------------------------------
// FIX: Start Output Buffering (CRITICAL for clean output)
// This is kept for safety with included files, but download logic is removed.
// -----------------------------------------------------
ob_start(); 

// Include necessary files (adjust paths as needed)
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');
// The qrlib.php include is no longer needed:
// include('../../phpqrcode/qrlib.php'); 

// The temporary folder for QR codes is no longer needed:
// $qr_temp_dir = 'temp_qrs/';
// if (!file_exists($qr_temp_dir)) {
//     mkdir($qr_temp_dir, 0777, true);
// }

// Function to generate a random 4-digit PIN
function generate_pin() {
    return str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

// Function to generate a unique QR token (Keep for database field integrity)
function generate_qr_token() {
    return bin2hex(random_bytes(8)); // 16 character hex string
}

$message = '';
$is_success = false;

// -----------------------------------------------------
// --- POST SUBMISSION & INSERTION LOGIC ---
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. Sanitize and Get Input
    $emp_id = trim($_POST['emp_id']);
    $route_code = trim($_POST['route_code']);
    $purpose = trim($_POST['purpose']);
    $calling_name = trim($_POST['calling_name']); 

    // 2. Auto-Generate Credentials
    $pin = generate_pin(); // This ensures exactly 4 digits
    $qr_token = generate_qr_token(); // Still generate for database field

    // ----------------------------------------------------------------
    // QR URL construction and QR generation logic REMOVED
    // ----------------------------------------------------------------
    
    // 3. Database Insertion
    $sql_insert = "INSERT INTO `user` (emp_id, route_code, purpose, pin, qr_token, calling_name) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql_insert);

    if ($stmt) {
        $stmt->bind_param("ssssss", $emp_id, $route_code, $purpose, $pin, $qr_token, $calling_name);
        
        if ($stmt->execute()) {
            
            // Success message updated, no mention of QR/download
            $message = "User added successfully. PIN: {$pin}";
            $is_success = true;

            // Clear buffer content BEFORE redirect
            ob_clean(); 
            // Redirect to the same page with a simple success flag
            header("Location: add_user.php?success=1");
            exit();

        } else {
            $message = "Error adding user: " . $stmt->error;
            if ($conn->errno == 1062) { // Duplicate key error
                $message = "Error: Employee ID or Route Code already exists.";
            }
        }
        $stmt->close();
    } else {
        $message = "Database prepare error: " . $conn->error;
    }
}

// -----------------------------------------------------
// --- DOWNLOAD HANDLER REMOVED ---
// -----------------------------------------------------
// The entire block checking for $_GET['qr_path'] has been removed.

// Set success message for display after a successful redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    // Message updated to reflect no download
    $message = "User added successfully. PIN for the new user is available in the database.";
    $is_success = true;
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New User</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
</head>
<body class="bg-gray-100">

<div class="w-[85%] ml-[15%]">
    <div class="container mx-auto mt-10 p-6 bg-white shadow-lg rounded-lg max-w-lg border-t-4 border-blue-600">
        
        <div class="flex items-center justify-between mb-6">
            <a href="user.php" class="text-white flex items-center rounded-full bg-blue-900 py-2 px-2">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h2 class="text-3xl font-bold text-gray-800">Add New User</h2>
            <div class="w-1"></div>
        </div>

        <?php if ($message): ?>
            <div id="toastMessage" data-message="<?php echo htmlspecialchars($message); ?>" 
                 data-success="<?php echo $is_success ? 'true' : 'false'; ?>">
            </div>
        <?php endif; ?>

        <form method="POST" action="add_user.php" class="space-y-4">
            <div>
                <label for="emp_id" class="block text-sm font-medium text-gray-700">Employee ID <span class="text-red-500">*</span></label>
                <input type="text" id="emp_id" name="emp_id" required maxlength="15"
                        class="mt-1 block w-full border border-gray-300 p-2 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label for="route_code" class="block text-sm font-medium text-gray-700">Route Code <span class="text-red-500">*</span></label>
                <input type="text" id="route_code" name="route_code" required maxlength="10"
                        class="mt-1 block w-full border border-gray-300 p-2 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                <p id="route_status" class="mt-1 text-xs text-gray-500">Route Code will auto-fill from employee records (max 10 chars).</p>
            </div>
            
            <div>
                <label for="calling_name" class="block text-sm font-medium text-gray-700">Calling Name <span class="text-red-500">*</span></label>
                <input type="text" id="calling_name" name="calling_name" required maxlength="50"
                        class="mt-1 block w-full border border-gray-300 p-2 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                <p id="name_status" class="mt-1 text-xs text-gray-500">Calling Name will auto-fill from employee records.</p>
            </div>

            <div>
                <label for="purpose" class="block text-sm font-medium text-gray-700">Purpose</label>
                <select id="purpose" name="purpose" class="mt-1 block w-full border border-gray-300 p-2 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="Staff">Staff</option>
                    <option value="Factory">Factory</option>
                </select>
            </div>

            <button type="submit" name="generate_qr"
                    class="w-full py-2 px-4 border border-transparent rounded-md shadow-sm text-lg font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Add User
            </button>
        </form>
    </div>
</div>

</body>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
// Function to handle the Toast messages
function showToast(message, isSuccess = true) {
    const backgroundStyle = isSuccess 
        ? "linear-gradient(to right, #00b09b, #96c93d)" 
        : "linear-gradient(to right, #ff5f6d, #ffc371)"; 

    Toastify({
        text: message,
        duration: isSuccess ? 5000 : 7000,
        close: true,
        gravity: "top",
        position: "right",
        style: {
            background: backgroundStyle,
        }
    }).showToast();
}

$(document).ready(function() {
    // Check for PHP message on load
    const toastDiv = $('#toastMessage');
    if (toastDiv.length) {
        const message = toastDiv.data('message');
        const isSuccess = toastDiv.data('success') === 'true';
        showToast(message, isSuccess);
        
        // Clean up the URL query parameters
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('success');
            // QR path/name parameters are removed from PHP, but kept for robustness
            url.searchParams.delete('qr_path');
            url.searchParams.delete('qr_name');
            window.history.replaceState({path: url.href}, '', url.href);
        }
    }

    // --- AJAX LOGIC FOR AUTO-POPULATING CALLING NAME AND ROUTE CODE ---
    let typingTimer;
    const doneTypingInterval = 500; 

    $('#emp_id').on('input', function() {
        clearTimeout(typingTimer);
        const empId = $(this).val().trim();
        
        if (empId.length > 0) {
            typingTimer = setTimeout(function() {
                fetchEmployeeData(empId);
            }, doneTypingInterval);
        } else {
            // Reset fields if input is cleared
            $('#calling_name').val('').prop('readonly', false);
            $('#route_code').val('').prop('readonly', false);
            $('#name_status').text('Calling Name will auto-fill from employee records.');
            $('#route_status').text('Route Code will auto-fill from employee records (max 10 chars).');
        }
    });

    function fetchEmployeeData(empId) {
        $.ajax({
            url: 'fetch_employee_name.php', 
            method: 'GET',
            data: { emp_id: empId },
            dataType: 'json', 
            beforeSend: function() {
                $('#name_status').text('Searching employee records...');
                $('#route_status').text('Searching employee records...');
                $('#calling_name').prop('readonly', true);
                $('#route_code').prop('readonly', true);
            },
            success: function(response) {
                $('#calling_name').prop('readonly', false);
                $('#route_code').prop('readonly', false);

                if (response.calling_name) {
                    $('#calling_name').val(response.calling_name);
                    $('#name_status').text('Name loaded successfully. (Read-only)');
                    $('#calling_name').prop('readonly', true);
                } else {
                    $('#calling_name').val('');
                    $('#name_status').text('Employee ID not found. Please enter Calling Name manually.');
                }
                
                if (response.route) {
                    // Truncate the route to a maximum of 10 characters
                    const shortRoute = response.route.substring(0, 10); 
                    $('#route_code').val(shortRoute);
                    $('#route_status').text(`Route loaded successfully: ${shortRoute}. (Read-only)`);
                    $('#route_code').prop('readonly', true);
                } else {
                    $('#route_code').val('');
                    $('#route_status').text('Route not found. Please enter Route Code manually (max 10 chars).');
                }
            },
            error: function(xhr, status, error) {
                console.error("Error fetching employee name:", status, error);
                showToast("Could not check Employee ID due to error. Please enter details manually.", false);
                $('#calling_name').prop('readonly', false);
                $('#route_code').prop('readonly', false);
                $('#name_status').text('Network error. Please enter Calling Name manually.');
                $('#route_status').text('Network error. Please enter Route Code manually.');
            }
        });
    }
});
</script>
<?php ob_end_flush(); ?>
</html>