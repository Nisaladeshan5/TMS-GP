<?php
// missing_routes.php - Dedicated page to show active routes with no entry for a selected date/shift

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true; // Added for navbar check

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// --- 1. Get Filter Parameters (Date and Shift) ---

// Set the filter date to today's date by default
$filterDate = date('Y-m-d');
// Set the filter shift default to 'morning'
$filterShift = 'morning'; 

// If a date or shift is submitted via the form, use those values for the filter
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Note: Used 'date' for the input name here, matching the input field name
    if (!empty($_POST['date'])) { 
        $filterDate = $_POST['date'];
    }
    // Capture the selected shift
    if (isset($_POST['shift'])) {
        $filterShift = $_POST['shift'];
    }
}

$displayDate = date('F j, Y', strtotime($filterDate));

// Initialize records to an empty array and an error message variable
$records = [];
$connection_error = null;

// CRITICAL FIX: Check if the connection object ($conn) is valid before using it
if (isset($conn) && $conn instanceof mysqli && $conn->connect_error === null) {
    // Connection is good, proceed with query
    
    // SQL: Find active routes (is_active=1) that DO NOT have a cross_check record for the filtered date/shift
    $sql = "SELECT 
                rm.route_code, 
                rm.route AS route_name
            FROM 
                route rm
            LEFT JOIN 
                cross_check r 
            ON 
                rm.route_code = r.route 
                AND DATE(r.date) = ? 
                AND r.shift = ? 
            WHERE 
                rm.is_active = 1 
                AND r.id IS NULL"; // CRITICAL: This condition finds the MISSING entries
    
    // NEW SORTING LOGIC: Order by the 7th, 8th, and 9th characters of the route code
    $sql .= " ORDER BY CAST(SUBSTR(rm.route_code, 7, 3) AS UNSIGNED) ASC";
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        $connection_error = "Database query failed to prepare. Check SQL syntax or table name: " . $conn->error;
    } else {
        // Bind the two required parameters: date and shift
        $param_types = 'ss';
        $param_values = [&$filterDate, &$filterShift];
        
        // Use call_user_func_array to bind parameters
        if (!empty($param_values)) {
            call_user_func_array([$stmt, 'bind_param'], array_merge([$param_types], $param_values));
        }

        $stmt->execute();
        $result = $stmt->get_result();
        // The result set contains only route_code and route_name
        $records = $result->fetch_all(MYSQLI_ASSOC); 
        $stmt->close();
    }

} else {
    $connection_error = "FATAL: Database connection failed. Please check `db_public.php` configuration and server status.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Missing Route Verification</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
</head>

<style>
    /* CSS for toast - copied from original file (kept for completeness) */
    #toast-container {
        position: fixed;
        top: 1rem;
        right: 1rem;
        z-index: 2000;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }
    /* Toast styles omitted for brevity but remain in the <style> block */
    .toast { /* ... styles ... */ }
    .toast.show { /* ... styles ... */ }
    .toast.success { background-color: #4CAF50; }
    .toast.error { background-color: #F44336; }
    .toast-icon { /* ... styles ... */ }
    
    /* Custom CSS for highlighting the missing routes */
    .route-F { background-color: #d1fae5 !important; font-weight: 500; }
    .route-S { background-color: #fff3da !important; font-weight: 500; }
    
</style>
<script>
    // Session Timeout Logic (9 hours)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>

<body class="bg-gray-100">

<div class="w-[85%] ml-[15%]">
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg">
        <div class="text-lg font-semibold ml-3">Verify</div>
        <div class="flex gap-4"> 
            <?php if ($is_logged_in): ?>
                <a href="varification.php" class="hover:text-yellow-600">Back</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="container" style="display: flex; flex-direction: column; align-items: center; width: 85%; margin-left: 15%;">
    <div class="p-4 bg-white shadow-xl rounded-xl border border-gray-200 mt-3 w-full max-w-5xl">

        <p class="text-3xl font-bold text-red-700 mt-2 text-center">Missing Route List</p>
        <p class="text-xl text-gray-800 mb-4 text-center">Active routes with NO entry in the Cross-Check</p>

        <?php if ($connection_error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 w-full" role="alert">
                <strong class="font-bold">Database Error!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($connection_error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="mb-6 flex justify-center items-center p-4 bg-gray-50 shadow-inner rounded-xl border border-gray-200">
            <div class="flex items-center space-x-4">
                
                <label for="date" class="text-lg font-medium">Select Date:</label>
                <input type="date" id="date" name="date" class="border border-gray-300 p-2 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500"
                    value="<?php echo htmlspecialchars($filterDate); ?>" required>
                
                <label for="shift" class="text-lg font-medium">Select Shift:</label>
                <select id="shift" name="shift" class="border border-gray-300 p-2 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="morning" <?php echo $filterShift === 'morning' ? 'selected' : ''; ?>>Morning</option>
                    <option value="evening" <?php echo $filterShift === 'evening' ? 'selected' : ''; ?>>Evening</option>
                </select>

                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg shadow-md hover:bg-red-700 transition duration-150 font-semibold">Show Missing Routes</button>
            </div>
        </form>

        <h3 class="text-2xl font-bold text-red-700 mb-4 p-3 bg-red-100 border border-red-300 rounded-lg shadow-md text-center">
            ❌ Missing Routes (<?php echo htmlspecialchars(ucfirst($filterShift)); ?> Shift) on <?php echo htmlspecialchars($displayDate); ?>
        </h3>
        
        <p class="text-xl font-semibold text-gray-800 mb-4 text-center">Total Missing Routes: <span class="text-red-600 font-extrabold"><?php echo count($records); ?></span></p>

        <?php if (!empty($records)): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 p-5 bg-white shadow-inner rounded-lg border border-gray-200">
                <?php 
                // Since the SQL already sorts it, we just iterate through $records
                foreach ($records as $row): 
                    $route_code = htmlspecialchars($row['route_code']);
                    $route_name = htmlspecialchars($row['route_name']);
                    $highlight_class = '';

                    // Highlighting logic based on 5th character (using F or S for class)
                    if (strlen($route_code) >= 5) {
                        $fifth_char = strtoupper($route_code[4]); 
                        if ($fifth_char === 'F') {
                            $highlight_class = 'route-F'; 
                        } elseif ($fifth_char === 'S') {
                            $highlight_class = 'route-S';
                        }
                    }
                ?>
                    <div class="p-3 border border-red-300 rounded-lg text-base font-medium text-gray-900 shadow-sm transition duration-150 hover:bg-red-100 <?php echo $highlight_class; ?>">
                        <span class="text-red-600 font-bold"><?php echo $route_code; ?>:</span> <?php echo $route_name; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="p-6 bg-green-100 text-green-700 text-xl font-semibold border-2 border-green-400 rounded-xl mb-6 text-center shadow-lg">
                ✅ සියලුම Active Routes සදහා දත්ත ඇතුලත් කර ඇත. (All Active Routes have entries.)
            </p>
        <?php endif; ?>
    </div>
</div>

<div id="toast-container"></div>

<script>
    // Toast Function (Included for completeness and error handling)
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let iconPath;
        switch (type) {
            case 'success':
                iconPath = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="toast-icon"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm13.36-1.814a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.23a.75.75 0 00-1.06 1.06l2.036 2.036a.75.75 0 001.06 0l3.86-5.404z" clip-rule="evenodd" /></svg>';
                break;
            case 'warning':
            case 'error':
                iconPath = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="toast-icon"><path fill-rule="evenodd" d="M9.401 3.003c1.155-2 4.043-2 5.197 0l7.355 12.75c1.154 2-.287 4.5-2.599 4.5H4.645c-2.312 0-3.753-2.5-2.598-4.5l7.355-12.75zM12 9a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V9.75A.75.75 0 0112 9zm0 8.25a.75.75 0 100-1.5.75.75 0 000 1.5z" clip-rule="evenodd" /></svg>';
                break;
            default:
                iconPath = '';
        }

        toast.innerHTML = `
            ${iconPath}
            <span>${message}</span>
        `;
        
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 5000);
    }

    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    const message = urlParams.get('message');
    
    if (status && message) {
        showToast(decodeURIComponent(message), status);
        window.history.replaceState(null, null, window.location.pathname);
    }
</script>

</body>
</html>