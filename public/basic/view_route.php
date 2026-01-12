<?php
// view_route.php
require_once '../../includes/session_check.php';

// Includes and Session setup
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- DYNAMIC FUEL PRICE & CONSUMPTION SETUP (Helper Functions) ---

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

// 2. Fetch Consumption Rates (km per Liter) indexed by c_type (Consumption ID)
$consumption_rates = [];
$consumption_sql = "SELECT c_id, distance FROM consumption"; 
$consumption_result = $conn->query($consumption_sql);
if ($consumption_result) {
    while ($row = $consumption_result->fetch_assoc()) {
        $consumption_rates[$row['c_id']] = $row['distance'];
    }
}
$default_km_per_liter = 1.00;

// 3. Helper Function to calculate the Fuel Cost per KM (නවීකරණය කරන ලදී)
// $fuel_price_per_liter පරාමිතිය ඉවත් කර ඇත
function calculate_fuel_cost_per_km($conn, $vehicle_no, $consumption_rates) {
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
    $consumption_id = $vehicle_row['fuel_efficiency'] ?? null;
    $rate_id = $vehicle_row['rate_id'] ?? null; 
    
    if ($consumption_id === null) {
        return 0;
    }

    if ($rate_id === null) {
        return 0;
    }

    // --- නව තර්කය: නිශ්චිත rate_id එකට අදාළ නවතම ඉන්ධන මිල ලබා ගැනීම ---
    $current_fuel_price_per_liter = get_latest_fuel_price_by_rate_id($conn, $rate_id);

    if ($current_fuel_price_per_liter <= 0) {
         return 0;
    }
    // ----------------------------------------------------------------------
    
    // b. Get the Consumption (km/L)
    $km_per_liter = $consumption_rates[$consumption_id] ?? 0;

    // Use default if derived rate is invalid
    if ($km_per_liter <= 0) {
        $km_per_liter = $default_km_per_liter;
    }
    
    // Fuel Cost per KM = Current Fuel Price / Consumption (km/L)
    $fuel_cost = $current_fuel_price_per_liter / $km_per_liter;
    return $fuel_cost;
}

// --- END DYNAMIC FUEL SETUP ---

$route_data = null;
// Use 'code' parameter, consistent with edit_route.php
$route_code = $_GET['code'] ?? ''; 

// 🎯 NEW: Capture Filter Parameters from the URL
$prev_purpose_filter = $_GET['purpose_filter'] ?? 'staff';
$prev_status_filter = $_GET['status_filter'] ?? 'active';

// Construct the URL to return to routes_staff2.php with filters preserved
$back_url = "routes_staff2.php?purpose_filter=" . urlencode($prev_purpose_filter) . "&status_filter=" . urlencode($prev_status_filter);

// --- 1. Fetch Existing Route Data and Supplier Name (Secure with Prepared Statements) ---
if (!empty($route_code)) {
    $sql_route = "SELECT 
                      r.*,
                      s.supplier AS supplier_name,
                      v.type ,
                      d.calling_name,
                      d.phone_no
                  FROM 
                      route r
                  JOIN 
                      supplier s ON r.supplier_code = s.supplier_code
                  LEFT JOIN
                      vehicle v ON r.vehicle_no = v.vehicle_no
                    LEFT JOIN
                      driver d ON v.driver_NIC = d.driver_NIC
                  WHERE r.route_code = ?";
    $stmt_route = $conn->prepare($sql_route);
    $stmt_route->bind_param("s", $route_code);
    $stmt_route->execute();
    $result_route = $stmt_route->get_result();
    
    if ($result_route->num_rows === 1) {
        $route_data = $result_route->fetch_assoc();
        
        $fixed_amount_float = (float)$route_data['fixed_amount'];
        $with_fuel = (int)$route_data['with_fuel'];
        $vehicle_no = htmlspecialchars($route_data['vehicle_no']);
        
        // 🎯 DYNAMIC FUEL CALCULATION (නවීකරණය කරන ලදී)
        $calculated_fuel_amount_per_km = 0;
        if ($with_fuel === 1) {
             $calculated_fuel_amount_per_km = calculate_fuel_cost_per_km(
                 $conn, 
                 $vehicle_no, 
                 $consumption_rates
                 // $current_fuel_price_per_liter පරාමිතිය ඉවත් කර ඇත
             );
        }
        
        $total_amount_per_km = $fixed_amount_float + $calculated_fuel_amount_per_km;

        // Determine Status text and color
        $status_text = ($route_data['is_active'] == 1) ? 'Active' : 'Inactive';
        $status_color_class = ($route_data['is_active'] == 1) ? 'bg-green-600' : 'bg-red-600';

        // Determine Fuel Option text
        $fuel_option_text = ($route_data['with_fuel'] == 1) ? 'With Fuel' : 'Without Fuel';

    } else {
        header("Location: {$back_url}&status=error&message=" . urlencode("Route not found."));
        exit();
    }
    $stmt_route->close();
} else {
    header("Location: {$back_url}&status=error&message=" . urlencode("Route code missing."));
    exit();
}

