<?php
// db.php
$host = 'localhost';
$dbname = 'transport'; // Replace with your database name
$username = 'root'; // Replace with your database username
$password = ''; // Replace with your database password

$conn = mysqli_connect($host, $username, $password, $dbname);
if(!$conn){
    die("Connection failed: " . mysqli_connect_error());
}
?>
