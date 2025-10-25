<?php
// export_employee_report.php

// 1. Include necessary files and initialize DB connection
include('../../includes/db.php'); 

// Check for required POST data (we still need these to get the current date for the filename)
if (!isset($_POST['month_input']) || !isset($_POST['year_input'])) {
    // If POST data is missing, use current month/year for the filename
    $month = (int)date('n');
    $year = (int)date('Y');
} else {
    // Use selected month/year only for the filename
    $month = intval($_POST['month_input']);
    $year = intval($_POST['year_input']);
}

// 2. Prepare and Execute the SQL Query (WITHOUT DATE FILTERING)
/* ASSUMED SCHEMA:
   - Table: employee
   - Columns: route, department, direct
*/

$sql = "
    SELECT
        department AS Department,
        SUM(CASE WHEN direct = 'YES' THEN 1 ELSE 0 END) AS DirectCount,
        SUM(CASE WHEN direct = 'NO' THEN 1 ELSE 0 END) AS IndirectCount,
        COUNT(*) AS Total
    FROM
        employee
    WHERE
        SUBSTRING(route, 5, 1) = 'F'  /* Only filter by Route condition */
    GROUP BY
        department
    ORDER BY
        department
";

// Use prepared statements, but SINCE WE HAVE NO DATE PARAMETERS, 
// we use a simplified query execution (assuming $conn is a mysqli object)

$result = $conn->query($sql);
if (!$result) {
    die("SQL Error: " . $conn->error);
}

$data = $result->fetch_all(MYSQLI_ASSOC);
$result->free();


// Get month name for filename (We use the selected month/year for context in the filename)
$monthName = date('F', mktime(0, 0, 0, $month, 10));
$filename = 'Employee_Route_F_Current_Counts_' . $monthName . '_' . $year . '.xls';

// 3. Set HTTP Headers for Excel Download
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");

// 4. Generate the Excel Content (Simple HTML/Table format for .xls)

$output = '<table border="1" cellpadding="5" cellspacing="0">';

// Table Header
$output .= '<tr>
                <th style="background-color:#F2F2F2; font-weight:bold;">Department</th>
                <th style="background-color:#D9EAD3; font-weight:bold;">Direct</th>
                <th style="background-color:#FCE5CD; font-weight:bold;">Indirect</th>
                <th style="background-color:#EFEFEF; font-weight:bold;">Total Employees</th>
            </tr>';

$grandTotalDirect = 0;
$grandTotalIndirect = 0;
$grandTotal = 0;

// Table Body
if (!empty($data)) {
    foreach ($data as $row) {
        $output .= '<tr>';
        $output .= '<td>' . htmlspecialchars($row['Department']) . '</td>';
        $output .= '<td>' . htmlspecialchars($row['DirectCount']) . '</td>';
        $output .= '<td>' . htmlspecialchars($row['IndirectCount']) . '</td>';
        $output .= '<td>' . htmlspecialchars($row['Total']) . '</td>';
        $output .= '</tr>';

        // Accumulate grand totals
        $grandTotalDirect += $row['DirectCount'];
        $grandTotalIndirect += $row['IndirectCount'];
        $grandTotal += $row['Total'];
    }

    // Table Footer (Totals)
    $output .= '<tr>
                    <td style="font-weight:bold; background-color:#F2F2F2;">GRAND TOTAL</td>
                    <td style="font-weight:bold; background-color:#D9EAD3;">' . $grandTotalDirect . '</td>
                    <td style="font-weight:bold; background-color:#FCE5CD;">' . $grandTotalIndirect . '</td>
                    <td style="font-weight:bold; background-color:#EFEFEF;">' . $grandTotal . '</td>
                </tr>';

} else {
    $output .= '<tr><td colspan="4">No data found.</td></tr>';
}

$output .= '</table>';

echo $output;

// Close the database connection
if (isset($conn) && is_object($conn)) {
    $conn->close(); 
}
?>