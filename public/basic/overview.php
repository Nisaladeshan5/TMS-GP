<?php
include('../../includes/db.php');

// Ensure db connection is available before proceeding
if (!$conn) {
    die("Database connection failed.");
}

// 1. Fetch Employee Count per Route Code (First 10 characters of 'route')
$sql_route_summary = "
    SELECT 
        SUBSTRING(route, 1, 10) AS route_code, 
        COUNT(emp_id) AS employee_count
    FROM employee 
    WHERE route IS NOT NULL AND LENGTH(route) >= 10
    GROUP BY route_code
    ORDER BY employee_count DESC, route_code ASC";

$result_route_summary = $conn->query($sql_route_summary);
$route_summary_data = $result_route_summary ? $result_route_summary->fetch_all(MYSQLI_ASSOC) : [];


// 2. Fetch Detailed Sub-Route Counts
$sql_subroute_summary = "
    SELECT 
        SUBSTRING(route, 1, 10) AS route_code, 
        CASE 
            WHEN near_bus_stop IS NOT NULL 
                AND LENGTH(near_bus_stop) >= 2 
                AND SUBSTRING(near_bus_stop, 1, 1) REGEXP '^[0-9]'
            THEN CONCAT(
                SUBSTRING(route, 1, 10), 
                '-', 
                SUBSTRING(near_bus_stop, 1, 1), 
                SUBSTRING(near_bus_stop, 2, 1)
            )
            ELSE 'No Sub-Route' 
        END AS sub_route_derived,
        COUNT(emp_id) AS sub_route_count
    FROM employee 
    WHERE route IS NOT NULL AND LENGTH(route) >= 10
    GROUP BY route_code, sub_route_derived
    ORDER BY route_code ASC, sub_route_derived ASC";

$result_subroute_summary = $conn->query($sql_subroute_summary);
$subroute_summary_data = $result_subroute_summary ? $result_subroute_summary->fetch_all(MYSQLI_ASSOC) : [];

// Reorganize Sub-Route data for easy lookup by Route Code in the HTML
$subroutes_by_route = [];
foreach ($subroute_summary_data as $row) {
    $code = $row['route_code'];
    if (!isset($subroutes_by_route[$code])) {
        $subroutes_by_route[$code] = [];
    }
    $subroutes_by_route[$code][] = $row;
}

