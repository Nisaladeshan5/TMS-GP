<?php
// Ensure session is started if not done in header.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Initialize filter variables
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Base SQL query to fetch log entries
// Assuming you have a 'users' table with columns 'id' and 'username'
$sql = "
    SELECT
    l.op_code,
    l.attendance_date,
    l.remark,
    l.action_timestamp,
    l.user_id,
    -- Use COALESCE to display the employee's calling_name if found, 
    -- otherwise fallback to the user_id for logging.
    COALESCE(e.calling_name, 'Admin ID: ' || l.user_id) AS deleted_by
FROM
    ne_delete_record AS l
-- 1. Join to the admin table using the user_id (the ID column in the admin table)
LEFT JOIN
    admin AS a ON l.user_id = a.user_id
-- 2. Join from the admin table (using emp_id) to the employee table
LEFT JOIN
    employee AS e ON a.emp_id = e.emp_id
";
$conditions = [];
$params = [];
$types = "";

// Add month and year filters based on the action_timestamp
if (!empty($filter_month) && !empty($filter_year)) {
    $conditions[] = "MONTH(l.action_timestamp) = ? AND YEAR(l.action_timestamp) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= "ii";
}

// Append conditions to the query
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
// Final ORDER BY clause
$sql .= " ORDER BY l.action_timestamp DESC";

// Prepare and execute the statement
$stmt = $conn->prepare($sql);
if ($types) {
    // PHP 5.6+ splat operator
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Night Emergency Deletion Log</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .log-table th, .log-table td {
            white-space: nowrap; /* Prevent date/time from wrapping */
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="night_emergency_attendance.php" class="hover:text-yellow-600">Back</a>
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[36px] font-bold text-gray-800 mt-2">Night Emergency Deletion Log</p>

    <form method="GET" action="" class="mb-6 flex justify-center mt-1">
        <div class="flex items-center">
            <label for="month" class="text-lg font-medium mr-2">Filter by Log Date:</label>
            
            <select id="month" name="month" class="border border-gray-300 p-2 rounded-md">
                <?php
                $months = [
                    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June',
                    '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                ];
                foreach ($months as $num => $name) {
                    $selected = ($num == $filter_month) ? 'selected' : '';
                    echo "<option value='{$num}' {$selected}>{$name}</option>";
                }
                ?>
            </select>
            
            <select id="year" name="year" class="border border-gray-300 p-2 rounded-md ml-2">
                <?php
                $current_year = date('Y');
                for ($y = $current_year; $y >= 2020; $y--) {
                    $selected = ($y == $filter_year) ? 'selected' : '';
                    echo "<option value='{$y}' {$selected}>{$y}</option>";
                }
                ?>
            </select>

            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md ml-2 hover:bg-blue-600">Filter</button>
        </div>
    </form>
    
    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6 w-full">
        <table class="min-w-full table-auto log-table">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Log Date/Time</th>
                    <th class="px-4 py-2 text-left">Deleted By</th>
                    <th class="px-4 py-2 text-left">OP Code (Deleted)</th>
                    <th class="px-4 py-2 text-left">Attendance Date (Deleted)</th>
                    <th class="px-4 py-2 text-left">Reason (Remark)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr class='hover:bg-gray-100'>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['action_timestamp']) . "</td>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['deleted_by']) . "</td>";
                        echo "<td class='border px-4 py-2 font-semibold'>" . htmlspecialchars($row['op_code']) . "</td>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['attendance_date']) . "</td>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['remark']) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' class='border px-4 py-2 text-center'>No deletion records found for this period.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>