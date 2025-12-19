<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// ----------------------------------------
// FUNCTION TO FETCH ROUTE DATA
// ASSUMED SCHEMA: table 'route' has column 'route' and 'vehicle_no'.
// Displays "None" if not found, or a DB error message if the SQL is wrong.
// ----------------------------------------
function get_vehicle_route($conn, $vehicle_no) {
    if (empty($vehicle_no)) {
        return "N/A";
    }

    // Adjust the table name ('route') or column name ('route') if they are different in your database
    $sql = "SELECT route FROM route WHERE vehicle_no = ? LIMIT 1";
    
    // Check if the statement can be prepared
    if (!$stmt = mysqli_prepare($conn, $sql)) {
        // Return the specific MySQL error message for debugging
        return "DB Error: " . mysqli_error($conn); 
    }

    mysqli_stmt_bind_param($stmt, "s", $vehicle_no);
    mysqli_stmt_execute($stmt);
    
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        // Return the 'route' column value, defaulting to 'None' if the value itself is empty/null
        return !empty($row['route']) ? htmlspecialchars($row['route']) : "None";
    }
    
    return "None"; // Explicitly return "None" if the vehicle_no doesn't have a route record
}
// ----------------------------------------

?>

<script>
    // Auto logout after 9 hours (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000;
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php";

    setTimeout(() => {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL;
    }, SESSION_TIMEOUT_MS);
</script>

