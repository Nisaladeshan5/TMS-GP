<?php
// process_fetch_route_employees.php
include('../../../../includes/db.php');

if (isset($_POST['route_code'])) {
    // JavaScript එකෙන් මෙතැනට එන්නේ Route එකක් හෝ Sub Route එකක් විය හැකියි.
    $code = $conn->real_escape_string(trim($_POST['route_code']));
    
    /**
     * LOGIC: 
     * 1. employee table එකේ 'route' column එකේ මුල් අකුරු 10 සමානදැයි බලන්න. (පරණ Route logic එක)
     * 2. හෝ (OR) 'sub_route_code' column එක තුළ අදාළ කෝඩ් එක කොමා (,) වලින් වෙන් කර තිබේදැයි බලන්න.
     * (FIND_IN_SET සහ REPLACE භාවිතා කර හිස්තැන් ඉවත් කර වඩාත් නිවැරදිව පරීක්ෂා කරයි)
     * 3. සේවකයා active (is_active = 1) විය යුතුයි.
     */
    $query = "SELECT emp_id FROM employee 
              WHERE (
                  LEFT(route, 10) = '$code' 
                  OR FIND_IN_SET('$code', REPLACE(sub_route_code, ' ', '')) > 0
                  OR sub_route_code LIKE '%$code%'
              )
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