<?php
// download_ev_journal_final.php

require_once '../../includes/session_check.php';
include('../../includes/db.php');

// --- 1. Get and Validate Input (Updated to catch 'period' from Report Main) ---
if (isset($_GET['period'])) {
    // period=2024-05 ලෙස එන අගය වෙන් කරගැනීම
    $parts = explode('-', $_GET['period']);
    $year = (int)$parts[0];
    $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
} else {
    // පරණ ක්‍රමයට month/year එවන්නේ නම්
    $month = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
    $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
}

// --- 2. Journal Constants ---
$batchName = 'LPLKR-MAL';
$documentType = 'Invoice';
$currencyCode = 'LKR';

// තෝරාගත් මාසයේ අවසාන දිනය නිවැරදිව සැකසීම (Y-m-t භාවිතා කර)
$lastDayOfMonth = date('Y-m-t', strtotime("{$year}-{$month}-01"));
$postingDate = $lastDayOfMonth; // මාසයේ අවසාන දිනයම Posting Date ලෙස යෙදීම

// Static Variables
$
$balAccountType = 'G/L Account';
$afdeling = '570';
$intercompany = '00';
$location = '510';
$costCenter = '320';

// --- 3. Data Processing Logic ---
function get_ev_full_matrix($conn, $month, $year) {
    // Rates & Specs Fetching
    $fuel_history = [];
    $fuel_res = $conn->query("SELECT rate_id, rate, date FROM fuel_rate ORDER BY date DESC");
    while ($f_row = $fuel_res->fetch_assoc()) {
        $fuel_history[$f_row['rate_id']][] = ['date' => $f_row['date'], 'rate' => (float)$f_row['rate']];
    }

    $op_rates = [];
    $op_res = $conn->query("SELECT op_code, extra_rate_ac, extra_rate FROM op_services");
    while ($o_row = $op_res->fetch_assoc()) {
        $op_rates[$o_row['op_code']] = ['ac' => (float)$o_row['extra_rate_ac'], 'non_ac' => (float)$o_row['extra_rate']];
    }

    $route_names = [];
    $route_data = [];
    $rt_res = $conn->query("SELECT route_code, route, fixed_amount, vehicle_no, with_fuel FROM route");
    while ($r_row = $rt_res->fetch_assoc()) {
        $route_names[$r_row['route_code']] = $r_row['route'];
        $route_data[$r_row['route_code']] = ['fixed' => (float)$r_row['fixed_amount'], 'veh' => $r_row['vehicle_no'], 'fuel' => (int)$r_row['with_fuel']];
    }

    $vehicle_specs = [];
    $veh_res = $conn->query("SELECT v.vehicle_no, v.rate_id, c.distance AS km_per_liter FROM vehicle v LEFT JOIN consumption c ON v.fuel_efficiency = c.c_id");
    while ($v_row = $veh_res->fetch_assoc()) {
        $vehicle_specs[$v_row['vehicle_no']] = ['rate_id' => $v_row['rate_id'], 'km_per_liter' => (float)$v_row['km_per_liter']];
    }

    $matrix = [];

    // Main SQL - Filtered by Month & Year
    $sql = "SELECT 
                evr.id as trip_id, evr.date, evr.distance, evr.op_code, evr.route as route_code, evr.ac_status, evr.supplier_code,
                gl.gl_code, gl.gl_name, s.beneficiaress_name,
                e.direct
            FROM extra_vehicle_register evr
            JOIN ev_trip_employee_reasons eter ON evr.id = eter.trip_id
            JOIN reason r ON eter.reason_code = r.reason_code
            JOIN gl gl ON r.gl_code = gl.gl_code
            JOIN supplier s ON evr.supplier_code = s.supplier_code
            LEFT JOIN employee e ON eter.emp_id = e.emp_id
            WHERE MONTH(evr.date) = ? AND YEAR(evr.date) = ? AND evr.done = 1
            GROUP BY evr.id, evr.supplier_code, gl.gl_code";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $trip_cost = 0;
        $dist = (float)$row['distance'];
        $is_op = !empty($row['op_code']);
        
        if ($is_op) {
            $op = $row['op_code'];
            if (isset($op_rates[$op])) {
                $rate = ($row['ac_status'] == 1) ? $op_rates[$op]['ac'] : $op_rates[$op]['non_ac'];
                $trip_cost = $dist * $rate;
            }
        } else {
            $rt = $row['route_code'];
            if (isset($route_data[$rt])) {
                $fuel_c = 0; $rd = $route_data[$rt];
                if ($rd['fuel'] == 1 && isset($vehicle_specs[$rd['veh']])) {
                    $v = $vehicle_specs[$rd['veh']];
                    $f_rate = 0;
                    if(isset($fuel_history[$v['rate_id']])) {
                        foreach ($fuel_history[$v['rate_id']] as $rec) {
                            if ($rec['date'] <= $row['date']) { $f_rate = $rec['rate']; break; }
                        }
                    }
                    if ($v['km_per_liter'] > 0) $fuel_c = $f_rate / $v['km_per_liter'];
                }
                $trip_cost = $dist * ($rd['fixed'] + $fuel_c);
            }
        }

        if ($is_op) {
            $desc_name = "Extra - " . $row['beneficiaress_name'];
            $key = $row['supplier_code'] . "_" . $row['gl_code'] . "_EXTRA";
        } else {
            $desc_name = $route_names[$row['route_code']] ?? 'Unknown Route';
            $key = $row['supplier_code'] . "_" . $row['gl_code'] . "_" . $row['route_code'];
        }

        if (!isset($matrix[$key])) {
            $matrix[$key] = [
                'sup_code' => $row['supplier_code'],
                'vendor' => $row['beneficiaress_name'],
                'gl_code' => $row['gl_code'],
                'gl_name' => $row['gl_name'],
                'description' => $desc_name,
                'amount' => 0,
                'type' => (isset($row['direct']) && strtoupper(trim($row['direct'])) === 'YES') ? 'DIRECT' : 'INDIRECT'
            ];
        }
        $matrix[$key]['amount'] += $trip_cost;
    }
    return $matrix;
}

