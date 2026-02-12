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
include('../../includes/header.php');
include('../../includes/navbar.php');

$toast_message = "";
$toast_type = "";

// 1. Fetch GL codes and names
$gl_options = [];
$gl_sql = "SELECT gl_code, gl_name FROM gl ORDER BY gl_name ASC";
$gl_result = $conn->query($gl_sql);

if ($gl_result && $gl_result->num_rows > 0) {
    while ($row = $gl_result->fetch_assoc()) {
        $gl_options[] = $row;
    }
}

// Handle adding a new reason (POST Request)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['new_reason_code'], $_POST['new_reason_text'], $_POST['gl_code'])) {
    
    $new_reason_code = trim($_POST['new_reason_code']); 
    $new_reason_text = trim($_POST['new_reason_text']);
    $selected_gl_code = trim($_POST['gl_code']); 

    if (empty($new_reason_code) || empty($new_reason_text) || empty($selected_gl_code)) {
        $toast_message = "Error: All fields are required.";
        $toast_type = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO reason (reason_code, reason, gl_code) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $new_reason_code, $new_reason_text, $selected_gl_code);

        if ($stmt->execute()) {
            // SUCCESS - Redirect
            $stmt->close();
            $conn->close();
            // Redirect logic with JS is handled below to show success if needed, 
            // but standard PHP redirect is cleaner for non-AJAX
            echo "<script>window.location.href='reason.php';</script>";
            exit(); 
        } else {
            // DB Error
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

// Ensure DB connection is closed
if (isset($conn) && $conn->ping()) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Reason</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
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
        }
        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
    <script>
        // Session Timeout Logic
        const SESSION_TIMEOUT_MS = 32400000; // 9 hours
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>
<body class="bg-gray-100 font-sans">

    <div id="toast-container"></div>

    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add New Reason</h1>
            
            <form action="add_reason.php" method="POST" class="space-y-6">
                
                <div class="grid md:grid-cols-1 gap-6">
                    <div>
                        <label for="new_reason_code" class="block text-sm font-medium text-gray-700">Reason Code (Unique ID):</label>
                        <input type="text" id="new_reason_code" name="new_reason_code" required
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"
                            value="<?php echo isset($_POST['new_reason_code']) ? htmlspecialchars($_POST['new_reason_code']) : ''; ?>">
                    </div>

                    <div>
                        <label for="new_reason_text" class="block text-sm font-medium text-gray-700">Reason Description:</label>
                        <input type="text" id="new_reason_text" name="new_reason_text" required
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"
                            value="<?php echo isset($_POST['new_reason_text']) ? htmlspecialchars($_POST['new_reason_text']) : ''; ?>">
                    </div>

                    <div>
                        <label for="gl_code" class="block text-sm font-medium text-gray-700">Reason Category (GL Name):</label>
                        <select id="gl_code" name="gl_code" required
                            class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            
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
                </div>

                <div class="flex justify-between mt-6 pt-4 border-t border-gray-200">
                    <a href="reason.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Cancel
                    </a>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Add Reason
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function showToast(message, type = 'success') {
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
                <span>${message}</span>
            `;

            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 3000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // PHP Logic to trigger toast if message exists
            <?php 
            if (!empty($toast_message) && isset($toast_type)): 
            ?>
                showToast("<?php echo htmlspecialchars($toast_message, ENT_QUOTES, 'UTF-8'); ?>", "<?php echo htmlspecialchars($toast_type, ENT_QUOTES, 'UTF-8'); ?>");
            <?php endif; ?>
        });
    </script>
</body>
</html>