<?php
// unmark_factory_route_attendace.php

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$user_role = $_SESSION['role'] ?? ''; 

include('../../includes/db.php'); 

// --- 1. Get Filter Parameters (Updated to use GET for auto-submit) ---

$filterDate = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d'); 
$filterShift = isset($_GET['shift']) ? $_GET['shift'] : 'morning';

$displayDate = date('F j, Y', strtotime($filterDate));

// --- 2. Fetch All Eligible Routes (Factory Specific Logic Kept) ---
$routes = [];
$all_route_codes = []; 
$sql_routes = "SELECT route_code, route 
                FROM route 
                WHERE route_code LIKE '____F%' AND is_active = 1
                ORDER BY route ASC";
$result_routes = $conn->query($sql_routes);
while ($row = $result_routes->fetch_assoc()) {
    $routes[] = $row;
    // Store all codes and names for the absent route check
    $all_route_codes[$row['route_code']] = $row['route']; 
}


// --- 3. Fetch ATTENDED Route Codes (Factory Specific Logic Kept) ---
$attended_route_codes_for_shift = []; 
$sql_attended_routes = "SELECT 
                            DISTINCT route
                        FROM 
                            factory_transport_vehicle_register 
                        WHERE 
                            DATE(date) = ? AND 
                            shift = ? AND is_active = 1";
                            
$stmt_attended_routes = $conn->prepare($sql_attended_routes);
$stmt_attended_routes->bind_param('ss', $filterDate, $filterShift); 
$stmt_attended_routes->execute();
$result_attended_routes = $stmt_attended_routes->get_result();

while ($row = $result_attended_routes->fetch_assoc()) {
    $attended_route_codes_for_shift[] = $row['route'];
}
$stmt_attended_routes->close();


// --- 4. Determine ABSENT Routes ---
$absent_route_codes = array_diff(array_keys($all_route_codes), $attended_route_codes_for_shift);

// Create a final list of absent routes with their names
$absent_routes_list = [];
foreach ($absent_route_codes as $code) {
    if (isset($all_route_codes[$code])) {
        $absent_routes_list[$code] = $all_route_codes[$code];
    }
}

include('../../includes/header.php'); 
include('../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Factory Absent Route List</title>
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
                <a href="factory_transport_vehicle_register.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Factory Transport Vehicle Registers
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Unmark Routes
                </span>
            </div>
        </div>

        <div class="flex items-center gap-4 text-sm font-medium"> 
            <?php if ($is_logged_in): ?>
                <a href="factory_transport_vehicle_register.php" class="hover:text-yellow-600">Register</a>
            <?php endif; ?>
        </div>
    </div>
    <main class="w-[85%] ml-[15%] p-6">

        <div class="bg-white p-3 rounded-xl shadow-md border border-gray-200 mb-6 flex flex-col md:flex-row justify-between items-center w-full">
            
            <div class="mb-4 md:mb-0">
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-clipboard-list text-red-500"></i>
                    Absent Route List (Factory)
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Check routes not marked for <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($displayDate); ?></span>
                </p>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-4">
                
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                        <i class="far fa-calendar-alt"></i>
                    </div>
                    <input type="date" id="filter_date" name="filter_date" 
                        onchange="this.form.submit()"
                        class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-gray-50 text-gray-900 cursor-pointer hover:bg-white transition"
                        value="<?php echo htmlspecialchars($filterDate); ?>" required>
                </div>

                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                        <i class="fas fa-clock"></i>
                    </div>
                    <select id="shift" name="shift" 
                        onchange="this.form.submit()"
                        class="pl-10 pr-8 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-gray-50 text-gray-900 cursor-pointer hover:bg-white transition">
                        <option value="morning" <?php echo $filterShift === 'morning' ? 'selected' : ''; ?>>Morning</option>
                        <option value="evening" <?php echo $filterShift === 'evening' ? 'selected' : ''; ?>>Evening</option>
                    </select>
                </div>

            </form>
        </div>

        <div class="w-full">
            
            <?php if (!empty($absent_routes_list)): ?>
                
                <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-lg shadow-sm flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-bold text-red-800">
                            <i class="fas fa-exclamation-circle mr-2"></i> Absent Routes Found
                        </h3>
                        <p class="text-sm text-red-600">
                            Shift: <span class="font-semibold"><?php echo ucfirst($filterShift); ?></span> | Date: <span class="font-semibold"><?php echo $displayDate; ?></span>
                        </p>
                    </div>
                    <div class="text-3xl font-extrabold text-red-600 bg-white px-4 py-2 rounded-lg shadow-sm border border-red-100">
                        <?php echo count($absent_routes_list); ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-4">
                    <?php foreach ($absent_routes_list as $code => $name): ?>
                        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow duration-200 relative group overflow-hidden">
                            <div class="absolute left-0 top-0 bottom-0 w-1 bg-red-400"></div>
                            
                            <div class="flex items-start justify-between">
                                <div>
                                    <span class="block text-xs font-bold text-gray-400 uppercase tracking-wide">Route Code</span>
                                    <span class="text-lg font-bold text-gray-800 font-mono"><?php echo htmlspecialchars($code); ?></span>
                                </div>
                                <div class="bg-red-50 text-red-600 p-2 rounded-full text-xs">
                                    <i class="fas fa-times"></i>
                                </div>
                            </div>
                            
                            <div class="mt-2 pt-2 border-t border-gray-100">
                                <span class="block text-xs font-bold text-gray-400 uppercase tracking-wide">Route Name</span>
                                <span class="text-sm font-medium text-gray-600 truncate"><?php echo htmlspecialchars($name); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                
                <div class="bg-white p-8 rounded-xl shadow-lg border border-green-200 text-center max-w-2xl mx-auto mt-10">
                    <div class="inline-block p-4 rounded-full bg-green-100 text-green-600 mb-4">
                        <i class="fas fa-check-circle text-5xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">All Routes Marked!</h3>
                    <p class="text-gray-600">
                        Great job! Records exist for all eligible routes in the 
                        <span class="font-bold text-green-600"><?php echo htmlspecialchars(ucfirst($filterShift)); ?></span> 
                        shift on 
                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($displayDate); ?></span>.
                    </p>
                </div>

            <?php endif; ?>
        </div>

    </main>
</body>
</html>