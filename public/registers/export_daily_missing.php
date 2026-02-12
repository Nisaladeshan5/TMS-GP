<?php
// export_daily_missing.php

require_once '../../includes/session_check.php';
include('../../includes/db.php');

// 1. Set Dates and Shifts
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));
$todayDate = date('Y-m-d');

// 2. Excel Headers
$filename = "Daily_Missing_Report_" . date('Y-m-d') . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// 3. Helper Function to Fetch Data
function getMissingRoutes($conn, $date, $shift) {
    $records = [];
    $sql = "SELECT 
                rm.route_code, 
                rm.route AS route_name,
                u.emp_id,
                u.calling_name,
                e.line AS emp_line
            FROM 
                route rm
            LEFT JOIN 
                cross_check r 
            ON 
                rm.route_code = r.route 
                AND DATE(r.date) = ? 
                AND r.shift = ? 
            LEFT JOIN
                `user` u 
            ON
                rm.route_code = u.route_code
            LEFT JOIN
                `employee` e
            ON
                u.emp_id = e.emp_id
            WHERE 
                rm.is_active = 1 
                AND r.id IS NULL 
            ORDER BY CAST(SUBSTR(rm.route_code, 7, 3) AS UNSIGNED) ASC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $date, $shift);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        $stmt->close();
    }
    return $records;
}

// Fetch Data
$yesterdayEvening = getMissingRoutes($conn, $yesterdayDate, 'evening');
$todayMorning = getMissingRoutes($conn, $todayDate, 'morning');

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        .header-main { background-color: #1f2937; color: white; font-size: 18px; font-weight: bold; text-align: center; }
        .header-sub { background-color: #b91c1c; color: white; font-size: 14px; font-weight: bold; }
        .header-col { background-color: #e5e7eb; font-weight: bold; }
        .empty-msg { text-align: center; font-style: italic; color: #666; }
    </style>
</head>
<body>

    <table>
        <tr>
            <td colspan="5" class="header-main" style="height: 40px; vertical-align: middle;">
                DAILY MISSING ROUTES REPORT - <?php echo date('F j, Y'); ?>
            </td>
        </tr>
    </table>

    <table>
        <tr>
            <td colspan="5" class="header-sub">
                1. YESTERDAY EVENING (<?php echo $yesterdayDate; ?>) - Shift: Evening
            </td>
        </tr>
        <tr class="header-col">
            <th>Route Code</th>
            <th>Route Name</th>
            <th>Assigned Employee</th>
            <th>Employee ID</th>
            <th>Line No</th>
        </tr>
        <?php if (!empty($yesterdayEvening)): ?>
            <?php foreach ($yesterdayEvening as $row): ?>
            <tr>
                <td style="font-weight:bold;"><?php echo htmlspecialchars($row['route_code']); ?></td>
                <td><?php echo htmlspecialchars($row['route_name']); ?></td>
                <td><?php echo htmlspecialchars($row['calling_name'] ?? 'Not Assigned'); ?></td>
                <td><?php echo htmlspecialchars($row['emp_id'] ?? '-'); ?></td>
                <td style="text-align:center;"><?php echo htmlspecialchars($row['emp_line'] ?? '-'); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="5" class="empty-msg">No missing routes found for Yesterday Evening!</td></tr>
        <?php endif; ?>
    </table>

    <br>

    <table>
        <tr>
            <td colspan="5" class="header-sub" style="background-color: #047857;">
                2. TODAY MORNING (<?php echo $todayDate; ?>) - Shift: Morning
            </td>
        </tr>
        <tr class="header-col">
            <th>Route Code</th>
            <th>Route Name</th>
            <th>Assigned Employee</th>
            <th>Employee ID</th>
            <th>Line No</th>
        </tr>
        <?php if (!empty($todayMorning)): ?>
            <?php foreach ($todayMorning as $row): ?>
            <tr>
                <td style="font-weight:bold;"><?php echo htmlspecialchars($row['route_code']); ?></td>
                <td><?php echo htmlspecialchars($row['route_name']); ?></td>
                <td><?php echo htmlspecialchars($row['calling_name'] ?? 'Not Assigned'); ?></td>
                <td><?php echo htmlspecialchars($row['emp_id'] ?? '-'); ?></td>
                <td style="text-align:center;"><?php echo htmlspecialchars($row['emp_line'] ?? '-'); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="5" class="empty-msg">No missing routes found for Today Morning!</td></tr>
        <?php endif; ?>
    </table>

</body>
</html>