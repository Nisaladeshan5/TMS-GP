<?php
// download_sub_route_excel.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

// Include database connection
include('../../../../includes/db.php');

// 1. Get Filters from POST
$month = isset($_POST['month']) ? (int)$_POST['month'] : (int)date('m');
$year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
$month_name = date('F', mktime(0, 0, 0, $month, 10));

// 2. Decode the Data sent from the page
$payment_data = [];
if (isset($_POST['payment_json'])) {
    $payment_data = json_decode($_POST['payment_json'], true);
}

// =======================================================================
// 3. HELPER FUNCTIONS FOR EXTRA PAYMENT CALCULATION
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

$consumption_rates = [];
$res = $conn->query("SELECT c_id, distance FROM consumption");
if ($res) while ($r = $res->fetch_assoc()) $consumption_rates[$r['c_id']] = $r['distance'];

function get_sub_route_extra_payment($conn, $sub_route_code, $vehicle_no, $month, $year, $fixed_rate, $with_fuel, $consumption_rates) {
    $extra_pay = 0.0;
    
    $sql = "SELECT date, distance FROM extra_vehicle_register WHERE sub_route = ? AND MONTH(date) = ? AND YEAR(date) = ? AND done = 1";
    $stmt = $conn->prepare($sql);
    if(!$stmt) return 0;
    $stmt->bind_param("sii", $sub_route_code, $month, $year);
    $stmt->execute();
    $extra_trips = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($extra_trips)) return 0.0;

    $stmt = $conn->prepare("SELECT fuel_efficiency, rate_id FROM vehicle WHERE vehicle_no = ?");
    $stmt->bind_param("s", $vehicle_no);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price_slabs = [];
    if ($with_fuel == 1 && $v) {
        $price_slabs = get_fuel_price_changes_in_month($conn, $v['rate_id'], $month, $year);
    }
    $km_per_l = ($v) ? ($consumption_rates[$v['fuel_efficiency']] ?? 1.0) : 1.0;

    foreach ($extra_trips as $trip) {
        $ex_date = $trip['date'];
        $ex_dist = (float)$trip['distance'];
        $fuel_price = 0;

        if ($with_fuel == 1 && !empty($price_slabs)) {
            foreach ($price_slabs as $change_date => $rate) {
                if (strtotime($ex_date) >= strtotime($change_date)) $fuel_price = $rate;
            }
        }
        
        $fuel_cost_per_km = ($fuel_price > 0) ? ($fuel_price / $km_per_l) : 0;
        $extra_pay += $ex_dist * ($fixed_rate + $fuel_cost_per_km);
    }

    return $extra_pay;
}

// 4. Set Filename
$filename = "Sub_Route_Payments_With_Extra_{$month_name}_{$year}.xls";

