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

// Initialize toast variables (for handling redirects from add_reason.php)
$toast_message = isset($_GET['toast_message']) ? $_GET['toast_message'] : null;
$toast_type = isset($_GET['toast_type']) ? $_GET['toast_type'] : null;

// Fetch All Reasons using SQL JOIN
// We join 'reason' table with 'gl' table on gl_code
$sql = "
    SELECT 
        r.reason_code, 
        r.reason, 
        r.gl_code, 
        g.gl_name 
    FROM 
        reason r
    JOIN 
        gl g ON r.gl_code = g.gl_code
    ORDER BY 
        g.gl_name, r.reason_code ASC";

$result = $conn->query($sql);

$reasons = [];
$reason_groups = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $reasons[] = $row;
        
        // Collect unique GL Names (Categories) for filtering
        if (!in_array($row['gl_name'], $reason_groups)) {
            $reason_groups[] = $row['gl_name'];
        }
    }
}

$conn->close(); // Close DB connection after fetching data

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reasons</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* ... (Your existing toast CSS styles remain here) ... */
        #toast-container {
            position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex;
            flex-direction: column; align-items: flex-end;
        }
        .toast {
            display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem;
            border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px); opacity: 0;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
    </style>
</head>
<script>
    // Session timeout script remains the same
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100 text-gray-800">
    <div class="h-screen">
        <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
            <div class="text-lg font-semibold ml-3">Operational Service</div>
            <div class="flex gap-4">
                <a href="op_services.php" class="hover:text-yellow-600">Services</a>
                <a href="add_reason.php" class="hover:text-yellow-600">Add Reason</a>
            </div>
        </div>

        <div class="flex justify-center items-start w-[85%] ml-[15%] h-[95%] pt-4">
            <div class="w-3xl mx-auto p-6 bg-white rounded-lg shadow-md">
                <div class="flex justify-between items-center mb-6"> <h2 class="text-2xl font-bold text-blue-600">Reason List</h2>

                    <div class="w-full max-w-lg"> <div class="flex items-center gap-4"> 
                            <label for="group_filter" class="text-gray-700 font-medium whitespace-nowrap">
                                Filter by Category:
                            </label>
                            
                            <select id="group_filter" onchange="filterReasons()"
                                class="flex-grow px-4 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 break-words">
                                
                                <option value="">All Categories</option> 
                                <?php foreach ($reason_groups as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>">
                                        <?php echo htmlspecialchars($category); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="overflow-x-auto mb-4">
                    <table class="min-w-full bg-white border border-gray-300">
                        <thead>
                            <tr class="bg-gray-100 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">
                                <th class="py-2 px-4 border-b">Reason Code</th>
                                <th class="py-2 px-4 border-b">Reason</th>
                                <th class="py-2 px-4 border-b">Category</th> </tr>
                        </thead>
                        <tbody id="reason-table-body">
                            <?php if (!empty($reasons)): ?>
                                <?php foreach ($reasons as $reason): ?>
                                    <tr class="reason-item text-sm text-gray-700 hover:bg-gray-50" data-group="<?php echo htmlspecialchars($reason['gl_name']); ?>">
                                        <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($reason['reason_code']); ?></td>
                                        <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($reason['reason']); ?></td>
                                        <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($reason['gl_name']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="py-4 text-center text-gray-500">No reasons found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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

        function filterReasons() {
            // Filter based on GL Name (Category)
            const selectedCategory = document.getElementById('group_filter').value;
            const reasons = document.querySelectorAll('#reason-table-body .reason-item'); 

            reasons.forEach(reason => {
                const reasonCategory = reason.getAttribute('data-group');
                // Display if selectedCategory is empty (All) OR if it matches the row's category (gl_name)
                if (selectedCategory === '' || reasonCategory === selectedCategory) {
                    reason.style.display = 'table-row'; 
                } else {
                    reason.style.display = 'none';
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            filterReasons();
            
            // Show toast if message is present (e.g., success redirect from add_reason.php)
            <?php if (isset($toast_message) && isset($toast_type)): ?>
                // Note: The add_reason.php must send the toast parameters via URL on error/success redirect
                showToast("<?php echo htmlspecialchars($toast_message, ENT_QUOTES, 'UTF-8'); ?>", "<?php echo htmlspecialchars($toast_type, ENT_QUOTES, 'UTF-8'); ?>");
            <?php endif; ?>
        });
    </script>
</body>
</html>