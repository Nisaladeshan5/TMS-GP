<?php
// cron_save_headcount.php

// 1. SECURITY CHECK: Allow only Command Line Execution (Cron Job)
// Browser එකෙන් කවුරුහරි මේක run කරන්න හැදුවොත් නවත්වනවා.
if (php_sapi_name() !== 'cli') {
    die("Access Denied: This script can only be run via command line (Cron Job).");
}

// 2. Database Connection
// ෆයිල් එක තියෙන තැන අනුව path එක හරියටම දෙන්න
require_once __DIR__ . '/../../includes/db.php'; 

// 3. Calculate Previous Month (Automation Logic)
// අද දිනේ මොකක් වුනත්, අපි ගන්නේ "ගිය මාසය" (Last Month)
$year = date('Y', strtotime('first day of last month'));
$month = date('n', strtotime('first day of last month'));

echo "Starting Automation for Period: $year-$month\n";

// 4. Check if data already exists to prevent duplicates
$check_sql = "SELECT id FROM monthly_department_summary WHERE year = '$year' AND month = '$month' LIMIT 1";
$check_result = $conn->query($check_sql);

if ($check_result->num_rows > 0) {
    echo "Skipping: Data already exists for $year-$month.\n";
    exit;
}

// 5. Fetch Data from Employee Table
$sql_src = "
    SELECT
        department,
        SUM(CASE WHEN direct = 'YES' THEN 1 ELSE 0 END) AS DirectCount,
        SUM(CASE WHEN direct = 'NO' THEN 1 ELSE 0 END) AS IndirectCount,
        COUNT(*) AS TotalCount
    FROM employee
    WHERE SUBSTRING(route, 5, 1) = 'F'
    GROUP BY department
";

$result = $conn->query($sql_src);

if ($result && $result->num_rows > 0) {
    // 6. Insert into History Table
    $stmt = $conn->prepare("INSERT INTO monthly_department_summary (year, month, department, direct_qty, indirect_qty, total_qty) VALUES (?, ?, ?, ?, ?, ?)");
    
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $stmt->bind_param("iisiii", $year, $month, $row['department'], $row['DirectCount'], $row['IndirectCount'], $row['TotalCount']);
        $stmt->execute();
        $count++;
    }
    
    echo "Success: Saved $count department records for $year-$month.\n";
    $stmt->close();
} else {
    echo "Error: No employee data found to save.\n";
}

$conn->close();
?>