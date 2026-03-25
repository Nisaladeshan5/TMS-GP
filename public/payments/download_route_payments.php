<?php
// download_route_payments.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$payment_data = [];

// =======================================================================
// 1. HELPER FUNCTIONS (DYNAMIC FUEL LOGIC)
// =======================================================================

// Fetch Fuel Price changes within the selected Month and Year
function get_fuel_price_changes_in_month($conn, $rate_id, $month, $year) {
    $start_date = "$year-$month-01";
    $end_date = date('Y-m-t', strtotime($start_date)); 

    $sql = "SELECT date, rate FROM fuel_rate WHERE rate_id = ? AND date <= ? ORDER BY date DESC, id DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return []; 
    
    $stmt->bind_param("is", $rate_id, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $changes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $slabs = [];
    foreach ($changes as $change) {
        $change_date = $change['date'];
        $rate = (float)$change['rate'];
        if (strtotime($change_date) < strtotime($start_date)) {
            $slabs[date('Y-m-d', strtotime($start_date))] = $rate;
            break; 
        }
        $slabs[$change_date] = $rate;
    }
    ksort($slabs);
    return $slabs;
}

// Fetch Consumption Rates
$consumption_rates = [];
$consumption_result = $conn->query("SELECT c_id, distance FROM consumption");
if ($consumption_result) {
    while ($row = $consumption_result->fetch_assoc()) {
        $consumption_rates[$row['c_id']] = $row['distance'];
    }
}
$default_km_per_liter = 1.00;

// Helper Function: Get Adjustment
function get_total_adjustment_amount($conn, $route_code, $supplier_code, $month, $year) {
    $total_adjustment = 0.00;
    $reduction_sql = "SELECT SUM(amount) AS total_adjustment_amount FROM reduction WHERE route_code = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
    $reduction_stmt = $conn->prepare($reduction_sql);
    if ($reduction_stmt) {
        $reduction_stmt->bind_param("ssii", $route_code, $supplier_code, $month, $year);
        if ($reduction_stmt->execute()) {
            $row = $reduction_stmt->get_result()->fetch_assoc();
            $total_adjustment = (float)($row['total_adjustment_amount'] ?? 0);
        }
        $reduction_stmt->close();
    }
    return $total_adjustment;
}

// =======================================================================
// 2. MAIN LOGIC (STAFF DYNAMIC CALCULATION)
// =======================================================================

$payments_sql = "
    SELECT DISTINCT stvr.route AS route_code, stvr.supplier_code, r.route, r.fixed_amount, r.distance AS route_distance, r.with_fuel, v.fuel_efficiency, v.rate_id, v.vehicle_no
    FROM staff_transport_vehicle_register stvr 
    JOIN route r ON stvr.route = r.route_code
    LEFT JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
    WHERE MONTH(stvr.date) = ? AND YEAR(stvr.date) = ? AND r.purpose = 'staff'
    ORDER BY CAST(SUBSTRING(stvr.route, 7, 3) AS UNSIGNED) ASC";

$payments_stmt = $conn->prepare($payments_sql);
$payments_stmt->bind_param("ii", $selected_month, $selected_year);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();

if ($payments_result && $payments_result->num_rows > 0) {
    while ($row = $payments_result->fetch_assoc()) {
        $route_code = $row['route_code'];
        $supplier_code = $row['supplier_code'];
        $fixed_amount = (float)$row['fixed_amount'];
        $route_distance = (float)$row['route_distance'];
        $with_fuel = (int)$row['with_fuel'];
        $consumption_id = $row['fuel_efficiency'];
        $rate_id = $row['rate_id'];

        // 1. Get Normal Trip Dates and Counts
        $trips_sql = "SELECT date, COUNT(id) AS daily_trips FROM staff_transport_vehicle_register WHERE route = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1 GROUP BY date";
        $trips_stmt = $conn->prepare($trips_sql);
        $trips_stmt->bind_param("ssii", $route_code, $supplier_code, $selected_month, $selected_year);
        $trips_stmt->execute();
        $daily_trips_data = $trips_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $trips_stmt->close();

        // Fuel Price Setup
        $price_slabs = ($with_fuel === 1 && $rate_id !== null) ? get_fuel_price_changes_in_month($conn, $rate_id, $selected_month, $selected_year) : [];
        $km_per_liter = $consumption_rates[$consumption_id] ?? $default_km_per_liter;

        $total_calculated_payment = 0;
        $total_trip_count = 0;
        $last_trip_rate = 0;

        // Calculate Payment based on Daily Fuel Rates
        foreach ($daily_trips_data as $dt) {
            $trip_date = $dt['date'];
            $trip_count = (int)$dt['daily_trips'];
            $total_trip_count += $trip_count;

            $latest_fuel_price = 0;
            if ($with_fuel === 1) {
                foreach ($price_slabs as $change_date => $rate) {
                    if (strtotime($trip_date) >= strtotime($change_date)) $latest_fuel_price = $rate;
                }
            }

            $fuel_per_km = ($with_fuel === 1 && $km_per_liter > 0) ? ($latest_fuel_price / $km_per_liter) : 0;
            $rate_per_km = $fixed_amount + $fuel_per_km;
            $last_trip_rate = $rate_per_km * ($route_distance / 2); 
            $total_calculated_payment += ($last_trip_rate * $trip_count);
        }

        // 2. Get EXTRA trips payment (using dynamic rate)
        $extra_payment = 0;
        $extra_sql = "SELECT date, distance FROM extra_vehicle_register WHERE route = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ? AND done = 1";
        $extra_stmt = $conn->prepare($extra_sql);
        $extra_stmt->bind_param("ssii", $route_code, $supplier_code, $selected_month, $selected_year);
        $extra_stmt->execute();
        $extra_res = $extra_stmt->get_result();
        while($ex = $extra_res->fetch_assoc()) {
            $ex_date = $ex['date'];
            $ex_fuel_price = 0;
            if ($with_fuel === 1) {
                foreach ($price_slabs as $c_date => $r) {
                    if (strtotime($ex_date) >= strtotime($c_date)) $ex_fuel_price = $r;
                }
            }
            $ex_fuel_per_km = ($with_fuel === 1 && $km_per_liter > 0) ? ($ex_fuel_price / $km_per_liter) : 0;
            $extra_payment += ((float)$ex['distance'] * ($fixed_amount + $ex_fuel_per_km));
        }
        $extra_stmt->close();

        // 3. Adjustment
        $other_amount = get_total_adjustment_amount($conn, $route_code, $supplier_code, $selected_month, $selected_year) * -1;
        
        // Calculate Working Days Payment (Base + Adjustment)
        $working_days_payment = $total_calculated_payment + $other_amount;
        
        $final_total = $working_days_payment + $extra_payment;

        $payment_data[] = [
            'route_code' => $route_code, 
            'vehicle_no' => $row['vehicle_no'] ?? 'N/A', 
            'supplier_code' => $supplier_code, 
            'route' => $row['route'],
            'day_rate' => $last_trip_rate, 
            'total_working_days' => $total_trip_count,
            'total_distance' => ($route_distance / 2) * $total_trip_count,
            'calculated_base_payment' => $total_calculated_payment,
            'other_amount' => $other_amount,
            'working_days_payment' => $working_days_payment,
            'extra_amount' => $extra_payment, 
            'payments' => $final_total 
        ];
    }
}

// --- EXCEL GENERATION START ---
$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));
$filename = "Staff_Payments_{$month_name}_{$selected_year}.xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        table { font-family: Arial, sans-serif; border-collapse: collapse; }
        .text-format { mso-number-format:"\@"; } 
        .currency-format { mso-number-format:"\#\,\#\#0\.00"; text-align: right; }
        .summary-row { background-color: #f2f2f2; font-weight: bold; }
        td, th { padding: 5px; vertical-align: middle; }
    </style>
</head>
<body>
    <table border="1">
        <thead>
            <tr>
                <th colspan="11" style="font-size: 18px; font-weight: bold; text-align: center; background-color: #FFFF00; padding: 15px;">
                    Staff Route Monthly Payment Report - <?php echo "$month_name $selected_year"; ?>
                </th>
            </tr>
            
            <tr>
                <td colspan="11" style="font-size: 15px; font-weight: bold; text-align: center; background-color: #EBF1DE; color: #005c29; border: 2px solid #00B050; padding: 10px;">
                    FOR ACCOUNTS DEPARTMENT: Only use the Green Column (Working Days Payment)
                </td>
            </tr>

            <tr>
                <td colspan="11" style="font-size: 15px; font-weight: bold; text-align: center; background-color: #F2DCDB; color: #C00000; border: 2px solid #C00000; padding: 10px;">
                    WARNING - FOR TRANSPORT DEPARTMENT ONLY: The Red Columns (Extra Payment, Total Payments) are STRICTLY for internal records. DO NOT send these values to Accounts!
                </td>
            </tr>

            <tr style="color:white; font-weight:bold; text-align:center;">
                <th style="background-color: #4F81BD;">Route Code</th>
                <th style="background-color: #4F81BD;">Route</th>
                <th style="background-color: #4F81BD;">Vehicle No</th>
                <th style="background-color: #4F81BD;">Trip Rate (LKR)</th>
                <th style="background-color: #4F81BD;">Total Trip Count</th>
                <th style="background-color: #4F81BD;">Total Distance (km)</th>
                <th style="background-color: #4F81BD;">Base Payment (LKR)</th>
                <th style="background-color: #4F81BD;">Adjustment (LKR)</th>
                
                <th style="background-color: #00B050; font-size: 13px;">Working Days Payment (LKR)<br>(Accounts Valid)</th>
                
                <th style="background-color: #C0504D;">Extra Payment (LKR)</th>
                <th style="background-color: #C00000; font-size: 13px;">Total Payments (LKR)<br>(Transport Only)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sum_trips = 0; $sum_dist = 0; $sum_base = 0; 
            $sum_adj = 0; $sum_working = 0; $sum_extra = 0; $sum_final = 0;

            if (!empty($payment_data)): 
                foreach ($payment_data as $row): 
                    $sum_trips += $row['total_working_days'];
                    $sum_dist += $row['total_distance'];
                    $sum_base += $row['calculated_base_payment'];
                    $sum_adj += $row['other_amount'];
                    $sum_working += $row['working_days_payment'];
                    $sum_extra += $row['extra_amount'];
                    $sum_final += $row['payments'];
            ?>
                    <tr>
                        <td class="text-format"><?php echo htmlspecialchars($row['route_code']); ?></td>
                        <td><?php echo htmlspecialchars($row['route']); ?></td>
                        <td><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                        <td class="currency-format"><?php echo number_format($row['day_rate'], 2); ?></td>
                        <td style="text-align:center;"><?php echo $row['total_working_days']; ?></td>
                        <td style="text-align:center;"><?php echo number_format($row['total_distance'], 2); ?></td>
                        <td class="currency-format"><?php echo number_format($row['calculated_base_payment'], 2); ?></td>
                        <td class="currency-format" style="color: <?php echo ($row['other_amount'] < 0) ? 'red' : 'black'; ?>;">
                            <?php echo number_format($row['other_amount'], 2); ?>
                        </td>
                        
                        <td class="currency-format" style="font-weight:bold; background-color:#EBF1DE; border: 2px solid #00B050; color:#005c29;">
                            <?php echo number_format($row['working_days_payment'], 2); ?>
                        </td>
                        
                        <td class="currency-format" style="color:#C00000; border-left: 2px solid #C00000;">
                            <?php echo number_format($row['extra_amount'], 2); ?>
                        </td>
                        <td class="currency-format" style="font-weight:bold; background-color:#F2DCDB; border: 2px solid #C00000; color:#C00000;">
                            <?php echo number_format($row['payments'], 2); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                
                <tr class="summary-row">
                    <td colspan="4" style="text-align: right; padding-right: 10px;">GRAND TOTAL:</td>
                    <td style="text-align:center;"><?php echo $sum_trips; ?></td>
                    <td style="text-align:center;"><?php echo number_format($sum_dist, 2); ?></td>
                    <td class="currency-format"><?php echo number_format($sum_base, 2); ?></td>
                    <td class="currency-format"><?php echo number_format($sum_adj, 2); ?></td>
                    
                    <td class="currency-format" style="background-color:#EBF1DE; border: 2px solid #00B050; color:#005c29; font-weight:bold;">
                        <?php echo number_format($sum_working, 2); ?>
                    </td>
                    
                    <td class="currency-format" style="color:#C00000; border-left: 2px solid #C00000;">
                        <?php echo number_format($sum_extra, 2); ?>
                    </td>
                    <td class="currency-format" style="background-color:#F2DCDB; border: 2px solid #C00000; color:#C00000; font-weight:bold;">
                        <?php echo number_format($sum_final, 2); ?>
                    </td>
                </tr>
            <?php else: ?>
                <tr><td colspan="11" style="text-align:center; padding: 20px;">No records found for this period.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php
    // =======================================================================
    // 3. FETCH AND DISPLAY EXTRA TRIPS BREAKDOWN TABLE (STAFF)
    // =======================================================================

    // Add spacing between tables
    echo '<br><br>';

    echo '<table border="1">';

    // Title for breakdown table
    echo '<tr>';
    echo '<td colspan="7" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #F2DCDB; color: #C00000; padding: 10px; border: 2px solid #C00000;">';
    echo 'Extra Trips Breakdown (Transport Department Reference) - ' . $month_name . ' ' . $selected_year;
    echo '</td>';
    echo '</tr>';

    // Headers
    echo '<tr style="color:white; font-weight:bold; text-align:center; background-color:#C0504D;">';
    echo '<th style="padding: 8px; width: 100px;">Date</th>';
    echo '<th style="padding: 8px; width: 200px;">Route</th>';
    echo '<th style="padding: 8px; width: 200px;">Supplier</th>';
    echo '<th style="padding: 8px; width: 150px;">From</th>';
    echo '<th style="padding: 8px; width: 150px;">To</th>';
    echo '<th style="padding: 8px; width: 100px;">Distance (km)</th>';
    echo '<th style="padding: 8px; width: 250px;">Remarks</th>';
    echo '</tr>';

    // Query for Extra Vehicle Register (Staff Specific)
    $extra_sql = "
        SELECT 
            evr.date, 
            evr.route AS route_code, 
            r.route AS route_name,
            evr.supplier_code, 
            s.supplier AS supplier_name,
            evr.from_location, 
            evr.to_location, 
            evr.distance, 
            evr.remarks
        FROM extra_vehicle_register evr
        LEFT JOIN route r ON evr.route = r.route_code
        LEFT JOIN supplier s ON evr.supplier_code = s.supplier_code
        WHERE MONTH(evr.date) = ? 
        AND YEAR(evr.date) = ? 
        AND evr.done = 1 
        AND SUBSTRING(evr.route, 5, 1) = 'S' /* Staff Routes Only */
        ORDER BY evr.date ASC, evr.route ASC
    ";

    $extra_stmt = $conn->prepare($extra_sql);
    $extra_stmt->bind_param("ii", $selected_month, $selected_year);
    $extra_stmt->execute();
    $extra_result = $extra_stmt->get_result();

    if ($extra_result && $extra_result->num_rows > 0) {
        while ($extra = $extra_result->fetch_assoc()) {
            $ex_supplier = !empty($extra['supplier_name']) ? htmlspecialchars($extra['supplier_name']) : htmlspecialchars($extra['supplier_code'] ?? '');
            $ex_route = !empty($extra['route_name']) ? htmlspecialchars($extra['route_name']) : htmlspecialchars($extra['route_code'] ?? '');

            echo '<tr>';
            echo '<td style="text-align:center; padding: 5px;">' . date('Y-m-d', strtotime($extra['date'])) . '</td>';
            echo '<td style="padding: 5px;">' . $ex_route . '</td>';
            echo '<td style="padding: 5px;">' . $ex_supplier . '</td>';
            echo '<td style="padding: 5px;">' . htmlspecialchars($extra['from_location'] ?? '') . '</td>'; // Null safe
            echo '<td style="padding: 5px;">' . htmlspecialchars($extra['to_location'] ?? '') . '</td>';   // Null safe
            echo '<td style="text-align:right; padding: 5px;">' . number_format($extra['distance'], 2) . '</td>';
            echo '<td style="padding: 5px;">' . htmlspecialchars($extra['remarks'] ?? '') . '</td>';       // Null safe
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="7" style="text-align:center; padding: 20px;">No extra trips recorded for this period.</td></tr>';
    }

    echo '</table>';
    $extra_stmt->close();
    $conn->close();
    ?>
</body>
</html>