// 5. Headers for Excel
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
        .currency-format { mso-number-format:"\#\,\#\#0\.00"; }
        .total-bg { background-color: #f0f0f0; font-weight: bold; }
        td, th { padding: 5px; vertical-align: middle; }
    </style>
</head>
<body>
    <table border="1">
        <thead>
            <tr>
                <th colspan="11" style="font-size: 18px; font-weight: bold; text-align: center; background-color: #FFFF00; padding: 15px;">
                    Sub Route Monthly Payment Report - <?php echo "$month_name $year"; ?>
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
                <th style="background-color: #4F81BD;">#</th>
                <th style="background-color: #4F81BD;">Sub Route Code</th>
                <th style="background-color: #4F81BD;">Sub Route Name</th>
                <th style="background-color: #4F81BD;">Vehicle No</th>
                <th style="background-color: #4F81BD;">Daily Rate (LKR)</th>
                <th style="background-color: #4F81BD;">Days</th>
                <th style="background-color: #4F81BD;">Adj</th>
                <th style="background-color: #4F81BD;">Reduction (LKR)</th>
                
                <th style="background-color: #00B050; font-size: 13px;">Working Days Payment (LKR)<br>(Accounts Valid)</th>
                
                <th style="background-color: #C0504D;">Extra Payment (LKR)</th>
                <th style="background-color: #C00000; font-size: 13px;">Total Payments (LKR)<br>(Transport Only)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $count = 1;
            $sum_working = 0; $sum_extra = 0; $grand_total = 0;

            if (!empty($payment_data)) {
                foreach ($payment_data as $data) {
                    $sub_code = $data['sub_route_code'];
                    $working_days_payment = (float)$data['total_payment']; // Original UI Total is the Working Days Pay

                    // Fetch fixed rate & with_fuel for this sub route to calculate Extra
                    $stmt = $conn->prepare("SELECT fixed_rate, with_fuel, vehicle_no FROM sub_route WHERE sub_route_code = ?");
                    $stmt->bind_param("s", $sub_code);
                    $stmt->execute();
                    $sr_info = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $fixed_rate = (float)($sr_info['fixed_rate'] ?? 0);
                    $with_fuel = (int)($sr_info['with_fuel'] ?? 0);
                    $vehicle_no = $sr_info['vehicle_no'] ?? $data['vehicle_no'];

                    // Calculate Extra Amount dynamically in Excel
                    $extra_amount = get_sub_route_extra_payment($conn, $sub_code, $vehicle_no, $month, $year, $fixed_rate, $with_fuel, $consumption_rates);
                    $total_transport_payment = $working_days_payment + $extra_amount;

                    $sum_working += $working_days_payment;
                    $sum_extra += $extra_amount;
                    $grand_total += $total_transport_payment;
                    ?>
                    <tr>
                        <td style="text-align: center;"><?php echo $count++; ?></td>
                        <td class="text-format"><?php echo htmlspecialchars($data['sub_route_code']); ?></td>
                        <td><?php echo htmlspecialchars($data['sub_route_name']); ?></td>
                        <td><?php echo htmlspecialchars($data['vehicle_no']); ?></td>
                        <td class="currency-format" style="text-align: right;"><?php echo (float)$data['day_rate']; ?></td>
                        <td style="text-align: center;"><?php echo (int)$data['final_days']; ?></td>
                        <td style="text-align: center;"><?php echo (int)$data['adjustments']; ?></td>
                        <td class="currency-format" style="text-align: right; color: red;">
                            <?php echo number_format((float)($data['reduction'] ?? 0), 2); ?>
                        </td>

                        <td class="currency-format" style="font-weight:bold; text-align:right; background-color:#EBF1DE; border: 2px solid #00B050; color:#005c29;">
                            <?php echo number_format($working_days_payment, 2); ?>
                        </td>
                        
                        <td class="currency-format" style="text-align:right; color:#C00000; border-left: 2px solid #C00000;">
                            <?php echo number_format($extra_amount, 2); ?>
                        </td>
                        <td class="currency-format" style="text-align:right; font-weight:bold; background-color:#F2DCDB; border: 2px solid #C00000; color:#C00000;">
                            <?php echo number_format($total_transport_payment, 2); ?>
                        </td>
                    </tr>
                    <?php
                }
                // Grand Total Row
                ?>
                <tr class="total-bg">
                    <td colspan="8" style="text-align: right; padding-right: 10px;">GRAND TOTAL:</td>
                    <td class="currency-format" style="text-align:right; background-color:#EBF1DE; border: 2px solid #00B050; color:#005c29;">
                        <?php echo number_format($sum_working, 2); ?>
                    </td>
                    <td class="currency-format" style="text-align:right; color:#C00000; border-left: 2px solid #C00000;">
                        <?php echo number_format($sum_extra, 2); ?>
                    </td>
                    <td class="currency-format" style="text-align:right; background-color:#F2DCDB; border: 2px solid #C00000; color:#C00000;">
                        <?php echo number_format($grand_total, 2); ?>
                    </td>
                </tr>
                <?php
            } else {
                echo "<tr><td colspan='11' style='text-align:center; padding: 20px;'>No data available to export.</td></tr>";
            }
            ?>
        </tbody>
    </table>

    <?php
    // =======================================================================
    // 5. FETCH AND DISPLAY EXTRA TRIPS BREAKDOWN TABLE (SUB ROUTES)
    // =======================================================================

    echo '<br><br>';
    echo '<table border="1">';
    echo '<tr>';
    echo '<td colspan="7" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #F2DCDB; color: #C00000; padding: 10px; border: 2px solid #C00000;">';
    echo 'Extra Trips Breakdown for Sub Routes (Transport Department Reference) - ' . $month_name . ' ' . $year;
    echo '</td>';
    echo '</tr>';

    echo '<tr style="color:white; font-weight:bold; text-align:center; background-color:#C0504D;">';
    echo '<th style="padding: 8px; width: 100px;">Date</th>';
    echo '<th style="padding: 8px; width: 200px;">Sub Route</th>';
    echo '<th style="padding: 8px; width: 200px;">Supplier</th>';
    echo '<th style="padding: 8px; width: 150px;">From</th>';
    echo '<th style="padding: 8px; width: 150px;">To</th>';
    echo '<th style="padding: 8px; width: 100px;">Distance (km)</th>';
    echo '<th style="padding: 8px; width: 250px;">Remarks</th>';
    echo '</tr>';

    $extra_sql = "
        SELECT 
            evr.date,
            sr.sub_route, 
            evr.sub_route AS route_code, 
            evr.supplier_code, 
            s.supplier AS supplier_name,
            evr.from_location, 
            evr.to_location, 
            evr.distance, 
            evr.remarks
        FROM extra_vehicle_register evr
        LEFT JOIN supplier s ON evr.supplier_code = s.supplier_code
        LEFT JOIN sub_route sr ON evr.sub_route = sr.sub_route_code
        WHERE MONTH(evr.date) = ? 
        AND YEAR(evr.date) = ? 
        AND evr.done = 1 
        AND evr.sub_route IS NOT NULL 
        AND evr.sub_route != '' 
        ORDER BY evr.date ASC, evr.sub_route ASC
    ";

    $extra_stmt = $conn->prepare($extra_sql);
    
    if ($extra_stmt) {
        $extra_stmt->bind_param("ii", $month, $year);
        $extra_stmt->execute();
        $extra_result = $extra_stmt->get_result();

        if ($extra_result && $extra_result->num_rows > 0) {
            while ($extra = $extra_result->fetch_assoc()) {
                $ex_supplier = !empty($extra['supplier_name']) ? htmlspecialchars($extra['supplier_name']) : htmlspecialchars($extra['supplier_code'] ?? '');
                $ex_route = htmlspecialchars($extra['sub_route'] ?? ''); 

                echo '<tr>';
                echo '<td style="text-align:center; padding: 5px;">' . date('Y-m-d', strtotime($extra['date'])) . '</td>';
                echo '<td style="padding: 5px;">' . $ex_route . '</td>';
                echo '<td style="padding: 5px;">' . $ex_supplier . '</td>';
                echo '<td style="padding: 5px;">' . htmlspecialchars($extra['from_location'] ?? '') . '</td>'; 
                echo '<td style="padding: 5px;">' . htmlspecialchars($extra['to_location'] ?? '') . '</td>';   
                echo '<td style="text-align:right; padding: 5px;">' . number_format($extra['distance'], 2) . '</td>';
                echo '<td style="padding: 5px;">' . htmlspecialchars($extra['remarks'] ?? '') . '</td>';       
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="7" style="text-align:center; padding: 20px;">No extra trips recorded for sub routes this period.</td></tr>';
        }
        $extra_stmt->close();
    } else {
        echo '<tr><td colspan="7" style="text-align:center; padding: 20px; color: red;">Error loading extra trips data.</td></tr>';
    }

    $conn->close();
    ?>
</body>
</html>