$final_data = get_ev_full_matrix($conn, $month, $year);

// --- 4. Headers for Excel ---
$monthName = date('F', mktime(0, 0, 0, (int)$month, 10));
$filename = "EV_Transport_Journal_{$monthName}_{$year}.xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");
?>
<table border="1">
    <thead>
        <tr>
            <th>Batch Name</th>
            <th>Posting Date</th>
            <th>Document Type</th>
            <th>Document No.</th>
            <th>External Document No.</th>
            <th>Account Type</th>
            <th>Account No.</th>
            <th>Vendor Name</th>
            <th>Approval Status</th>
            <th>Currency Code</th>
            <th>Description</th>
            <th>Sup. SVAT Ex. Rate</th>
            <th>Purchase Order No</th>
            <th>GRN Date</th>
            <th>Document Amount</th>
            <th>Debit Amount</th>
            <th>Credit Amount</th>
            <th>Amount</th>
            <th>Amount (LCY)</th>
            <th>VAT Bus. Posting Group</th>
            <th>VAT Prod. Posting Group</th>
            <th>Gen. Posting Type</th>
            <th>Bal. Account Type</th>
            <th>Bal. Account No.</th>
            <th>Bal. VAT Bus. Posting Group</th>
            <th>Bal. VAT Prod. Posting Group</th>
            <th>Bal. Gen. Posting Type</th>
            <th>Afdeling</th>
            <th>Intercompany</th>
            <th>Location</th>
            <th>Cost Center</th>
            <th>Direct & Indirect</th>
            <th>GL Description</th>
            <th>NumberOfJournalRecords</th>
            <th>Balance</th>
            <th>Total Balance</th>
        </tr>
    </thead>
    <tbody>
        <?php
        if (!empty($final_data)) {
            foreach ($final_data as $row) {
                $fullDescription = "EV Transport " . strtoupper($monthName) . " " . $year . " - " . $row['description'];
                $amountNegative = number_format(-$row['amount'], 2, '.', '');
                ?>
                <tr>
                    <td><?= htmlspecialchars($batchName) ?></td>
                    <td><?= htmlspecialchars($postingDate) ?></td>
                    <td><?= htmlspecialchars($documentType) ?></td>
                    <td></td> <td></td> <td>Vendor</td>
                    <td>="<?= htmlspecialchars($row['sup_code']) ?>"</td>
                    <td><?= htmlspecialchars($row['vendor']) ?></td>
                    <td></td> <td><?= htmlspecialchars($currencyCode) ?></td>
                    <td><?= htmlspecialchars($fullDescription) ?></td>
                    <td style="text-align:right;">0</td>
                    <td></td> <td></td> <td></td> <td></td> <td></td> 
                    <td style="text-align:right;"><?= $amountNegative ?></td>
                    <td></td> <td></td> <td></td> <td></td> 
                    <td><?= $balAccountType ?></td>
                    <td>="<?= htmlspecialchars($row['gl_code']) ?>"</td>
                    <td>LK</td>
                    <td>EXEMPT</td>
                    <td>Purchase</td>
                    <td>="<?= $afdeling ?>"</td>
                    <td>="<?= $intercompany ?>"</td>
                    <td>="<?= $location ?>"</td>
                    <td>="<?= $costCenter ?>"</td>
                    <td><?= $row['type'] ?></td>
                    <td><?= htmlspecialchars($row['gl_name']) ?></td>
                    <td></td> <td></td> <td></td>
                </tr>
                <?php
            }
        } else {
            echo '<tr><td colspan="36" style="text-align:center;">No data found for ' . $monthName . ' ' . $year . '.</td></tr>';
        }
        ?>
    </tbody>
</table>
<?php $conn->close(); ?>