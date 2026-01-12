<?php
// ne_journal.php
// Night Emergency Journal

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../login.php");
    exit();
}

include('../../includes/db.php');

// --- 1. Get and Validate Input ---
if (isset($_GET['period'])) {
    $parts = explode('-', $_GET['period']);
    $year = (int)$parts[0];
    $month = (int)$parts[1];
} else {
    echo "Invalid request. Period is required.";
    exit;
}

// --- 2. Calculate Posting Date ---
$lastDayOfMonth = date('Y-m-d', strtotime("{$year}-{$month}-01 +1 month -1 day"));
$postingDate = date('Y-m-d', strtotime($lastDayOfMonth . ' -1 day'));
$monthName = date('F', mktime(0, 0, 0, $month, 10));

// --- 3. Define Static Variables ---
$batchName = 'LPLKR-MAL';
$approvalStatus = '';
$currencyCode = 'LKR';
$svatExRate = 0; 
$balAccountType = 'G/L Account';
$documentType = 'Invoice';

// --- Requested Hardcoded Values ---
// Note: NE සඳහා GL Account එක 623402 ලෙස යොදා ඇත (ඔබට අවශ්‍ය නම් මෙය වෙනස් කරන්න)
$balAccountNo = '623402'; 
$balVATBusPostingGroup = 'LK';
$balVATProdPostingGroup = 'EXEMPT';
$balGenPostingType = 'Purchase';

$afdeling = '570';       // Requested: 570
$intercompany = '00';
$location = '510';       // Requested: 510
$costCenter = '320';     // Default for NE (Change if needed)
$directIndirect = 'DIRECT'; // Requested: DIRECT
$glDescription = 'Night Emergency'; // Requested Description

// --- 4. Prepare SQL Query ---
// Fetch data from monthly_payment_ne joined with supplier
$sql = "
    SELECT 
        mp.supplier_code,
        s.acc_no,
        s.beneficiaress_name,
        SUM(mp.monthly_payment) AS total_document_amount
    FROM 
        monthly_payment_ne mp
    JOIN 
        supplier s ON mp.supplier_code = s.supplier_code
    WHERE 
        mp.year = ? AND mp.month = ?
    GROUP BY 
        mp.supplier_code, s.acc_no, s.beneficiaress_name
    HAVING 
        SUM(mp.monthly_payment) > 0
    ORDER BY 
        mp.supplier_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $year, $month);
$stmt->execute();
$result = $stmt->get_result();

// --- 5. Set Headers for Excel Download ---
$filename = 'Night_Emergency_Journal_' . $monthName . '_' . $year . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Pragma: no-cache");
header("Expires: 0");

// --- 6. Build Excel Table Output ---
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
    // Description Prefix
    $descriptionText = 'Night Emergency - ' . strtoupper($monthName) . ' ' . $year;

    while ($row = $result->fetch_assoc()) {
        $documentAmount = (float)$row['total_document_amount'];
        $amountNegative = number_format(-$documentAmount, 2, '.', ''); 
        
        $accNo = $row['acc_no'];

        $output .= '
            <tr>
                <td>' . htmlspecialchars($batchName) . '</td>
                <td>' . htmlspecialchars($postingDate) . '</td>
                <td>' . htmlspecialchars($documentType) . '</td>
                <td></td> 
                <td></td> 
                <td>Vendor</td>
                <td style="mso-number-format:\'\@\'">' . htmlspecialchars($accNo) . '</td>
                <td>' . htmlspecialchars($row['beneficiaress_name']) . '</td>
                <td>' . htmlspecialchars($approvalStatus) . '</td>
                <td>' . htmlspecialchars($currencyCode) . '</td>
                <td>' . htmlspecialchars($descriptionText) . '</td>
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
                <td style="mso-number-format:\'\@\'">' . htmlspecialchars($balAccountNo) . '</td>
                <td>' . htmlspecialchars($balVATBusPostingGroup) . '</td>
                <td>' . htmlspecialchars($balVATProdPostingGroup) . '</td>
                <td>' . htmlspecialchars($balGenPostingType) . '</td>
                
                <td style="mso-number-format:\'\@\'">' . htmlspecialchars($afdeling) . '</td>
                <td style="mso-number-format:\'\@\'">' . htmlspecialchars($intercompany) . '</td>
                <td style="mso-number-format:\'\@\'">' . htmlspecialchars($location) . '</td>
                <td style="mso-number-format:\'\@\'">' . htmlspecialchars($costCenter) . '</td>
                
                <td>' . htmlspecialchars($directIndirect) . '</td>
                <td>' . htmlspecialchars($glDescription) . '</td>
                <td></td>
                <td></td>
                <td></td>
            </tr>';
    }
} else {
    $output .= '<tr><td colspan="36">No Night Emergency data found for ' . $monthName . ' ' . $year . '.</td></tr>';
}

$output .= '</tbody></table>';

echo $output;

$stmt->close();
$conn->close();
?>