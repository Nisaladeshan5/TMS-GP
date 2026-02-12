<?php
// download_dh_cost_breakdown.php

require_once '../../includes/session_check.php';
include('../../includes/db.php');

// 1. Inputs ලබා ගැනීම
$month = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// 2. Excel Headers සැකසීම
$monthName = date('F', mktime(0, 0, 0, $month, 1));
$filename = "Day_Heldup_Cost_Breakdown_{$year}_{$month}.xls";
$reportTitle = "Day Heldup Cost Allocation Report - " . $monthName . " " . $year;

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// --- CORE FUNCTIONS ---

// A. වාහනයේ වියදම සහ සැබෑ දුර ගණනය (Slab Logic සමග - Logic වෙනස් කර නෑ)
function calculate_vehicle_totals($conn, $month, $year) {
    $sql = "SELECT dha.op_code, dha.date, dha.ac, os.slab_limit_distance, os.extra_rate_ac, os.extra_rate AS extra_rate_nonac 
            FROM dh_attendance dha
            JOIN op_services os ON dha.op_code = os.op_code
            WHERE DATE_FORMAT(dha.date, '%Y-%m') = ?";
    
    $stmt = $conn->prepare($sql);
    $param = "$year-$month";
    $stmt->bind_param("s", $param);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $daily_data = [];

    foreach ($records as $rec) {
        $date = $rec['date'];
        $op_code = $rec['op_code'];
        
        $dist_sql = "SELECT SUM(distance) as total_dist FROM day_heldup_register WHERE op_code='$op_code' AND date='$date' AND done=1";
        $dist_res = $conn->query($dist_sql);
        $dist_row = $dist_res->fetch_assoc();
        $actual_dist = (float)($dist_row['total_dist'] ?? 0);

        $rate = ($rec['ac'] == 1) ? $rec['extra_rate_ac'] : $rec['extra_rate_nonac'];
        $pay_dist = max($actual_dist, $rec['slab_limit_distance']);
        $payment = $pay_dist * $rate;

        if (!isset($daily_data[$op_code])) {
            $daily_data[$op_code] = ['total_payment' => 0, 'total_actual_distance' => 0];
        }
        $daily_data[$op_code]['total_payment'] += $payment;
        $daily_data[$op_code]['total_actual_distance'] += $actual_dist;
    }
    return $daily_data;
}

// B. Cost Matrix (NEW LOGIC: Using employee table 'direct' column)
function get_cost_matrix($conn, $month, $year, $daily_totals) {
    $matrix = [];

    // SQL වෙනස්කම: e.direct column එක select කළා සහ group by කළා
    // සැලකිය යුතුයි: ඔයාගේ table එකේ නම 'employees' හෝ 'employee' ද කියන එක තහවුරු කරගන්න. 
    // මම මෙතන දාලා තියෙන්නේ 'employees' කියලා. Table එක 'employee' නම් පහත පේළියේ නම වෙනස් කරන්න.
    $sql = "SELECT 
                dhr.date, dhr.op_code, dhr.trip_id, dhr.distance AS trip_distance,
                gl.gl_code, gl.gl_name, 
                e.department, e.direct,  
                COUNT(dher.id) AS emp_count_group, 
                (SELECT COUNT(*) FROM dh_emp_reason WHERE trip_id = dhr.trip_id) AS total_trip_employees
            FROM day_heldup_register dhr
            JOIN dh_emp_reason dher ON dhr.trip_id = dher.trip_id
            JOIN reason r ON dher.reason_code = r.reason_code
            JOIN gl gl ON r.gl_code = gl.gl_code
            LEFT JOIN employee e ON dher.emp_id = e.emp_id 
            WHERE DATE_FORMAT(dhr.date, '%Y-%m') = ? AND dhr.done = 1
            GROUP BY dhr.trip_id, gl.gl_code, e.department, e.direct"; 

    $stmt = $conn->prepare($sql);
    $param = "$year-$month";
    $stmt->bind_param("s", $param);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $op_code = $row['op_code'];
        if (!isset($daily_totals[$op_code]) || $daily_totals[$op_code]['total_actual_distance'] <= 0) continue;

        $totals = $daily_totals[$op_code];
        $rate_per_km = $totals['total_payment'] / $totals['total_actual_distance'];
        
        $trip_cost = $row['trip_distance'] * $rate_per_km;
        $total_heads = $row['total_trip_employees'];
        
        if ($total_heads > 0) {
            $cost_per_head = $trip_cost / $total_heads;
            $group_cost = $cost_per_head * $row['emp_count_group'];

            $gl_code = $row['gl_code'];
            $gl_name = $row['gl_name'];
            $dept = !empty($row['department']) ? $row['department'] : 'Unassigned';
            
            // --- NEW LOGIC START ---
            // Employee table එකේ 'direct' column එක YES නම් Direct, නැත්නම් Indirect
            $is_direct = isset($row['direct']) && strtoupper(trim($row['direct'])) === 'YES';
            $type = $is_direct ? 'Direct' : 'Indirect';
            // --- NEW LOGIC END ---

            if (!isset($matrix[$gl_code])) {
                $matrix[$gl_code] = ['name' => $gl_name, 'depts' => []];
            }
            if (!isset($matrix[$gl_code]['depts'][$dept])) {
                $matrix[$gl_code]['depts'][$dept] = ['Direct' => 0, 'Indirect' => 0];
            }
            $matrix[$gl_code]['depts'][$dept][$type] += $group_cost;
        }
    }
    return $matrix;
}

