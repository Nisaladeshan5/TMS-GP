<?php
// Include the database connection
include('../../../includes/db.php'); 

header('Content-Type: application/json');

// Check connection
if ($conn->connect_error) {
    echo json_encode(['exists' => false, 'message' => 'Database connection failed.']);
    exit;
}

$emp_id = $_POST['emp_id'] ?? '';

if (empty($emp_id)) {
    echo json_encode(['exists' => false, 'message' => 'Employee ID is required.']);
    exit;
}

// Prepare and execute the query to check if the emp_id exists
$sql = "SELECT calling_name FROM employee WHERE emp_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $emp_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([
        'exists' => true, 
        'message' => 'Employee ID found.',
        'name' => htmlspecialchars($row['calling_name'])
    ]);
} else {
    echo json_encode([
        'exists' => false, 
        'message' => 'Employee ID not found.'
    ]);
}

$stmt->close();
$conn->close();
?>