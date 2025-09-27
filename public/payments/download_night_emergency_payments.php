<?php
// Include the database connection file
include('../../includes/db.php');

// Get the parameters from the URL
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Set the HTTP headers for Excel file download
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=Night_Emergency_Payments_{$selected_month}_{$selected_year}.xls");
header("Pragma: no-cache");
header("Expires: 0");

// HTML for the Excel file
echo "<html>";
echo "<head><meta charset='utf-8'></head>";
echo "<body>";
echo "<h1>Night Emergency Payments Summary - " . date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . "</h1>";
echo "<table border='1'>";
echo "<thead>";
echo "<tr>";
echo "<th>Supplier</th>";
echo "<th>Supplier Code</th>";
echo "<th>Worked Days</th>";
echo "<th>Day Rate (LKR)</th>";
echo "<th>Total Payment (LKR)</th>";
echo "</tr>";
echo "</thead>";
echo "<tbody>";

// Fetch suppliers and their night emergency worked days and day rates
$sql = "SELECT
            s.supplier,
            s.supplier_code,
            COUNT(nea.date) AS worked_days,
            COALESCE(nedr.day_rate, 0) AS day_rate,
            (COALESCE(nedr.day_rate, 0) * COUNT(nea.date)) AS total_payment
        FROM night_emergency_attendance AS nea
        JOIN supplier AS s ON nea.supplier_code = s.supplier_code
        LEFT JOIN night_emergency_day_rate AS nedr
            ON nedr.supplier_code = s.supplier_code
           AND STR_TO_DATE(CONCAT(nedr.year, '-', nedr.month, '-01'), '%Y-%m-%d') = (
                SELECT MAX(STR_TO_DATE(CONCAT(nedr2.year, '-', nedr2.month, '-01'), '%Y-%m-%d'))
                FROM night_emergency_day_rate AS nedr2
                WHERE nedr2.supplier_code = s.supplier_code
                  AND STR_TO_DATE(CONCAT(nedr2.year, '-', nedr2.month, '-01'), '%Y-%m-%d')
                      <= STR_TO_DATE(CONCAT(?, '-', ?, '-01'), '%Y-%m-%d')
           )
        WHERE MONTH(nea.date) = ? AND YEAR(nea.date) = ?
        GROUP BY s.supplier, s.supplier_code, nedr.day_rate
        ORDER BY s.supplier ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("siii", $selected_year, $selected_month, $selected_month, $selected_year);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $worked_days = $row['worked_days'];
        $day_rate = $row['day_rate'];
        $total_payment = $row['total_payment'];

        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['supplier']) . "</td>";
        echo "<td>" . htmlspecialchars($row['supplier_code']) . "</td>";
        echo "<td>" . htmlspecialchars($worked_days) . "</td>";
        echo "<td>" . number_format($day_rate, 2) . "</td>";
        echo "<td>" . number_format($total_payment, 2) . "</td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='5'>No night emergency payments found for this period.</td></tr>";
}

$stmt->close();
$conn->close();

echo "</tbody>";
echo "</table>";
echo "</body>";
echo "</html>";
?>
