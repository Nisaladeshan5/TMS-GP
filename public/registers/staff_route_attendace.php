<?php
// staff_route_attendance.php

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
include('../../includes/header.php'); 
include('../../includes/navbar.php'); 

// --- 1. Get Filter Parameters (Updated for GET/POST compatibility) ---

// Check $_REQUEST to handle both POST (Form) and GET (Prev/Next buttons)
$filterDate = $_REQUEST['month_year'] ?? date('Y-m'); // Default to current YYYY-MM
$filterRouteCode = $_REQUEST['route_code'] ?? null;

// Handle empty string if passed via URL
if ($filterRouteCode === '') {
    $filterRouteCode = null;
}

// Split the YYYY-MM into year and month
list($filterYear, $filterMonth) = explode('-', $filterDate);

// Calculate Previous and Next Months for Navigation Buttons
$prevDate = date('Y-m', strtotime($filterDate . " -1 month"));
$nextDate = date('Y-m', strtotime($filterDate . " +1 month"));

// Convert month name to full name for display
$displayMonth = date('F', mktime(0, 0, 0, (int)$filterMonth, 10));
$displayYear = $filterYear;


// --- 2. Fetch Route List for Dropdown (Logic Unchanged) ---
$routes = [];
$sql_routes = "SELECT route_code, route 
                FROM route 
                WHERE route_code LIKE '____S%' AND is_active = 1
                ORDER BY route ASC";
$result_routes = $conn->query($sql_routes);
while ($row = $result_routes->fetch_assoc()) {
    $routes[] = $row;
}


// --- 3. Fetch Attendance Data for Calendar (Logic Unchanged) ---

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
                            staff_transport_vehicle_register 
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

