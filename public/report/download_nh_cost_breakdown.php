<?php
// download_nh_cost_breakdown.php

require_once '../../includes/session_check.php';
include('../../includes/db.php');

// 1. Inputs ලබා ගැනීම
$month = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// 2. Excel Headers සැකසීම
$monthName = date('F', mktime(0, 0, 0, $month, 1));
$filename = "Night_Heldup_Cost_Allocation_{$year}_{$month}.xls";
$reportTitle = "Night Heldup Cost Allocation Report - " . $monthName . " " . $year;

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// --- CORE FUNCTIONS ---

function get_nh_cost_matrix($conn, $month, $year) {
    // A. Op Services Rates & Supplier Codes ලබා ගැනීම
    $op_info = [];
    $op_res = $conn->query("SELECT op_code, extra_rate, slab_limit_distance, supplier_code FROM op_services");
    while ($o_row = $op_res->fetch_assoc()) {
        $op_info[$o_row['op_code']] = [
            'rate' => (float)$o_row['extra_rate'], 
            'slab' => (float)$o_row['slab_limit_distance'],
            'sup'  => $o_row['supplier_code']
        ];
    }

    // B. GL Name එක GL Table එකෙන් ලබා ගැනීම
    $gl_code_fixed = "614003";
    $gl_name = "Night Heldup Charges"; // Default name
    $gl_res = $conn->query("SELECT gl_name FROM gl WHERE gl_code = '$gl_code_fixed' LIMIT 1");
    if ($gl_row = $gl_res->fetch_assoc()) {
        $gl_name = $gl_row['gl_name'];
    }

    $matrix = [];

    // C. Main SQL Query
    $sql = "SELECT 
                nh.id as trip_id, nh.op_code, nh.distance,
                e.department, e.direct,
                COUNT(ntd.emp_id) AS emp_count_group,
                (SELECT COUNT(*) FROM nh_trip_departments WHERE trip_id = nh.id) AS total_trip_employees
            FROM nh_register nh
            JOIN nh_trip_departments ntd ON nh.id = ntd.trip_id
            LEFT JOIN employee e ON ntd.emp_id = e.emp_id 
            WHERE MONTH(nh.date) = ? AND YEAR(nh.date) = ? AND nh.done = 1
            GROUP BY nh.id, nh.op_code, e.department, e.direct";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $trip_cost = 0;
        $sup_code = 'Unknown';
        $op = $row['op_code'];
        $dist = (float)$row['distance'];

        if (isset($op_info[$op])) {
            $payable_dist = max($dist, $op_info[$op]['slab']);
            $trip_cost = $payable_dist * $op_info[$op]['rate'];
            $sup_code = !empty($op_info[$op]['sup']) ? $op_info[$op]['sup'] : 'Unknown';
        }

        $total_heads = (int)$row['total_trip_employees'];
        if ($total_heads > 0) {
            $cost_per_head = $trip_cost / $total_heads;
            $group_cost = $cost_per_head * $row['emp_count_group'];

            $dept = !empty($row['department']) ? $row['department'] : 'Unassigned';
            $is_direct = isset($row['direct']) && strtoupper(trim($row['direct'])) === 'YES';
            $type = $is_direct ? 'Direct' : 'Indirect';

            if (!isset($matrix[$sup_code])) {
                $matrix[$sup_code] = [];
            }
            if (!isset($matrix[$sup_code][$gl_code_fixed])) {
                $matrix[$sup_code][$gl_code_fixed] = ['name' => $gl_name, 'depts' => []];
            }
            if (!isset($matrix[$sup_code][$gl_code_fixed]['depts'][$dept])) {
                $matrix[$sup_code][$gl_code_fixed]['depts'][$dept] = ['Direct' => 0, 'Indirect' => 0];
            }
            $matrix[$sup_code][$gl_code_fixed]['depts'][$dept][$type] += $group_cost;
        }
    }
    return $matrix;
}

$final_data = get_nh_cost_matrix($conn, $month, $year);

// --- OUTPUT TABLE DATA (Original Style) ---
echo '<table border="1">';

echo '<tr><td colspan="7" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">' . $reportTitle . '</td></tr>';

echo '<tr style="color:white; font-weight:bold; text-align:center;">';
echo '<th style="background-color:#4F81BD;">Supplier Code</th>';
echo '<th style="background-color:#4F81BD;">GL Code</th>';
echo '<th style="background-color:#4F81BD;">GL Name</th>';
echo '<th style="background-color:#4F81BD;">Department</th>';
echo '<th style="background-color:#4F81BD;">Direct Cost (LKR)</th>';
echo '<th style="background-color:#4F81BD;">Indirect Cost (LKR)</th>';
echo '<th style="background-color:#4F81BD;">Total Cost (LKR)</th>';
echo '</tr>';

$grand_total = 0; $grand_direct = 0; $grand_indirect = 0;

if (!empty($final_data)) {
    foreach ($final_data as $sup_code => $gl_list) {
        foreach ($gl_list as $gl_code => $gl_data) {
            foreach ($gl_data['depts'] as $dept => $costs) {
                $direct = $costs['Direct'];
                $indirect = $costs['Indirect'];
                $row_total = $direct + $indirect;
                
                $grand_total += $row_total;
                $grand_direct += $direct;
                $grand_indirect += $indirect;
                
                echo '<tr>';
                echo '<td style="mso-number-format:\'@\';">' . htmlspecialchars($sup_code) . '</td>';
                echo '<td style="mso-number-format:\'@\';">' . htmlspecialchars($gl_code) . '</td>';
                echo '<td>' . htmlspecialchars($gl_data['name']) . '</td>';
                echo '<td>' . htmlspecialchars($dept) . '</td>';
                echo '<td style="text-align:right;">' . ($direct > 0 ? number_format($direct, 2, '.', '') : '0.00') . '</td>';
                echo '<td style="text-align:right;">' . ($indirect > 0 ? number_format($indirect, 2, '.', '') : '0.00') . '</td>';
                echo '<td style="text-align:right; font-weight:bold;">' . number_format($row_total, 2, '.', '') . '</td>';
                echo '</tr>';
            }
        }
    }

    echo '<tr>';
    echo '<td colspan="4" style="text-align:right; font-weight:bold;">GRAND TOTAL</td>';
    echo '<td style="text-align:right; font-weight:bold; background-color:#FFFF00;">' . number_format($grand_direct, 2, '.', '') . '</td>';
    echo '<td style="text-align:right; font-weight:bold; background-color:#FFFF00;">' . number_format($grand_indirect, 2, '.', '') . '</td>';
    echo '<td style="text-align:right; font-weight:bold; background-color:#FFFF00;">' . number_format($grand_total, 2, '.', '') . '</td>';
    echo '</tr>';
} else {
    echo '<tr><td colspan="7" style="text-align:center;">No records found.</td></tr>';
}

echo '</table>';
?>