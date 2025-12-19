<?php
// routes_staff2.php
// Output buffering ආරම්භ කරන්න, navbar.php හෝ header.php වෙතින් header error වළක්වා ගැනීමට
ob_start();
require_once '../../includes/session_check.php';

// Includes
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

// 1. Fetch LATEST Fuel Price for a specific rate_id (consumption ID)
// මෙම ශ්‍රිතය fuel_rate වගුවෙන් නිශ්චිත rate_id එකට අදාළ නවතම මිල ලබා ගනී.
function get_latest_fuel_price_by_rate_id($conn, $rate_id)
{
    // rate_id එක integer එකක් යැයි උපකල්පනය කරමු
    $sql = "SELECT rate FROM fuel_rate WHERE rate_id = ? ORDER BY date DESC, id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        // SQL prepare error: 
        return 0; 
    }
    $stmt->bind_param("i", $rate_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['rate'] ?? 0;
}
// ගෝලීය වශයෙන් නවතම මිල ලබා ගැනීම අවශ්‍ය නොවේ, එය එක් එක් මාර්ගය සඳහා ගණනය කරනු ලැබේ.
// $current_fuel_price_per_liter = get_current_fuel_price($conn); // <<-- මෙම පේළිය ඉවත් කර ඇත

// 2. Fetch Consumption Rates (km per Liter)
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
// $fuel_price_per_liter පරාමිතිය ඉවත් කර ඇත
function calculate_fuel_cost_per_km($conn, $vehicle_no, $consumption_rates) 
{
    global $default_km_per_liter;

    if (empty($vehicle_no)) {
        return 0;
    }

    // a. Get the Vehicle's Fuel Efficiency ID (rate_id)
    $vehicle_stmt = $conn->prepare("SELECT fuel_efficiency, rate_id FROM vehicle WHERE vehicle_no = ?");
    $vehicle_stmt->bind_param("s", $vehicle_no);
    $vehicle_stmt->execute();
    $vehicle_result = $vehicle_stmt->get_result();
    $vehicle_row = $vehicle_result->fetch_assoc();
    $vehicle_stmt->close();

    // fuel_efficiency යනු rate_id යැයි උපකල්පනය කරමු
    $rate_id = $vehicle_row['rate_id'] ?? null; 
    $consumption_id = $vehicle_row['fuel_efficiency'] ?? null; 

    if ($rate_id === null) {
        return 0;
    }

    if ($consumption_id === null) {
        return 0;
    }

    // --- නව තර්කය: නිශ්චිත rate_id එකට අදාළ නවතම ඉන්ධන මිල ලබා ගැනීම ---
    $current_fuel_price_per_liter = get_latest_fuel_price_by_rate_id($conn, $rate_id);

    if ($current_fuel_price_per_liter <= 0) {
         return 0;
    }
    // ----------------------------------------------------------------------

    // b. Get the Consumption (km/L)
    // consumption_id එක fuel_efficiency (rate_id) එක ලෙස භාවිත කරයි
    $km_per_liter = $consumption_rates[$consumption_id] ?? 0;

    if ($km_per_liter <= 0) {
        $km_per_liter = $default_km_per_liter;
    }

    // නිශ්චිත rate_id එකට අදාළ නවතම මිල භාවිතයෙන් ගණනය කිරීම
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
if (!$stmt) {
    die("SQL prepare error: " . $conn->error);
}
$stmt->bind_param("si", $purpose_filter, $status_value);
$stmt->execute();
$result = $stmt->get_result();

// --- Toast Message ---
$toast_message = '';
$toast_status = '';
if (isset($_GET['status']) && isset($_GET['message'])) {
    $toast_status = htmlspecialchars($_GET['status']);
    $toast_message = htmlspecialchars($_GET['message']);
    $toast_message = urldecode($toast_message);
}

include('../../includes/header.php');
include('../../includes/navbar.php');

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Toast CSS */
        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
        }
        .toast {
            display: none;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }
        .toast.show {
            display: flex;
            align-items: center;
            transform: translateY(0);
            opacity: 1;
        }
        .toast.success {
            background-color: #4CAF50;
            color: white;
        }
        .toast.error {
            background-color: #F44336;
            color: white;
        }
        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
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