// --- 4. Calendar Logic (Logic Unchanged) ---
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$filterMonth, (int)$filterYear);
$firstDayOfWeek = date('w', strtotime("{$filterYear}-{$filterMonth}-01"));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Route Attendance</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }

        /* Calendar Styles */
        .day-cell { min-height: 75px; transition: background-color 0.2s; }
        .shift-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; box-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .shift-morning { background-color: #3b82f6; /* Blue */ }
        .shift-evening { background-color: #f59e0b; /* Amber */ }
        .empty-cell { background-color: #f9fafb; }
        /* Highlight current day */
        .current-day { background-color: #fffbeb; z-index: 10; outline: 2px solid #fbbf24; outline-offset: -2px; }
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
                <a href="staff transport vehicle register.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Staff Transport Vehicle Registers
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Attendance Calendar
                </span>
            </div>
        </div>

        <div class="flex items-center gap-4 text-sm font-medium"> 
            <?php if ($is_logged_in): ?>
                <a href="staff transport vehicle register.php" class="hover:text-yellow-600">Register</a>
            <?php endif; ?>
        </div>
    </div>
    <main class="w-[85%] ml-[15%] p-6">

        <div class="bg-white p-4 rounded-xl shadow-md border border-gray-200 mb-6 flex flex-col md:flex-row justify-between items-center min-h-[80%]">
            
            <div class="mb-4 md:mb-0">
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="far fa-calendar-check text-indigo-600"></i>
                    Route Attendance
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Showing: <span class="font-semibold text-indigo-600"><?php echo htmlspecialchars($selected_route_name); ?></span> 
                    for <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($displayMonth . ' ' . $displayYear); ?></span>
                </p>
            </div>

            <form method="POST" class="flex flex-wrap items-center gap-3">
                
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                        <i class="fas fa-bus"></i>
                    </div>
                    <select id="route_code" name="route_code" onchange="this.form.submit()" class="pl-10 pr-8 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-gray-50">
                        <option value="" <?php echo is_null($filterRouteCode) ? 'selected' : ''; ?>>-- All Routes --</option> 
                        <?php foreach ($routes as $route): ?>
                            <option value="<?php echo htmlspecialchars($route['route_code']); ?>" 
                                    <?php echo $filterRouteCode === $route['route_code'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($route['route']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-center bg-white border border-gray-300 rounded-lg shadow-sm">
                    <a href="?month_year=<?php echo $prevDate; ?>&route_code=<?php echo htmlspecialchars($filterRouteCode ?? ''); ?>" 
                       class="px-3 py-2 text-gray-500 hover:bg-gray-100 hover:text-indigo-600 rounded-l-lg border-r border-gray-200 transition">
                        <i class="fas fa-chevron-left"></i>
                    </a>

                    <input type="month" id="month_year" name="month_year" onchange="this.form.submit()"
                        class="border-none py-2 px-2 text-sm font-semibold text-gray-700 focus:ring-0 bg-transparent cursor-pointer"
                        value="<?php echo htmlspecialchars($filterDate); ?>" required>

                    <a href="?month_year=<?php echo $nextDate; ?>&route_code=<?php echo htmlspecialchars($filterRouteCode ?? ''); ?>" 
                       class="px-3 py-2 text-gray-500 hover:bg-gray-100 hover:text-indigo-600 rounded-r-lg border-l border-gray-200 transition">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>

                <a href="all_routes_report_excel.php?month_year=<?php echo htmlspecialchars($filterDate); ?>&route_code=<?php echo htmlspecialchars($filterRouteCode ?? ''); ?>" 
                    target="_blank" 
                    class="bg-green-600 text-white px-4 py-2 rounded-lg shadow hover:bg-green-700 transition font-medium text-sm flex items-center gap-2">
                    <i class="fas fa-file-excel"></i> Excel
                </a>
                
                <a href="all_routes_report_pdf.php?month_year=<?php echo htmlspecialchars($filterDate); ?>&route_code=<?php echo htmlspecialchars($filterRouteCode ?? ''); ?>" 
                    target="_blank" 
                    class="bg-red-600 text-white px-4 py-2 rounded-lg shadow hover:bg-red-700 transition font-medium text-sm flex items-center gap-2">
                    <i class="fas fa-file-pdf"></i> Report
                </a>
            </form>
        </div>

        <div class="bg-white shadow-xl rounded-xl overflow-hidden border border-gray-200">
            
            <div class="grid grid-cols-7 text-center bg-gray-100 border-b border-gray-200">
                <div class="py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Sun</div>
                <div class="py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Mon</div>
                <div class="py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Tue</div>
                <div class="py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Wed</div>
                <div class="py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Thu</div>
                <div class="py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Fri</div>
                <div class="py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Sat</div>
            </div>

            <div class="grid grid-cols-7 bg-gray-200 gap-px border-b border-gray-200">
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
                    
                    $cellClass = 'bg-white hover:bg-blue-50'; 
                    
                    // Style Current Day
                    if ($isCurrentMonth && (int)$day === (int)$currentDay) {
                        $cellClass .= ' current-day relative';
                    }

                    $morning_trip = $isTripDay && $attendance_data[$day]['morning'];
                    $evening_trip = $isTripDay && $attendance_data[$day]['evening'];
                    
                    echo "<div class='day-cell {$cellClass} p-2 flex flex-col justify-between'>";
                    
                    // Date Number
                    $dateColor = ($isCurrentMonth && (int)$day === (int)$currentDay) ? 'text-amber-600 font-extrabold' : 'text-gray-700 font-bold';
                    echo "<span class='text-sm {$dateColor}'>{$day}</span>";
                    
                    // Dots container
                    echo "<div class='flex items-center gap-1 mt-2'>";
                    if ($morning_trip) {
                        echo "<span class='shift-dot shift-morning' title='Morning Trip'></span>";
                    }
                    if ($evening_trip) {
                        echo "<span class='shift-dot shift-evening' title='Evening Trip'></span>";
                    }
                    
                    // Prompt to select route if none selected
                    if (!$filterRouteCode) {
                        echo "<span class='text-[10px] text-gray-300 italic'>-</span>";
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
            
            <div class="bg-gray-50 p-4 flex flex-wrap items-center justify-center gap-6 text-sm text-gray-600">
                <div class="flex items-center">
                    <span class="shift-dot shift-morning mr-2"></span>
                    <span class="font-medium">Morning Trip</span>
                </div>
                <div class="flex items-center">
                    <span class="shift-dot shift-evening mr-2"></span>
                    <span class="font-medium">Evening Trip</span>
                </div>
                <?php if ($isCurrentMonth): ?>
                <div class="flex items-center">
                    <div class="w-3 h-3 border border-amber-500 bg-amber-50 rounded-sm mr-2"></div>
                    <span class="font-medium">Current Day</span>
                </div>
                <?php endif; ?>
            </div>

        </div>

    </main>
</body>
</html>