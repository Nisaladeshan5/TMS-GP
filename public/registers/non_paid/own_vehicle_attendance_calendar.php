<?php
// own_vehicle_attendance_calendar.php

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
$user_role = $_SESSION['role'] ?? ''; 

include('../../../includes/db.php'); 
include('../../../includes/header.php'); 
include('../../../includes/navbar.php'); 

// --- 1. Get Filter Parameters ---

$filterDate = $_REQUEST['month_year'] ?? date('Y-m'); 
$filterEmpID = $_REQUEST['emp_id'] ?? null;

if ($filterEmpID === '') {
    $filterEmpID = null;
}

list($filterYear, $filterMonth) = explode('-', $filterDate);

$prevDate = date('Y-m', strtotime($filterDate . " -1 month"));
$nextDate = date('Y-m', strtotime($filterDate . " +1 month"));

$displayMonth = date('F', mktime(0, 0, 0, (int)$filterMonth, 10));
$displayYear = $filterYear;


// --- 2. Fetch Employee List for Dropdown (UPDATED) ---
// We select DISTINCT emp_id so one employee appears only once, regardless of vehicle.
$employees = [];
$sql_emps = "SELECT DISTINCT ov.emp_id, e.calling_name
             FROM own_vehicle ov 
             LEFT JOIN employee e ON ov.emp_id = e.emp_id
             WHERE ov.vehicle_no IS NOT NULL AND ov.vehicle_no != ''
             GROUP BY ov.emp_id 
             ORDER BY e.calling_name ASC";
             
$result_emps = $conn->query($sql_emps);
while ($row = $result_emps->fetch_assoc()) {
    $employees[] = $row;
}


// --- 3. Fetch Attendance Data ---

$attendance_data = []; 
$selected_emp_name = "All Employees"; 

// Set display name if employee selected (Vehicle No removed from display)
if ($filterEmpID) {
    foreach ($employees as $emp) {
        if ($emp['emp_id'] === $filterEmpID) {
            $selected_emp_name = $emp['calling_name']; // Only Name
            break;
        }
    }
}

// Build SQL Query based on filter
$sql_attendance = "SELECT DATE(date) as attendance_date 
                   FROM own_vehicle_attendance 
                   WHERE YEAR(date) = ? AND MONTH(date) = ?";

$params = [$filterYear, $filterMonth];
$types = "ii";

if ($filterEmpID) {
    $sql_attendance .= " AND emp_id = ?";
    $params[] = $filterEmpID;
    $types .= "s";
}

$sql_attendance .= " GROUP BY attendance_date"; 

$stmt_attendance = $conn->prepare($sql_attendance);
$stmt_attendance->bind_param($types, ...$params);
$stmt_attendance->execute();
$result_attendance = $stmt_attendance->get_result();

// Store active days
while ($row = $result_attendance->fetch_assoc()) {
    $day = date('j', strtotime($row['attendance_date']));
    $attendance_data[$day] = true; 
}
$stmt_attendance->close();


