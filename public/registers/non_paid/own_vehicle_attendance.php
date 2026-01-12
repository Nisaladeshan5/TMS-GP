<?php
// require_once '../../../includes/session_check.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : ''; 
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Initialize filter variable
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'); 

// Calculate Previous and Next Dates for navigation buttons
$prevDate = date('Y-m-d', strtotime($filter_date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($filter_date . ' +1 day'));

// --- SQL QUERY UPDATE ---
// Added ova.out_time to the selection
$sql = "
    SELECT
        ova.emp_id,
        e.calling_name,
        ova.vehicle_no,
        ova.date,
        ova.time,
        ova.out_time 
    FROM
        own_vehicle_attendance AS ova
    JOIN
        employee AS e ON ova.emp_id = e.emp_id
";
$conditions = [];
$params = [];
$types = "";

if (!empty($filter_date)) {
    if (DateTime::createFromFormat('Y-m-d', $filter_date) !== false) {
        $conditions[] = "ova.date = ?";
        $params[] = $filter_date;
        $types .= "s";
    } else {
        $filter_date = date('Y-m-d'); 
        $conditions[] = "ova.date = ?";
        $params[] = $filter_date;
        $types .= "s";
    }
}

if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY ova.time DESC";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Own Vehicle Attendance Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .red-cell { background-color: #fca5a5; }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>
<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="own_vehicle_attendance.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
                Own Vehicle Register
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Attendance
            </span>
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            
            <a href="?date=<?php echo $prevDate; ?>" 
               class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150" 
               title="Previous Day">
                <i class="fas fa-chevron-left"></i>
            </a>

            <form method="GET" class="flex items-center mx-1">
                <input type="date" name="date" 
                       value="<?php echo htmlspecialchars($filter_date); ?>" 
                       onchange="this.form.submit()" 
                       class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer px-2 appearance-none text-center h-8">
            </form>

            <a href="?date=<?php echo $nextDate; ?>" 
               class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150" 
               title="Next Day">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <span class="text-gray-600">|</span>

        <?php if ($is_logged_in): ?>
            <a href="own_vehicle_extra_register.php" class="text-gray-300 hover:text-white transition">Extra Register</a>
            
            <a href="add_own_vehicle_attendance.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
                Add Attendance
            </a>
        <?php endif; ?>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full mx-auto">
        <table class="w-full table-auto">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-6 py-3 text-left">Employee ID</th>
                    <th class="px-6 py-3 text-left">Employee Name</th>
                    <th class="px-6 py-3 text-left">Vehicle No</th>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3 text-left">In Time</th>
                    <th class="px-6 py-3 text-left">Out Time</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        
                        // Check if out_time exists to display properly
                        $out_time_display = !empty($row['out_time']) && $row['out_time'] != '00:00:00' 
                                            ? htmlspecialchars($row['out_time']) 
                                            : '<span class="text-gray-400 italic">-</span>';

                        echo "<tr class='hover:bg-indigo-50 border-b border-gray-100 transition duration-150'>";
                        echo "<td class='px-6 py-3 font-mono text-blue-600 font-medium'>" . htmlspecialchars($row['emp_id']) . "</td>";
                        echo "<td class='px-6 py-3 font-medium text-gray-800'>" . htmlspecialchars($row['calling_name']) . "</td>";
                        echo "<td class='px-6 py-3 font-bold uppercase'>" . htmlspecialchars($row['vehicle_no']) . "</td>";
                        echo "<td class='px-6 py-3'>" . htmlspecialchars($row['date']) . "</td>";
                        echo "<td class='px-6 py-3 font-bold text-green-600'>" . htmlspecialchars($row['time']) . "</td>";
                        echo "<td class='px-6 py-3 font-bold text-red-600'>" . $out_time_display . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' class='px-6 py-4 text-center text-gray-500'>
                            No attendance records found for own vehicles on " . htmlspecialchars($filter_date) . ".
                          </td></tr>";
                }
                $stmt->close();
                if (isset($conn)) $conn->close();
                ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>