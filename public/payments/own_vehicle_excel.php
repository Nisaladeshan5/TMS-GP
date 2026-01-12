<?php
// own_vehicle_excel.php

require_once '../../includes/session_check.php';
include('../../includes/db.php');

// 1. Filter Parameters (Month/Year)
$filterDate = $_GET['month_year'] ?? date('Y-m');
list($filterYear, $filterMonth) = explode('-', $filterDate);

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$filterMonth, (int)$filterYear);
$monthName = date('F Y', strtotime($filterDate));

// 2. Set Excel Headers
$filename = "Own_Vehicle_Attendance_" . $monthName . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// 3. Fetch Data from Database
$attendance = [];

// SQL එක එලෙසම තබමු, නමුත් ORDER එකේ vehicle_no එකත් දාමු, එතකොට පිළිවෙලට එයි
$sql = "SELECT 
            ova.emp_id, 
            e.calling_name, 
            ova.vehicle_no, 
            DATE(ova.date) as attendance_date 
        FROM 
            own_vehicle_attendance ova
        JOIN 
            employee e ON ova.emp_id = e.emp_id
        WHERE 
            YEAR(ova.date) = ? AND MONTH(ova.date) = ?
        ORDER BY 
            ova.emp_id ASC, ova.vehicle_no ASC"; // Vehicle No එකෙනුත් sort කරමු

$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $filterYear, $filterMonth);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $empId = $row['emp_id'];
    $vehicleNo = trim($row['vehicle_no']); // Vehicle No එක ගන්න
    $day = (int)date('j', strtotime($row['attendance_date']));
    
    // මෙන්න වෙනස: Key එක හදන්නේ EmpID සහ Vehicle No එකතු කරලා (Unique Key)
    // උදාහරණ: "1001_WP-CAB-1234" සහ "1001_WP-KD-5678"
    $uniqueKey = $empId . '_' . $vehicleNo;
    
    if (!isset($attendance[$uniqueKey])) {
        $attendance[$uniqueKey] = [
            'emp_id' => $empId, // Emp ID එක ඇතුලෙම save කරගන්නවා display කරන්න
            'calling_name' => $row['calling_name'],
            'vehicle_no' => $vehicleNo,
            'days' => []
        ];
    }
    
    $attendance[$uniqueKey]['days'][$day] = true;
}

?>

<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #4F81BD; color: white; border: 1px solid #000; padding: 5px; text-align: center; }
        td { border: 1px solid #000; padding: 5px; text-align: center; vertical-align: middle; }
        
        /* Styles */
        .weekend { background-color: #FF99CC; } 
        .present { background-color: #C6EFCE; color: #006100; font-weight: bold; }
        .header-info { font-size: 16px; font-weight: bold; text-align: left; }
        .vehicle-col { background-color: #f2f2f2; }
        .name-col { text-align: left; padding-left: 5px; }
        .total-col { background-color: #FFEB9C; font-weight: bold; color: #9C6500; }
    </style>
</head>
<body>

    <table>
        <tr>
            <td colspan="<?php echo ($daysInMonth + 4); ?>" class="header-info">
                Own Vehicle Attendance Report - <?php echo $monthName; ?>
            </td>
        </tr>
        <tr></tr>

        <tr>
            <th style="width: 80px;">EMP ID</th>
            <th style="width: 150px;">Calling Name</th>
            <th style="width: 100px;">Vehicle No</th>
            
            <?php
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dateStr = "$filterYear-$filterMonth-$d";
                $dayOfWeek = date('N', strtotime($dateStr)); 
                $isWeekend = ($dayOfWeek >= 6);
                
                $headerColor = $isWeekend ? 'background-color: #C0504D;' : ''; 
                echo "<th style='width: 30px; $headerColor'>$d</th>";
            }
            ?>
            <th style="width: 60px; background-color: #F79646;">Total</th>
        </tr>

        <?php
        if (empty($attendance)) {
            echo "<tr><td colspan='" . ($daysInMonth + 4) . "'>No records found for this month.</td></tr>";
        } else {
            // Loop එක වෙනස් කරන්න ඕන නෑ, නමුත් $empId කියන variable එක array key එකෙන් ගන්නේ නෑ
            foreach ($attendance as $uniqueKey => $data) {
                // Calculate Total Days
                $totalDays = count($data['days']);
                ?>
                <tr>
                    <td style="font-weight: bold;"><?php echo htmlspecialchars($data['emp_id']); ?></td>
                    <td class="name-col"><?php echo htmlspecialchars($data['calling_name']); ?></td>
                    <td class="vehicle-col"><?php echo htmlspecialchars($data['vehicle_no']); ?></td>

                    <?php
                    for ($d = 1; $d <= $daysInMonth; $d++) {
                        $dateStr = "$filterYear-$filterMonth-$d";
                        $dayOfWeek = date('N', strtotime($dateStr)); 
                        $isWeekend = ($dayOfWeek >= 6);
                        
                        $isPresent = isset($data['days'][$d]);
                        
                        $style = "";
                        $content = "";

                        if ($isPresent) {
                            $style = "class='present'";
                            $content = "P"; 
                        } elseif ($isWeekend) {
                            $style = "class='weekend'"; 
                        }

                        echo "<td $style>$content</td>";
                    }
                    ?>
                    
                    <td class="total-col"><?php echo $totalDays; ?></td>
                </tr>
                <?php
            }
        }
        ?>
    </table>
</body>
</html>