// Assuming header and navbar files exist at these paths
include('../../includes/header.php'); 
include('../../includes/navbar.php');
?>

<style>
    /* Styling for read-only fields for a clean look */
    .display-field {
        background-color: #e5e7eb; /* Tailwind gray-200 */
        border: 1px solid #d1d5db; /* Tailwind gray-300 */
        padding: 0.5rem 0.75rem;
        border-radius: 0.375rem; /* Tailwind rounded-md */
        color: #1f2937; /* Tailwind gray-900 */
        font-weight: 500;
        line-height: 1.5;
        min-height: 2.5rem; /* Ensure consistent height with inputs */
        display: flex;
        align-items: center;
    }
    .label-style {
        display: block;
        font-size: 0.875rem; /* text-sm */
        font-weight: 501; /* font-medium */
        color: #4b5563; /* text-gray-700 */
        margin-bottom: 0.25rem;
    }
</style>

<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>

<body class="bg-gray-100 font-sans">

<div class="w-[85%] ml-[15%]">
    <div class="max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10 mx-auto">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 pb-2 flex justify-between items-center">
            Route Details: <?= htmlspecialchars($route_data['route_code']) ?>
            <span class="text-sm font-semibold py-1 px-3 rounded-full <?= $status_color_class ?> text-white"><?= $status_text ?></span>
        </h1>
        
        <div class="space-y-6">
            <div class="border p-3 rounded-md space-y-2 bg-gray-50">
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label class="label-style">Route Code:</label>
                        <div class="display-field"><?= htmlspecialchars($route_data['route_code']) ?></div>
                    </div>
                    <div> 
                        <label class="label-style">Route Name:</label>
                        <div class="display-field"><?= htmlspecialchars($route_data['route']) ?></div>
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label class="label-style">Distance (km):</label>
                        <div class="display-field"><?= number_format($route_data['distance'], 2) ?> km</div>
                    </div>
                    <div>
                        <label class="label-style">Supplier:</label>
                        <div class="display-field">
                            <?= htmlspecialchars($route_data['supplier_name']) ?> (<?= htmlspecialchars($route_data['supplier_code']) ?>)
                        </div>
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label class="label-style">Vehicle No:</label>
                        <div class="display-field"><?= htmlspecialchars($route_data['vehicle_no']) ?> (<?= ucfirst(htmlspecialchars($route_data['type'])) ?>)</div>
                    </div>
                    <div>
                        <label class="label-style">Purpose:</label>
                        <div class="display-field"><?= ucfirst(htmlspecialchars($route_data['purpose'])) ?></div>
                    </div>
                </div>
            </div>    

            <div class="border rounded-md p-3 mt-3 gap-6 space-y-2 bg-gray-50">
                <h2 class="text-xl font-bold border-b pb-1 text-gray-800 mb-4">Pricing Details (Per 1 km)</h2>
                <div class="grid md:grid-cols-3 gap-6">
                    <div>
                        <label class="label-style">Fixed Amount (Rs./km):</label>
                        <div class="display-field">Rs. <?= number_format($fixed_amount_float, 2) ?></div>
                    </div>
                    <div>
                        <label class="label-style">Fuel Amount (Rs./km):</label>
                        <div class="display-field">Rs. <?= number_format($calculated_fuel_amount_per_km, 2) ?></div>
                    </div>
                    <div>
                        <label class="label-style">Total Amount (Rs./km):</label>
                        <div class="display-field font-extrabold text-lg text-indigo-700">Rs. <?= number_format($total_amount_per_km, 2) ?></div>
                    </div>
                </div>
                <div class="grid md:grid-cols-3 gap-6">
                    <div>
                        <label class="label-style">Assigned Person:</label>
                        <div class="display-field"><?= htmlspecialchars($route_data['assigned_person']) ?></div>
                    </div>
                    <div>
                        <label class="label-style">Fuel Option:</label>
                        <div class="display-field font-semibold"><?= $fuel_option_text ?></div>
                    </div>
                    <div>
                        <label class="label-style">driver:</label>
                        <div class="display-field"><?= htmlspecialchars($route_data['calling_name']) .' - ' . htmlspecialchars($route_data['phone_no']) ?></div>
                </div>
            </div>
        </div>
        
        <div class="flex justify-end mt-4 pt-2 space-x-4">
            <a href="<?= htmlspecialchars($back_url) ?>" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                Close
            </a>
        </div>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ?>