<?php
// Database sambandathawa (Connection details wenas karaganna)
include_once 'db.php';

// SQL Query
$sql = "SELECT r.reason_code, r.reason, g.gl_name 
        FROM reason r
        INNER JOIN gl g ON r.gl_code = g.gl_code";

$result = $conn->query($sql);

$filename = "Reason_Report_" . date('Ymd') . ".xls";

// Browser ekata Excel ekak kiyala kiyanawa
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");

// HTML eken lassanata design eka hadamu
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-type" content="text/html;charset=utf-8" />
    <style>
        .table-style { border: 0.5pt solid #ccc; font-family: Calibri, sans-serif; }
        .header { color: white; font-weight: bold; text-align: center; }
        .row-data { border: 0.5pt solid #eee; }
    </style>
</head>
<body>
    <table border="1" class="table-style">
        <thead>
            <tr>
                <th colspan="3" style="font-size: 18px; height: 30px; background-color: #f2f2f2;">Reason & GL Details Report</th>
            </tr>
            <tr class="header">
                <th style="background-color: #4CAF50; width: 150px;">Reason Code</th>
                <th style="background-color: #4CAF50; width: 300px;">Reason Description</th>
                <th style="background-color: #4CAF50; width: 200px;">GL Name</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td style='text-align: center;'>" . $row['reason_code'] . "</td>";
                    echo "<td>" . $row['reason'] . "</td>";
                    echo "<td>" . $row['gl_name'] . "</td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='3'>No data available</td></tr>";
            }
            ?>
        </tbody>
    </table>
</body>
</html>
<?php
$conn->close();
exit;
?>