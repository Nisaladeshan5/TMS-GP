<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

$toast_message = "";
$toast_type = "";

// 1. Fetch GL codes and names from the 'gl' table for the dropdown
$gl_options = [];
$gl_sql = "SELECT gl_code, gl_name FROM gl ORDER BY gl_name ASC";
$gl_result = $conn->query($gl_sql);

if ($gl_result && $gl_result->num_rows > 0) {
    while ($row = $gl_result->fetch_assoc()) {
        $gl_options[] = $row;
    }
}
// Note: We no longer rely on $allowed_categories array for transport types, 
// we use the actual data from the 'gl' table.

// Handle adding a new reason
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['new_reason_code'], $_POST['new_reason_text'], $_POST['gl_code'])) {
    
    $new_reason_code = trim($_POST['new_reason_code']); 
    $new_reason_text = trim($_POST['new_reason_text']);
    $selected_gl_code = trim($_POST['gl_code']); 

    // 1. Validation check for required fields
    if (empty($new_reason_code) || empty($new_reason_text) || empty($selected_gl_code)) {
        $toast_message = "Error: All fields (Code, Text, Category) are required.";
        $toast_type = "error";
    } 
    // 2. We skip the array validation since we trust the database lookup
    
    // 3. Proceed with DB insertion
    else {
        $stmt = $conn->prepare("INSERT INTO reason (reason_code, reason, gl_code) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $new_reason_code, $new_reason_text, $selected_gl_code);

        if ($stmt->execute()) {
            // SUCCESS - Redirect to reason.php WITHOUT any toast parameters
            $stmt->close();
            $conn->close();
            header("Location: reason.php"); 
            exit(); 
            
        } else {
            // DB Error - Show toast on the current page (add_reason.php)
            $error_info = $stmt->error;
            if (strpos($error_info, 'Duplicate entry') !== false) {
                 $toast_message = "Error: Reason Code '" . htmlspecialchars($new_reason_code) . "' already exists.";
            } else {
                 $toast_message = "Error adding reason: " . $error_info;
            }
            $toast_type = "error";
            $stmt->close();
        }
    }
}

// Ensure DB connection is closed if not already closed by successful redirect
if (isset($conn) && $conn->ping()) {
    $conn->close();
}


include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Reason</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Toast styles */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 1; transition: opacity 0.3s; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="h-screen flex">
        <div class="w-[15%]">
            <?php // include('../../includes/navbar.php'); ?>
        </div>
        
        <div class="w-[85%] flex flex-col">
            <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg h-[5%]">
                <div class="text-lg font-semibold ml-3">Add Reason</div>
                <div class="flex gap-4">
                    <a href="reason.php" class="hover:text-yellow-600 text-yellow-500 font-bold">Back to Reason List</a>
                </div>
            </div>

            <div class="flex-grow flex justify-center items-center p-6">
                <div class="w-full max-w-lg bg-white rounded-lg shadow-xl p-8 border border-blue-200">
                    <h2 class="text-3xl font-bold mb-6 text-center text-blue-800">Add New Transport Reason</h2>
                    
                    <form action="add_reason.php" method="POST"> 
                        
                        <div class="mb-4">
                            <label for="new_reason_code" class="block text-gray-700 font-medium mb-1">Reason Code (Unique ID):</label>
                            <input type="text" id="new_reason_code" name="new_reason_code" required
                                class="w-full px-4 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                value="<?php echo isset($_POST['new_reason_code']) ? htmlspecialchars($_POST['new_reason_code']) : ''; ?>">
                        </div>

                        <div class="mb-4">
                            <label for="new_reason_text" class="block text-gray-700 font-medium mb-1">Reason Description:</label>
                            <input type="text" id="new_reason_text" name="new_reason_text" required
                                class="w-full px-4 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['new_reason_text']) ? htmlspecialchars($_POST['new_reason_text']) : ''; ?>">
                        </div>

                        <div class="mb-6">
                            <label for="gl_code" class="block text-gray-700 font-medium mb-1">Reason Category (GL Name):</label>
                            <select id="gl_code" name="gl_code" required
                                class="w-full px-4 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                
                                <option value="" disabled <?php echo !isset($_POST['gl_code']) ? 'selected' : ''; ?>>-- Select GL Category --</option>
                                
                                <?php if (!empty($gl_options)): ?>
                                    <?php foreach ($gl_options as $gl): ?>
                                        <option value="<?php echo htmlspecialchars($gl['gl_code']); ?>"
                                            <?php echo (isset($_POST['gl_code']) && $_POST['gl_code'] === $gl['gl_code']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($gl['gl_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No GL Categories found</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 px-4 rounded-lg shadow-md hover:bg-blue-700 transition-colors">
                            Add Reason to Database
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <div id="toast-container"></div>
    <script>
        function showToast(message, type = 'success') {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const iconSvg = type === 'success' ? 
                '<path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.293 12.5a1.003 1.003 0 0 1-1.417 0L2.354 8.7a.733.733 0 0 1 1.047-1.05l3.245 3.246 6.095-6.094z"/>' :
                '<path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/> <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>';
                
            toast.innerHTML = `<svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16">${iconSvg}</svg><p class="font-semibold">${message}</p>`;

            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => toast.classList.remove('show'), 3000);
            setTimeout(() => toast.remove(), 3500);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // This now only shows the toast if an ERROR occurred (because success redirects without parameters)
            <?php 
            if (!empty($toast_message) && isset($toast_type)): 
            ?>
                showToast("<?php echo htmlspecialchars($toast_message, ENT_QUOTES, 'UTF-8'); ?>", "<?php echo htmlspecialchars($toast_type, ENT_QUOTES, 'UTF-8'); ?>");
            <?php endif; ?>
        });
    </script>
</body>
</html>