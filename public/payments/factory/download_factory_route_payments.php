<?php
// download_factory_payments.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');

// Get selected month and year
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$payment_data = [];

// =======================================================================
// 1. HELPER FUNCTIONS (DYNAMIC FUEL LOGIC)
// =======================================================================

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
$consumption_sql = "SELECT c_id, distance FROM consumption"; 
$consumption_result = $conn->query($consumption_sql);
if ($consumption_result) {
    while ($row = $consumption_result->fetch_assoc()) {
        $consumption_rates[$row['c_id']] = $row['distance'];
    }
}
$default_km_per_liter = 1.00;

function get_total_adjustment_amount($conn, $route_code, $supplier_code, $month, $year) {
    $sql = "SELECT SUM(amount) AS total FROM reduction WHERE route_code = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.00; 
    $stmt->bind_param("ssii", $route_code, $supplier_code, $month, $year);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($res['total'] ?? 0);
}

// =======================================================================
// 2. MAIN DATA FETCHING (DYNAMIC CALCULATION)
// =======================================================================

$payments_sql = "
    SELECT DISTINCT ftvr.route AS route_code, ftvr.supplier_code, r.route, r.fixed_amount, r.distance AS route_distance, r.with_fuel, v.fuel_efficiency, v.rate_id, v.vehicle_no
    FROM factory_transport_vehicle_register ftvr 
    JOIN route r ON ftvr.route = r.route_code
    LEFT JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
    WHERE MONTH(ftvr.date) = ? AND YEAR(ftvr.date) = ? AND r.purpose = 'factory'
    ORDER BY CAST(SUBSTRING(ftvr.route, 7, 3) AS UNSIGNED) ASC;
";

$payments_stmt = $conn->prepare($payments_sql);
$payments_stmt->bind_param("ii", $selected_month, $selected_year);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();

if ($payments_result && $payments_result->num_rows > 0) {
    while ($payment_row = $payments_result->fetch_assoc()) {
        $route_code = $payment_row['route_code'];
        $supplier_code = $payment_row['supplier_code'];
        $fixed_amount = (float)$payment_row['fixed_amount'];
        $route_distance = (float)$payment_row['route_distance'];
        $with_fuel = (int)$payment_row['with_fuel'];
        $consumption_id = $payment_row['fuel_efficiency'];
        $rate_id = $payment_row['rate_id'];

        // 1. Fetch Normal Trips Grouped by Date
        $trips_sql = "SELECT date, COUNT(id) AS daily_trips FROM factory_transport_vehicle_register WHERE route = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1 GROUP BY date";
        $trips_stmt = $conn->prepare($trips_sql);
        $trips_stmt->bind_param("ssii", $route_code, $supplier_code, $selected_month, $selected_year);
        $trips_stmt->execute();
        $daily_trip_counts = $trips_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $trips_stmt->close();

        // Fuel Setup
        $price_slabs = ($with_fuel === 1 && $rate_id !== null) ? get_fuel_price_changes_in_month($conn, $rate_id, $selected_month, $selected_year) : [];
        $km_per_liter = $consumption_rates[$consumption_id] ?? $default_km_per_liter;

        $total_base_payment = 0;
        $total_trip_count = 0;
        $last_effective_rate = 0;

        foreach ($daily_trip_counts as $dt) {
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
            $last_effective_rate = $rate_per_km * ($route_distance / 2); 
            $total_base_payment += ($last_effective_rate * $trip_count);
        }

        // 2. Extra Trips Calculation
        $total_extra_payment = 0;
        $extra_sql = "SELECT date, distance FROM extra_vehicle_register WHERE route = ? AND supplier_code = ? AND MONTH(date) = ? AND YEAR(date) = ? AND done = 1";
        $extra_stmt = $conn->prepare($extra_sql);
        $extra_stmt->bind_param("ssii", $route_code, $supplier_code, $selected_month, $selected_year);
        $extra_stmt->execute();
        $extra_data = $extra_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $extra_stmt->close();

        foreach ($extra_data as $extra) {
            $ex_date = $extra['date'];
            $ex_dist = (float)$extra['distance'];
            $ex_fuel_price = 0;
            if ($with_fuel === 1) {
                foreach ($price_slabs as $change_date => $rate) {
                    if (strtotime($ex_date) >= strtotime($change_date)) $ex_fuel_price = $rate;
                }
            }
            $ex_fuel_per_km = ($with_fuel === 1 && $km_per_liter > 0) ? ($ex_fuel_price / $km_per_liter) : 0;
            $total_extra_payment += ($ex_dist * ($fixed_amount + $ex_fuel_per_km));
        }

        $adj = get_total_adjustment_amount($conn, $route_code, $supplier_code, $selected_month, $selected_year) * -1; 
        $final_total = $total_base_payment + $total_extra_payment + $adj;

        $payment_data[] = [
            'route_code' => $route_code, 
            'vehicle_no' => $payment_row['vehicle_no'] ?? 'N/A', 
            'route' => $payment_row['route'],
            'day_rate' => $last_effective_rate, 
            'total_working_days' => $total_trip_count,
            'total_distance' => ($route_distance / 2) * $total_trip_count,
            'calculated_base_payment' => $total_base_payment,
            'extra_amount' => $total_extra_payment, 
            'other_amount' => $adj, 
            'payments' => $final_total 
        ];
    }
}
$payments_stmt->close();
$conn->close();

