<?php
// routes_staff2.php
ob_start();
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
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

include('../../includes/db.php');

// --- DYNAMIC FUEL PRICE & CONSUMPTION SETUP ---

// 1. Fetch LATEST Fuel Price for a specific rate_id
function get_latest_fuel_price_by_rate_id($conn, $rate_id)
{
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

// 2. Fetch Consumption Rates
$consumption_rates = [];
$consumption_sql = "SELECT c_id, distance FROM consumption";
$consumption_result = $conn->query($consumption_sql);
if ($consumption_result) {
    while ($row = $consumption_result->fetch_assoc()) {
        $consumption_rates[$row['c_id']] = $row['distance'];
    }
}
$default_km_per_liter = 1.00;

// 3. Helper Function to calculate Fuel Cost per KM
function calculate_fuel_cost_per_km($conn, $vehicle_no, $consumption_rates) 
{
    global $default_km_per_liter;

    if (empty($vehicle_no)) return 0;

    $vehicle_stmt = $conn->prepare("SELECT fuel_efficiency, rate_id FROM vehicle WHERE vehicle_no = ?");
    $vehicle_stmt->bind_param("s", $vehicle_no);
    $vehicle_stmt->execute();
    $vehicle_result = $vehicle_stmt->get_result();
    $vehicle_row = $vehicle_result->fetch_assoc();
    $vehicle_stmt->close();

    $rate_id = $vehicle_row['rate_id'] ?? null; 
    $consumption_id = $vehicle_row['fuel_efficiency'] ?? null; 

    if ($rate_id === null || $consumption_id === null) return 0;

    $current_fuel_price_per_liter = get_latest_fuel_price_by_rate_id($conn, $rate_id);

    if ($current_fuel_price_per_liter <= 0) return 0;

    $km_per_liter = $consumption_rates[$consumption_id] ?? 0;

    if ($km_per_liter <= 0) $km_per_liter = $default_km_per_liter;

    $fuel_cost = $current_fuel_price_per_liter / $km_per_liter; 
    return $fuel_cost;
}

// --------------------------------------------------------------------------------

$purpose_filter = isset($_GET['purpose_filter']) && in_array($_GET['purpose_filter'], ['staff', 'factory']) ? $_GET['purpose_filter'] : 'staff';
$status_filter = isset($_GET['status_filter']) && in_array($_GET['status_filter'], ['active', 'inactive']) ? $_GET['status_filter'] : 'active';

$status_value = ($status_filter === 'active') ? 1 : 0;

// --- Build the Secure SQL Query ---
$sql = "SELECT
            r.route_code, r.supplier_code, s.supplier, r.route, r.purpose,
            r.distance, r.vehicle_no, r.fixed_amount, r.fuel_amount, 
            r.with_fuel, 
            r.assigned_person, r.is_active
        FROM
            route r
        JOIN
            supplier s ON r.supplier_code = s.supplier_code
        WHERE
            r.purpose = ?
        AND
            r.is_active = ?
        ORDER BY
            SUBSTRING(r.route_code, 7, 3) ASC;";

$stmt = $conn->prepare($sql);
if (!$stmt) die("SQL prepare error: " . $conn->error);
$stmt->bind_param("si", $purpose_filter, $status_value);
$stmt->execute();
$result = $stmt->get_result();

// --- Toast Message ---
$toast_message = '';
$toast_status = '';
if (isset($_GET['status']) && isset($_GET['message'])) {
    $toast_status = htmlspecialchars($_GET['status']);
    $toast_message = urldecode(htmlspecialchars($_GET['message']));
}

include('../../includes/header.php');
include('../../includes/navbar.php');

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Toast CSS */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
        
        /* Scrollbar */
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
            Routes
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner space-x-2">
            
            <select id="purpose-filter" onchange="filterRoutes()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <option value="staff" <?php echo ($purpose_filter === 'staff') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Staff</option>
                <option value="factory" <?php echo ($purpose_filter === 'factory') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Factory</option>
            </select>
            
            <span class="text-gray-400">|</span>

            <select id="status-filter" onchange="filterRoutes()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-1 pr-2 appearance-none hover:text-yellow-200 transition">
                <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Active</option>
                <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Inactive</option>
            </select>

        </div>

        <span class="text-gray-600">|</span>

        <button onclick="generateRouteQrPdf()" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-file-pdf"></i> Genarate QR
        </button>

        <a href="add_route.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Route
        </a>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-h-[88vh]">
        <table class="w-full table-auto border-collapse">
            <thead class="text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-2 py-3 text-center w-10 shadow-sm">
                        <input type="checkbox" id="select-all" onclick="toggleAllCheckboxes()" class="cursor-pointer">
                    </th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Route Code</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Route</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Supplier</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Fixed (1km)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Fuel (1km)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Total (1km)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Distance (km)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm" style="min-width: 140px;">Actions</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php
                if ($result && $result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        $route_code = htmlspecialchars($row["route_code"]);
                        $supplier_name = htmlspecialchars($row["supplier"]);
                        $route_name = htmlspecialchars($row["route"]);
                        $vehicle_no = htmlspecialchars($row["vehicle_no"]);
                        $with_fuel = (int)$row["with_fuel"];
                        $fixed_amount_float = (float)$row["fixed_amount"];
                        $distance_float = (float)$row["distance"];
                        
                        $current_calculated_fuel_amount = 0;
                        $current_filter_params = "&purpose_filter=" . urlencode($purpose_filter) . "&status_filter=" . urlencode($status_filter);

                        if ($with_fuel === 1) {
                            $current_calculated_fuel_amount = calculate_fuel_cost_per_km($conn, $vehicle_no, $consumption_rates);
                        }

                        $total_amount = $fixed_amount_float + $current_calculated_fuel_amount;

                        $is_active = htmlspecialchars($row["is_active"]);
                        $toggle_button_text = ($is_active == 1) ? 'Disable' : 'Enable';
                        $toggle_button_class = ($is_active == 1) ? 'bg-red-500 hover:bg-red-600' : 'bg-green-500 hover:bg-green-600';
                        $toggle_icon = ($is_active == 1) ? 'fa-ban' : 'fa-check';

                        echo "<tr class='hover:bg-indigo-50 border-b border-gray-100 transition duration-150'>";
                        echo "<td class='px-2 py-3 text-center'><input type='checkbox' name='selected_routes[]' value='" . $route_code . "' class='route-checkbox cursor-pointer'></td>";
                        echo "<td class='px-4 py-3 font-mono text-blue-600 font-medium'>" . $route_code . "</td>";
                        echo "<td class='px-4 py-3 font-medium text-gray-800'>" . $route_name . "</td>";
                        echo "<td class='px-4 py-3 text-sm'>" . $supplier_name . "</td>";
                        echo "<td class='px-4 py-3 text-right font-mono'>" . number_format($fixed_amount_float, 2) . "</td>";
                        echo "<td class='px-4 py-3 text-right font-mono text-gray-600'>" . number_format($current_calculated_fuel_amount, 2) . "</td>";
                        echo "<td class='px-4 py-3 text-right font-bold text-orange-600 font-mono'>" . number_format($total_amount, 2) . "</td>";
                        echo "<td class='px-4 py-3 text-right font-mono'>" . number_format($distance_float, 2) . "</td>";
                        
                        echo "<td class='px-4 py-3 text-center'>
                                <div class='flex justify-center gap-1'>
                                    <a href='view_route.php?code=$route_code" . $current_filter_params . "' class='bg-green-500 hover:bg-green-600 text-white py-1 px-2 rounded-md shadow-sm transition' title='View'>
                                        <i class='fas fa-eye text-xs'></i>
                                    </a>
                                    
                                    <a href='edit_route.php?code=$route_code" . $current_filter_params . "' class='bg-yellow-500 hover:bg-yellow-600 text-white py-1 px-2 rounded-md shadow-sm transition' title='Edit'>
                                        <i class='fas fa-edit text-xs'></i>
                                    </a>
                                    
                                    <button onclick='toggleRouteStatus(\"$route_code\", $is_active)' class='" . $toggle_button_class . " text-white py-1 px-2 rounded-md shadow-sm transition' title='$toggle_button_text'>
                                        <i class='fas $toggle_icon text-xs'></i>
                                    </button>
                                </div>
                              </td>";
                        echo "</tr>";
                    }
                } else {
                    $display_message = ($status_filter === 'active')
                        ? "No active routes found for {$purpose_filter} purpose."
                        : "No inactive routes found for {$purpose_filter} purpose.";
                    echo "<tr><td colspan='9' class='px-6 py-4 text-center text-gray-500 italic'>
                            {$display_message}
                          </td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

<script>
// Toast Function
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

document.addEventListener('DOMContentLoaded', function() {
    const initialStatus = '<?= $toast_status ?>';
    const initialMessage = '<?= $toast_message ?>';
    if (initialMessage && initialStatus) {
        showToast(initialMessage, initialStatus);
    }
});

function toggleAllCheckboxes() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.route-checkbox');
    checkboxes.forEach(checkbox => checkbox.checked = selectAll.checked);
}

