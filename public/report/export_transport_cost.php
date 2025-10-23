<?php

include('../../includes/db.php'); // Your database connection file

// 1. Get and Validate Input
$month = $_POST['month_input'] ?? '';
$year = $_POST['year_input'] ?? '';

if (empty($month) || empty($year)) {
    echo "Invalid request. Month and year are required.";
    exit;
}

$month = (int)$month;
$year = (int)$year;
$glCodeFilter = '623401';
$glDescription = 'TRANS COST FACTORY';
$documentType = 'Invoice';

// 2. Calculate Posting Date
$lastDayOfMonth = date('Y-m-d', strtotime("{$year}-{$month}-01 +1 month -1 day"));
$postingDate = date('Y-m-d', strtotime($lastDayOfMonth . ' -1 day'));

// 3. Prepare SQL Query - CORRECTED LOGIC
// We use a subquery (gl_sum) to calculate the total allocation first,
// which avoids duplicating the amount when joining the 'route' table.
$sql = "
    SELECT 
        gl_sum.supplier_code,
        s.acc_no,
        s.beneficiaress_name,
        GROUP_CONCAT(DISTINCT r.route SEPARATOR '/') AS route_names,
        gl_sum.total_document_amount AS document_amount
    FROM (
        -- Subquery: Calculate the sum of monthly_allocation (Document Amount) for the GL code and month/year
        SELECT
            supplier_code,
            SUM(monthly_allocation) AS total_document_amount
        FROM
            monthly_cost_allocation
        WHERE
            month = ? AND year = ? AND gl_code = ?
        GROUP BY
            supplier_code
        HAVING
            SUM(monthly_allocation) > 0
    ) gl_sum
    JOIN
        supplier s ON gl_sum.supplier_code = s.supplier_code
    LEFT JOIN
        route r ON gl_sum.supplier_code = r.supplier_code AND SUBSTRING(r.route_code, 5, 1) = 'F'
    GROUP BY
        gl_sum.supplier_code, s.acc_no, s.beneficiaress_name, gl_sum.total_document_amount
    ORDER BY
        gl_sum.supplier_code
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iis", $month, $year, $glCodeFilter); // Binding parameters for the subquery
$stmt->execute();
$result = $stmt->get_result();

// 4. Set Headers for Excel Download
$monthName = date('F', mktime(0, 0, 0, $month, 10));
$filename = 'Factory_Transport_Cost_GL' . $glCodeFilter . '_' . $monthName . $year . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Pragma: no-cache");
header("Expires: 0");

// 5. Build Excel Table Output

$output = '<table border="1">
    <thead>
        <tr>
            <th>Posting Date</th>
            <th>Document Type</th>
            <th>Document No.</th>
            <th>External Document No.</th>
            <th>Account Type</th>
            <th>Account No.</th>
            <th>Vendor Name</th>
            <th>Description</th>
            <th>Document Amount</th>
            <th>GL Description</th>
        </tr>
    </thead>
    <tbody>';

if ($result->num_rows > 0) {
    // Generate the initial part of the Description: "Factory Transport MONTHYEAR - "
    $descriptionPrefix = 'Factory Transport ' . strtoupper($monthName) . $year . ' - ';

    while ($row = $result->fetch_assoc()) {
        $routes = htmlspecialchars($row['route_names']);
        
        // 4. Create the full Description
        $fullDescription = $descriptionPrefix . $routes;

        // Populate the table row
        $output .= '
            <tr>
                <td>' . htmlspecialchars($postingDate) . '</td>
                <td>' . htmlspecialchars($documentType) . '</td>
                <td></td> 
                <td></td> 
                <td>Vendor</td>
                <td>' . htmlspecialchars($row['acc_no']) . '</td>
                <td>' . htmlspecialchars($row['beneficiaress_name']) . '</td>
                <td>' . $fullDescription . '</td>
                <td style="text-align:right;">' . number_format((float)$row['document_amount'], 2, '.', '') . '</td>
                <td>' . htmlspecialchars($glDescription) . '</td>
            </tr>';
    }
} else {
    // Optional: Add a row if no data is found
    $output .= '<tr><td colspan="10">No transport cost data found for the selected month and year.</td></tr>';
}

$output .= '</tbody></table>';

echo $output;

$stmt->close();
$conn->close();

?>