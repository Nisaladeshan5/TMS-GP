<?php
// Include the database connection.
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<div class="w-[85%] ml-[15%] p-6 font-sans bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">

    <!-- Drivers with Expiring Licenses -->
    <section class="m-4 p-6 rounded-2xl shadow-xl bg-white border border-blue-100">
        <div class="flex items-center gap-2 mb-6">
            <span class="text-3xl">⏳</span>
            <p class="text-2xl font-bold text-gray-800">Drivers with Expiring Licenses</p>
        </div>
        <?php
        $sql_drivers = "SELECT calling_name, phone_no, license_expiry_date, DATEDIFF(license_expiry_date, CURDATE()) AS days_left 
                        FROM driver 
                        WHERE DATEDIFF(license_expiry_date, CURDATE()) <= 15 OR DATEDIFF(license_expiry_date, CURDATE()) < 0";
        $result_drivers = mysqli_query($conn, $sql_drivers);

        if (mysqli_num_rows($result_drivers) > 0) {
            echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">';
            while($row = mysqli_fetch_assoc($result_drivers)) {
                $days_left = $row['days_left'];
                $status_class = ($days_left > 0) ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700';
                $status_text = ($days_left > 0) ? $days_left . ' days left' : abs($days_left) . ' days passed';

                echo '<div class="rounded-xl bg-gradient-to-br from-blue-50 to-white shadow-md p-5 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">';
                echo '<h4 class="text-lg font-bold text-gray-900 mb-2">👤 ' . htmlspecialchars($row["calling_name"]) . '</h4>';
                echo '<p class="text-sm text-gray-600 mb-2"><span class="font-semibold text-gray-800">📞 Phone:</span> ' . htmlspecialchars($row["phone_no"]) . '</p>';
                echo '<span class="px-3 py-1 text-sm font-bold rounded-full ' . $status_class . '">' . $status_text . '</span>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<p class="text-gray-500 italic mt-4 text-center">✨ No drivers with licenses expiring soon.</p>';
        }
        ?>
    </section>

    <!-- Vehicles with Expiring Documents -->
    <section class="m-4 p-6 rounded-2xl shadow-xl bg-white border border-blue-100">
        <div class="flex items-center gap-2 mb-6">
            <span class="text-3xl">🚗</span>
            <p class="text-2xl font-bold text-gray-800">Vehicles with Expiring Documents</p>
        </div>

        <!-- License Expiry -->
        <h3 class="text-xl font-semibold text-gray-700 border-b-2 border-gray-200 pb-2 mb-4">📜 License Expiry</h3>
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
            echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-8">';
            while($row = mysqli_fetch_assoc($result_vehicle_license)) {
                $days_left = $row['days_left'];
                $status_class = ($days_left > 0) ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700';
                $status_text = ($days_left > 0) ? $days_left . ' days left' : abs($days_left) . ' days passed';

                echo '<div class="rounded-xl bg-gradient-to-br from-blue-50 to-white shadow-md p-5 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">';
                echo '<h4 class="text-lg font-bold text-gray-900 mb-2">🚙 ' . htmlspecialchars($row["vehicle_no"]) . '</h4>';
                echo '<p class="text-sm text-gray-600 mb-2"><span class="font-semibold text-gray-800">📞 Phone:</span> ' . htmlspecialchars($row["supplier_phone"]) . '</p>';
                echo '<span class="px-3 py-1 text-sm font-bold rounded-full ' . $status_class . '">' . $status_text . '</span>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<p class="text-gray-500 italic mb-6 text-center">✨ No vehicle licenses expiring soon.</p>';
        }
        ?>

        <!-- Insurance Expiry -->
        <h3 class="text-xl font-semibold text-gray-700 border-b-2 border-gray-200 pb-2 mb-4">🛡️ Insurance Expiry</h3>
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
            echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">';
            while($row = mysqli_fetch_assoc($result_vehicle_insurance)) {
                $days_left = $row['days_left'];
                $status_class = ($days_left > 0) ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700';
                $status_text = ($days_left > 0) ? $days_left . ' days left' : abs($days_left) . ' days passed';

                echo '<div class="rounded-xl bg-gradient-to-br from-blue-50 to-white shadow-md p-5 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">';
                echo '<h4 class="text-lg font-bold text-gray-900 mb-2">🚘 ' . htmlspecialchars($row["vehicle_no"]) . '</h4>';
                echo '<p class="text-sm text-gray-600 mb-2"><span class="font-semibold text-gray-800">📞 Phone:</span> ' . htmlspecialchars($row["supplier_phone"]) . '</p>';
                echo '<span class="px-3 py-1 text-sm font-bold rounded-full ' . $status_class . '">' . $status_text . '</span>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<p class="text-gray-500 italic mt-4 text-center">✨ No vehicle insurance expiring soon.</p>';
        }
        ?>
    </section>

    <!-- Vehicles with Expiring Inspections -->
    <section class="m-4 p-6 rounded-2xl shadow-xl bg-white border border-blue-100">
        <div class="flex items-center gap-2 mb-6">
            <span class="text-3xl">🛠️</span>
            <p class="text-2xl font-bold text-gray-800">Vehicles with Expiring Inspections</p>
        </div>
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
                $supplier_code = $row['supplier_code'];
                if (!isset($grouped_data[$supplier_code])) {
                    $grouped_data[$supplier_code] = [
                        'name' => $row['supplier_name'],
                        'phone' => $row['supplier_phone'],
                        'most_critical_days_left' => $row['days_left']
                    ];
                } else {
                    if ($row['days_left'] < $grouped_data[$supplier_code]['most_critical_days_left']) {
                        $grouped_data[$supplier_code]['most_critical_days_left'] = $row['days_left'];
                    }
                }
            }

            echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">';
            foreach($grouped_data as $supplier_code => $data) {
                $days_left = $data['most_critical_days_left'];
                $status_class = ($days_left > 0) ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700';
                $status_text = ($days_left > 0) ? $days_left . ' days left' : abs($days_left) . ' days passed';

                echo '<div class="rounded-xl bg-gradient-to-br from-blue-50 to-white shadow-md p-5 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">';
                echo '<h4 class="text-lg font-bold text-gray-900 mb-2">🏭 Supplier: ' . htmlspecialchars($data["name"]) . '</h4>';
                echo '<p class="text-sm text-gray-600 mb-2"><span class="font-semibold text-gray-800">📞 Phone:</span> ' . htmlspecialchars($data["phone"]) . '</p>';
                echo '<span class="px-3 py-1 text-sm font-bold rounded-full ' . $status_class . '">' . $status_text . '</span>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<p class="text-gray-500 italic mt-4 text-center">✨ No inspections expiring soon.</p>';
        }
        ?>
    </section>

</div>

<?php
// Close the database connection
mysqli_close($conn);
?>
