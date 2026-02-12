<?php
// factory_journal.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in
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

// GL Constants
$glCodeFilter = '623401';
$glDescription = 'TRANS COST FACTORY';
$documentType = 'Invoice';

// --- 2. Calculate Posting Date ---
$lastDayOfMonth = date('Y-m-d', strtotime("{$year}-{$month}-01 +1 month -1 day"));
$postingDate = date('Y-m-d', strtotime($lastDayOfMonth . ' -1 day'));

// --- 3. Define Static Variables ---
$batchName = 'LPLKR-MAL';
$approvalStatus = '';
$currencyCode = 'LKR';
$svatExRate = 0; 
$balAccountType = 'G/L Account';
$balAccountNo = '623401'; 
$balVATBusPostingGroup = 'LK';
$balVATProdPostingGroup = 'EXEMPT';
$balGenPostingType = 'Purchase';
$afdeling = '570';
$intercompany = '00';
$location = '510';
$costCenter = '320';
$directIndirect = 'DIRECT';

// --- 4. Prepare SQL Query (Advanced Logic) ---

/* Logic Explanation:
   1. අපි මුලින්ම monthly_payments_f (MAIN) සහ monthly_payments_sub (SUB) යන දෙකේම දත්ත එකට ගන්නවා (UNION ALL).
   2. එතකොට අපිට තනි List එකක් එනවා Supplier Code, Payment සහ Route Code එක්ක.
   3. ඊට පස්සේ අපි ඒක `supplier` table එකත් එක්ක JOIN කරනවා. (මෙතනදී Acc No හරියටම එනවා).
   4. Main Route එකක් නම් `route` table එකෙන් නම ගන්නවා.
   5. Sub Route එකක් නම් `sub_route` table එකෙන් නම ගන්නවා.
*/

$sql = "
    SELECT 
        combined_mp.supplier_code,
        s.acc_no,
        s.beneficiaress_name,
        
        -- Route Name Logic:
        -- Source එක MAIN නම් 'route' table එකෙන් නම ගන්න.
        -- Source එක SUB නම් 'sub_route' table එකෙන් නම ගන්න.
        GROUP_CONCAT(DISTINCT 
            CASE 
                WHEN combined_mp.source_type = 'MAIN' THEN COALESCE(r.route, 'Unknown Main Route')
                WHEN combined_mp.source_type = 'SUB' THEN COALESCE(sr.sub_route, 'Unknown Sub Route') 
                ELSE '' 
            END 
        SEPARATOR ' / ') AS route_names,
        
        SUM(combined_mp.monthly_payment) AS total_document_amount
    FROM 
        (
            -- 1. Main Table Data
            SELECT supplier_code, monthly_payment, route_code AS r_code, 'MAIN' AS source_type 
            FROM monthly_payments_f 
            WHERE year = ? AND month = ?
            
            UNION ALL
            
            -- 2. Sub Table Data (Using sub_route_code)
            SELECT supplier_code, monthly_payment, sub_route_code AS r_code, 'SUB' AS source_type 
            FROM monthly_payments_sub 
            WHERE year = ? AND month = ?
        ) AS combined_mp
        
    JOIN 
        supplier s ON combined_mp.supplier_code = s.supplier_code
        
    -- Join for Main Routes (Matches if source is MAIN)
    LEFT JOIN 
        route r ON (combined_mp.source_type = 'MAIN' AND combined_mp.r_code = r.route_code)
        
    -- Join for Sub Routes (Matches if source is SUB)
    -- NOTE: Assuming your table name is 'sub_route' and columns are 'sub_route_code' & 'sub_route'
    LEFT JOIN 
        sub_route sr ON (combined_mp.source_type = 'SUB' AND combined_mp.r_code = sr.sub_route_code)
        
    GROUP BY 
        combined_mp.supplier_code, s.acc_no, s.beneficiaress_name
    HAVING 
        SUM(combined_mp.monthly_payment) > 0
    ORDER BY 
        combined_mp.supplier_code ASC
";

$stmt = $conn->prepare($sql);
// Bind params: year, month (table 1) AND year, month (table 2)
$stmt->bind_param("ssss", $year, $month, $year, $month);

$stmt->execute();
$result = $stmt->get_result();

// --- 5. Set Headers for Excel Download ---
$monthName = date('F', mktime(0, 0, 0, $month, 10));
$filename = 'Factory_Transport_Journal_' . $monthName . '_' . $year . '.xls';

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
    $descriptionPrefix = 'Factory Transport ' . strtoupper($monthName) . ' ' . $year . ' - ';

    while ($row = $result->fetch_assoc()) {
        $documentAmount = (float)$row['total_document_amount'];
        
        // Routes logic
        $routes = !empty($row['route_names']) ? $row['route_names'] : 'Factory Route';
        $routes = htmlspecialchars($routes);
        
        $amountNegative = number_format(-$documentAmount, 2, '.', ''); 
        $fullDescription = $descriptionPrefix . $routes;
        $supplierCode = $row['supplier_code'];

        $output .= '
            <tr>
                <td>' . htmlspecialchars($batchName) . '</td>
                <td>' . htmlspecialchars($postingDate) . '</td>
                <td>' . htmlspecialchars($documentType) . '</td>
                <td></td> 
                <td></td> 
                <td>Vendor</td>
                <td>="' . htmlspecialchars($supplierCode) . '"</td>
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
    $output .= '<tr><td colspan="36">No factory transport data found for ' . $monthName . ' ' . $year . '.</td></tr>';
}

$output .= '</tbody></table>';

echo $output;

$stmt->close();
$conn->close();
?>