<body class="bg-gray-100">
<div class="containerl flex justify-center">
    <div class="w-[85%] ml-[15%]">
        <div class="p-3">
            <h1 class="text-4xl mx-auto font-bold text-gray-800 mt-3 mb-3 text-center">Route Details</h1>
            <div class="w-full flex justify-between items-center mb-6">

                <div class="flex space-x-4">
                    <a href="add_route.php" class="bg-blue-500 text-white font-bold py-2 px-4 rounded-md shadow-md hover:bg-blue-600">
                        Add New Route
                    </a>
                    <button onclick="generateRouteQrPdf()" class="bg-green-700 text-white font-bold py-2 px-4 rounded-md shadow-md hover:bg-green-800">
                        Generate Route QR PDF
                    </button>
                </div>

                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-1">
                        <label for="purpose-filter" class="text-gray-700 font-semibold">Filter by Purpose:</label>
                        <select id="purpose-filter" onchange="filterRoutes()" class="p-2 border rounded-md">
                            <option value="staff" <?php echo ($purpose_filter === 'staff') ? 'selected' : ''; ?>>Staff</option>
                            <option value="factory" <?php echo ($purpose_filter === 'factory') ? 'selected' : ''; ?>>Factory</option>
                        </select>
                    </div>
                    <div class="flex items-center space-x-1">
                        <label for="status-filter" class="text-gray-700 font-semibold">Filter by Status:</label>
                        <select id="status-filter" onchange="filterRoutes()" class="p-2 border rounded-md">
                            <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto bg-white shadow-md rounded-md w-full">
                <table class="min-w-full table-auto">
                    <thead class="bg-blue-600 text-white">
                        <tr>
                            <th class="px-2 py-2 text-center w-10"><input type="checkbox" id="select-all" onclick="toggleAllCheckboxes()"></th>
                            <th class="px-2 py-2 text-left">Route Code</th>
                            <th class="px-2 py-2 text-left">Route</th>
                            <th class="px-2 py-2 text-left">Supplier</th>
                            <th class="px-2 py-2 text-left">Fixed Price(1km)</th>
                            <th class="px-2 py-2 text-left">Fuel Price(1km)</th>
                            <th class="px-2 py-2 text-left">Total Price(1km)</th>
                            <th class="px-2 py-2 text-left">Distance (km)</th>
                            <th class="px-2 py-2 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
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
                                    // නවීකරණය කරන ලද ශ්‍රිතය ඇමතීම
                                    $current_calculated_fuel_amount = calculate_fuel_cost_per_km($conn, $vehicle_no, $consumption_rates);
                                }

                                $total_amount = $fixed_amount_float + $current_calculated_fuel_amount;

                                $is_active = htmlspecialchars($row["is_active"]);
                                $toggle_button_text = ($is_active == 1) ? 'Disable' : 'Enable';
                                $toggle_button_color = ($is_active == 1) ? 'bg-red-500 hover:bg-red-600' : 'bg-green-500 hover:bg-green-600';

                                echo "<tr>";
                                echo "<td class='border px-2 py-2 text-center'><input type='checkbox' name='selected_routes[]' value='" . $route_code . "' class='route-checkbox'></td>";
                                echo "<td class='border px-2 py-2'>" . $route_code . "</td>";
                                echo "<td class='border px-2 py-2'>" . $route_name . "</td>";
                                echo "<td class='border px-2 py-2'>" . $supplier_name . "</td>";
                                echo "<td class='border px-2 py-2'>" . number_format($fixed_amount_float, 2) . "</td>";
                                echo "<td class='border px-2 py-2 font-semibold'>" . number_format($current_calculated_fuel_amount, 2) . "</td>";
                                echo "<td class='border px-2 py-2 font-bold text-orange-600'>" . number_format($total_amount, 2) . "</td>";
                                echo "<td class='border px-2 py-2'>" . number_format($distance_float, 2) . "</td>";
                                echo "<td class='border px-2 py-2'>
                                        <div class='flex flex-nowrap space-x-1'>
                                            <a href='view_route.php?code=$route_code" . $current_filter_params . "' class='bg-green-500 hover:bg-green-600 text-white font-bold py-0.5 px-1 rounded text-xs'>View</a>
                                            
                                            <a href='edit_route.php?code=$route_code" . $current_filter_params . "' class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-0.5 px-1 rounded text-xs'>Edit</a>
                                            
                                            <button onclick='toggleRouteStatus(\"$route_code\", $is_active)' class='" . $toggle_button_color . " text-white font-bold py-0.5 px-1 rounded text-xs'>$toggle_button_text</button>
                                        </div>
                                    </td>";
                                echo "</tr>";
                            }
                        } else {
                            $display_message = ($status_filter === 'active')
                                ? "No active routes found for {$purpose_filter} purpose."
                                : "No inactive routes found for {$purpose_filter} purpose.";
                            echo "<tr><td colspan='9' class='border px-4 py-2 text-center'>{$display_message}</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script>
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.classList.add('toast', type);
    toast.innerHTML = `
        <svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            ${type === 'success' ?
                `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />`
                :
                `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />`
            }
        </svg>
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
                setTimeout(() => { filterRoutes(); }, 2300);
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