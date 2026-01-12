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

include('../../includes/db.php');

if (!$conn) {
    die("Database connection failed.");
}

// 1. Fetch Employee Count per Route Code
$sql_route_summary = "
    SELECT 
        SUBSTRING(route, 1, 10) AS route_code, 
        COUNT(emp_id) AS employee_count
    FROM employee 
    WHERE route IS NOT NULL AND LENGTH(route) >= 10
    GROUP BY route_code
    ORDER BY CAST(SUBSTRING(route_code, 7, 3) AS UNSIGNED) ASC"; 

$result_route_summary = $conn->query($sql_route_summary);
$route_summary_data = $result_route_summary ? $result_route_summary->fetch_all(MYSQLI_ASSOC) : [];


// 2. Fetch Detailed Sub-Route Counts + JOIN with sub_route table
$sql_subroute_summary = "
    SELECT 
        main.route_code,
        main.sub_route_derived_code,
        sr.sub_route AS sub_route_name,
        main.sub_route_count
    FROM (
        SELECT 
            SUBSTRING(route, 1, 10) AS route_code, 
            CASE 
                WHEN near_bus_stop IS NOT NULL 
                    AND LENGTH(near_bus_stop) >= 6 
                    AND SUBSTRING(near_bus_stop, 1, 1) REGEXP '^[0-9]'
                THEN CONCAT(
                    SUBSTRING(route, 1, 10), 
                    '-', 
                    SUBSTRING(near_bus_stop, 1, 6)
                )
                ELSE 'No Sub-Route' 
            END AS sub_route_derived_code,
            COUNT(emp_id) AS sub_route_count
        FROM employee 
        WHERE route IS NOT NULL AND LENGTH(route) >= 10
        GROUP BY route_code, sub_route_derived_code
    ) AS main
    LEFT JOIN sub_route sr ON main.sub_route_derived_code = sr.sub_route_code
    ORDER BY main.route_code ASC, main.sub_route_derived_code ASC";

$result_subroute_summary = $conn->query($sql_subroute_summary);
$subroute_summary_data = $result_subroute_summary ? $result_subroute_summary->fetch_all(MYSQLI_ASSOC) : [];

// Reorganize Sub-Route data
$subroutes_by_route = [];
foreach ($subroute_summary_data as $row) {
    $code = $row['route_code'];
    if (!isset($subroutes_by_route[$code])) {
        $subroutes_by_route[$code] = [];
    }
    $subroutes_by_route[$code][] = $row;
}

// 3. Fetch Vehicle Capacity and Route Name
$sql_vehicle_capacity_and_name = "
    SELECT 
        r.route_code, 
        r.route,
        v.capacity
    FROM route r
    LEFT JOIN vehicle v ON r.vehicle_no = v.vehicle_no"; 

$result_capacity_and_name = $conn->query($sql_vehicle_capacity_and_name);
$route_details = [];

if ($result_capacity_and_name) {
    while ($row = $result_capacity_and_name->fetch_assoc()) {
        $route_details[$row['route_code']] = [
            'capacity' => (int)$row['capacity'],
            'name' => htmlspecialchars($row['route'] ?? 'N/A') 
        ];
    }
}

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Employee Overview</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #64748b; }
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

