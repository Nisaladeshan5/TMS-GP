<?php
// Include the database connection
// NOTE: Adjust the path to your db.php file as needed
include('../../includes/db.php'); 

// Get selected month and year from the URL, defaulting to current
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// 1. Set File Headers for Excel/CSV Download
$filename = "Own_Vehicle_Payments_" . date('Y_m', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Output stream is opened for writing
$output = fopen('php://output', 'w');

// Define CSV headers (must match the order of data retrieval below)
$csv_headers = [
    "Employee ID",
    "Employee (Vehicle No)",
    "Attendance Days",
    "Total Distance (km)",
    "Total Payment (LKR)"
];
// Write the headers to the CSV file
fputcsv($output, $csv_headers);


// 2. Fetch Data from the Database
$sql = "
    SELECT 
        ovp.emp_id,
        e.calling_name,
        ov.vehicle_no,
        ovp.no_of_attendance,
        ovp.distance AS total_distance,
        ovp.monthly_payment AS final_payment
    FROM 
        own_vehicle_payments ovp
    JOIN 
        employee e ON ovp.emp_id = e.emp_id
    JOIN 
        own_vehicle ov ON ovp.emp_id = ov.emp_id 
    WHERE 
        ovp.month = ? AND ovp.year = ?
    ORDER BY 
        e.calling_name ASC;
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$result = $stmt->get_result();

// 3. Output Data to the CSV
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        
        // Prepare the row data in the exact order of $csv_headers
        $row_data = [
            $row['emp_id'],
            $row['calling_name'] . " (" . $row['vehicle_no'] . ")",
            $row['no_of_attendance'] ?? 0,
            number_format($row['total_distance'] ?? 0, 2, '.', ''), // Format distance
            number_format($row['final_payment'] ?? 0, 2, '.', '')    // Format payment
        ];
        
        // Write the data row to the CSV file
        fputcsv($output, $row_data);
    }
}

// Close the file stream and database connection
fclose($output);
$stmt->close();
$conn->close();

// Stop script execution after sending the file
exit;
?>