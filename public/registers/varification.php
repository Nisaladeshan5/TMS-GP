<?php
// varification.php

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Set the filter date to today's date by default
$filterDate = date('Y-m-d');
// 🔑 UPDATED: Set the filter shift default to 'morning' (removed 'all')
$filterShift = 'morning'; 

// If a date or shift is submitted via the form, use those values for the filter
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!empty($_POST['date'])) {
        $filterDate = $_POST['date'];
    }
    // Capture the selected shift
    if (isset($_POST['shift'])) {
        $filterShift = $_POST['shift'];
    }
}

// Initialize records to an empty array and an error message variable
$records = [];
$connection_error = null;

// CRITICAL FIX: Check if the connection object ($conn) is valid before using it
if (isset($conn) && $conn instanceof mysqli && $conn->connect_error === null) {
    // Connection is good, proceed with query
    
    // 🔑 UPDATED SQL: Include LEFT JOIN to get the route name
    // Assuming route table has columns: route_code and route (route name)
    $sql = "SELECT r.id, r.route AS route_code, rm.route, r.actual_vehicle_no, r.driver_NIC, r.time, r.shift
            FROM cross_check r
            LEFT JOIN route rm ON r.route = rm.route_code
            WHERE DATE(r.date) = ?";
            
    // ADD shift condition (no need to check for 'all' since it's removed from options)
    if ($filterShift !== 'all') { // Keeping this check just in case, but 'all' shouldn't be selected
        $sql .= " AND r.shift = ?";
    }
    
    // 🔑 NEW SORTING LOGIC: Order by the 7th, 8th, and 9th characters of the route code, cast as an integer.
    // SUBSTR(r.route, 7, 3) takes 3 characters starting from position 7 (7th, 8th, 9th).
    // CAST( ... AS UNSIGNED) ensures numeric sorting (001, 002, 010 etc.).
    $sql .= " ORDER BY CAST(SUBSTR(r.route, 7, 3) AS UNSIGNED) ASC, r.time ASC";
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        $connection_error = "Database query failed to prepare. Check SQL syntax or table name: " . $conn->error;
    } else {
        // Bind parameters dynamically
        $param_types = 's';
        $param_values = [&$filterDate];
        
        if ($filterShift !== 'all') {
            $param_types .= 's';
            $param_values[] = &$filterShift;
        }
        
        // Use call_user_func_array to bind parameters
        if (!empty($param_values)) {
            call_user_func_array([$stmt, 'bind_param'], array_merge([$param_types], $param_values));
        }

        $stmt->execute();
        $result = $stmt->get_result();
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
    <title>Verify Vehicle Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
</head>

<style>
    /* CSS for toast */
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
        max-width: 400px;
    }

    .toast.show {
        transform: translateY(0);
        opacity: 1;
    }

    .toast.success {
        background-color: #4CAF50;
    }
    .toast.warning {
        background-color: #ff9800;
    }
    .toast.error {
        background-color: #F44336;
    }

    .toast-icon {
        width: 1.5rem;
        height: 1.5rem;
        margin-right: 0.75rem;
    }
    
    /* Custom CSS for highlighting the entire row */
    .route-F {
        background-color: #d1fae5 !important; /* Tailwind bg-green-100 */
        font-weight: 500;
    }
    .route-S {
        background-color: #fff3da !important; /* Light Orange/Yellow */
        font-weight: 500;
    }
    
    /* Ensure text color is readable when highlighting rows */
    .route-F td, .route-S td {
        color: #1f2937; /* Dark text color */
    }
    
</style>
<script>
    // Session Timeout Logic (9 hours)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>

<body class="bg-gray-100">
<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
    <div class="text-lg font-semibold ml-3">Verify</div>
    <div class="flex gap-4">
        <a href="missing_routes.php" class="hover:text-yellow-600">Missing Routes</a>
    </div>
</div>
<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[48px] font-bold text-gray-800 mt-2">Verify Vehicle Details</p>

    <?php if ($connection_error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 w-full max-w-4xl" role="alert">
            <strong class="font-bold">Database Error!</strong>
            <span class="block sm:inline"><?php echo htmlspecialchars($connection_error); ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" class="mb-6 flex justify-center">
        <div class="flex items-center space-x-4">
            
            <div class="flex items-center">
                <label for="date" class="text-lg font-medium mr-2">Filter by Date:</label>
                <input type="date" id="date" name="date" class="border border-gray-300 p-2 rounded-md"
                       value="<?php echo htmlspecialchars($filterDate); ?>" required>
            </div>
            
            <div class="flex items-center">
                <label for="shift" class="text-lg font-medium mr-2">Filter by Shift:</label>
                <select id="shift" name="shift" class="border border-gray-300 p-2 rounded-md">
                    <?php
                    // 🔑 UPDATED: Assumed shift values (Removed 'all')
                    $shifts = ['morning', 'evening']; 
                    foreach ($shifts as $shift) {
                        $selected = ($filterShift === $shift) ? 'selected' : '';
                        echo "<option value='{$shift}' {$selected}>" . ucfirst($shift) . "</option>";
                    }
                    ?>
                </select>
            </div>
            
            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md hover:bg-blue-600">Filter</button>
            
        </div>
    </form>

    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6 w-[80%]">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-6 py-2 text-left">Route Code</th>
                    <th class="px-6 py-2 text-left">Route</th> 
                    <th class="px-6 py-2 text-left">Vehicle No</th>
                    <th class="px-6 py-2 text-left">Driver LID</th>
                    <th class="px-6 py-2 text-left">Time</th>
                    <th class="px-6 py-2 text-left">Shift</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (count($records) > 0) {
                    $row_counter = 0;
                    foreach ($records as $row) {
                        $row_counter++;
                        $time = ($row['time'] !== null) ? date('H:i', strtotime($row['time'])) : '-';
                        $time_display = "{$time}";
                        
                        // Check the 5th character of the route code
                        $route_code = $row['route_code']; // Used route_code from the query
                        $highlight_class = '';
                        
                        // Check if the route code is long enough (at least 5 characters)
                        if (strlen($route_code) >= 5) {
                            $fifth_char = strtoupper($route_code[4]); // 5th character is at index 4
                            
                            if ($fifth_char === 'F') {
                                $highlight_class = 'route-F'; // Green highlight
                            } elseif ($fifth_char === 'S') {
                                $highlight_class = 'route-S'; // Orange highlight
                            }
                        }
                        
                        // Add alternate row color for rows without special highlighting
                        $base_row_class = ($row_counter % 2 == 0) ? 'bg-gray-50' : '';
                        
                        // Get Route Name (handle case where LEFT JOIN returns NULL)
                        $route_name = !empty($row['route']) ? htmlspecialchars($row['route']) : 'N/A';
                        
                        // Applying the highlight class to the TR tag
                        echo "<tr class='border-b {$base_row_class} {$highlight_class}'>
                            <td class='border px-6 py-2'>{$route_code}</td>
                            <td class='border px-6 py-2 font-semibold text-gray-700'>{$route_name}</td> 
                            <td class='border px-6 py-2'>{$row['actual_vehicle_no']}</td>
                            <td class='border px-6 py-2'>{$row['driver_NIC']}</td>
                            <td class='border px-6 py-2'>{$time_display}</td>
                            <td class='border px-6 py-2'>" . ucfirst($row['shift']) . "</td>
                        </tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' class='text-center py-4 text-gray-500'>No records found for the selected date: " . htmlspecialchars($filterDate) . " and Shift: " . ucfirst(htmlspecialchars($filterShift)) . "</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

<script>
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let iconPath;
        // Simplified SVG icons for demonstration
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