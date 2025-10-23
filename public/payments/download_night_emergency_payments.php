<?php
// Include necessary files
include('../../includes/db.php'); // Assuming this includes the $conn variable for database connection

// -----------------------------------------------------------
// 1. Setup and Input Validation
// -----------------------------------------------------------

// Get selected month and year from GET parameters
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Basic sanitization (assuming $conn is a mysqli object and db.php connects securely)
$selected_month = htmlspecialchars($selected_month);
$selected_year = htmlspecialchars($selected_year);

// Generate filename for the downloaded file
$month_name = date('F', mktime(0, 0, 0, (int)$selected_month, 1));
$filename = "Night_Emergency_Payments_{$month_name}_{$selected_year}.csv";

// -----------------------------------------------------------
// 2. Database Query
// -----------------------------------------------------------

// SQL query to fetch payment from monthly_payment_ne AND count attendance
// This is the same query used in night_emergency_payments.php
$sql = "SELECT
            s.supplier,
            s.supplier_code,
            mpn.monthly_payment AS total_payment,
            (SELECT COUNT(nea.date)
             FROM night_emergency_attendance AS nea
             WHERE nea.supplier_code = s.supplier_code
               AND MONTH(nea.date) = ?
               AND YEAR(nea.date) = ?) AS total_worked_days
        FROM monthly_payment_ne AS mpn
        JOIN supplier AS s
            ON mpn.supplier_code = s.supplier_code
        WHERE
            mpn.month = ?
            AND mpn.year = ?
        ORDER BY
            s.supplier ASC";

$stmt = $conn->prepare($sql);
// Bind parameters: (Month, Year) for subquery COUNT, then (Month, Year) for main query WHERE
$stmt->bind_param("siss", $selected_month, $selected_year, $selected_month, $selected_year);
$stmt->execute();
$result = $stmt->get_result();

// -----------------------------------------------------------
// 3. CSV Generation and Download Headers
// -----------------------------------------------------------

// Set headers for CSV file download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Define CSV column headers (Matching night_emergency_payments.php's $table_headers, without "Actions")
$table_headers = ["Supplier", "Supplier Code", "Total Worked Days", "Total Payment (LKR)"];
fputcsv($output, $table_headers);

// -----------------------------------------------------------
// 4. Output Data
// -----------------------------------------------------------

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Prepare data row for CSV
        $csv_row = [
            $row['supplier'],
            $row['supplier_code'],
            $row['total_worked_days'],
            !is_null($row['total_payment']) ? number_format($row['total_payment'], 2, '.', '') : '0.00' // Format as currency, no LKR symbol
        ];
        
        // Output the row to the CSV file
        fputcsv($output, $csv_row);
    }
}

// Close the file pointer and database connection
fclose($output);
$stmt->close();
$conn->close();

// Terminate script execution after file output
exit;
?>