<?php
// unmark_own_vehicle_attendance.php

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
include('../../../includes/db.php'); 

// --- 1. Get Filter Parameters ---
$filterDate = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d'); 

// Calculate Previous and Next Dates for navigation buttons
$prevDate = date('Y-m-d', strtotime($filterDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($filterDate . ' +1 day'));

$displayDate = date('F j, Y', strtotime($filterDate));

// --- 2. Fetch All Eligible Employees (Who have Own Vehicles) ---
$all_managers = []; 
$sql_managers = "SELECT 
                    ov.emp_id, 
                    e.calling_name, 
                    ov.vehicle_no 
                 FROM own_vehicle ov 
                 LEFT JOIN employee e ON ov.emp_id = e.emp_id 
                 WHERE ov.vehicle_no IS NOT NULL AND ov.vehicle_no != '' AND ov.is_active = 1
                 ORDER BY ov.emp_id ASC";

$result_managers = $conn->query($sql_managers);
if ($result_managers) {
    while ($row = $result_managers->fetch_assoc()) {
        // Use emp_id as the key for easy comparison
        $all_managers[$row['emp_id']] = [
            'name' => $row['calling_name'] ?? 'Unknown',
            'vehicle' => $row['vehicle_no']
        ];
    }
}

// --- 3. Fetch ATTENDED Employees for the selected date ---
$attended_emp_ids = []; 
$sql_attendance = "SELECT DISTINCT emp_id 
                   FROM own_vehicle_attendance 
                   WHERE date = ?";
                            
$stmt = $conn->prepare($sql_attendance);
$stmt->bind_param('s', $filterDate); 
$stmt->execute();
$result_attendance = $stmt->get_result();

while ($row = $result_attendance->fetch_assoc()) {
    $attended_emp_ids[] = $row['emp_id'];
}
$stmt->close();

// --- 4. Determine UNMARKED Managers (Set Difference) ---
// Remove attended IDs from the full list
$unmarked_managers = array_diff_key($all_managers, array_flip($attended_emp_ids));

include('../../../includes/header.php'); 
include('../../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unmarked Own Vehicle Attendance</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
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

<body class="bg-gray-100 font-sans text-gray-800">

    <div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-2 sticky top-0 z-40 border-b border-gray-700">
        
        <div class="flex items-center gap-3">
            <div class="flex items-center space-x-2 p-3 w-fit">
                <a href="own_vehicle_attendance.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Own Vehicle Register
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Unmarked List
                </span>
            </div>
        </div>

        <div class="flex items-center gap-4 text-sm font-medium"> 
            <?php if ($is_logged_in): ?>
                <a href="own_vehicle_attendance.php" class="hover:text-yellow-600">View Register</a>
            <?php endif; ?>
        </div>
    </div>

    <main class="w-[85%] ml-[15%] p-6">

        <div class="bg-white p-3 rounded-xl shadow-md border border-gray-200 mb-6 flex flex-col md:flex-row justify-between items-center w-full">
            
            <div class="mb-4 md:mb-0">
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-user-clock text-red-500"></i>
                    Unmarked Managers List
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Check employees who haven't marked attendance for <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($displayDate); ?></span>
                </p>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-4">
                
                <div class="flex items-center bg-gray-50 rounded-lg border border-gray-300 p-1 shadow-sm">
                    <a href="?filter_date=<?php echo $prevDate; ?>" 
                       class="p-2 rounded-md text-gray-500 hover:text-blue-600 hover:bg-gray-200 transition" 
                       title="Previous Day">
                        <i class="fas fa-chevron-left"></i>
                    </a>

                    <div class="relative px-2">
                        <input type="date" id="filter_date" name="filter_date" 
                            onchange="this.form.submit()"
                            class="bg-transparent border-none focus:ring-0 text-sm font-semibold text-gray-700 cursor-pointer outline-none"
                            value="<?php echo htmlspecialchars($filterDate); ?>" required>
                    </div>

                    <a href="?filter_date=<?php echo $nextDate; ?>" 
                       class="p-2 rounded-md text-gray-500 hover:text-blue-600 hover:bg-gray-200 transition" 
                       title="Next Day">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>

            </form>
        </div>

        <div class="w-full">
            
            <?php if (!empty($unmarked_managers)): ?>
                
                <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-lg shadow-sm flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-bold text-red-800">
                            <i class="fas fa-exclamation-circle mr-2"></i> Unmarked Records Found
                        </h3>
                        <p class="text-sm text-red-600">
                           Date: <span class="font-semibold"><?php echo $displayDate; ?></span>
                        </p>
                    </div>
                    <div class="text-3xl font-extrabold text-red-600 bg-white px-4 py-2 rounded-lg shadow-sm border border-red-100">
                        <?php echo count($unmarked_managers); ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-4">
                    <?php foreach ($unmarked_managers as $emp_id => $data): ?>
                        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow duration-200 relative group overflow-hidden">
                            <div class="absolute left-0 top-0 bottom-0 w-1 bg-red-400"></div>
                            
                            <div class="flex items-start justify-between">
                                <div>
                                    <span class="block text-xs font-bold text-gray-400 uppercase tracking-wide">Emp ID</span>
                                    <span class="text-lg font-bold text-gray-800 font-mono"><?php echo htmlspecialchars($emp_id); ?></span>
                                </div>
                                <div class="bg-red-50 text-red-600 p-2 rounded-full text-xs">
                                    <i class="fas fa-times"></i>
                                </div>
                            </div>
                            
                            <div class="mt-2 pt-2 border-t border-gray-100">
                                <span class="block text-xs font-bold text-gray-400 uppercase tracking-wide">Name</span>
                                <span class="text-sm font-medium text-gray-600 truncate block"><?php echo htmlspecialchars($data['name']); ?></span>
                            </div>

                             <div class="mt-1">
                                <span class="block text-xs font-bold text-gray-400 uppercase tracking-wide">Vehicle</span>
                                <span class="text-sm font-bold text-indigo-600 truncate block"><?php echo htmlspecialchars($data['vehicle']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                
                <div class="bg-white p-8 rounded-xl shadow-lg border border-green-200 text-center max-w-2xl mx-auto mt-10">
                    <div class="inline-block p-4 rounded-full bg-green-100 text-green-600 mb-4">
                        <i class="fas fa-check-circle text-5xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">All Managers Marked!</h3>
                    <p class="text-gray-600">
                        Great job! Attendance records exist for all assigned vehicles on 
                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($displayDate); ?></span>.
                    </p>
                </div>

            <?php endif; ?>
        </div>

    </main>
</body>
</html>