function generateRouteQrPdf() {
    const selectedRoutes = Array.from(document.querySelectorAll('.route-checkbox:checked'))
        .map(checkbox => checkbox.value);
    
    if (selectedRoutes.length === 0) {
        showToast("Please select at least one route to generate the PDF.", 'error');
        return;
    }
    
    const routeCodesString = selectedRoutes.join(',');
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'generate_qr_route_pdf.php';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'selected_route_codes';
    input.value = routeCodesString;
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

function toggleRouteStatus(routeCode, currentStatus) {
    const newStatus = currentStatus === 1 ? 0 : 1;
    const actionText = newStatus === 1 ? 'enable' : 'disable';
    
    if (confirm(`Are you sure you want to ${actionText} this route?`)) {
        fetch(`routes_backend2.php?toggle_status=true&route_code=${encodeURIComponent(routeCode)}&new_status=${newStatus}`)
        .then(response => response.text())
        .then(data => {
            if (data.trim() === "Success") {
                showToast(`Route ${actionText}d successfully!`, 'success');
                setTimeout(() => { location.reload(); }, 1000); // Reload to reflect changes cleanly
            } else {
                showToast("Error: " + data, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast("An error occurred. Please try again.", 'error');
        });
    }
}

function filterRoutes() {
    const purpose = document.getElementById('purpose-filter').value;
    const status = document.getElementById('status-filter').value;
    window.location.href = `routes_staff2.php?purpose_filter=${purpose}&status_filter=${status}`;
}
</script>
</body>
</html>

<?php 
if (isset($stmt)) $stmt->close();
$conn->close();
?>