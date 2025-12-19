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
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

include('../../includes/db.php'); 
include('../../includes/header.php');
include('../../includes/navbar.php');

// --- 1. Get Filter Parameters ---

$filterYear = date('Y');
$filterMonth = date('m');
$filterRouteCode = $_POST['route_code'] ?? null; // Null means All Routes

// If a form is submitted, update the filters
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $filterDate = $_POST['month_year'] ?? date('Y-m'); // Expected format: YYYY-MM
    $filterRouteCode = $_POST['route_code'] ?? null;
    
    // Split the YYYY-MM into year and month
    list($filterYear, $filterMonth) = explode('-', $filterDate);
} else {
    // Default filter date to YYYY-MM format
    $filterDate = date('Y-m');
}

// Convert month name to full name for display
$displayMonth = date('F', mktime(0, 0, 0, (int)$filterMonth, 10));
$displayYear = $filterYear;


// --- 2. Fetch Route List for Dropdown ---
$routes = [];
$sql_routes = "SELECT route_code, route 
               FROM route 
               WHERE route_code LIKE '____F%'
               ORDER BY route ASC";
$result_routes = $conn->query($sql_routes);
while ($row = $result_routes->fetch_assoc()) {
    $routes[] = $row;
}


// --- 3. Fetch Attendance Data for Calendar (Only for a Selected Route) ---

$attendance_data = [];
$selected_route_name = "All Routes";

if ($filterRouteCode) {
    // Get the name of the selected route for display
    foreach ($routes as $route) {
        if ($route['route_code'] === $filterRouteCode) {
            $selected_route_name = $route['route'];
            break;
        }
    }

    // Fetch records for the selected route only for calendar visualization
    $sql_attendance = "SELECT 
                            DATE(date) as trip_date, 
                            shift 
                        FROM 
                            factory_transport_vehicle_register 
                        WHERE 
                            route = ? AND 
                            YEAR(date) = ? AND 
                            MONTH(date) = ? AND is_active = 1
                        GROUP BY trip_date, shift"; 
    
    $stmt_attendance = $conn->prepare($sql_attendance);
    $stmt_attendance->bind_param('sii', $filterRouteCode, $filterYear, $filterMonth);
    $stmt_attendance->execute();
    $result_attendance = $stmt_attendance->get_result();

    // Organize data by date and shift
    while ($row = $result_attendance->fetch_assoc()) {
        $day = date('j', strtotime($row['trip_date']));
        
        if (!isset($attendance_data[$day])) {
            $attendance_data[$day] = ['morning' => false, 'evening' => false];
        }
        
        if ($row['shift'] === 'morning') {
            $attendance_data[$day]['morning'] = true;
        } elseif ($row['shift'] === 'evening') {
            $attendance_data[$day]['evening'] = true;
        }
    }
    $stmt_attendance->close();
}

