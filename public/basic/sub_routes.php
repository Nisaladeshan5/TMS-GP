<?php
// sub_routes.php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- FUEL PRICE & CONSUMPTION LOGIC (Routes eken gaththa widiyatama) ---
function get_latest_fuel_price_by_rate_id($conn, $rate_id) {
    $sql = "SELECT rate FROM fuel_rate WHERE rate_id = ? ORDER BY date DESC, id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0; 
    $stmt->bind_param("i", $rate_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['rate'] ?? 0;
}

$consumption_rates = [];
$consumption_result = $conn->query("SELECT c_id, distance FROM consumption");
if ($consumption_result) {
    while ($row = $consumption_result->fetch_assoc()) {
        $consumption_rates[$row['c_id']] = $row['distance'];
    }
}

function calculate_fuel_cost_per_km($conn, $vehicle_no, $consumption_rates) {
    if (empty($vehicle_no)) return 0;
    $stmt = $conn->prepare("SELECT fuel_efficiency, rate_id FROM vehicle WHERE vehicle_no = ?");
    $stmt->bind_param("s", $vehicle_no);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $rate_id = $res['rate_id'] ?? null; 
    $c_id = $res['fuel_efficiency'] ?? null; 
    if (!$rate_id || !$c_id) return 0;
    $price = get_latest_fuel_price_by_rate_id($conn, $rate_id);
    $km_per_l = $consumption_rates[$c_id] ?? 1.0;
    return ($price > 0) ? ($price / $km_per_l) : 0;
}
// -----------------------------------------------------------------------

include('../../includes/header.php');
include('../../includes/navbar.php');

// Filter values
$status_filter = isset($_GET['status_filter']) && in_array($_GET['status_filter'], ['active', 'inactive']) ? $_GET['status_filter'] : 'active';
$route_filter = isset($_GET['route_filter']) ? $_GET['route_filter'] : '';

// 1. Fetch Routes for dropdown
$routes_list = $conn->query("SELECT DISTINCT r.route_code, r.route 
                             FROM route r 
                             INNER JOIN sub_route sr ON r.route_code = sr.route_code 
                             ORDER BY r.route ASC");

// 2. Modified SQL Query (Column names fixed_rate, with_fuel use kara)
$sub_routes_sql = "SELECT sr.sub_route_code, sr.route_code, sr.supplier_code, s.supplier, 
                          sr.sub_route, sr.distance, sr.fixed_rate, sr.is_active, sr.vehicle_no, sr.with_fuel
                   FROM sub_route sr
                   JOIN route r ON sr.route_code = r.route_code
                   JOIN supplier s ON sr.supplier_code = s.supplier_code";

$where_clauses = [];
if ($status_filter === 'active') {
    $where_clauses[] = "sr.is_active = 1";
} else {
    $where_clauses[] = "sr.is_active = 0";
}

if (!empty($route_filter)) {
    $where_clauses[] = "sr.route_code = '" . $conn->real_escape_string($route_filter) . "'";
}

if (count($where_clauses) > 0) {
    $sub_routes_sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sub_routes_result = $conn->query($sub_routes_sql);

$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sub-Route Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
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

<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Sub-Routes
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            <select id="route-filter" onchange="filterData()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <option value="" class="text-gray-900 bg-white">All Main Routes</option>
                <?php 
                if ($routes_list) {
                    while($r = $routes_list->fetch_assoc()) {
                        $selected = ($route_filter == $r['route_code']) ? 'selected' : '';
                        echo "<option value='".htmlspecialchars($r['route_code'])."' $selected class='text-gray-900 bg-white'>".htmlspecialchars($r['route'])."</option>";
                    }
                }
                ?>
            </select>
        </div>

        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            <select id="status-filter" onchange="filterData()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Active</option>
                <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Inactive</option>
            </select>
        </div>

        <span class="text-gray-600">|</span>

        <a href="add_sub_route.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Sub-Route
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    <div id="table-container" class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-h-[85vh]">
        <table class="w-full table-auto border-collapse">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Sub-Route Code</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Supplier & Vehicle</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Sub-Route</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Fixed (1km)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Fuel (1km)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Total (1km)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Dist. (km)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm" style="min-width: 140px;">Actions</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php
                if ($sub_routes_result && $sub_routes_result->num_rows > 0) {
                    while ($row = $sub_routes_result->fetch_assoc()) {
                        $fixed = (float)$row["fixed_rate"];
                        $fuel = 0;
                        if ((int)$row["with_fuel"] === 1) {
                            $fuel = calculate_fuel_cost_per_km($conn, $row["vehicle_no"], $consumption_rates);
                        }
                        $total = $fixed + $fuel;

                        $is_active = htmlspecialchars($row["is_active"]);
                        $toggle_button_text = ($is_active == 1) ? 'Disable' : 'Enable';
                        $toggle_button_class = ($is_active == 1) ? 'bg-red-500 hover:bg-red-600' : 'bg-green-500 hover:bg-green-600';
                        $toggle_icon = ($is_active == 1) ? 'fa-ban' : 'fa-check';

                        echo "<tr class='hover:bg-indigo-50 border-b border-gray-100 transition duration-150'>";
                        echo "<td class='px-4 py-3 font-mono text-blue-600 font-medium'>" . htmlspecialchars($row["sub_route_code"]) . "</td>";
                        echo "<td class='px-4 py-3'>
                                <div class='font-bold uppercase'>" . htmlspecialchars($row["vehicle_no"]) . "</div>
                                <div class='text-xs text-gray-500'>" . htmlspecialchars($row["supplier"]) . "</div>
                              </td>";
                        echo "<td class='px-4 py-3 font-medium text-gray-800'>" . htmlspecialchars($row["sub_route"]) . "</td>";
                        echo "<td class='px-4 py-3 text-right font-mono text-gray-600'>" . number_format($fixed, 2) . "</td>";
                        echo "<td class='px-4 py-3 text-right font-mono text-gray-500'>" . number_format($fuel, 2) . "</td>";
                        echo "<td class='px-4 py-3 text-right font-bold text-orange-600 font-mono'>" . number_format($total, 2) . "</td>";
                        echo "<td class='px-4 py-3 text-right font-mono'>" . htmlspecialchars($row["distance"]) . "</td>";
                        echo "<td class='px-4 py-3 text-center'>
                                <div class='flex justify-center gap-2'>
                                    <a href='edit_sub_route.php?code=" . urlencode($row['sub_route_code']) . "' class='bg-yellow-500 hover:bg-yellow-600 text-white py-1 px-2 rounded-md shadow-sm transition' title='Edit'><i class='fas fa-edit text-xs'></i></a>
                                    <button onclick='toggleStatus(\"{$row['sub_route_code']}\", {$is_active})' class='" . $toggle_button_class . " text-white py-1 px-2 rounded-md shadow-sm transition' title='$toggle_button_text'><i class='fas $toggle_icon text-xs'></i></button>
                                </div>
                              </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='8' class='px-6 py-4 text-center text-gray-500 italic'>No records found matching criteria.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

<script>
    function filterData() {
        const status = document.getElementById('status-filter').value;
        const route = document.getElementById('route-filter').value;
        window.location.href = `?status_filter=${status}&route_filter=${route}`;
    }

    function showToast(message, type = 'success') {
        const toastContainer = document.getElementById("toast-container");
        const toast = document.createElement('div');
        toast.className = `toast ${type} show`;
        const iconHtml = type === 'success' ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-triangle mr-2"></i>';
        toast.innerHTML = iconHtml + `<span>${message}</span>`;
        toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 3000);
    }

    <?php if ($toast): ?>
        showToast("<?php echo htmlspecialchars($toast['message']); ?>", "<?php echo htmlspecialchars($toast['type']); ?>");
    <?php endif; ?>

    function toggleStatus(subRouteCode, currentStatus) {
        const newStatus = currentStatus === 1 ? 0 : 1;
        const actionText = newStatus === 1 ? 'enable' : 'disable';
        if (confirm(`Are you sure you want to ${actionText} this sub-route?`)) {
            fetch(`sub_routes_backend.php?toggle_status=true&sub_route_code=${encodeURIComponent(subRouteCode)}&new_status=${newStatus}`)
            .then(response => response.text())
            .then(data => {
                if (data.trim() === "Success") {
                    location.reload();
                } else {
                    showToast("Error: " + data, 'error');
                }
            });
        }
    }
</script>
</body>
</html>