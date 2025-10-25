<?php
// Include the database connection.
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<div class="w-[85%] ml-[15%] min-h-screen bg-gray-100 font-sans p-8">

    <!-- Header -->
    <div class="flex justify-between items-center mb-8 border-b border-gray-300 pb-4">
        <h1 class="text-3xl font-bold text-gray-800 tracking-tight">Expiration Monitoring Dashboard</h1>
        <a href="generate_report.php" target="_blank"
           class="px-5 py-2 bg-emerald-600 text-white font-semibold rounded-md hover:bg-emerald-700 transition">
            ⬇️ Generate Full Report
        </a>
    </div>

    <!-- Drivers -->
    <section class="mb-10 bg-white border border-gray-200 rounded-lg shadow-sm p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
            <span class="text-2xl">🚗</span> Drivers with Expiring Licenses
        </h2>

        <?php
        $sql_drivers = "
            SELECT d.calling_name, d.phone_no, d.license_expiry_date, 
                DATEDIFF(d.license_expiry_date, CURDATE()) AS days_left,
                v.vehicle_no
            FROM driver d
            LEFT JOIN vehicle v ON d.driver_NIC = v.driver_NIC
            WHERE DATEDIFF(d.license_expiry_date, CURDATE()) <= 15 
            OR DATEDIFF(d.license_expiry_date, CURDATE()) < 0
            ORDER BY d.license_expiry_date ASC
        ";
        $result_drivers = mysqli_query($conn, $sql_drivers);

        if (mysqli_num_rows($result_drivers) > 0) {
            echo '<div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">';
            while ($row = mysqli_fetch_assoc($result_drivers)) {
                $days_left = (int)$row['days_left'];
                $isExpired = $days_left < 0;
                $status_class = $isExpired ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800';
                $status_text = $isExpired 
                    ? abs($days_left) . ' days passed' 
                    : $days_left . ' days left';

                echo '
                <div class="border border-gray-200 rounded-lg bg-gray-50 p-5 hover:shadow-md transition duration-200">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-lg font-semibold text-gray-900">' . htmlspecialchars($row["calling_name"]) . '</h3>
                        <span class="inline-block px-3 py-1 text-xs font-medium rounded-full ' . $status_class . '">' . $status_text . '</span>
                    </div>
                    <p class="text-sm text-gray-600 mb-1">📞 ' . htmlspecialchars($row["phone_no"]) . '</p>
                    <p class="text-sm text-gray-600 mb-1">🚘 <span class="font-medium text-gray-800">' . (!empty($row["vehicle_no"]) ? htmlspecialchars($row["vehicle_no"]) : '-') . '</span></p>
                    <p class="text-sm text-gray-600">📅 Expiry: <span class="font-medium text-gray-800">' . htmlspecialchars($row["license_expiry_date"]) . '</span></p>
                </div>';
            }
            echo '</div>';
        } else {
            echo '<p class="text-gray-500 italic text-center">No drivers with licenses expiring soon.</p>';
        }
        ?>
    </section>

    <!-- Vehicle Documents -->
    <section class="mb-10 bg-white border border-gray-200 rounded-lg shadow-sm p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <span class="text-2xl">🚘</span> Vehicles with Expiring Documents
        </h2>

        <!-- License -->
        <h3 class="text-lg font-semibold text-gray-700 mb-3 border-b border-gray-200 pb-1">📜 License Expiry</h3>
        <?php
        $sql_vehicle_license = "SELECT 
                v.vehicle_no, 
                DATEDIFF(v.license_expiry_date, CURDATE()) AS days_left,
                s.s_phone_no AS supplier_phone
            FROM vehicle v
            JOIN supplier s ON v.supplier_code = s.supplier_code
            WHERE DATEDIFF(v.license_expiry_date, CURDATE()) <= 15 OR DATEDIFF(v.license_expiry_date, CURDATE()) < 0";
        $result_vehicle_license = mysqli_query($conn, $sql_vehicle_license);

        if (mysqli_num_rows($result_vehicle_license) > 0) {
            echo '<div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 mb-8">';
            while($row = mysqli_fetch_assoc($result_vehicle_license)) {
                $days_left = $row['days_left'];
                $isExpired = $days_left < 0;
                $status_class = $isExpired ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800';
                $status_text = $isExpired ? abs($days_left) . ' days passed' : $days_left . ' days left';

                echo '
                <div class="border border-gray-200 rounded-lg bg-gray-50 p-5 hover:shadow-md transition">
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">🚙 ' . htmlspecialchars($row["vehicle_no"]) . '</h3>
                    <p class="text-sm text-gray-600 mb-2">📞 ' . htmlspecialchars($row["supplier_phone"]) . '</p>
                    <span class="inline-block px-3 py-1 text-sm font-medium rounded-full ' . $status_class . '">' . $status_text . '</span>
                </div>';
            }
            echo '</div>';
        } else {
            echo '<p class="text-gray-500 italic text-center mb-6">No vehicle licenses expiring soon.</p>';
        }
        ?>

        <!-- Insurance -->
        <h3 class="text-lg font-semibold text-gray-700 mb-3 border-b border-gray-200 pb-1">🛡️ Insurance Expiry</h3>
        <?php
        $sql_vehicle_insurance = "SELECT 
                v.vehicle_no, 
                DATEDIFF(v.insurance_expiry_date, CURDATE()) AS days_left,
                s.s_phone_no AS supplier_phone
            FROM vehicle v
            JOIN supplier s ON v.supplier_code = s.supplier_code
            WHERE DATEDIFF(v.insurance_expiry_date, CURDATE()) <= 15 OR DATEDIFF(v.insurance_expiry_date, CURDATE()) < 0";
        $result_vehicle_insurance = mysqli_query($conn, $sql_vehicle_insurance);

        if (mysqli_num_rows($result_vehicle_insurance) > 0) {
            echo '<div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">';
            while($row = mysqli_fetch_assoc($result_vehicle_insurance)) {
                $days_left = $row['days_left'];
                $isExpired = $days_left < 0;
                $status_class = $isExpired ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800';
                $status_text = $isExpired ? abs($days_left) . ' days passed' : $days_left . ' days left';

                echo '
                <div class="border border-gray-200 rounded-lg bg-gray-50 p-5 hover:shadow-md transition">
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">🚘 ' . htmlspecialchars($row["vehicle_no"]) . '</h3>
                    <p class="text-sm text-gray-600 mb-2">📞 ' . htmlspecialchars($row["supplier_phone"]) . '</p>
                    <span class="inline-block px-3 py-1 text-sm font-medium rounded-full ' . $status_class . '">' . $status_text . '</span>
                </div>';
            }
            echo '</div>';
        } else {
            echo '<p class="text-gray-500 italic text-center">No vehicle insurance expiring soon.</p>';
        }
        ?>
    </section>

    <!-- Inspections -->
    <section class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <span class="text-2xl">🧰</span> Vehicles with Expiring Inspections
        </h2>

        <?php
        $sql_inspection = "SELECT 
                s.supplier AS supplier_name,
                s.s_phone_no AS supplier_phone,
                c.supplier_code,
                DATEDIFF(c.date, CURDATE()) AS days_left
            FROM checkUp c
            JOIN supplier s ON c.supplier_code = s.supplier_code
            WHERE DATEDIFF(c.date, CURDATE()) <= 15 OR DATEDIFF(c.date, CURDATE()) < 0
            ORDER BY s.supplier, days_left";
        $result_inspection = mysqli_query($conn, $sql_inspection);

        if (mysqli_num_rows($result_inspection) > 0) {
            $grouped_data = [];
            while($row = mysqli_fetch_assoc($result_inspection)) {
                $code = $row['supplier_code'];
                if (!isset($grouped_data[$code])) {
                    $grouped_data[$code] = [
                        'name' => $row['supplier_name'],
                        'phone' => $row['supplier_phone'],
                        'days_left' => $row['days_left']
                    ];
                } else {
                    if ($row['days_left'] < $grouped_data[$code]['days_left']) {
                        $grouped_data[$code]['days_left'] = $row['days_left'];
                    }
                }
            }

            echo '<div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">';
            foreach ($grouped_data as $data) {
                $days_left = $data['days_left'];
                $isExpired = $days_left < 0;
                $status_class = $isExpired ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800';
                $status_text = $isExpired ? abs($days_left) . ' days passed' : $days_left . ' days left';

                echo '
                <div class="border border-gray-200 rounded-lg bg-gray-50 p-5 hover:shadow-md transition">
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">' . htmlspecialchars($data["name"]) . '</h3>
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
