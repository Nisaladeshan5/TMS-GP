<?php
ob_start();
require_once '../../includes/session_check.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- FILTER LOGIC ---
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

// Fetch data from route, sub_route, and sum of fuel_issues for the SELECTED MONTH
$sql = "SELECT 
            combined_routes.*,
            v.type AS vehicle_type, 
            v.rate_id,
            c.c_type AS consumption_type, 
            c.distance AS km_per_liter,
            fr.type AS fuel_type,
            COALESCE(fi.total_issued_this_month, 0) AS issued_qty
        FROM (
            SELECT 
                r.route_code, r.route, TRIM(r.vehicle_no) AS vehicle_no, 
                r.distance AS route_distance, r.monthly_allocate, 'Main' AS route_category
            FROM route r
            WHERE r.is_active = 1

            UNION ALL

            SELECT 
                sr.sub_route_code AS route_code, sr.sub_route AS route, TRIM(sr.vehicle_no) AS vehicle_no, 
                sr.distance AS route_distance, sr.monthly_allocate, 'Sub' AS route_category
            FROM sub_route sr
            WHERE sr.is_active = 1
        ) combined_routes
        LEFT JOIN vehicle v ON combined_routes.vehicle_no = TRIM(v.vehicle_no)
        LEFT JOIN consumption c ON v.fuel_efficiency = c.c_id
        LEFT JOIN (
            SELECT rate_id, type 
            FROM fuel_rate 
            WHERE id IN (SELECT MAX(id) FROM fuel_rate GROUP BY rate_id)
        ) fr ON v.rate_id = fr.rate_id
        LEFT JOIN (
            -- Subquery to get the sum of issued fuel for the selected month and year
            SELECT code, SUM(issued_qty) AS total_issued_this_month 
            FROM fuel_issues 
            WHERE YEAR(date) = $selected_year AND MONTH(date) = $selected_month
            GROUP BY code
        ) fi ON combined_routes.route_code = fi.code
        ORDER BY combined_routes.route_category ASC, combined_routes.route_code ASC";

$result = $conn->query($sql);

include('../../includes/header.php');
include('../../includes/navbar.php');
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Diesel Issue</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
        
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }

        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }

        /* Double scroll eka nathi karanna body scroll hide karanawa */
        body { overflow: hidden; } 
    </style>
</head>