<body class="bg-slate-100 overflow-hidden">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
                <a href="employee.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Employee
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Overview
                </span>
            </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-search text-gray-400 group-focus-within:text-blue-400 transition-colors"></i>
            </div>
            <input type="text" id="routeSearchInput" 
                   class="bg-gray-700 text-white text-sm rounded-full pl-10 pr-4 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500 w-64 transition-all focus:w-80 placeholder-gray-400 border border-gray-600 focus:bg-gray-800" 
                   placeholder="Search Route Code or Name...">
        </div>

        <span class="text-gray-600 text-lg font-thin">|</span>

        <a href="employee.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Employee List
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 h-screen flex flex-col relative">
    
    <div class="flex-grow overflow-y-auto p-8 bg-slate-100">
        <div id="routeSummaryContainer" class="max-w-7xl mx-auto pb-12">
            
            <?php if (empty($route_summary_data)): ?>
                <div class="flex flex-col items-center justify-center h-[60vh] text-gray-400">
                    <div class="bg-white p-6 rounded-full shadow-sm mb-4">
                        <i class="fas fa-route text-4xl text-gray-300"></i>
                    </div>
                    <p class="text-lg font-medium text-gray-500">No route data available for analysis.</p>
                </div>
            <?php else: ?>
                
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-8">
                    <?php foreach ($route_summary_data as $route_data): ?>
                        <?php 
                        $route_code = htmlspecialchars($route_data['route_code']); 
                        $employee_count = (int)$route_data['employee_count'];

                        $details = $route_details[$route_code] ?? ['capacity' => 0, 'name' => 'Name N/A'];
                        $capacity = $details['capacity'];
                        $route_name = $details['name'];

                        $availability = $capacity - $employee_count;
                        
                        // Status Logic
                        $status_badge_class = 'bg-gray-100 text-gray-600 border-gray-200';
                        $status_icon = 'fa-question-circle';
                        $status_text = 'Unknown';
                        $card_border_top = 'border-t-4 border-t-gray-400';

                        if ($capacity > 0) {
                            if ($availability > 0) {
                                $status_badge_class = 'bg-green-100 text-green-700 border-green-200';
                                $status_icon = 'fa-check-circle';
                                $status_text = $availability . ' Seats Left';
                                $card_border_top = 'border-t-4 border-t-green-500';
                            } elseif ($availability < 0) {
                                $status_badge_class = 'bg-red-100 text-red-700 border-red-200';
                                $status_icon = 'fa-exclamation-circle';
                                $status_text = 'Overloaded by ' . abs($availability);
                                $card_border_top = 'border-t-4 border-t-red-500';
                            } else {
                                $status_badge_class = 'bg-yellow-100 text-yellow-700 border-yellow-200';
                                $status_icon = 'fa-lock';
                                $status_text = 'Full Capacity';
                                $card_border_top = 'border-t-4 border-t-yellow-500';
                            }
                        }
                        ?>
                        
                        <div class="route-item bg-white rounded-xl shadow-md border border-gray-200 hover:shadow-xl transition-all duration-300 group overflow-hidden flex flex-col <?php echo $card_border_top; ?>" 
                             data-route-code="<?php echo $route_code; ?>" 
                             data-route-name="<?php echo $route_name; ?>">
                            
                            <div class="p-6">
                                <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-1">
                                            <h3 class="text-2xl font-bold text-gray-800 font-mono tracking-tight group-hover:text-blue-600 transition-colors"><?php echo $route_code; ?></h3>
                                        </div>
                                        <div class="flex items-center text-gray-500 font-medium text-sm">
                                            <i class="fas fa-map-marker-alt text-gray-400 mr-2"></i>
                                            <span class="truncate max-w-xs"><?php echo $route_name; ?></span>
                                        </div>
                                    </div>

                                    <div class="px-3 py-1.5 rounded-full text-xs font-bold border <?php echo $status_badge_class; ?> flex items-center gap-2 shadow-sm whitespace-nowrap">
                                        <i class="fas <?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-px bg-gray-200 rounded-lg overflow-hidden border border-gray-200">
                                    <div class="bg-gray-50 p-3 text-center hover:bg-white transition-colors">
                                        <p class="text-[10px] text-gray-400 uppercase font-bold tracking-widest mb-0.5">Employees</p>
                                        <p class="text-xl font-bold text-gray-800"><?php echo $employee_count; ?></p>
                                    </div>
                                    <div class="bg-gray-50 p-3 text-center hover:bg-white transition-colors">
                                        <p class="text-[10px] text-gray-400 uppercase font-bold tracking-widest mb-0.5">Capacity</p>
                                        <p class="text-xl font-bold text-gray-500"><?php echo $capacity > 0 ? $capacity : '-'; ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="h-px w-full bg-gray-200"></div>

                            <div class="p-5 bg-gray-50/80 flex-grow">
                                <h4 class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                                    <i class="fas fa-project-diagram text-gray-300"></i> Sub-Route Breakdown
                                </h4>
                                
                                <div class="grid grid-cols-2 gap-3">
                                    <?php 
                                    $subroutes = $subroutes_by_route[$route_code] ?? [];
                                    $subroute_found = false;
                                    
                                    foreach ($subroutes as $sub_data): 
                                        if ($sub_data['sub_route_derived_code'] !== 'No Sub-Route'):
                                            $subroute_found = true;
                                            $display_name = !empty($sub_data['sub_route_name']) ? htmlspecialchars($sub_data['sub_route_name']) : "Unknown Loc";
                                            $display_code = htmlspecialchars($sub_data['sub_route_derived_code']);
                                    ?>
                                            <div class="bg-white p-2.5 rounded-lg border border-gray-200 shadow-sm hover:border-blue-400 hover:shadow-md transition-all duration-200 flex justify-between items-center group/item">
                                                <div class="flex flex-col overflow-hidden mr-2">
                                                    <span class="text-xs font-semibold text-gray-700 truncate max-w-[100px]" title="<?php echo $display_name; ?>">
                                                        <?php echo $display_name; ?>
                                                    </span>
                                                    <span class="font-mono text-[9px] text-gray-400 mt-0.5 truncate">
                                                        <?php echo $display_code; ?>
                                                    </span>
                                                </div>
                                                
                                                <span class="flex items-center justify-center w-6 h-6 bg-indigo-50 text-indigo-600 text-[10px] font-bold rounded-full border border-indigo-100 shrink-0">
                                                    <?php echo $sub_data['sub_route_count']; ?>
                                                </span>
                                            </div>
                                    <?php 
                                        endif; 
                                    endforeach; 
                                    
                                    // Handle "No Sub-Route" entries
                                    $no_subroute_entry = array_filter($subroutes, fn($r) => $r['sub_route_derived_code'] === 'No Sub-Route');
                                    $no_subroute_count = !empty($no_subroute_entry) ? $no_subroute_entry[array_key_first($no_subroute_entry)]['sub_route_count'] : 0;
                                    
                                    if ($no_subroute_count > 0 || !$subroute_found): 
                                    ?>
                                        <div class="bg-white/50 p-2.5 rounded-lg border border-gray-300 border-dashed flex justify-between items-center opacity-70">
                                            <div class="flex flex-col">
                                                <span class="text-xs font-medium text-gray-500 italic">Direct / Other</span>
                                                <span class="text-[9px] text-gray-400">Unspecified</span>
                                            </div>
                                            <span class="flex items-center justify-center w-6 h-6 bg-gray-200 text-gray-500 text-[10px] font-bold rounded-full border border-gray-300">
                                                <?php echo $no_subroute_count; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('routeSearchInput');
    const container = document.getElementById('routeSummaryContainer');
    const routeItems = container.querySelectorAll('.route-item');
    
    // No results message setup
    const noResultsMessage = document.createElement('div');
    noResultsMessage.innerHTML = `
        <div class="flex flex-col items-center justify-center mt-20 text-gray-400">
            <i class="fas fa-search-minus text-5xl mb-4 text-gray-300"></i>
            <p class="text-lg font-medium">No routes found matching your search.</p>
        </div>`;
    noResultsMessage.className = 'hidden';
    noResultsMessage.id = 'noResults';
    container.appendChild(noResultsMessage);

    function filterRoutes() {
        const searchText = searchInput.value.trim().toUpperCase();
        let found = false;

        routeItems.forEach(item => {
            // Get Route Code
            const routeCode = item.getAttribute('data-route-code').toUpperCase();
            // Get Route Name (Description) - Handle nulls safely
            const routeNameAttr = item.getAttribute('data-route-name');
            const routeName = routeNameAttr ? routeNameAttr.toUpperCase() : "";

            // Check if Search Text is inside Route Code OR Route Name
            if (routeCode.includes(searchText) || routeName.includes(searchText)) {
                item.style.display = ''; // Show
                found = true;
            } else {
                item.style.display = 'none'; // Hide
            }
        });

        if (found) {
            noResultsMessage.classList.add('hidden');
        } else {
            noResultsMessage.classList.remove('hidden');
        }
    }

    searchInput.addEventListener('input', filterRoutes);
});
</script>

</body>
</html>

<?php 
if (isset($result_route_summary)) { $result_route_summary->free(); }
if (isset($result_subroute_summary)) { $result_subroute_summary->free(); }
if (isset($result_capacity_and_name)) { $result_capacity_and_name->free(); }
$conn->close(); 
?>