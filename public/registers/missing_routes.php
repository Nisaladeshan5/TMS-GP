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

// Use GET to allow link navigation (Arrows)
$filterDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'); 
$filterShift = isset($_GET['shift']) ? $_GET['shift'] : 'morning';

// Calculate Previous and Next Dates for navigation buttons
$prevDate = date('Y-m-d', strtotime($filterDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($filterDate . ' +1 day'));

$displayDate = date('F j, Y', strtotime($filterDate));

// --- 2. Fetch Missing Routes Logic ---
// We need routes from `route` table that are ACTIVE but NOT in `cross_check` table for this date/shift
$records = [];
$connection_error = null;

if (isset($conn) && $conn instanceof mysqli && $conn->connect_error === null) {
    
    $sql = "SELECT 
                rm.route_code, 
                rm.route AS route_name
            FROM 
                route rm
            LEFT JOIN 
                cross_check r 
            ON 
                rm.route_code = r.route 
                AND DATE(r.date) = ? 
                AND r.shift = ? 
            WHERE 
                rm.is_active = 1 
                AND r.id IS NULL -- This finds the MISSING entries
            ORDER BY CAST(SUBSTR(rm.route_code, 7, 3) AS UNSIGNED) ASC"; // Sort by numeric part of code

    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param('ss', $filterDate, $filterShift);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result->fetch_all(MYSQLI_ASSOC);
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
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
        
        .route-F { background-color: #d1fae5 !important; border-color: #34d399; }
        .route-S { background-color: #fff3da !important; border-color: #fcd34d; }
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
                
                <a href="?date=<?php echo $prevDate; ?>&shift=<?php echo $filterShift; ?>" 
                   class="p-2 text-gray-300 hover:text-white hover:bg-white/10 rounded-md transition duration-150" 
                   title="Previous Day">
                    <i class="fas fa-chevron-left"></i>
                </a>

                <input type="date" name="date" 
                       value="<?php echo htmlspecialchars($filterDate); ?>" 
                       onchange="this.form.submit()" 
                       class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer px-2 appearance-none text-center h-8 font-mono">
                
                <a href="?date=<?php echo $nextDate; ?>&shift=<?php echo $filterShift; ?>" 
                   class="p-2 text-gray-300 hover:text-white hover:bg-white/10 rounded-md transition duration-150" 
                   title="Next Day">
                    <i class="fas fa-chevron-right"></i>
                </a>

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

            <a href="download_full_month_report.php?date=<?php echo $filterDate; ?>" 
               class="group relative inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold uppercase tracking-wider rounded-lg shadow-lg shadow-emerald-900/20 border border-emerald-500 transition-all duration-200 transform hover:-translate-y-0.5 active:translate-y-0">
                
                <i class="fas fa-file-csv text-lg group-hover:scale-110 transition-transform duration-200 text-emerald-100"></i>
                
                <div class="flex flex-col items-start leading-none">
                    <span class="text-[10px] text-emerald-200 font-medium">Export</span>
                    <span>Monthly Report</span>
                </div>
                
                <div class="absolute inset-0 rounded-lg ring-2 ring-white/20 group-hover:ring-white/40 transition-all"></div>
            </a>

            <!-- <a href="export_own_vehicle.php" 
   class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-md shadow-md transition flex items-center gap-2">
    <i class="fas fa-file-excel"></i>
    Export to Excel
</a> -->

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
            
            <?php if (!empty($records)): ?>
                
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
                        <span class="text-3xl font-extrabold text-red-600 leading-none"><?php echo count($records); ?></span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                    <?php foreach ($records as $row): 
                        $code = $row['route_code'];
                        $name = $row['route_name'];
                        
                        // Determine styling based on 5th character (F/S)
                        $card_class = "bg-white border-red-200"; // Default
                        if (strlen($code) >= 5) {
                            $fifth_char = strtoupper($code[4]);
                            if ($fifth_char === 'F') {
                                $card_class = "route-F border-green-200";
                            } elseif ($fifth_char === 'S') {
                                $card_class = "route-S border-yellow-200";
                            }
                        }
                    ?>
                        <div class="<?php echo $card_class; ?> p-4 rounded-xl border shadow-sm hover:shadow-md transition-all duration-200 relative group overflow-hidden">
                            <div class="absolute left-0 top-0 bottom-0 w-1 bg-red-400 group-hover:bg-red-500 transition-colors"></div>
                            
                            <div class="flex items-start justify-between">
                                <div>
                                    <span class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-0.5">Route Code</span>
                                    <span class="text-lg font-bold text-gray-800 font-mono tracking-tight"><?php echo htmlspecialchars($code); ?></span>
                                </div>
                                <div class="bg-red-50 text-red-600 w-8 h-8 flex items-center justify-center rounded-full text-xs shadow-inner">
                                    <i class="fas fa-times"></i>
                                </div>
                            </div>
                            
                            <div class="mt-3 pt-2 border-t border-gray-100/50">
                                <span class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-0.5">Route Name</span>
                                <span class="text-sm font-medium text-gray-700 truncate block" title="<?php echo htmlspecialchars($name); ?>">
                                    <?php echo htmlspecialchars($name); ?>
                                </span>
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