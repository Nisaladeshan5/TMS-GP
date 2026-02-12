<?php
// view_vehicle.php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

$vehicle_no = $_GET['vehicle_no'] ?? null;
$vehicle_data = null;
$error_message = '';
$assigned_route_code = 'None'; // 🎯 නව විචල්‍යය

if ($vehicle_no) {
    // --- 1. Fetch Vehicle Data ---
    $sql = "SELECT
                vehicle.vehicle_no,
                vehicle.supplier_code,
                supplier.supplier,
                vehicle.capacity,
                vehicle.standing_capacity,
                ct.c_type, 
                vehicle.type,
                vehicle.purpose,
                vehicle.license_expiry_date,
                vehicle.insurance_expiry_date,
                vehicle.is_active,
                fr.type AS fuel_type
            FROM
                vehicle
            LEFT JOIN
                supplier ON vehicle.supplier_code = supplier.supplier_code
            LEFT JOIN
                consumption AS ct ON vehicle.fuel_efficiency = ct.c_id
            LEFT JOIN
                fuel_rate AS fr ON vehicle.rate_id = fr.rate_id
            WHERE
                vehicle.vehicle_no = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $vehicle_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $vehicle_data = $result->fetch_assoc();
    $stmt->close();

    if (!$vehicle_data) {
        $error_message = "Vehicle with number '" . htmlspecialchars($vehicle_no) . "' not found.";
    } else {
        $status_text = ($vehicle_data['is_active'] == 1) ? 'Active' : 'Inactive';
        $status_color_class = ($vehicle_data['is_active'] == 1) ? 'bg-green-600' : 'bg-red-600';

        // --- 2. 🎯 Check for Assigned Route ---
        $route_sql = "SELECT route_code, route FROM route WHERE vehicle_no = ? AND is_active = 1 LIMIT 1";
        $route_stmt = $conn->prepare($route_sql);
        $route_stmt->bind_param('s', $vehicle_no);
        $route_stmt->execute();
        $route_result = $route_stmt->get_result();
        
        if ($route_result->num_rows > 0) {
            $route_row = $route_result->fetch_assoc();
            $assigned_route_code = htmlspecialchars($route_row['route_code']);
            $assigned_route = htmlspecialchars($route_row['route']);
        }
        $route_stmt->close();
    }

} else {
    $error_message = "No vehicle number provided.";
}

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<style>
    /* Compact View Styles from your previous request */
    .display-field-view {
        background-color: #e5e7eb; /* Tailwind gray-200 */
        border: 1px solid #d1d5db; /* Tailwind gray-300 */
        padding: 0.5rem 0.75rem;
        border-radius: 0.375rem; /* Tailwind rounded-md */
        color: #1f2937; /* Tailwind gray-900 */
        font-weight: 500;
        line-height: 1.5;
        min-height: 2.5rem;
        display: flex;
        align-items: center;
    }
    .label-style-view {
        display: block;
        font-size: 0.875rem; /* text-sm */
        font-weight: 501; /* font-medium */
        color: #4b5563; /* text-gray-700 */
        margin-bottom: 0.125rem;
    }
</style>
<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100 font-sans">

<div class="w-[85%] ml-[15%]">
    <div class="w-2xl p-8 bg-white shadow-lg rounded-lg mt-10 mx-auto max-w-4xl">
        
        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                <strong class="font-bold">Error!</strong>
                <span class="block sm:inline"><?php echo $error_message; ?></span>
            </div>
            <div class="mt-4">
                <a href="vehicle.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition duration-300">Go Back</a>
            </div>
        <?php else: ?>

            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-3  pb-1 flex justify-between items-center">
                Vehicle Details: <?= htmlspecialchars($vehicle_data['vehicle_no']) ?>
                <span class="text-sm font-semibold py-1 px-3 rounded-full <?= $status_color_class ?> text-white"><?= $status_text ?></span>
            </h1>
            
            <div class="space-y-3">
                
                <div class="border pt-2 mt-2 bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-xl font-bold text-blue-900 mb-2 border-b pb-1">Basic Information</h2>
                    <div class="grid md:grid-cols-2 gap-3">
                        
                        <div>
                            <label class="label-style-view">Vehicle No:</label>
                            <div class="display-field-view"><?= htmlspecialchars($vehicle_data['vehicle_no']) ?></div>
                        </div>
                        <div>
                            <label class="label-style-view">Supplier:</label>
                            <div class="display-field-view"><?= htmlspecialchars($vehicle_data['supplier']) ?> (<?= htmlspecialchars($vehicle_data['supplier_code']) ?>)</div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="label-style-view">Seating:</label>
                                <div class="display-field-view"><?= htmlspecialchars($vehicle_data['capacity']) ?></div>
                            </div>
                            <div>
                                <label class="label-style-view">Standing:</label>
                                <div class="display-field-view"><?= htmlspecialchars($vehicle_data['standing_capacity']) ?></div>
                            </div>
                        </div>
                        <div>
                            <label class="label-style-view">Type:</label>
                            <div class="display-field-view"><?= htmlspecialchars($vehicle_data['type']) ?></div>
                        </div>

                        <div>
                            <label class="label-style-view">Purpose:</label>
                            <div class="display-field-view"><?= ucfirst(htmlspecialchars($vehicle_data['purpose'])) ?></div>
                        </div>
                        <div>
                            <label class="label-style-view">Fuel Type:</label>
                            <div class="display-field-view"><?= htmlspecialchars($vehicle_data['fuel_type']) ?></div>
                        </div>
                        
                        <div class="md:col-span-2 grid grid-cols-2 gap-3">
                            <div>
                                <label class="label-style-view">Fuel Efficiency:</label>
                                <div class="display-field-view"><?= htmlspecialchars($vehicle_data['c_type']) ?></div>
                            </div>
                            <div>
                                <label class="label-style-view">Assigned Route:</label>
                                <div class="display-field-view font-bold text-indigo-700">
                                    <?= $assigned_route_code ?> (<?= $assigned_route ?>)
                                </div>
                            </div>
                        </div>
                        
                    </div>
                </div>

                <div class="border pt-2 mt-2 bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-xl font-bold text-gray-800 mb-3 border-b pb-1">Document Expiry Dates</h2>
                    <div class="grid md:grid-cols-2 gap-3">
                        
                        <div>
                            <label class="label-style-view">License Expiry Date:</label>
                            <div class="display-field-view"><?= htmlspecialchars($vehicle_data['license_expiry_date'] ?? 'N/A') ?></div>
                        </div>
                        
                        <div>
                            <label class="label-style-view">Insurance Expiry Date:</label>
                            <div class="display-field-view"><?= htmlspecialchars($vehicle_data['insurance_expiry_date'] ?? 'N/A') ?></div>
                        </div>
                        
                    </div>
                </div>

            </div>
            
            <div class="flex justify-end mt-4 pt-2 border-t border-gray-200 space-x-4">
                <a href="vehicle.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Close
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ?>