<?php
// download_ev_cost_breakdown.php

require_once '../../includes/session_check.php';
include('../../includes/db.php');

$month = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

$monthName = date('F', mktime(0, 0, 0, $month, 1));
$filename = "Supplier_GL_Dept_Allocation_{$year}_{$month}.xls";
$reportTitle = "EV Cost Allocation - " . $monthName . " " . $year;

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// --- FUNCTIONS ---
function get_rate_for_date($rate_id, $trip_date, $history) {
    if (!isset($history[$rate_id])) return 0;
    foreach ($history[$rate_id] as $record) {
        if ($record['date'] <= $trip_date) return $record['rate'];
    }
    return end($history[$rate_id])['rate'] ?? 0;
}

function get_ev_cost_matrix($conn, $month, $year) {
    // A. Fuel History
    $fuel_history = [];
    $fuel_res = $conn->query("SELECT rate_id, rate, date FROM fuel_rate ORDER BY date DESC");
    while ($f_row = $fuel_res->fetch_assoc()) {
        $fuel_history[$f_row['rate_id']][] = ['date' => $f_row['date'], 'rate' => (float)$f_row['rate']];
    }

    // B. Op Services & Route Specs
    $op_rates = [];
    $op_res = $conn->query("SELECT op_code, extra_rate_ac, extra_rate FROM op_services");
    while ($o_row = $op_res->fetch_assoc()) {
        $op_rates[$o_row['op_code']] = ['ac' => (float)$o_row['extra_rate_ac'], 'non_ac' => (float)$o_row['extra_rate']];
    }

    $route_data = [];
    $rt_res = $conn->query("SELECT route_code, fixed_amount, vehicle_no, with_fuel FROM route");
    while ($r_row = $rt_res->fetch_assoc()) {
        $route_data[$r_row['route_code']] = ['fixed' => (float)$r_row['fixed_amount'], 'veh' => $r_row['vehicle_no'], 'fuel' => (int)$r_row['with_fuel']];
    }

    $vehicle_specs = [];
    $veh_res = $conn->query("SELECT v.vehicle_no, v.rate_id, c.distance AS km_per_liter FROM vehicle v LEFT JOIN consumption c ON v.fuel_efficiency = c.c_id");
    while ($v_row = $veh_res->fetch_assoc()) {
        $vehicle_specs[$v_row['vehicle_no']] = ['rate_id' => $v_row['rate_id'], 'km_per_liter' => (float)$v_row['km_per_liter']];
    }

    $matrix = [];

    // C. Main Query (Supplier Code added)
    $sql = "SELECT 
                evr.id as trip_id, evr.date, evr.distance, evr.op_code, evr.route, evr.ac_status, evr.supplier_code,
                gl.gl_code, gl.gl_name, 
                e.department, e.direct,
                COUNT(eter.id) AS emp_count_group,
                (SELECT COUNT(*) FROM ev_trip_employee_reasons WHERE trip_id = evr.id) AS total_trip_employees
            FROM extra_vehicle_register evr
            JOIN ev_trip_employee_reasons eter ON evr.id = eter.trip_id
            JOIN reason r ON eter.reason_code = r.reason_code
            JOIN gl gl ON r.gl_code = gl.gl_code
            LEFT JOIN employee e ON eter.emp_id = e.emp_id 
            WHERE MONTH(evr.date) = ? AND YEAR(evr.date) = ? AND evr.done = 1
            GROUP BY evr.id, evr.supplier_code, gl.gl_code, e.department, e.direct";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $trip_cost = 0;
        $dist = (float)$row['distance'];
        
        if (!empty($row['op_code'])) {
            $op = $row['op_code'];
            if (isset($op_rates[$op])) {
                $rate = ($row['ac_status'] == 1) ? $op_rates[$op]['ac'] : $op_rates[$op]['non_ac'];
                $trip_cost = $dist * $rate;
            }
        } elseif (!empty($row['route'])) {
            $rt = $row['route'];
            if (isset($route_data[$rt])) {
                $fuel_c = 0;
                $rd = $route_data[$rt];
                if ($rd['fuel'] == 1 && isset($vehicle_specs[$rd['veh']])) {
                    $v = $vehicle_specs[$rd['veh']];
                    $f_rate = get_rate_for_date($v['rate_id'], $row['date'], $fuel_history);
                    if ($v['km_per_liter'] > 0) $fuel_c = $f_rate / $v['km_per_liter'];
                }
                $trip_cost = $dist * ($rd['fixed'] + $fuel_c);
            }
        }

        $total_heads = (int)$row['total_trip_employees'];
        if ($total_heads > 0) {
            $cost_per_head = $trip_cost / $total_heads;
            $group_cost = $cost_per_head * $row['emp_count_group'];

            $sup_code = !empty($row['supplier_code']) ? $row['supplier_code'] : 'NO_SUPPLIER';
            $gl_code  = $row['gl_code'];
            $gl_name  = $row['gl_name'];
            $dept     = !empty($row['department']) ? $row['department'] : 'Unassigned';
            $type     = (isset($row['direct']) && strtoupper(trim($row['direct'])) === 'YES') ? 'Direct' : 'Indirect';

            // Hierarchical Matrix Structure
            if (!isset($matrix[$sup_code])) {
                $matrix[$sup_code] = [];
            }
            if (!isset($matrix[$sup_code][$gl_code])) {
                $matrix[$sup_code][$gl_code] = ['name' => $gl_name, 'depts' => []];
            }
            if (!isset($matrix[$sup_code][$gl_code]['depts'][$dept])) {
                $matrix[$sup_code][$gl_code]['depts'][$dept] = ['Direct' => 0, 'Indirect' => 0];
            }
            $matrix[$sup_code][$gl_code]['depts'][$dept][$type] += $group_cost;
        }
    }
    return $matrix;
}