// --- 4. Calendar Logic ---
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$filterMonth, (int)$filterYear);
$firstDayOfWeek = date('w', strtotime("{$filterYear}-{$filterMonth}-01"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Route Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* ... (CSS styles remain the same) ... */
        .day-cell { min-height: 80px; border: 1px solid #d1d5db; position: relative; transition: background-color 0.2s; }
        .day-number { position: absolute; top: 5px; right: 5px; font-size: 0.875rem; font-weight: 700; color: #1f2937; }
        .shift-dot { width: 10px; height: 10px; border-radius: 50%; margin-right: 4px; display: inline-block; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.5); }
        .shift-morning { background-color: #3b82f6; }
        .shift-evening { background-color: #f59e0b; }
        .empty-cell { background-color: #f9fafb; }
        .current-day { background-color: #e0f2f1; border: 2px solid #2dd4bf; font-weight: 700; }
    </style>
</head>
<script>
    // ... (Session timeout script remains the same) ...
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
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4"> 
        <?php if ($is_logged_in): ?>
            <a href="factory_transport_vehicle_register.php" class="hover:text-yellow-600">Back</a>
            <a href="unmark_factory_route_attendace.php" class="hover:text-yellow-600">Unmark Routes</a>      
            <p class="text-yellow-400 font-bold">Attendance</p>
            <?php
            // Now $user_role is safely defined
            if ($user_role === 'manager' || $user_role === 'super admin' || $user_role === 'admin' || $user_role === 'developer') {
            ?>
            <a href="add_records/factory/adjustment_factory.php" class="hover:text-yellow-600">Adjustments</a>
            <a href="add_records/factory/add_factory_record.php" class="hover:text-yellow-600">Add Record</a>
            <?php
            }
            ?>
        <?php endif; ?>
    </div>
</div>

<div class="container " style="display: flex; flex-direction: column; align-items: center;">
    <div class="p-4 bg-white shadow-xl rounded-xl border border-gray-200 mt-3">
        <p class="text-3xl font-bold text-gray-800 mt-2 mb-4">Route Vehicle Attendance</p>

        <form method="POST" class="mb-6 flex justify-center items-center p-4 bg-white shadow-lg rounded-xl border border-gray-200">
            <div class="flex items-center space-x-2">
                <label for="route_code" class="text-lg font-medium">Select Route:</label>
                <select id="route_code" name="route_code" class="border border-gray-300 p-2 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="" <?php echo is_null($filterRouteCode) ? 'selected' : ''; ?>>-- All Routes --</option> 
                    <?php foreach ($routes as $route): ?>
                        <option value="<?php echo htmlspecialchars($route['route_code']); ?>" 
                                <?php echo $filterRouteCode === $route['route_code'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($route['route']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="month_year" class="text-lg font-medium">Filter by Month:</label>
                <input type="month" id="month_year" name="month_year" class="border border-gray-300 p-2 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500"
                    value="<?php echo htmlspecialchars($filterDate); ?>" required>
                
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg shadow-md hover:bg-blue-700 transition duration-150 font-semibold">Generate Calendar</button>
                <a href="all_f_routes_report_pdf.php?month_year=<?php echo htmlspecialchars($filterDate); ?>" 
                    target="_blank" 
                    class="bg-green-600 text-white px-4 py-2 rounded-lg shadow-md hover:bg-green-700 transition duration-150 font-semibold flex items-center">
                    Attendance Report
                </a>
            </div>
        </form>
        
        <h2 class="text-2xl font-bold text-gray-700 mb-4 p-2 bg-white rounded shadow-sm">
            Attendance for <?php echo htmlspecialchars($selected_route_name); ?> in <?php echo htmlspecialchars($displayMonth . ' ' . $displayYear); ?>
        </h2>

        <div class="w-full max-w-4xl mx-auto bg-white shadow-xl rounded-xl overflow-hidden mb-6 border border-gray-300">
            <div class="grid grid-cols-7 text-center font-bold text-gray-800 bg-blue-100 border-b border-blue-200">
                <div class="px-2 py-3">Sun</div>
                <div class="px-2 py-3">Mon</div>
                <div class="px-2 py-3">Tue</div>
                <div class="px-2 py-3">Wed</div>
                <div class="px-2 py-3">Thu</div>
                <div class="px-2 py-3">Fri</div>
                <div class="px-2 py-3">Sat</div>
            </div>

            <div class="grid grid-cols-7">
                <?php
                $currentDay = date('j');
                $currentMonthYear = date('Y-m');
                $isCurrentMonth = ($filterYear . '-' . $filterMonth === $currentMonthYear);
                
                // 5. Output Empty Cells for padding before the 1st day
                for ($i = 0; $i < $firstDayOfWeek; $i++) {
                    echo "<div class='day-cell empty-cell'></div>";
                }

                // 6. Output Day Cells
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    // Only display attendance if a route is selected
                    $isTripDay = $filterRouteCode && isset($attendance_data[$day]);
                    
                    $cellClass = $isTripDay ? 'bg-green-50 hover:bg-green-100' : 'bg-white hover:bg-gray-50'; 
                    
                    if ($isCurrentMonth && (int)$day === (int)$currentDay) {
                        $cellClass .= ' current-day';
                    }

                    $morning_trip = $isTripDay && $attendance_data[$day]['morning'];
                    $evening_trip = $isTripDay && $attendance_data[$day]['evening'];
                    
                    echo "<div class='day-cell {$cellClass} p-2 flex flex-col items-start'>";
                    echo "<span class='day-number'>{$day}</span>";
                    
                    // Display shift indicators as colored dots
                    echo "<div class='mt-5 flex items-center'>";
                    if ($morning_trip) {
                        echo "<span class='shift-dot shift-morning' title='Morning Trip'></span>";
                    }
                    if ($evening_trip) {
                        echo "<span class='shift-dot shift-evening' title='Evening Trip'></span>";
                    }
                    // If no route is selected, calendar will be empty
                    if (!$filterRouteCode) {
                        echo "<span class='text-xs text-gray-400'>Select Route</span>";
                    }
                    echo "</div>";
                    
                    echo "</div>";
                }

                // 7. Output Empty Cells for padding after the last day
                $lastDayIndex = $firstDayOfWeek + $daysInMonth - 1;
                $remainingCells = (ceil(($lastDayIndex + 1) / 7) * 7) - ($lastDayIndex + 1);
                for ($i = 0; $i < $remainingCells; $i++) {
                    echo "<div class='day-cell empty-cell'></div>";
                }
                ?>
            </div>
        </div>
        
        <div class="flex space-x-6 text-sm mt-6 p-3 bg-white shadow-lg rounded-lg border border-gray-200">
            <span class="font-semibold text-gray-700">Legend:</span>
            <div class="flex items-center">
                <span class="shift-dot shift-morning mr-2"></span>
                - Morning Trip (Blue)
            </div>
            <div class="flex items-center">
                <span class="shift-dot shift-evening mr-2"></span>
                - Evening Trip (Amber)
            </div>
            <?php if ($isCurrentMonth): ?>
            <div class="flex items-center">
                <div class="w-4 h-4 rounded-full mr-2 current-day border-2 border-teal-400"></div>
                - Current Day
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
    </div>
</body>
</html>