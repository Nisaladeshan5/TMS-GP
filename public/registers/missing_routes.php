<?php
// missing_routes.php

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
$filterDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'); 
$filterShift = isset($_GET['shift']) ? $_GET['shift'] : 'morning';

$prevDate = date('Y-m-d', strtotime($filterDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($filterDate . ' +1 day'));

$displayDate = date('F j, Y', strtotime($filterDate));

// --- 2. Fetch Missing Routes Logic ---
$grouped_routes = []; 
$connection_error = null;

if (isset($conn) && $conn instanceof mysqli && $conn->connect_error === null) {
    
    $sql = "SELECT 
                rm.route_code, 
                rm.route AS route_name,
                u.emp_id,
                u.calling_name,
                e.line AS emp_line 
            FROM 
                route rm
            LEFT JOIN 
                cross_check r 
            ON 
                rm.route_code = r.route 
                AND DATE(r.date) = ? 
                AND r.shift = ? 
            LEFT JOIN
                `user` u 
            ON
                rm.route_code = u.route_code
            LEFT JOIN
                `employee` e
            ON
                u.emp_id = e.emp_id
            WHERE 
                rm.is_active = 1 
                AND r.id IS NULL 
            ORDER BY CAST(SUBSTR(rm.route_code, 7, 3) AS UNSIGNED) ASC";

    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param('ss', $filterDate, $filterShift);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $code = $row['route_code'];
            
            if (!isset($grouped_routes[$code])) {
                $grouped_routes[$code] = [
                    'route_name' => $row['route_name'],
                    'employees' => []
                ];
            }
            
            if (!empty($row['emp_id'])) {
                $grouped_routes[$code]['employees'][] = [
                    'id' => $row['emp_id'],
                    'name' => $row['calling_name'],
                    'line' => $row['emp_line']
                ];
            }
        }
        $stmt->close();
    } else {
        $connection_error = "Database Error: " . $conn->error;
    }

} else {
    $connection_error = "Database Connection Failed.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Missing Route Verification</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #a0aec0; }
        
        .route-F { background-color: #d1fae5 !important; border-color: #34d399; }
        .route-S { background-color: #fff3da !important; border-color: #fcd34d; }

        /* --- FLIP CARD CSS --- */
        .flip-card {
            background-color: transparent;
            perspective: 1000px; /* Remove this if you don't want the 3D effect */
            height: 190px; /* Fixed height is important for flip */
            cursor: pointer;
        }

        .flip-card-inner {
            position: relative;
            width: 100%;
            height: 100%;
            text-align: center;
            transition: transform 0.6s;
            transform-style: preserve-3d;
        }

        /* Class added by JS to trigger flip */
        .flip-card.is-flipped .flip-card-inner {
            transform: rotateY(180deg);
        }

        .flip-card-front, .flip-card-back {
            position: absolute;
            width: 100%;
            height: 100%;
            -webkit-backface-visibility: hidden;
            backface-visibility: hidden;
            border-radius: 0.75rem; /* rounded-xl */
            display: flex;
            flex-direction: column;
        }

        /* Back needs to be rotated already so it's correct when flipped */
        .flip-card-back {
            transform: rotateY(180deg);
            background-color: white;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
    </style>
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php";

        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);

        // Simple function to toggle the flip class
        function toggleFlip(element) {
            element.classList.toggle('is-flipped');
        }
    </script>
</head>

<body class="bg-gray-100 font-sans text-gray-800">

    <div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
        <div class="flex items-center gap-3">
            <div class="flex items-center space-x-2 w-fit">
                <a href="varification.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Varification
                </a>
                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Missing Routes
                </span>
            </div>
        </div>

        <div class="flex items-center gap-4 text-sm font-medium">
            <form method="GET" class="flex items-center bg-gray-700/50 backdrop-blur-sm rounded-lg p-1 border border-gray-600 shadow-inner">
                <a href="?date=<?php echo $prevDate; ?>&shift=<?php echo $filterShift; ?>" class="p-2 text-gray-300 hover:text-white hover:bg-white/10 rounded-md transition duration-150"><i class="fas fa-chevron-left"></i></a>
                <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer px-2 appearance-none text-center h-8 font-mono">
                <a href="?date=<?php echo $nextDate; ?>&shift=<?php echo $filterShift; ?>" class="p-2 text-gray-300 hover:text-white hover:bg-white/10 rounded-md transition duration-150"><i class="fas fa-chevron-right"></i></a>
                <span class="text-gray-500 mx-1">|</span>
                <div class="relative group">
                    <select name="shift" onchange="this.form.submit()" class="bg-transparent text-yellow-300 text-sm font-bold border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-6 appearance-none uppercase tracking-wide">
                        <option value="morning" <?php echo $filterShift === 'morning' ? 'selected' : ''; ?> class="text-gray-900 bg-white">Morning</option>
                        <option value="evening" <?php echo $filterShift === 'evening' ? 'selected' : ''; ?> class="text-gray-900 bg-white">Evening</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-1 text-yellow-300">
                        <i class="fas fa-caret-down text-xs"></i>
                    </div>
                </div>
            </form>
            <span class="text-gray-600">|</span>
            <a href="download_full_month_report.php?date=<?php echo $filterDate; ?>" class="group relative inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold uppercase tracking-wider rounded-lg shadow-lg shadow-emerald-900/20 border border-emerald-500 transition-all duration-200 transform hover:-translate-y-0.5 active:translate-y-0">
                <i class="fas fa-file-csv text-lg group-hover:scale-110 transition-transform duration-200 text-emerald-100"></i>
                <div class="flex flex-col items-start leading-none">
                    <span class="text-[10px] text-emerald-200 font-medium">Export</span>
                    <span>Monthly Report</span>
                </div>
                <div class="absolute inset-0 rounded-lg ring-2 ring-white/20 group-hover:ring-white/40 transition-all"></div>
            </a>

            <a href="export_daily_missing.php" 
            class="ml-2 group relative inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold uppercase tracking-wider rounded-lg shadow-lg shadow-indigo-900/20 border border-indigo-500 transition-all duration-200 transform hover:-translate-y-0.5 active:translate-y-0">
                
                <i class="fas fa-file-excel text-lg group-hover:scale-110 transition-transform duration-200 text-indigo-100"></i>
                
                <div class="flex flex-col items-start leading-none">
                    <span class="text-[10px] text-indigo-200 font-medium">Export</span>
                    <span>Daily Check</span>
                </div>
                
                <div class="absolute inset-0 rounded-lg ring-2 ring-white/20 group-hover:ring-white/40 transition-all"></div>
            </a>
            
            <?php if ($is_logged_in): ?>
                <span class="text-gray-600">|</span>
                <a href="varification.php" class="text-gray-400 hover:text-white transition flex items-center gap-2 text-xs font-semibold uppercase tracking-wide">
                    <span>Back</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <main class="w-[85%] ml-[15%] p-6">

        <?php if ($connection_error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded shadow-sm" role="alert">
                <p class="font-bold">Database Error</p>
                <p><?php echo htmlspecialchars($connection_error); ?></p>
            </div>
        <?php endif; ?>

        <div class="w-full">
            
            <?php if (!empty($grouped_routes)): ?>
                
                <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-lg shadow-sm flex justify-between items-center transition hover:shadow-md">
                    <div>
                        <h3 class="text-lg font-bold text-red-800 flex items-center gap-2">
                            <i class="fas fa-exclamation-triangle text-red-600"></i> Missing Routes Found
                        </h3>
                        <p class="text-sm text-red-600 mt-1">
                            Shift: <span class="font-semibold uppercase tracking-wide"><?php echo $filterShift; ?></span> | 
                            Date: <span class="font-semibold"><?php echo $displayDate; ?></span>
                        </p>
                    </div>
                    <div class="flex flex-col items-center justify-center bg-white px-5 py-2 rounded-lg shadow-sm border border-red-100">
                        <span class="text-xs text-gray-400 uppercase font-bold">Total</span>
                        <span class="text-3xl font-extrabold text-red-600 leading-none"><?php echo count($grouped_routes); ?></span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                    
                    <?php 
                    foreach ($grouped_routes as $code => $data): 
                        $name = $data['route_name'];
                        $employees = $data['employees']; 
                        $empCount = count($employees);
                        
                        // Styling for FRONT CARD
                        $front_class = "bg-white border-red-200"; 
                        $icon_bg = "bg-red-50 text-red-600";
                        
                        if (strlen($code) >= 5) {
                            $fifth_char = strtoupper($code[4]);
                            if ($fifth_char === 'F') {
                                $front_class = "route-F border-green-200";
                                $icon_bg = "bg-green-100 text-green-600";
                            } elseif ($fifth_char === 'S') {
                                $front_class = "route-S border-yellow-200";
                                $icon_bg = "bg-yellow-100 text-yellow-600";
                            }
                        }
                    ?>
                        <div class="flip-card group" onclick="toggleFlip(this)">
                            <div class="flip-card-inner">
                                
                                <div class="flip-card-front <?php echo $front_class; ?> p-4 border shadow-sm rounded-xl relative overflow-hidden text-left">
                                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-red-400 group-hover:bg-red-500 transition-colors"></div>
                                    
                                    <div class="flex items-start justify-between">
                                        <div>
                                            <span class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-0.5">Route Code</span>
                                            <span class="text-lg font-bold text-gray-800 font-mono tracking-tight"><?php echo htmlspecialchars($code); ?></span>
                                        </div>
                                        <div class="<?php echo $icon_bg; ?> w-8 h-8 flex items-center justify-center rounded-full text-xs shadow-inner">
                                            <i class="fas fa-times"></i>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4 pt-3 border-t border-gray-400/20">
                                        <span class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-0.5">Route Name</span>
                                        <span class="text-sm font-medium text-gray-700 truncate block" title="<?php echo htmlspecialchars($name); ?>">
                                            <?php echo htmlspecialchars($name); ?>
                                        </span>
                                    </div>

                                    <div class="mt-auto flex justify-center">
                                        <span class="text-[10px] text-gray-400 font-medium flex items-center gap-1 bg-white/50 px-2 py-1 rounded-full border border-gray-100">
                                            <i class="fas fa-sync-alt text-gray-300"></i> Click to view <?php echo $empCount; ?> staff
                                        </span>
                                    </div>
                                </div>

                                <div class="flip-card-back p-4 shadow-md rounded-xl text-left bg-gray-50 border border-gray-200">
                                    <div class="flex items-center justify-between border-b border-gray-200 pb-2 mb-2">
                                        <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">
                                            <i class="fas fa-users text-indigo-500 mr-1"></i> Assigned
                                        </span>
                                        <span class="text-[10px] text-gray-400"><i class="fas fa-undo"></i> Back</span>
                                    </div>

                                    <div class="overflow-y-auto custom-scrollbar flex-1 pr-1">
                                        <?php if (!empty($employees)): ?>
                                            <div class="space-y-2">
                                                <?php foreach ($employees as $emp): ?>
                                                    <div class="bg-white rounded p-2 border border-gray-100 shadow-sm">
                                                        <div class="flex flex-col">
                                                            <span class="text-xs font-bold text-indigo-700 leading-tight truncate">
                                                                <?php echo htmlspecialchars($emp['name']); ?>
                                                            </span>
                                                            <div class="flex justify-between items-center mt-1">
                                                                <span class="text-[10px] text-gray-500 font-mono">
                                                                    ID: <?php echo htmlspecialchars($emp['id']); ?>
                                                                </span>
                                                                <?php if ($emp['line']): ?>
                                                                    <span class="text-[9px] font-bold bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded border">
                                                                        L: <?php echo htmlspecialchars($emp['line']); ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="h-full flex flex-col items-center justify-center text-gray-400">
                                                <i class="fas fa-user-slash text-2xl mb-1 opacity-50"></i>
                                                <span class="text-xs">No Staff</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="bg-white p-10 rounded-xl shadow-lg border border-green-100 text-center max-w-2xl mx-auto mt-10">
                    <div class="inline-flex p-4 rounded-full bg-green-50 text-green-500 mb-4 shadow-sm">
                        <i class="fas fa-check-circle text-5xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">All Active Routes Verified!</h3>
                    <p class="text-gray-500 leading-relaxed">
                        Great job! There are no missing route entries for the 
                        <span class="font-bold text-green-600 uppercase"><?php echo $filterShift; ?></span> shift on 
                        <span class="font-bold text-gray-800"><?php echo $displayDate; ?></span>.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>