<div class="w-[85%] ml-[15%] min-h-screen bg-gray-100 font-sans p-8">

    <div class="flex justify-between items-center mb-8 border-b border-gray-300 pb-4">
        <h1 class="text-3xl font-bold text-gray-800 tracking-tight">Expiration Monitoring Dashboard</h1>

        <a href="generate_report.php" target="_blank"
            class="px-5 py-2 bg-emerald-600 text-white font-semibold rounded-md hover:bg-emerald-700 transition">
            ⬇️ Generate Full Report
        </a>
    </div>

    <section class="mb-10 bg-white border border-gray-200 rounded-lg shadow-sm p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
            <span class="text-2xl">🚗</span> Drivers with Expiring Licenses
        </h2>

        <?php
        $sql_drivers = "
            SELECT d.calling_name, d.phone_no, d.license_expiry_date,
                    DATEDIFF(d.license_expiry_date, CURDATE()) AS days_left,
                    v.vehicle_no, v.purpose
            FROM driver d
            LEFT JOIN vehicle v ON d.driver_NIC = v.driver_NIC
            WHERE DATEDIFF(d.license_expiry_date, CURDATE()) <= 15 AND d.is_active = 1
            ORDER BY d.license_expiry_date ASC
        ";

        $result_drivers = mysqli_query($conn, $sql_drivers);

        if (mysqli_num_rows($result_drivers) > 0) {
            echo '<div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">';
            while ($row = mysqli_fetch_assoc($result_drivers)) {

                $days_left = (int)$row['days_left'];
                $isExpired = $days_left < 0;

                $status_class = $isExpired ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800';
                $status_text  = $isExpired ? abs($days_left) . ' days passed' : $days_left . ' days left';

                // vehicle_no (purpose)
                $vehicle_display = "-";
                $route_name = "None"; // Default route name

                if (!empty($row["vehicle_no"])) {
                    $vehicle_no = htmlspecialchars($row["vehicle_no"]);
                    $vehicle_display = $vehicle_no . " (" . htmlspecialchars($row["purpose"]) . ")";
                    
                    // Fetch Route for Driver's Vehicle
                    $route_name = get_vehicle_route($conn, $row["vehicle_no"]);
                }

                // Removed 'group relative' and tooltip classes, and added the Route line
                echo '
                <div class="border border-gray-200 rounded-lg bg-gray-50 p-5 hover:shadow-md transition duration-200">
                    <h3 class="text-lg font-semibold text-gray-900">' . htmlspecialchars($row["calling_name"]) . '</h3>

                    <span class="inline-block px-3 py-1 text-xs font-medium rounded-full ' . $status_class . '">' . $status_text . '</span>

                    <p class="text-sm text-gray-600 mt-2">📞 ' . htmlspecialchars($row["phone_no"]) . '</p>
                    <p class="text-sm text-gray-600">🚘 ' . $vehicle_display . '</p>
                    <p class="text-sm text-gray-600">🛣️ Route: <b>' . $route_name . '</b></p>
                    <p class="text-sm text-gray-600">📅 Expiry: <b>' . htmlspecialchars($row["license_expiry_date"]) . '</b></p>
                </div>';
            }
            echo '</div>';
        } else {
            echo '<p class="text-gray-500 italic text-center">No drivers with licenses expiring soon.</p>';
        }
        ?>
    </section>

    <section class="mb-10 bg-white border border-gray-200 rounded-lg shadow-sm p-6">

        <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <span class="text-2xl">🚘</span> Vehicles with Expiring Documents
        </h2>

        <h3 class="text-lg font-semibold text-gray-700 mb-3 border-b border-gray-200 pb-1">📜 License Expiry</h3>

        <?php
        $sql_vehicle_license = "
            SELECT v.vehicle_no, v.purpose,
                    DATEDIFF(v.license_expiry_date, CURDATE()) AS days_left,
                    s.s_phone_no AS supplier_phone, d.phone_no AS driver_phone
            FROM vehicle v
            JOIN supplier s ON v.supplier_code = s.supplier_code
            LEFT JOIN driver d ON v.driver_NIC = d.driver_NIC
            WHERE DATEDIFF(v.license_expiry_date, CURDATE()) <= 15 AND v.is_active = 1
        ";

        $result_vehicle_license = mysqli_query($conn, $sql_vehicle_license);

        if (mysqli_num_rows($result_vehicle_license) > 0) {
            echo '<div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 mb-8">';
            while ($row = mysqli_fetch_assoc($result_vehicle_license)) {
                $days_left = $row['days_left'];
                $isExpired = $days_left < 0;

                $status_class = $isExpired ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800';
                $status_text  = $isExpired ? abs($days_left) . ' days passed' : $days_left . ' days left';

                // Fetch Route for the vehicle
                $vehicle_no = htmlspecialchars($row["vehicle_no"]);
                $route_name = get_vehicle_route($conn, $vehicle_no);
                
                // Removed 'group relative' and tooltip classes, and added the Route line
                echo '
                <div class="border border-gray-200 rounded-lg bg-gray-50 p-5 hover:shadow-md transition">
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">🚙 ' . $vehicle_no . '</h3>
                    <p class="text-sm text-gray-600">📋 Purpose: <b>' . htmlspecialchars($row["purpose"]) . '</b></p>
                    <p class="text-sm text-gray-600">🛣️ Route: <b>' . $route_name . '</b></p>
                    <p class="text-sm text-gray-600 mb-2">📞 ' . htmlspecialchars($row["supplier_phone"]) . ' (Suplier)</p>
                    <p class="text-sm text-gray-600 mb-2">📞 ' . htmlspecialchars($row["driver_phone"]) . ' (Driver)</p>
                    <span class="inline-block px-3 py-1 text-sm font-medium rounded-full ' . $status_class . '">' . $status_text . '</span>
                </div>';
            }
            echo '</div>';
        } else {
            echo '<p class="text-gray-500 italic text-center mb-6">No vehicle licenses expiring soon.</p>';
        }
        ?>

        <h3 class="text-lg font-semibold text-gray-700 mb-3 border-b border-gray-200 pb-1">🛡️ Insurance Expiry</h3>

        <?php
        $sql_vehicle_insurance = "
            SELECT v.vehicle_no, v.purpose,
                    DATEDIFF(v.insurance_expiry_date, CURDATE()) AS days_left,
                    s.s_phone_no AS supplier_phone, d.phone_no AS driver_phone
            FROM vehicle v
            JOIN supplier s ON v.supplier_code = s.supplier_code
            LEFT JOIN driver d ON v.driver_NIC = d.driver_NIC
            WHERE DATEDIFF(v.insurance_expiry_date, CURDATE()) <= 15 AND v.is_active = 1
        ";

        $result_vehicle_insurance = mysqli_query($conn, $sql_vehicle_insurance);

        if (mysqli_num_rows($result_vehicle_insurance) > 0) {
            echo '<div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">';
            while ($row = mysqli_fetch_assoc($result_vehicle_insurance)) {

                $days_left = $row['days_left'];
                $isExpired = $days_left < 0;

                $status_class = $isExpired ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800';
                $status_text  = $isExpired ? abs($days_left) . ' days passed' : $days_left . ' days left';
                
                // Fetch Route for the vehicle
                $vehicle_no = htmlspecialchars($row["vehicle_no"]);
                $route_name = get_vehicle_route($conn, $vehicle_no);

                // Removed 'group relative' and tooltip classes, and added the Route line
                echo '
                <div class="border border-gray-200 rounded-lg bg-gray-50 p-5 hover:shadow-md transition">
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">🚘 ' . $vehicle_no . '</h3>
                    <p class="text-sm text-gray-600">📋 Purpose: <b>' . htmlspecialchars($row["purpose"]) . '</b></p>
                    <p class="text-sm text-gray-600">🛣️ Route: <b>' . $route_name . '</b></p>
                    <p class="text-sm text-gray-600 mb-2">📞 ' . htmlspecialchars($row["supplier_phone"]) . ' (Suplier)</p>
                    <p class="text-sm text-gray-600 mb-2">📞 ' . htmlspecialchars($row["driver_phone"]) . ' (Driver)</p>
                    <span class="inline-block px-3 py-1 text-sm font-medium rounded-full ' . $status_class . '">' . $status_text . '</span>
                </div>';
            }
            echo '</div>';
        } else {
            echo '<p class="text-gray-500 italic text-center">No vehicle insurance expiring soon.</p>';
        }
        ?>
    </section>

    <section class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">

        <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <span class="text-2xl">🧰</span> Vehicles with Expiring Inspections
        </h2>

        <?php
        $sql_inspection = "
            SELECT s.supplier AS supplier_name,
                    s.s_phone_no AS supplier_phone,
                    c.supplier_code,
                    DATEDIFF(c.date, CURDATE()) AS days_left,
                    v.purpose
            FROM checkUp c
            JOIN supplier s ON c.supplier_code = s.supplier_code
            LEFT JOIN vehicle v ON s.supplier_code = v.supplier_code
            WHERE DATEDIFF(c.date, CURDATE()) <= 15 AND v.is_active = 1
            ORDER BY s.supplier, days_left
        ";

        $result_inspection = mysqli_query($conn, $sql_inspection);

        if (mysqli_num_rows($result_inspection) > 0) {

            // Group by supplier code
            $grouped_data = [];
            while ($row = mysqli_fetch_assoc($result_inspection)) {
                $code = $row['supplier_code'];

                if (!isset($grouped_data[$code])) {
                    $grouped_data[$code] = [
                        'name'      => $row['supplier_name'],
                        'phone'     => $row['supplier_phone'],
                        'days_left' => $row['days_left'],
                        'purpose'   => $row['purpose']
                    ];
                } else {
                    if ($row['days_left'] < $grouped_data[$code]['days_left']) {
                        $grouped_data[$code]['days_left'] = $row['days_left'];
                    }

                    if (!str_contains($grouped_data[$code]['purpose'], $row['purpose'])) {
                        $grouped_data[$code]['purpose'] .= " / " . $row['purpose'];
                    }
                }
            }

            echo '<div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">';
            foreach ($grouped_data as $data) {
                $days_left = $data['days_left'];
                $isExpired = $days_left < 0;

                $status_class = $isExpired ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800';
                $status_text  = $isExpired ? abs($days_left) . ' days passed' : $days_left . ' days left';

                echo '
                <div class="border border-gray-200 rounded-lg bg-gray-50 p-5 hover:shadow-md transition">
                    <h3 class="text-lg font-semibold text-gray-900">🛠️ ' . htmlspecialchars($data["name"]) . '</h3>
                    <p class="text-sm text-gray-600">📋 Purpose: <b>' . htmlspecialchars($data["purpose"]) . '</b></p>
                    <p class="text-sm text-gray-600 mb-2">📞 ' . htmlspecialchars($data["phone"]) . '</p>

                    <span class="inline-block px-3 py-1 text-sm font-medium rounded-full ' . $status_class . '">' . $status_text . '</span>
                </div>';
            }
            echo '</div>';

        } else {
            echo '<p class="text-gray-500 italic text-center">No inspections expiring soon.</p>';
        }
        ?>
    </section>

</div>

<?php mysqli_close($conn); ?>