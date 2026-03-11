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
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reason_prefix'], $_POST['new_reason_code'], $_POST['new_reason_text'], $_POST['gl_code'])) {
    
    // Prefix එක සහ Code එක එකතු කර සම්පූර්ණ Code එක සාදා ගැනීම
    $full_reason_code = trim($_POST['reason_prefix']) . trim($_POST['new_reason_code']); 
    $new_reason_text = trim($_POST['new_reason_text']);
    $selected_gl_code = trim($_POST['gl_code']); 

    if (empty(trim($_POST['new_reason_code'])) || empty($new_reason_text) || empty($selected_gl_code)) {
        $toast_message = "Error: All fields are required.";
        $toast_type = "error";
    } else {
        // පළමුව මෙම Code එක දැනටමත් තිබේදැයි පරීක්ෂා කිරීම
        $check_stmt = $conn->prepare("SELECT reason_code FROM reason WHERE reason_code = ?");
        $check_stmt->bind_param("s", $full_reason_code);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $toast_message = "Error: Reason Code '" . htmlspecialchars($full_reason_code) . "' already exists.";
            $toast_type = "error";
            $check_stmt->close();
        } else {
            $check_stmt->close();
            // Database එකට ඇතුළත් කිරීම
            $stmt = $conn->prepare("INSERT INTO reason (reason_code, reason, gl_code) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $full_reason_code, $new_reason_text, $selected_gl_code);

            if ($stmt->execute()) {
                $stmt->close();
                $conn->close();
                echo "<script>window.location.href='reason.php';</script>";
                exit(); 
            } else {
                $toast_message = "Error adding reason: " . $stmt->error;
                $toast_type = "error";
                $stmt->close();
            }
        }
    }
}

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
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: all 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
    </style>
</head>
<body class="bg-gray-100 font-sans">

    <div id="toast-container"></div>

    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10 mx-auto">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add New Reason</h1>
            
            <form action="" method="POST" class="space-y-6">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700">Reason Code (Unique ID):</label>
                    <div class="flex mt-1">
                        <select name="reason_prefix" class="rounded-l-md border-r-0 border-gray-300 bg-gray-50 text-gray-600 sm:text-sm p-2 border focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="R-" <?php echo (isset($_POST['reason_prefix']) && $_POST['reason_prefix'] == 'R-') ? 'selected' : ''; ?>>R-</option>
                            <option value="SUB-" <?php echo (isset($_POST['reason_prefix']) && $_POST['reason_prefix'] == 'SUB-') ? 'selected' : ''; ?>>SUB-</option>
                        </select>
                        <input type="text" id="new_reason_code" name="new_reason_code" required
                            placeholder="e.g. 101"
                            class="flex-1 block w-full rounded-r-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border"
                            value="<?php echo isset($_POST['new_reason_code']) ? htmlspecialchars($_POST['new_reason_code']) : ''; ?>">
                    </div>
                </div>

                <div>
                    <label for="new_reason_text" class="block text-sm font-medium text-gray-700">Reason Description:</label>
                    <input type="text" id="new_reason_text" name="new_reason_text" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border"
                        value="<?php echo isset($_POST['new_reason_text']) ? htmlspecialchars($_POST['new_reason_text']) : ''; ?>">
                </div>

                <div>
                    <label for="gl_code" class="block text-sm font-medium text-gray-700">Reason Category (GL Name):</label>
                    <select id="gl_code" name="gl_code" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                        <option value="" disabled <?php echo !isset($_POST['gl_code']) ? 'selected' : ''; ?>>-- Select GL Category --</option>
                        <?php foreach ($gl_options as $gl): ?>
                            <option value="<?php echo htmlspecialchars($gl['gl_code']); ?>"
                                <?php echo (isset($_POST['gl_code']) && $_POST['gl_code'] === $gl['gl_code']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($gl['gl_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex justify-between mt-6 pt-4 border-t border-gray-200">
                    <a href="reason.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300">
                        Cancel
                    </a>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300">
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
            const icon = type === 'success' ? 
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />' : 
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />';

            toast.innerHTML = `<svg class="toast-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">${icon}</svg><span>${message}</span>`;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove());
            }, 4000);
        }

        <?php if (!empty($toast_message)): ?>
            showToast("<?php echo $toast_message; ?>", "<?php echo $toast_type; ?>");
        <?php endif; ?>
    </script>
</body>
</html>