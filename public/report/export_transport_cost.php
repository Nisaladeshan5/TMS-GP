<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Note: Ensure the path to db.php is correct in your environment
include('../../includes/db.php'); // Your database connection file

// --- 1. Get and Validate Input ---
$month = $_POST['month_input'] ?? '';
$year = $_POST['year_input'] ?? '';

if (empty($month) || empty($year)) {
    // Standard practice: return a minimal HTML error page or a JSON response if appropriate, 
    // but for a direct download script, a simple echo works.
    echo "Invalid request. Month and year are required.";
    exit;
}

$month = (int)$month;
$year = (int)$year;

// GL Codes and descriptions are typically fixed based on the report type
$glCodeFilter = '623401';
$glDescription = 'TRANS COST FACTORY';
$documentType = 'Invoice';

// --- 2. Calculate Posting Date ---
// Posting date is typically the day before the last day of the month
$lastDayOfMonth = date('Y-m-d', strtotime("{$year}-{$month}-01 +1 month -1 day"));
$postingDate = date('Y-m-d', strtotime($lastDayOfMonth . ' -1 day')); // YYYY-MM-DD format

// --- 3. Define New Static Variables for Journal Export ---
// These values are required for the full GL journal import file format.
$batchName = 'LPLKR-MAL';
$approvalStatus = '';
$currencyCode = 'LKR';
$svatExRate = 0; // Correctly set to '0.'
$documentAmountField = ''; // This column is left empty in the output
$debitAmount = ''; // Set to empty string, as this column is now requested to be empty
$creditAmount = ''; // This column is left empty
$amountLCY = ''; // This column is left empty
$vatBusPostingGroup = ''; // This column is left empty
$vatProdPostingGroup = ''; // This column is left empty
$genPostingType = ''; // This column is left empty
$balAccountType = 'G/L Account';
$balAccountNo = '623401'; // Should match $glCodeFilter
$balVATBusPostingGroup = 'LK';
$balVATProdPostingGroup = 'EXEMPT';
$balGenPostingType = 'Purchase';
$afdeling = '570';
$intercompany = '00'; // Correctly set to '00'
$location = '510';
$costCenter = '320';
$directIndirect = 'DIRECT';
$numberOfJournalRecords = ''; // Now empty, as requested
$balance = ''; // Now empty, as requested
$totalBalance = ''; // Now empty, as requested


// --- 4. Prepare SQL Query (Unchanged from original, as the logic is correct) ---
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
$stmt->bind_param("iis", $month, $year, $glCodeFilter);
$stmt->execute();
$result = $stmt->get_result();

// --- 5. Set Headers for Excel Download ---
$monthName = date('F', mktime(0, 0, 0, $month, 10));
$filename = 'Factory_Transport_Cost_GL' . $glCodeFilter . '_' . $monthName . $year . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Pragma: no-cache");
header("Expires: 0");

// --- 6. Build Excel Table Output with all 36 Columns ---

$output = '<table border="1">
    <thead>
        <tr>
            <th>Batch Name</th>
            <th>Posting Date</th>
            <th>Document Type</th>
            <th>Document No.</th>
            <th>External Document No.</th>
            <th>Account Type</th>
            <th>Account No.</th>
            <th>Vendor Name</th>
            <th>Approval Status</th>
            <th>Currency Code</th>
            <th>Description</th>
            <th>Sup. SVAT Ex. Rate</th>
            <th>Purchase Order No</th>
            <th>GRN Date</th>
            <th>Document Amount</th>
            <th>Debit Amount</th>
            <th>Credit Amount</th>
            <th>Amount</th>
            <th>Amount (LCY)</th>
            <th>VAT Bus. Posting Group</th>
            <th>VAT Prod. Posting Group</th>
            <th>Gen. Posting Type</th>
            <th>Bal. Account Type</th>
            <th>Bal. Account No.</th>
            <th>Bal. VAT Bus. Posting Group</th>
            <th>Bal. VAT Prod. Posting Group</th>
            <th>Bal. Gen. Posting Type</th>
            <th>Afdeling</th>
            <th>Intercompany</th>
            <th>Location</th>
            <th>Cost Center</th>
            <th>Direct & Indirect</th>
            <th>GL Description</th>
            <th>NumberOfJournalRecords</th>
            <th>Balance</th>
            <th>Total Balance</th>
        </tr>
    </thead>
    <tbody>';

if ($result->num_rows > 0) {
    // Generate the initial part of the Description: "Factory Transport MONTHYEAR - "
    $descriptionPrefix = 'Factory Transport ' . strtoupper($monthName) . $year . ' - ';

    while ($row = $result->fetch_assoc()) {
        $documentAmount = (float)$row['document_amount'];
        $routes = htmlspecialchars($row['route_names']);
        
        // Calculate the required negative 'Amount' value, formatted to 2 decimal places
        $amountNegative = number_format(-$documentAmount, 2, '.', ''); 
        
        // Calculate the full Description
        $fullDescription = $descriptionPrefix . $routes;

        // Populate the table row (36 columns)
        // Note: text-align:right is added for numerical columns for better Excel display
        // We use ="" to force Excel to treat these specific fields as text strings to preserve their exact formatting.
        $output .= '
            <tr>
                <td>' . htmlspecialchars($batchName) . '</td>
                <td>' . htmlspecialchars($postingDate) . '</td>
                <td>' . htmlspecialchars($documentType) . '</td>
                <td></td> 
                <td></td> 
                <td>Vendor</td>
                <td>="' . htmlspecialchars($row['acc_no']) . '"</td>
                <td>' . htmlspecialchars($row['beneficiaress_name']) . '</td>
                <td>' . htmlspecialchars($approvalStatus) . '</td>
                <td>' . htmlspecialchars($currencyCode) . '</td>
                <td>' . htmlspecialchars($fullDescription) . '</td>
                <td style="text-align:right;">' . htmlspecialchars($svatExRate) . '</td>
                <td></td> 
                <td></td> 
                <td></td>
                <td></td>
                <td></td>
                <td style="text-align:right;">' . htmlspecialchars($amountNegative) . '</td> 
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td>' . htmlspecialchars($balAccountType) . '</td>
                <td>="' . htmlspecialchars($balAccountNo) . '"</td>
                <td>' . htmlspecialchars($balVATBusPostingGroup) . '</td>
                <td>' . htmlspecialchars($balVATProdPostingGroup) . '</td>
                <td>' . htmlspecialchars($balGenPostingType) . '</td>
                <td>="' . htmlspecialchars($afdeling) . '"</td>
                <td>="' . htmlspecialchars($intercompany) . '"</td>
                <td>="' . htmlspecialchars($location) . '"</td>
                <td>="' . htmlspecialchars($costCenter) . '"</td>
                <td>' . htmlspecialchars($directIndirect) . '</td>
                <td>' . htmlspecialchars($glDescription) . '</td>
                <td></td>
                <td></td>
                <td></td>
            </tr>';
    }
} else {
    // Optional: Add a row if no data is found
    $output .= '<tr><td colspan="36">No transport cost data found for the selected month and year.</td></tr>';
}

$output .= '</tbody></table>';

echo $output;

$stmt->close();
$conn->close();

?>
