<?php
require_once '../../includes/session_check.php';
include('../../includes/db.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code']) && isset($_POST['category']) && isset($_POST['val'])) {
    
    $code = $conn->real_escape_string($_POST['code']);
    $category = $conn->real_escape_string($_POST['category']); // 'Main' or 'Sub'
    $val = (float)$_POST['val'];

    $table = ($category === 'Main') ? 'route' : 'sub_route';
    $code_col = ($category === 'Main') ? 'route_code' : 'sub_route_code';

    $sql = "UPDATE `$table` SET `monthly_allocate` = $val WHERE `$code_col` = '$code'";
    
    if ($conn->query($sql) === TRUE) {
        echo "Success";
    } else {
        echo "Error: " . $conn->error;
    }
} else {
    echo "Invalid request";
}

$conn->close();
?>