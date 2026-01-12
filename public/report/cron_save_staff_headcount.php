<?php
// cron_save_staff_headcount.php

// 1. SECURITY CHECK
if (php_sapi_name() !== 'cli') {
    die("Access Denied: This script can only be run via command line (Cron Job).");
}

// 2. Database Connection
require_once __DIR__ . '/../../includes/db.php'; 

// 3. Calculate Previous Month
$year = date('Y', strtotime('first day of last month'));
$month = date('n', strtotime('first day of last month'));

echo "Starting STAFF Automation for Period: $year-$month\n";

// 4. Check if data already exists in STAFF table
$check_sql = "SELECT id FROM monthly_staff_summary WHERE year = '$year' AND month = '$month' LIMIT 1";
$check_result = $conn->query($check_sql);

if ($check_result->num_rows > 0) {
    echo "Skipping: Staff data already exists for $year-$month.\n";
    exit;
}

// 5. Fetch Data from Employee Table (Filter for STAFF routes 'S')
// Note: Assuming Staff routes have 'S' at the 5th position (e.g., COL-S-01)
$sql_src = "
    SELECT
        department,
        SUM(CASE WHEN direct = 'YES' THEN 1 ELSE 0 END) AS DirectCount,
        SUM(CASE WHEN direct = 'NO' THEN 1 ELSE 0 END) AS IndirectCount,
        COUNT(*) AS TotalCount
    FROM employee
    WHERE SUBSTRING(route, 5, 1) = 'S'  /* Changed 'F' to 'S' for Staff */
    GROUP BY department
";

$result = $conn->query($sql_src);

if ($result && $result->num_rows > 0) {
    // 6. Insert into STAFF History Table
    $stmt = $conn->prepare("INSERT INTO monthly_staff_summary (year, month, department, direct_qty, indirect_qty, total_qty) VALUES (?, ?, ?, ?, ?, ?)");
    
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $stmt->bind_param("iisiii", $year, $month, $row['department'], $row['DirectCount'], $row['IndirectCount'], $row['TotalCount']);
        $stmt->execute();
        $count++;
    }
    
    echo "Success: Saved $count STAFF department records for $year-$month.\n";
    $stmt->close();
} else {
    echo "Error: No STAFF employee data found to save.\n";
}

$conn->close();
?>