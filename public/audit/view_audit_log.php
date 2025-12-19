<?php
/**
 * AUDIT LOG VIEWER
 * This page retrieves and displays system changes logged in the 'audit_log' table.
 * It joins 'admin' and 'employee' tables to show the user's calling name and role.
 */
require_once '../../includes/session_check.php';

// 1. SESSION AND AUTHENTICATION
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// 2. DATABASE CONNECTION
include('../../includes/db.php'); // db.php file එක ඇතුළත් කිරීම

// 3. FETCH AUDIT LOG DATA
$sql = "SELECT 
            al.*, 
            a.role, 
            e.calling_name AS user_name, -- employee table එකෙන් calling_name එක ලබා ගනිමු
            a.emp_id 
        FROM 
            audit_log al
        LEFT JOIN 
            admin a ON al.user_id = a.user_id
        LEFT JOIN
            employee e ON a.emp_id = e.emp_id -- admin table එක employee table එකට emp_id හරහා join කිරීම
        ORDER BY 
            al.change_time DESC
        LIMIT 500"; // Performance සඳහා සීමා කිරීම

$log_result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log - System Changes</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    
    <style>
        .log-table th, .log-table td {
            padding: 10px;
            text-align: left;
        }
        /* Custom styles to highlight action type for better scannability */
        .action-INSERT { 
            background-color: #d1fae5; /* Green */ 
            color: #065f46; 
            font-weight: 600; 
            padding: 2px 8px;
            border-radius: 4px;
            white-space: nowrap;
        } 
        .action-UPDATE { 
            background-color: #fef3c7; /* Yellow */ 
            color: #92400e; 
            font-weight: 600; 
            padding: 2px 8px;
            border-radius: 4px;
            white-space: nowrap;
        } 
        .action-DELETE { 
            background-color: #fee2e2; /* Red */ 
            color: #991b1b; 
            font-weight: 600; 
            padding: 2px 8px;
            border-radius: 4px;
            white-space: nowrap;
        } 

        /* Style for Old/New Value comparison */
        .old-value { 
            text-decoration: line-through; 
            color: #ef4444; /* Red */
            font-style: italic; 
        }
        .new-value { 
            font-weight: 600; 
            color: #10b981; /* Teal/Green */
        }
    </style>
</head>
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
<body class="bg-gray-100">

<?php 
// Header සහ Navbar ඇතුළත් කිරීම (ඔබගේ ව්‍යුහය අනුව)
include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<div class="w-[85%] ml-[15%] p-4 sm:p-6 lg:p-8 min-h-screen">
    <div class="max-w-screen-xl mx-auto shadow-sm w-full p-4 min-h-screen bg-white rounded-lg"> 
        <div class="flex flex-col items-center mb-1">
            <h1 class="text-4xl font-extrabold text-gray-800 mt-2 mb-2 pb-2 border-b-2 border-blue-500">
                Audit Log
            </h1>
            <p class="text-gray-500">Displaying the last 500 system changes</p>
        </div>

        <div class="overflow-x-auto shadow-xl rounded-lg w-full">
            <table class="min-w-full table-auto log-table border-collapse">
                <thead class="bg-blue-700 text-white sticky top-0 z-10">
                    <tr>
                        <th class="px-4 py-3">Time</th>
                        <th class="px-4 py-3">User / Role</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Table / Record</th>
                        <th class="px-4 py-3">Field Changed</th>
                        <th class="px-4 py-3">Old Value → New Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($log_result && $log_result->num_rows > 0): ?>
                        <?php while ($log = $log_result->fetch_assoc()): 
                            // Determine the CSS class for action type
                            $action_class = 'action-' . strtoupper($log['action_type']);
                            
                            // Get User details
                            $display_name = htmlspecialchars($log['user_name'] ?? $log['emp_id'] ?? 'User ID: ' . $log['user_id']);
                            $display_role = htmlspecialchars($log['role'] ?? 'N/A'); // 'a.role' ලෙස SQL එකේ භාවිතා කර ඇත
                            $record_id_display = htmlspecialchars($log['record_id'] ?? '-');

                            // Prepare Old and New Values
                            $old_val = htmlspecialchars($log['old_value'] ?? '');
                            $new_val = htmlspecialchars($log['new_value'] ?? '');
                            
                            // Format the Old/New column based on action type
                            if ($log['action_type'] === 'UPDATE' && !empty($log['field_name'])) {
                                $value_display = "<span class='old-value'>{$old_val}</span> <span class='text-gray-500'>→</span> <span class='new-value'>{$new_val}</span>";
                            } elseif ($log['action_type'] === 'INSERT') {
                                $value_display = "<span class='new-value'>Record Created</span>";
                            } elseif ($log['action_type'] === 'DELETE') {
                                $value_display = "<span class='old-value'>Record Deleted</span>";
                            } else {
                                $value_display = htmlspecialchars($log['details'] ?? 'N/A');
                            }
                        ?>
                            <tr class="border-t border-gray-200 hover:bg-gray-50 transition duration-100">
                                <td class="px-4 py-2 text-sm text-gray-700 whitespace-nowrap">
                                    <?php echo date('Y-m-d H:i:s', strtotime($log['change_time'])); ?>
                                </td>
                                <td class="px-4 py-2 text-sm whitespace-nowrap">
                                    <span class="font-bold text-gray-800"><?php echo $display_name; ?></span><br>
                                    <span class="text-xs text-blue-500">(<?php echo $display_role; ?>)</span>
                                </td>
                                <td class="px-4 py-2 text-xs">
                                    <span class="<?php echo $action_class; ?>">
                                        <?php echo strtoupper($log['action_type']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    <span class="font-semibold text-blue-800"><?php echo htmlspecialchars($log['table_name']); ?></span> <br>
                                    <span class="text-xs text-gray-500">ID: <?php echo $record_id_display; ?></span>
                                </td>
                                <td class="px-4 py-2 text-sm font-medium text-gray-700">
                                    <?php echo htmlspecialchars($log['field_name'] ?? '-'); ?>
                                </td>
                                <td class="px-4 py-2 text-sm max-w-xs break-words">
                                    <?php echo $value_display; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-gray-500 text-lg font-semibold">
                                No audit logs found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>

<?php $conn->close(); ?>