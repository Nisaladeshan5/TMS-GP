<?php
// fetch_employee_name.php
// Fetches employee name and route to auto-populate the form fields.

header('Content-Type: application/json');
// IMPORTANT: db.php MUST NOT echo or output anything besides the connection object
include('../../includes/db.php');

$response = ['calling_name' => null, 'route' => null];

if (isset($_GET['emp_id'])) {
    $emp_id = trim($_GET['emp_id']);

    // Assuming you have a separate 'employee' table with 'emp_id', 'calling_name', and 'route'
    // Adjust table and column names as necessary for your actual database schema.
    $sql = "SELECT calling_name, route FROM employee WHERE emp_id = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("s", $emp_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // Found employee, populate response
            $response['calling_name'] = $row['calling_name'];
            $response['route'] = $row['route'];
        }
        $stmt->close();
    }
}

$conn->close();
echo json_encode($response);
// Omit closing PHP tag