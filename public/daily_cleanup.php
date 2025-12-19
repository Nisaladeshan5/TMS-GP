<?php

// --- Database Connection ---
$host = "localhost";
$dbname = "transport";
$username = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}


// --- Target Date (Yesterday) ---
$yesterday_date = date('Y-m-d', strtotime('-1 day'));
echo "Target Date for Cleanup: {$yesterday_date}\n\n";


// --- Tables to Clean ---
$tables_to_clean = [
    'staff_transport_vehicle_register',
    'factory_transport_vehicle_register'
];


// --- Function: Remove Duplicates For Yesterday ---
function remove_yesterday_duplicates_simple(PDO $pdo, string $table_name, string $date_to_clean): int
{
    $duplicate_criteria = "date, route, shift";

    $sql = "
        DELETE FROM `$table_name`
        WHERE `date` = :date1
        AND id NOT IN (
            SELECT MIN(t.id)
            FROM (
                SELECT id, date, route, shift
                FROM `$table_name`
                WHERE date = :date2
            ) AS t
            GROUP BY $duplicate_criteria
        );
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':date1', $date_to_clean);
        $stmt->bindParam(':date2', $date_to_clean);
        $stmt->execute();

        return $stmt->rowCount();

    } catch (PDOException $e) {
        echo "Error cleaning table {$table_name}: " . $e->getMessage() . "\n";
        return 0;
    }
}


// --- Cleanup Process ---
echo "Starting Duplicate Cleanup...\n\n";

foreach ($tables_to_clean as $table) {

    echo "Processing table: {$table}...\n";

    $deleted_count = remove_yesterday_duplicates_simple($pdo, $table, $yesterday_date);

    echo "Deleted {$deleted_count} duplicate rows from {$table}.\n\n";
}

echo "Cleanup complete!\n";

?>
