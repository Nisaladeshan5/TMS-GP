<?php
// download_full_month_report.php

require_once '../../includes/session_check.php';
require_once '../../includes/db.php';

// 1. Get Parameters (Only Date is needed to determine the Month)
$filterDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Extract Month/Year Info
$year  = date('Y', strtotime($filterDate));
$month = date('m', strtotime($filterDate));
$daysInMonth = date('t', strtotime($filterDate));
$monthName = date('F', strtotime($filterDate));

// 2. Set Headers for Excel Download
$filename = "Full_Route_Report_{$monthName}_{$year}.xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// 3. Fetch All Active Routes (Master List)
// මෙය Routes table එකෙන් ගන්නා නිසා මාර්ක් කරලා නැති ඒවාත් Report එකේ පෙන්වයි.
$routes = [];
$r_sql = "SELECT route_code, route FROM route WHERE is_active = 1 ORDER BY CAST(SUBSTR(route_code, 7, 3) AS UNSIGNED) ASC";
$r_result = $conn->query($r_sql);
while ($row = $r_result->fetch_assoc()) {
    $routes[] = $row;
}

// 4. Fetch Verification Data (Morning AND Evening)
// Data structure: $checks['RouteCode'][Day]['morning'] = true;
$checks = [];
$c_sql = "SELECT route, DAY(date) as day_num, shift 
          FROM cross_check 
          WHERE MONTH(date) = ? AND YEAR(date) = ?";
$stmt = $conn->prepare($c_sql);
$stmt->bind_param('ss', $month, $year);
$stmt->execute();
$c_result = $stmt->get_result();

while ($row = $c_result->fetch_assoc()) {
    // Normalize shift name to lowercase just in case
    $shift_key = strtolower($row['shift']); 
    $checks[ $row['route'] ][ $row['day_num'] ][ $shift_key ] = true;
}

?>
<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 12px; }
        
        /* General Borders */
        th, td { border: 1px solid #cbd5e0; text-align: center; vertical-align: middle; }

        /* Headers */
        .main-header { background-color: #2d3748; color: white; font-size: 16px; font-weight: bold; padding: 10px; text-align: left; }
        .day-header { background-color: #4a5568; color: white; font-weight: bold; }
        .shift-header { background-color: #e2e8f0; color: #2d3748; font-size: 10px; font-weight: bold; }
        
        /* Route Column */
        .route-col { background-color: #f7fafc; font-weight: bold; text-align: left; padding-left: 5px; width: 120px; }
        
        /* Weekend Colors */
        .weekend-bg { background-color: #fefcbf; border-color: #f6e05e; } /* Light Yellow */
        .weekend-header-bg { background-color: #d69e2e; color: black; } /* Darker Yellow for header */

        /* Status Marks */
        .check-ok { color: #047857; font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>

<table>
    <tr>
        <td colspan="<?php echo 2 + ($daysInMonth * 2); ?>" class="main-header">
            Full Monthly Route Report - <?php echo "$monthName $year"; ?> (Morning & Evening)
        </td>
    </tr>

    <tr>
        <th rowspan="2" style="width: 80px; background-color: #cbd5e0;">Code</th>
        <th rowspan="2" style="width: 200px; background-color: #cbd5e0;">Route Name</th>
        
        <?php for ($d = 1; $d <= $daysInMonth; $d++): 
            // Check Weekend
            $currentDate = "$year-$month-$d";
            $dayOfWeek = date('N', strtotime($currentDate));
            $isWeekend = ($dayOfWeek >= 6); // 6=Sat, 7=Sun
            $class = $isWeekend ? 'weekend-header-bg' : 'day-header';
        ?>
            <th colspan="2" class="<?php echo $class; ?>"><?php echo $d; ?></th>
        <?php endfor; ?>
    </tr>

    <tr>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): 
            $currentDate = "$year-$month-$d";
            $dayOfWeek = date('N', strtotime($currentDate));
            $isWeekend = ($dayOfWeek >= 6);
            $bgStyle = $isWeekend ? 'background-color: #fff9c4;' : ''; // Slight yellow for M/E row on weekends
        ?>
            <th class="shift-header" style="<?php echo $bgStyle; ?>">M</th>
            <th class="shift-header" style="<?php echo $bgStyle; ?>">E</th>
        <?php endfor; ?>
    </tr>

    <?php foreach ($routes as $route): 
        $code = $route['route_code'];
        $name = $route['route'];
    ?>
        <tr>
            <td class="route-col"><?php echo $code; ?></td>
            <td class="route-col" style="font-weight: normal;"><?php echo $name; ?></td>

            <?php for ($d = 1; $d <= $daysInMonth; $d++): 
                // Weekend Styling
                $currentDate = "$year-$month-$d";
                $dayOfWeek = date('N', strtotime($currentDate));
                $isWeekend = ($dayOfWeek >= 6);
                $cellClass = $isWeekend ? 'weekend-bg' : '';

                // Check Morning
                $m_mark = isset($checks[$code][$d]['morning']) ? "<span class='check-ok'>✔</span>" : "";
                // Check Evening
                $e_mark = isset($checks[$code][$d]['evening']) ? "<span class='check-ok'>✔</span>" : "";
            ?>
                <td class="<?php echo $cellClass; ?>"><?php echo $m_mark; ?></td>
                <td class="<?php echo $cellClass; ?>"><?php echo $e_mark; ?></td>
            <?php endfor; ?>
        </tr>
    <?php endforeach; ?>

</table>

</body>
</html>
<?php exit(); ?>