// NOTE: Placeholder includes are kept, assuming they define the structure.
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
    <style>
        /* Ensures the sidebar gap and height are respected */
        .main-content {
            height: calc(100vh - 3rem); /* Adjust based on top bar height */
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="h-screen flex flex-col">
        
        <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-12 flex-shrink-0">
            <div class="text-lg font-semibold ml-3">Employee</div>
            <div class="flex gap-4">
                <a href="employee.php" class="hover:text-yellow-600">Employee Details</a>
                <p class="hover:text-yellow-600 text-yellow-500 font-bold">Overview</p>
            </div>
        </div>

        <div class="flex items-start shadow-lg w-[85%] ml-[15%] flex-col overflow-hidden flex-grow main-content">
            
            <div class="container p-4 w-full h-full flex flex-col">
                <p class="text-center text-4xl font-bold text-gray-800 mt-1 mb-4 flex-shrink-0">Route Employee Overview</p>
                
                <div class="w-full bg-white p-4 rounded-md shadow-md mb-6 flex-shrink-0">
                    <h4 class="text-xl font-semibold mb-3 text-blue-600">Search Route Code</h4>
                    <input 
                        type="text" 
                        id="routeSearchInput" 
                        placeholder="Start typing a route code (e.g., A1B2C)" 
                        class="mt-1 p-2 block w-full border rounded-md shadow-sm border-gray-300 focus:ring-blue-500 focus:border-blue-500"
                    >
                </div>

                <div class="bg-white shadow-md rounded-md w-full overflow-y-auto flex-grow p-6">
                    
                    <h2 class="text-2xl font-bold text-blue-600 mb-4 border-b pb-2">Employee Count by Route Code</h2>
                    
                    <div id="routeSummaryContainer">
                        <?php if (empty($route_summary_data)): ?>
                            <p class="text-gray-600">No route data available for analysis.</p>
                        <?php else: ?>
                            
                            <?php foreach ($route_summary_data as $route_data): ?>
                                <?php $route_code = htmlspecialchars($route_data['route_code']); ?>
                                
                                <div class="route-item mb-6 p-4 border rounded-lg bg-blue-50" data-route-code="<?php echo $route_code; ?>">
                                    
                                    <div class="flex justify-between items-center mb-2">
                                        <h3 class="text-xl font-semibold text-gray-800">
                                            Route: <span class="text-blue-700"><?php echo $route_code; ?></span>
                                        </h3>
                                        <span class="text-2xl font-bold text-blue-900">
                                            Total Employees: <?php echo $route_data['employee_count']; ?>
                                        </span>
                                    </div>
                                    
                                    <h4 class="text-lg font-medium text-gray-700 mt-3 border-t pt-2">Sub-Route Breakdown:</h4>
                                    
                                    <?php 
                                    $subroutes = $subroutes_by_route[$route_code] ?? [];
                                    $subroute_found = false;
                                    ?>

                                    <ul class="list-disc ml-6 space-y-1">
                                        <?php foreach ($subroutes as $sub_data): ?>
                                            <?php if ($sub_data['sub_route_derived'] !== 'No Sub-Route'): ?>
                                                <?php $subroute_found = true; ?>
                                                <li class="text-gray-700">
                                                    <span class="font-mono text-sm bg-gray-200 px-2 py-1 rounded">
                                                        <?php echo htmlspecialchars($sub_data['sub_route_derived']); ?>
                                                    </span> 
                                                    <span class="text-sm">
                                                        (Employees: <strong><?php echo $sub_data['sub_route_count']; ?></strong>)
                                                    </span>
                                                </li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>

                                        <?php 
                                        $no_subroute_entry = array_filter($subroutes, fn($r) => $r['sub_route_derived'] === 'No Sub-Route');
                                        $no_subroute_count = !empty($no_subroute_entry) ? $no_subroute_entry[array_key_first($no_subroute_entry)]['sub_route_count'] : 0;
                                        
                                        if ($no_subroute_count > 0 || !$subroute_found): 
                                        ?>
                                            <li class="text-gray-500 italic">
                                                No specific sub-route defined: 
                                                <span class="text-sm">
                                                    (Employees: <strong><?php echo $no_subroute_count; ?></strong>)
                                                </span>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                            
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<div id="toast-container"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('routeSearchInput');
    const container = document.getElementById('routeSummaryContainer');
    // Get all route items once on page load
    const routeItems = container.querySelectorAll('.route-item');
    
    // Create the "No results" message element
    const noResultsMessage = document.createElement('p');
    noResultsMessage.textContent = 'No routes found matching your search criteria.';
    noResultsMessage.className = 'text-lg text-red-500 mt-4 hidden';
    noResultsMessage.id = 'noResults';
    container.appendChild(noResultsMessage);

    // Function to filter the route blocks
    function filterRoutes() {
        const searchText = searchInput.value.trim().toUpperCase();
        let found = false;

        routeItems.forEach(item => {
            // Get the route code from the data attribute
            const routeCode = item.getAttribute('data-route-code').toUpperCase();
            
            // Show the item if its route code CONTAINS the search text
            if (routeCode.includes(searchText)) {
                item.style.display = 'block';
                found = true;
            } else {
                item.style.display = 'none';
            }
        });

        // Toggle the "No results" message based on the search
        if (found) {
            noResultsMessage.classList.add('hidden');
        } else {
            noResultsMessage.classList.remove('hidden');
        }
    }

    // Attach the filter function to the 'input' event (updates on every key stroke)
    searchInput.addEventListener('input', filterRoutes);
});
</script>

</body>
</html>

<?php 
// Close the statement and connection at the very end
if (isset($result_route_summary)) {
    $result_route_summary->free(); 
}
if (isset($result_subroute_summary)) {
    $result_subroute_summary->free(); 
}
$conn->close(); 
?>