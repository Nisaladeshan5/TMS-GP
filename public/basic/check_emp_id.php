<?php
// check_employee.php (MODIFIED: Only checks if Employee ID EXISTS)

// Includes & Session Management
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in 
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php'); 

// Set the header for JSON response
header('Content-Type: application/json');

// Check connection
if ($conn->connect_error) {
    echo json_encode(['isValid' => false, 'message' => 'Database connection failed.', 'name' => null]);
    exit;
}

// Use $_GET as the AJAX call from add_own_vehicle.php uses a GET parameter
$emp_id = $_GET['emp_id'] ?? ''; 

if (empty($emp_id)) {
    echo json_encode(['isValid' => false, 'message' => 'Employee ID is required.', 'name' => null]);
    exit;
}

// --- 1. Check if Employee ID exists and get the name ---
$employee_name = null;
$sql_employee = "SELECT calling_name FROM employee WHERE emp_id = ?";
$stmt_employee = $conn->prepare($sql_employee);

if (!$stmt_employee) {
    $conn->close();
    echo json_encode(['isValid' => false, 'message' => 'Database preparation failed.', 'name' => null]);
    exit;
}

$stmt_employee->bind_param("s", $emp_id);
$stmt_employee->execute();
$result_employee = $stmt_employee->get_result();

if ($result_employee->num_rows > 0) {
    $row = $result_employee->fetch_assoc();
    $employee_name = htmlspecialchars($row['calling_name']);
    $stmt_employee->close();
    $conn->close();
    
    // --- Final Success Case (Only checks for Existence) ---
    // Employee exists, which is sufficient validation for adding a vehicle.
    echo json_encode([
        'isValid' => true, 
        'message' => "Valid Employee: {$employee_name}.",
        'name' => $employee_name
    ]);
    exit;

} else {
    // Employee ID does not exist at all
    $stmt_employee->close();
    $conn->close();
    echo json_encode(['isValid' => false, 'message' => 'Employee ID not found in the system.', 'name' => null]);
    exit;
}

// NOTE: The previous check for assignment in `own_vehicle` (Section 2) has been entirely removed, 
// allowing multiple vehicles to be assigned to the same valid employee.

?>