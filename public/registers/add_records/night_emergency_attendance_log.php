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

// Data for filters
$months = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', 
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August', 
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];
$current_year_sys = date('Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Night Emergency Deletion Log</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Table Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
                <a href="../night_emergency.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Night Emergency Register
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Deletion Log
                </span>
            </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <form method="GET" action="" class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            
            <select name="month" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <?php foreach ($months as $num => $name): 
                    $selected = ($num == $filter_month) ? 'selected' : '';
                ?>
                    <option value="<?php echo $num; ?>" <?php echo $selected; ?> class="text-gray-900 bg-white">
                        <?php echo $name; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <span class="text-gray-400 mx-1">|</span>

            <select name="year" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-1 pr-2 appearance-none hover:text-yellow-200 transition">
                <?php for ($y = $current_year_sys; $y >= 2020; $y--): 
                    $selected = ($y == $filter_year) ? 'selected' : '';
                ?>
                    <option value="<?php echo $y; ?>" <?php echo $selected; ?> class="text-gray-900 bg-white">
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>

        </form>

        <span class="text-gray-600">|</span>

        <a href="night_emergency_attendance.php" class="text-gray-300 hover:text-white transition flex items-center gap-1">
            Attendance
        </a>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200">
        <table class="w-full table-auto">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-3 text-left w-48">Log Date/Time</th>
                    <th class="px-4 py-3 text-left w-48">Deleted By</th>
                    <th class="px-4 py-3 text-left w-32">OP Code</th>
                    <th class="px-4 py-3 text-left w-40">Att. Date</th>
                    <th class="px-4 py-3 text-left">Reason (Remark)</th>
                </tr>
            </thead>
            <tbody class="text-sm text-gray-700">
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr class='hover:bg-indigo-50 border-b border-gray-100 transition duration-150'>";
                        echo "<td class='px-4 py-3 whitespace-nowrap text-gray-500'>" . htmlspecialchars($row['action_timestamp']) . "</td>";
                        echo "<td class='px-4 py-3 font-semibold text-gray-800'>" . htmlspecialchars($row['deleted_by']) . "</td>";
                        echo "<td class='px-4 py-3 font-mono text-indigo-600 font-medium'>" . htmlspecialchars($row['op_code']) . "</td>";
                        echo "<td class='px-4 py-3 whitespace-nowrap'>" . htmlspecialchars($row['attendance_date']) . "</td>";
                        echo "<td class='px-4 py-3 text-gray-600'>" . htmlspecialchars($row['remark']) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' class='px-6 py-4 text-center text-gray-500'>
                            No deletion records found for the selected period.
                          </td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>