<?php
// factory_cost_distribution.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- 1. INPUT HANDLING ---
if (isset($_GET['period'])) {
    $parts = explode('-', $_GET['period']);
    $year = (int)$parts[0];
    $month = (int)$parts[1];
} else {
    $year = (int)date('Y');
    $month = (int)date('n');
}

// --- 2. GET TOTAL FACTORY COST (UPDATED) ---

// A. Main Table Cost (monthly_payments_f)
$sql_cost = "SELECT SUM(monthly_payment) as total_cost FROM monthly_payments_f WHERE year = ? AND month = ?";
$stmt_cost = $conn->prepare($sql_cost);
$stmt_cost->bind_param("ss", $year, $month);
$stmt_cost->execute();
$result_cost = $stmt_cost->get_result();
$row_cost = $result_cost->fetch_assoc();
$cost_main = $row_cost['total_cost'] ?? 0;
$stmt_cost->close();

// B. Sub Table Cost (monthly_payments_sub) - ADDED
$sql_sub = "SELECT SUM(monthly_payment) as total_cost FROM monthly_payments_sub WHERE year = ? AND month = ?";
$stmt_sub = $conn->prepare($sql_sub);
$stmt_sub->bind_param("ss", $year, $month);
$stmt_sub->execute();
$result_sub = $stmt_sub->get_result();
$row_sub = $result_sub->fetch_assoc();
$cost_sub = $row_sub['total_cost'] ?? 0;
$stmt_sub->close();

// C. Calculate Grand Total (Main + Sub)
$totalFactoryCost = $cost_main + $cost_sub;


// --- 3. GET EMPLOYEE COUNTS (SMART LOGIC) ---

// A. මුලින්ම බලනවා History Table එකේ දත්ත තියෙනවද කියලා
$sql_check = "SELECT COUNT(*) as cnt FROM monthly_department_summary WHERE year = '$year' AND month = '$month'";
$check_res = $conn->query($sql_check);
$has_history = ($check_res->fetch_assoc()['cnt'] > 0);

$departments = [];
$grandTotalEmployees = 0;
$dataSource = ""; // To show in report footer for verification

if ($has_history) {
    // History Table එකෙන් දත්ත ගන්නවා (Accurate for Past)
    $dataSource = "Historical Snapshot";
    $sql_emp = "
        SELECT 
            department AS Department,
            direct_qty AS DirectCount,
            indirect_qty AS IndirectCount,
            total_qty AS TotalCount
        FROM monthly_department_summary
        WHERE year = '$year' AND month = '$month'
        ORDER BY department
    ";
} else {
    // දත්ත Save කරලා නැත්නම් Live Table එකෙන් ගන්නවා (Fallback)
    $dataSource = "Live Data (Estimated)";
    $sql_emp = "
        SELECT
            department AS Department,
            SUM(CASE WHEN direct = 'YES' THEN 1 ELSE 0 END) AS DirectCount,
            SUM(CASE WHEN direct = 'NO' THEN 1 ELSE 0 END) AS IndirectCount,
            COUNT(*) AS TotalCount
        FROM employee
        WHERE SUBSTRING(route, 5, 1) = 'F'
        GROUP BY department
        ORDER BY department
    ";
}

$result_emp = $conn->query($sql_emp);

if ($result_emp) {
    while ($row = $result_emp->fetch_assoc()) {
        $departments[] = $row;
        $grandTotalEmployees += $row['TotalCount'];
    }
}

// --- 4. CALCULATE PER HEAD COST ---
$costPerHead = 0;
if ($grandTotalEmployees > 0) {
    $costPerHead = $totalFactoryCost / $grandTotalEmployees;
}