<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="fuel.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Fuel Management
            </a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Diesel Issue
            </span>
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        <form method="GET" action="route_diesel_issue.php" class="flex items-center gap-3 bg-gray-800 px-3 py-1.5 rounded-lg border border-gray-600">
        <select name="month" onchange="this.form.submit()" class="bg-transparent text-white outline-none cursor-pointer text-sm font-semibold">
            <?php 
            for($m=1; $m<=12; ++$m){
                $m_name = date('F', mktime(0, 0, 0, $m, 1));
                $sel = ($m == $selected_month) ? 'selected' : '';
                echo "<option value='$m' class='text-gray-900' $sel>$m_name</option>";
            }
            ?>
        </select>
        <span class="text-gray-400">|</span>
        <select name="year" onchange="this.form.submit()" class="bg-transparent text-white outline-none cursor-pointer text-sm font-semibold">
            <?php 
            $curr_y = date('Y');
            for($y = $curr_y - 1; $y <= $curr_y + 1; $y++){
                $sel = ($y == $selected_year) ? 'selected' : '';
                echo "<option value='$y' class='text-gray-900' $sel>$y</option>";
            }
            ?>
        </select>
    </form>
        <a href="diesel_issue_excel.php" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition flex items-center gap-2 font-semibold">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="fuel.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            <i class="fas fa-gas-pump text-lg"></i> Fuel Rates
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 pb-4 h-[calc(100vh-50px)]">
    <div class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full h-full">
        <table class="w-full table-auto border-collapse">
            <thead class="text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Route</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Vehicle & Type</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Consumption</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Distance (km)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Need for Week (L)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">QR Quota (L)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Difference (L)</th>
                    <th class="sticky top-0 z-10 bg-blue-700 px-4 py-3 text-center shadow-sm border-l border-blue-500" style="min-width: 140px;">Monthly Allocate</th>
                    <th class="sticky top-0 z-10 bg-blue-700 px-4 py-3 text-right shadow-sm text-yellow-300">Issued (L)</th>
                    <th class="sticky top-0 z-10 bg-blue-700 px-4 py-3 text-right shadow-sm">Remaining (L)</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php
                if ($result && $result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        $route_code = htmlspecialchars($row["route_code"] ?? '-');
                        $route_name = htmlspecialchars($row["route"] ?? '-');
                        $vehicle_no = htmlspecialchars($row["vehicle_no"] ?? '-');
                        $vehicle_type = strtolower(trim($row['vehicle_type'] ?? ''));
                        $consumption_type = htmlspecialchars($row['consumption_type'] ?? '-');
                        $category = $row['route_category'];
                        
                        $distance = (float)($row['route_distance'] ?? 0);
                        $km_per_liter = (float)($row['km_per_liter'] ?? 0);

                        $need_for_week = ($km_per_liter > 0) ? ($distance / $km_per_liter) * 6 : 0;

                        $qr_quota = 0;
                        if ($vehicle_type === 'bus') $qr_quota = 60;
                        elseif ($vehicle_type === 'van') $qr_quota = 40;
                        elseif ($vehicle_type === 'wheel') $qr_quota = 15;

                        $difference = $qr_quota - $need_for_week;
                        $diff_color_class = $difference < 0 ? 'text-red-600' : 'text-green-600';
                        $sign = $difference > 0 ? '+' : '';

                        // --- NEW CALCULATIONS ---
                        $monthly_allocate = (float)($row['monthly_allocate'] ?? 0);
                        $issued_qty = (float)($row['issued_qty'] ?? 0);
                        $remaining = $monthly_allocate - $issued_qty;
                        $rem_color = ($remaining <= 10 && $monthly_allocate > 0) ? 'text-red-600' : 'text-green-600';

                        echo "<tr class='hover:bg-indigo-50 border-b border-gray-100 transition duration-150'>";
                        
                        echo "<td class='px-4 py-3'>
                                <div class='flex items-center gap-2'>
                                    <div class='font-mono text-blue-600 font-medium'>$route_code</div>
                                    <span class='text-[9px] uppercase px-1.5 py-0.5 bg-gray-100 text-gray-500 rounded-full border border-gray-200 font-bold'>$category</span>
                                </div>
                                <div class='text-xs text-gray-500'>$route_name</div>
                              </td>";
                              
                        echo "<td class='px-4 py-3'>
                                <div class='font-medium text-gray-800'>$vehicle_no</div>
                                <div class='text-[10px] bg-indigo-100 inline-block px-1.5 py-0.5 rounded text-indigo-700 mt-1 uppercase font-bold tracking-wide'>" . ($vehicle_type ?: 'Unknown') . "</div>
                              </td>";
                              
                        echo "<td class='px-4 py-3 text-sm'>
                                <div>$consumption_type</div>
                                <div class='text-xs text-gray-500'>" . number_format($km_per_liter, 2) . " km/L</div>
                              </td>";
                              
                        echo "<td class='px-4 py-3 text-right font-mono'>" . number_format($distance, 1) . "</td>";
                        echo "<td class='px-4 py-3 text-right font-mono font-bold text-orange-600'>" . number_format($need_for_week, 2) . "</td>";
                        echo "<td class='px-4 py-3 text-right font-mono font-bold text-indigo-600'>$qr_quota</td>";
                        echo "<td class='px-4 py-3 text-right font-mono font-bold $diff_color_class'>$sign" . number_format($difference, 2) . "</td>";
                        
                        // Monthly Allocate (Can be edited, triggers JS to save)
                        echo "<td class='px-4 py-3 text-center border-l border-gray-200 bg-blue-50/30'>
                                <input type='number' step='0.01' min='0' id='alloc_$route_code' value='" . number_format($monthly_allocate, 2, '.', '') . "' 
                                       onchange='updateAllocation(\"$route_code\", \"$category\", this.value)'
                                       class='w-24 px-2 py-1 text-right border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm transition font-bold text-gray-800'>
                              </td>";

                        // Issued (Sum from DB for the selected month)
                        echo "<td class='px-4 py-3 text-right font-mono font-bold text-yellow-600 bg-yellow-50/50'>" . number_format($issued_qty, 2) . "</td>";

                        // Remaining (Calculate dynamically)
                        echo "<td class='px-4 py-3 text-right font-mono font-bold $rem_color bg-green-50/30' id='rem_$route_code'>" . number_format($remaining, 2) . "</td>";
                              
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='10' class='px-6 py-4 text-center text-gray-500 italic'>No data found.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

<script>
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
    
    toast.innerHTML = `
        <i class="fas ${iconClass} toast-icon"></i>
        <span>${message}</span>`;
    
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Function to update the monthly allocation via AJAX without refreshing the page
function updateAllocation(code, category, value) {
    if (value === "" || value < 0) {
        showToast("Please enter a valid positive number.", "error");
        return;
    }

    fetch('update_allocation.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `code=${encodeURIComponent(code)}&category=${encodeURIComponent(category)}&val=${encodeURIComponent(value)}`
    })
    .then(response => response.text())
    .then(data => {
        if (data.trim() === "Success") {
            showToast(`Allocation for ${code} updated to ${value}L`, 'success');
            // Dynamically recalculate the remaining value on the UI
            setTimeout(() => { location.reload(); }, 500); // Reload to recalculate everything properly
        } else {
            showToast("Error updating: " + data, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast("Network error. Could not save.", 'error');
    });
}
</script>

</body>
</html>
<?php 
$conn->close();
?>