$final_data = get_ev_cost_matrix($conn, $month, $year);

// --- OUTPUT ---
echo '<table border="1">';
echo '<tr><td colspan="7" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">' . $reportTitle . '</td></tr>';

echo '<tr style="color:white; font-weight:bold; text-align:center;">';
echo '<th style="background-color:#4F81BD;">Supplier Code</th>';
echo '<th style="background-color:#4F81BD;">GL Code</th>';
echo '<th style="background-color:#4F81BD;">GL Name</th>';
echo '<th style="background-color:#4F81BD;">Department</th>';
echo '<th style="background-color:#4F81BD;">Direct (LKR)</th>';
echo '<th style="background-color:#4F81BD;">Indirect (LKR)</th>';
echo '<th style="background-color:#4F81BD;">Total (LKR)</th>';
echo '</tr>';

$grand_total = 0;
if (!empty($final_data)) {
    foreach ($final_data as $sup_code => $gl_list) {
        foreach ($gl_list as $gl_code => $gl_data) {
            foreach ($gl_data['depts'] as $dept => $costs) {
                $row_total = $costs['Direct'] + $costs['Indirect'];
                $grand_total += $row_total;
                
                echo '<tr>';
                echo '<td style="mso-number-format:\'@\';">' . htmlspecialchars($sup_code) . '</td>';
                echo '<td style="mso-number-format:\'@\';">' . htmlspecialchars($gl_code) . '</td>';
                echo '<td>' . htmlspecialchars($gl_data['name']) . '</td>';
                echo '<td>' . htmlspecialchars($dept) . '</td>';
                echo '<td style="text-align:right;">' . number_format($costs['Direct'], 2, '.', '') . '</td>';
                echo '<td style="text-align:right;">' . number_format($costs['Indirect'], 2, '.', '') . '</td>';
                echo '<td style="text-align:right; font-weight:bold;">' . number_format($row_total, 2, '.', '') . '</td>';
                echo '</tr>';
            }
        }
    }
    echo '<tr style="font-weight:bold;">';
    echo '<td colspan="6" style="background-color:#FFFF00; text-align:right;">GRAND TOTAL</td>';
    echo '<td style="background-color:#FFFF00; text-align:right;">' . number_format($grand_total, 2, '.', '') . '</td>';
    echo '</tr>';
} else {
    echo '<tr><td colspan="7" style="text-align:center;">No records found.</td></tr>';
}
echo '</table>';
?>