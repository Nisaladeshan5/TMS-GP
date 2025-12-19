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
    <style>
        .red-cell { background-color: #fca5a5; }
    </style>
</head>
<script>
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4"> 
        <?php if ($is_logged_in): ?>
            <a href="own_vehicle_extra_register.php" class="hover:text-yellow-600">Extra Register</a>
            <a href="add_own_vehicle_attendance.php" class="hover:text-yellow-600">Add Attendance</a>
        <?php endif; ?>
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[36px] font-bold text-gray-800 mt-2">Own Vehicle Attendance Register</p>

    <form method="GET" action="" class="mb-6 flex justify-center mt-1">
        <div class="flex items-center">
            <label for="date_filter" class="text-lg font-medium mr-2">Filter by Date:</label>
            <input 
                type="date" 
                id="date_filter" 
                name="date" 
                class="border border-gray-300 p-2 rounded-md"
                value="<?php echo htmlspecialchars($filter_date); ?>"
            >
            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md ml-2 hover:bg-blue-600">Filter</button>
        </div>
    </form>
    
    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6 w-full">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Employee ID</th>
                    <th class="px-4 py-2 text-left">Employee Name</th>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">In Time</th>
                    <th class="px-4 py-2 text-left">Out Time</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        
                        // Check if out_time exists to display properly
                        $out_time_display = !empty($row['out_time']) && $row['out_time'] != '00:00:00' 
                                            ? htmlspecialchars($row['out_time']) 
                                            : '<span class="text-gray-400">-</span>';

                        echo "<tr class='hover:bg-gray-100'>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['emp_id']) . "</td>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['calling_name']) . "</td>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['vehicle_no']) . "</td>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['date']) . "</td>";
                        echo "<td class='border px-4 py-2 font-medium text-green-700'>" . htmlspecialchars($row['time']) . "</td>";
                        echo "<td class='border px-4 py-2 font-medium text-red-700'>" . $out_time_display . "</td>";
                        echo "</tr>";
                    }
                } else {
                    // Adjusted colspan to 6 because we added a column
                    echo "<tr><td colspan='6' class='border px-4 py-2 text-center'>No attendance records found for own vehicles on " . htmlspecialchars($filter_date) . "</td></tr>";
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