// 3. Process Data
$daily_totals = calculate_vehicle_totals($conn, $month, $year);
$final_data = get_cost_matrix($conn, $month, $year, $daily_totals);

// --- OUTPUT TABLE DATA (EXCEL STYLE) ---
echo '<table border="1">';

// 1. YELLOW TITLE HEADER ROW
echo '<tr>';
echo '<td colspan="6" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #FFFF00;">';
echo $reportTitle;
echo '</td>';
echo '</tr>';

// 2. COLUMN HEADERS ROW (BLUE)
echo '<tr style="color:white; font-weight:bold; text-align:center;">';
echo '<th style="background-color:#4F81BD; width: 100px;">GL Code</th>';
echo '<th style="background-color:#4F81BD; width: 250px;">GL Name</th>';
echo '<th style="background-color:#4F81BD; width: 200px;">Department</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Direct Cost (LKR)</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Indirect Cost (LKR)</th>';
echo '<th style="background-color:#4F81BD; width: 150px;">Total Cost (LKR)</th>';
echo '</tr>';

// 3. DATA ROWS
$grand_total = 0;
$grand_direct = 0;
$grand_indirect = 0;

if (!empty($final_data)) {
    foreach ($final_data as $gl_code => $data) {
        $gl_name = $data['name'];
        
        foreach ($data['depts'] as $dept => $costs) {
            $direct = $costs['Direct'];
            $indirect = $costs['Indirect'];
            $row_total = $direct + $indirect;
            
            $grand_total += $row_total;
            $grand_direct += $direct;
            $grand_indirect += $indirect;
            
            echo '<tr>';
            echo '<td style="text-align:left; vertical-align: middle; mso-number-format:\'@\';">' . htmlspecialchars($gl_code) . '</td>';
            echo '<td style="text-align:left; vertical-align: middle;">' . htmlspecialchars($gl_name) . '</td>';
            echo '<td style="text-align:left; vertical-align: middle;">' . htmlspecialchars($dept) . '</td>';
            echo '<td style="text-align:right; vertical-align: middle;">' . ($direct > 0 ? number_format($direct, 2) : '-') . '</td>';
            echo '<td style="text-align:right; vertical-align: middle;">' . ($indirect > 0 ? number_format($indirect, 2) : '-') . '</td>';
            echo '<td style="text-align:right; font-weight:bold; vertical-align: middle;">' . number_format($row_total, 2) . '</td>';
            echo '</tr>';
        }
    }

    // 4. GRAND TOTAL ROW (YELLOW BG)
    echo '<tr>';
    echo '<td colspan="3" style="text-align:right; font-weight:bold; vertical-align: middle;">GRAND TOTAL</td>';
    echo '<td style="text-align:right; font-weight:bold; vertical-align: middle; background-color:#FFFF00;">' . number_format($grand_direct, 2) . '</td>';
    echo '<td style="text-align:right; font-weight:bold; vertical-align: middle; background-color:#FFFF00;">' . number_format($grand_indirect, 2) . '</td>';
    echo '<td style="text-align:right; font-weight:bold; vertical-align: middle; background-color:#FFFF00;">' . number_format($grand_total, 2) . '</td>';
    echo '</tr>';

} else {
    echo '<tr><td colspan="6" style="text-align:center; padding: 20px;">No records found for this period.</td></tr>';
}

echo '</table>';
?>