// --- 4. Calendar Logic ---
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$filterMonth, (int)$filterYear);
$firstDayOfWeek = date('w', strtotime("{$filterYear}-{$filterMonth}-01"));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Own Vehicle Attendance Calendar</title>
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
        .day-cell { min-height: 80px; transition: background-color 0.2s; }
        
        /* Dot Style */
        .status-dot { 
            width: 14px; 
            height: 14px; 
            border-radius: 50%; 
            display: inline-block; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .status-present { background-color: #10b981; /* Emerald Green */ }
        
        .empty-cell { background-color: #f9fafb; }
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
                <a href="own_vehicle_attendance.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Own Vehicle Register
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Attendance Calendar
                </span>
            </div>
        </div>

        <div class="flex items-center gap-4 text-sm font-medium"> 
            <?php if ($is_logged_in): ?>
                <a href="own_vehicle_attendance.php" class="hover:text-yellow-600">Table View</a>
            <?php endif; ?>
        </div>
    </div>

    <main class="w-[85%] ml-[15%] p-6">

        <div class="bg-white p-4 rounded-xl shadow-md border border-gray-200 mb-6 flex flex-col md:flex-row justify-between items-center min-h-[80%]">
            
            <div class="mb-4 md:mb-0">
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="far fa-calendar-alt text-green-600"></i>
                    Monthly Presence
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Showing presence for: <span class="font-semibold text-green-600"><?php echo htmlspecialchars($selected_emp_name); ?></span> 
                    in <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($displayMonth . ' ' . $displayYear); ?></span>
                </p>
            </div>

            <form method="POST" class="flex flex-wrap items-center gap-3">
                
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <select id="emp_id" name="emp_id" onchange="this.form.submit()" class="pl-10 pr-8 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm bg-gray-50 max-w-xs">
                        <option value="" <?php echo is_null($filterEmpID) ? 'selected' : ''; ?>>-- All Employees --</option> 
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo htmlspecialchars($emp['emp_id']); ?>" 
                                    <?php echo $filterEmpID === $emp['emp_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['calling_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-center bg-white border border-gray-300 rounded-lg shadow-sm">
                    <a href="?month_year=<?php echo $prevDate; ?>&emp_id=<?php echo htmlspecialchars($filterEmpID ?? ''); ?>" 
                       class="px-3 py-2 text-gray-500 hover:bg-gray-100 hover:text-green-600 rounded-l-lg border-r border-gray-200 transition">
                        <i class="fas fa-chevron-left"></i>
                    </a>

                    <input type="month" id="month_year" name="month_year" onchange="this.form.submit()"
                        class="border-none py-2 px-2 text-sm font-semibold text-gray-700 focus:ring-0 bg-transparent cursor-pointer"
                        value="<?php echo htmlspecialchars($filterDate); ?>" required>

                    <a href="?month_year=<?php echo $nextDate; ?>&emp_id=<?php echo htmlspecialchars($filterEmpID ?? ''); ?>" 
                       class="px-3 py-2 text-gray-500 hover:bg-gray-100 hover:text-green-600 rounded-r-lg border-l border-gray-200 transition">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>

                <a href="own_vehicle_excel.php?month_year=<?php echo htmlspecialchars($filterDate); ?>" 
                   target="_blank" 
                   class="bg-green-600 text-white px-4 py-2 rounded-lg shadow hover:bg-green-700 transition font-medium text-sm flex items-center gap-2">
                    <i class="fas fa-file-excel"></i> Excel
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
                
                for ($i = 0; $i < $firstDayOfWeek; $i++) {
                    echo "<div class='day-cell empty-cell'></div>";
                }

                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $is_present = isset($attendance_data[$day]);
                    
                    $cellClass = 'bg-white hover:bg-green-50'; 
                    
                    if ($isCurrentMonth && (int)$day === (int)$currentDay) {
                        $cellClass .= ' current-day relative';
                    }

                    echo "<div class='day-cell {$cellClass} p-2 flex flex-col items-center justify-between cursor-default'>";
                    
                    $dateColor = ($isCurrentMonth && (int)$day === (int)$currentDay) ? 'text-amber-600 font-extrabold' : 'text-gray-700 font-bold';
                    echo "<span class='text-sm {$dateColor} self-start'>{$day}</span>";
                    
                    echo "<div class='flex items-center justify-center flex-grow'>";
                    if ($is_present) {
                        echo "<span class='status-dot status-present' title='Present'></span>";
                    } elseif (!$is_present && $filterEmpID && $day < $currentDay && $isCurrentMonth) {
                         echo "<span class='text-[10px] text-gray-200'>-</span>";
                    }
                    echo "</div>";
                    
                    echo "</div>";
                }

                $lastDayIndex = $firstDayOfWeek + $daysInMonth - 1;
                $remainingCells = (ceil(($lastDayIndex + 1) / 7) * 7) - ($lastDayIndex + 1);
                for ($i = 0; $i < $remainingCells; $i++) {
                    echo "<div class='day-cell empty-cell'></div>";
                }
                ?>
            </div>
            
            <div class="bg-gray-50 p-4 flex flex-wrap items-center justify-center gap-6 text-sm text-gray-600">
                <div class="flex items-center">
                    <span class="status-dot status-present mr-2"></span>
                    <span class="font-medium">Present / Marked</span>
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