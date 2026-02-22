<?php
// own_vehicle_excel.php

require_once '../../../includes/session_check.php';
include('../../../includes/db.php');

// 1. Filter Parameters (Month/Year)
$filterDate = $_GET['month_year'] ?? date('Y-m');
list($filterYear, $filterMonth) = explode('-', $filterDate);

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$filterMonth, (int)$filterYear);
$monthName = date('F Y', strtotime($filterDate));

// --- AUTOMATED HOLIDAY FETCHING ---
// ශ්‍රී ලංකාවේ පොදු නිවාඩු දින API එක හරහා ලබා ගැනීම
$holidaysArray = [];
$apiUrl = "https://date.nager.at/api/v3/PublicHolidays/" . $filterYear . "/LK";

// API එකට request එක යැවීම (Context එකක් සහිතව - සමහර servers වලට headers අවශ්‍ය වේ)
$opts = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: PHP-Script\r\n"
    ]
];
$context = stream_context_create($opts);
$response = @file_get_contents($apiUrl, false, $context);

if ($response) {
    $holidaysData = json_decode($response, true);
    foreach ($holidaysData as $holiday) {
        // අදාළ මාසයට පමණක් දත්ත වෙන් කර ගැනීම
        if (date('m', strtotime($holiday['date'])) == $filterMonth) {
            $holidaysArray[] = $holiday['date'];
        }
    }
}

// 2. Set Excel Headers
$filename = "Own_Vehicle_Detailed_" . $monthName . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// 3. Fetch Data from Database
$attendance = [];

$sql = "SELECT 
            ov.emp_id, 
            e.calling_name, 
            ov.vehicle_no, 
            DATE(ova.date) as attendance_date,
            (
                SELECT SUM(distance) 
                FROM own_vehicle_extra ove 
                WHERE ove.emp_id = ov.emp_id 
                  AND ove.vehicle_no = ov.vehicle_no 
                  AND YEAR(ove.date) = ? 
                  AND MONTH(ove.date) = ?
            ) as total_distance
        FROM 
            own_vehicle ov
        LEFT JOIN 
            employee e ON ov.emp_id = e.emp_id
        LEFT JOIN 
            own_vehicle_attendance ova ON ov.emp_id = ova.emp_id 
            AND ov.vehicle_no = ova.vehicle_no 
            AND YEAR(ova.date) = ? 
            AND MONTH(ova.date) = ?
        WHERE 
            ov.vehicle_no IS NOT NULL AND ov.vehicle_no != '' AND ov.is_active = 1
        ORDER BY 
            ov.emp_id ASC, ov.vehicle_no ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('iiii', $filterYear, $filterMonth, $filterYear, $filterMonth);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $empId = $row['emp_id'];
    $vehicleNo = trim($row['vehicle_no']);
    $uniqueKey = $empId . '_' . $vehicleNo;
    
    if (!isset($attendance[$uniqueKey])) {
        $attendance[$uniqueKey] = [
            'emp_id' => $empId,
            'calling_name' => $row['calling_name'] ?? 'Unknown',
            'vehicle_no' => $vehicleNo,
            'total_distance' => $row['total_distance'] ?? 0,
            'days' => []
        ];
    }
    
    if (!empty($row['attendance_date'])) {
        $day = (int)date('j', strtotime($row['attendance_date']));
        $attendance[$uniqueKey]['days'][$day] = true;
    }
}
?>

<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #4F81BD; color: white; border: 1px solid #000; padding: 5px; text-align: center; }
        td { border: 1px solid #000; padding: 5px; text-align: center; vertical-align: middle; }
        
        .weekend { background-color: #FF99CC; } 
        .holiday { background-color: #FFC000; font-weight: bold; } /* Mercantile Holiday Color */
        .present { background-color: #C6EFCE; color: #006100; font-weight: bold; }
        .header-info { font-size: 16px; font-weight: bold; text-align: left; }
        .vehicle-col { background-color: #f2f2f2; }
        .name-col { text-align: left; padding-left: 5px; }
        .total-col { background-color: #FFEB9C; font-weight: bold; color: #9C6500; }
        .distance-col { background-color: #EBF1DE; font-weight: bold; }
    </style>
</head>
<body>

    <table>
        <tr>
            <td colspan="<?php echo ($daysInMonth + 5); ?>" class="header-info">
                Own Vehicle Attendance & Distance Report - <?php echo $monthName; ?>
            </td>
        </tr>
        <tr></tr>

        <tr>
            <th style="width: 80px;">EMP ID</th>
            <th style="width: 150px;">Calling Name</th>
            <th style="width: 100px;">Vehicle No</th>
            
            <?php
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dateStr = sprintf("%s-%s-%02d", $filterYear, $filterMonth, $d);
                $dayOfWeek = date('N', strtotime($dateStr)); 
                $isWeekend = ($dayOfWeek >= 6);
                $isHoliday = in_array($dateStr, $holidaysArray);
                
                $headerColor = '';
                if ($isHoliday) {
                    $headerColor = 'background-color: #E46C0A;'; // Holiday header color
                } elseif ($isWeekend) {
                    $headerColor = 'background-color: #C0504D;'; 
                }
                
                echo "<th style='width: 30px; $headerColor'>$d</th>";
            }
            ?>
            <th style="width: 60px; background-color: #F79646;">Total Days</th>
            <th style="width: 80px; background-color: #92D050;">Extra Distance</th>
        </tr>

        <?php
        if (empty($attendance)) {
            echo "<tr><td colspan='" . ($daysInMonth + 5) . "'>No vehicles registered in the system.</td></tr>";
        } else {
            foreach ($attendance as $uniqueKey => $data) {
                $totalDays = count($data['days']);
                ?>
                <tr>
                    <td style="font-weight: bold;"><?php echo htmlspecialchars($data['emp_id']); ?></td>
                    <td class="name-col"><?php echo htmlspecialchars($data['calling_name']); ?></td>
                    <td class="vehicle-col"><?php echo htmlspecialchars($data['vehicle_no']); ?></td>

                    <?php
                    for ($d = 1; $d <= $daysInMonth; $d++) {
                        $dateStr = sprintf("%s-%s-%02d", $filterYear, $filterMonth, $d);
                        $dayOfWeek = date('N', strtotime($dateStr)); 
                        $isWeekend = ($dayOfWeek >= 6);
                        $isHoliday = in_array($dateStr, $holidaysArray);
                        $isPresent = isset($data['days'][$d]);
                        
                        $class = "";
                        $content = "";

                        if ($isPresent) {
                            $class = "class='present'";
                            $content = "P"; 
                        } elseif ($isHoliday) {
                            $class = "class='holiday'"; 
                            $content = "H"; 
                        } elseif ($isWeekend) {
                            $class = "class='weekend'"; 
                        }

                        echo "<td $class>$content</td>";
                    }
                    ?>
                    
                    <td class="total-col"><?php echo $totalDays; ?></td>
                    <td class="distance-col"><?php echo number_format($data['total_distance'], 2); ?></td>
                </tr>
                <?php
            }
        }
        ?>
    </table>
</body>
</html>