// --- 5. EXCEL EXPORT HEADERS ---
$monthName = date('F', mktime(0, 0, 0, $month, 10));
$filename = 'Factory_Cost_Distribution_' . $monthName . '_' . $year . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// --- 6. GENERATE OUTPUT TABLE ---
?>
<table border="1" style="font-family: Arial, sans-serif; font-size: 12px;">
    <thead>
        <tr>
            <th colspan="6" style="background-color: #404040; color: white; font-size: 14px; text-align: center; height: 30px;">
                Factory Transport Cost Distribution - <?php echo $monthName . ' ' . $year; ?>
            </th>
        </tr>
        
        <tr>
            <th colspan="6" style="text-align: left; background-color: #f2f2f2; height: 25px;">
                <span style="margin-right: 20px;"><strong>Total Factory Cost:</strong> <?php echo number_format($totalFactoryCost, 2); ?></span>
                <span style="margin-right: 20px;"><strong>Total Employees (F):</strong> <?php echo $grandTotalEmployees; ?></span>
                <span><strong>Cost Per Head:</strong> <?php echo number_format($costPerHead, 2); ?></span>
                <span style="float:right; font-size:10px; color:#555;">(Source: <?php echo $dataSource; ?>)</span>
            </th>
        </tr>

        <tr>
            <th rowspan="2" style="background-color: #008080; color: white; font-weight: bold; vertical-align: middle; width: 200px; border: 1px solid #ffffff;">Department</th>
            <th colspan="2" style="background-color: #006666; color: white; font-weight: bold; text-align: center; border: 1px solid #ffffff;">Head Count</th>
            <th colspan="3" style="background-color: #004d4d; color: white; font-weight: bold; text-align: center; border: 1px solid #ffffff;">Cost Allocation (LKR)</th>
        </tr>

        <tr>
            <th style="background-color: #008080; color: white; font-weight: bold; border: 1px solid #ffffff;">Direct Qty</th>
            <th style="background-color: #008080; color: white; font-weight: bold; border: 1px solid #ffffff;">Indirect Qty</th>
            <th style="background-color: #008080; color: white; font-weight: bold; border: 1px solid #ffffff;">Direct Cost</th>
            <th style="background-color: #008080; color: white; font-weight: bold; border: 1px solid #ffffff;">Indirect Cost</th>
            <th style="background-color: #008080; color: white; font-weight: bold; border: 1px solid #ffffff;">Total Cost</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $sumDirectQty = 0;
        $sumIndirectQty = 0;
        $sumDirectCost = 0;
        $sumIndirectCost = 0;
        $sumTotalCost = 0;

        foreach ($departments as $dept) {
            $directQty = $dept['DirectCount'];
            $indirectQty = $dept['IndirectCount'];
            
            // Calculate Costs
            $directCost = $directQty * $costPerHead;
            $indirectCost = $indirectQty * $costPerHead;
            $totalRowCost = $directCost + $indirectCost;

            // Accumulate Totals
            $sumDirectQty += $directQty;
            $sumIndirectQty += $indirectQty;
            $sumDirectCost += $directCost;
            $sumIndirectCost += $indirectCost;
            $sumTotalCost += $totalRowCost;
            ?>
            <tr>
                <td><?php echo htmlspecialchars($dept['Department']); ?></td>
                <td style="text-align: center;"><?php echo $directQty; ?></td>
                <td style="text-align: center;"><?php echo $indirectQty; ?></td>
                <td style="text-align: right;"><?php echo number_format($directCost, 2); ?></td>
                <td style="text-align: right;"><?php echo number_format($indirectCost, 2); ?></td>
                <td style="text-align: right; font-weight: bold;"><?php echo number_format($totalRowCost, 2); ?></td>
            </tr>
            <?php
        }
        ?>
    </tbody>
    <tfoot>
        <tr style="font-weight: bold;">
            <td style="background-color: #cccccc; border: 1px solid ;">GRAND TOTAL</td>
            <td style="background-color: #cccccc; text-align: center; border: 1px solid ;"><?php echo $sumDirectQty; ?></td>
            <td style="background-color: #cccccc; text-align: center; border: 1px solid ;"><?php echo $sumIndirectQty; ?></td>
            <td style="background-color: #cccccc; text-align: right; border: 1px solid ;"><?php echo number_format($sumDirectCost, 2); ?></td>
            <td style="background-color: #cccccc; text-align: right; border: 1px solid ;"><?php echo number_format($sumIndirectCost, 2); ?></td>
            <td style="background-color: #cccccc; text-align: right; border: 1px solid ;"><?php echo number_format($sumTotalCost, 2); ?></td>
        </tr>
    </tfoot>
</table>

<?php
$conn->close();
?>