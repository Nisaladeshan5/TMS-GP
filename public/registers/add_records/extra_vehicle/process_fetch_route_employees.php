<?php
// process_fetch_route_employees.php
include('../../../../includes/db.php');

if (isset($_POST['route_code'])) {
    $route_code = $conn->real_escape_string($_POST['route_code']);
    
    /**
     * logic: 
     * 1. employee table එකේ 'route' column එකේ මුල් අකුරු 10 ලබාගන්න.
     * 2. එය අප තෝරාගත් $route_code එකට සමානදැයි බලන්න.
     * 3. සේවකයා active (is_active = 1) විය යුතුයි.
     */
    $query = "SELECT emp_id FROM employee 
              WHERE LEFT(route, 10) = '$route_code' 
              AND is_active = 1";
              
    $result = $conn->query($query);

    $employees = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row['emp_id'];
        }
    }

    header('Content-Type: application/json');
    echo json_encode($employees);
    exit;
}
?>