$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));
$filename = "Factory_Payments_{$month_name}_{$selected_year}.xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        .text-format { mso-number-format:"\@"; } 
        .currency-format { mso-number-format:"\#\,\#\#0\.00"; }
        .summary-row { background-color: #f2f2f2; font-weight: bold; }
    </style>
</head>
<body>
    <table border="1">
        <thead>
            <tr>
                <th colspan="10" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">
                    Factory Route Monthly Payment Report - <?php echo "$month_name $selected_year"; ?>
                </th>
            </tr>
            <tr>
                <th style="background-color: #ADD8E6; font-weight: bold;">Route Code</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Route</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Vehicle No</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Trip Rate (LKR)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Trip Count</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Distance (km)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Base Payment (LKR)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Extra Payment (LKR)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Adjustment (LKR)</th>
                <th style="background-color: #ADD8E6; font-weight: bold;">Total Payments (LKR)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sum_trips = 0; $sum_dist = 0; $sum_base = 0; $sum_extra = 0; $sum_adj = 0; $sum_final = 0;
            if (!empty($payment_data)): 
                foreach ($payment_data as $row): 
                    $sum_trips += $row['total_working_days'];
                    $sum_dist += $row['total_distance'];
                    $sum_base += $row['calculated_base_payment'];
                    $sum_extra += $row['extra_amount'];
                    $sum_adj += $row['other_amount'];
                    $sum_final += $row['payments'];
            ?>
                    <tr>
                        <td class="text-format"><?php echo htmlspecialchars($row['route_code']); ?></td>
                        <td><?php echo htmlspecialchars($row['route']); ?></td>
                        <td><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                        <td class="currency-format"><?php echo $row['day_rate']; ?></td>
                        <td style="text-align:center;"><?php echo $row['total_working_days']; ?></td>
                        <td style="text-align:center;"><?php echo number_format($row['total_distance'], 2); ?></td>
                        <td class="currency-format"><?php echo $row['calculated_base_payment']; ?></td>
                        <td class="currency-format"><?php echo $row['extra_amount']; ?></td>
                        <td class="currency-format" style="color: <?php echo ($row['other_amount'] < 0) ? 'red' : 'black'; ?>;">
                            <?php echo $row['other_amount']; ?>
                        </td>
                        <td class="currency-format" style="font-weight:bold;"><?php echo $row['payments']; ?></td>
                    </tr>
                <?php endforeach; ?>
                
                <tr class="summary-row">
                    <td colspan="4" style="text-align: right;">GRAND TOTAL:</td>
                    <td style="text-align:center;"><?php echo $sum_trips; ?></td>
                    <td style="text-align:center;"><?php echo number_format($sum_dist, 2); ?></td>
                    <td class="currency-format"><?php echo $sum_base; ?></td>
                    <td class="currency-format"><?php echo $sum_extra; ?></td>
                    <td class="currency-format"><?php echo $sum_adj; ?></td>
                    <td class="currency-format" style="background-color: #FFFF00;"><?php echo $sum_final; ?></td>
                </tr>
            <?php else: ?>
                <tr><td colspan="10" style="text-align:center;">No records found for this period.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>