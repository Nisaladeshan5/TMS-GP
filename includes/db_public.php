<?php
// db_config.php
// Database connection parameters
define('DB_SERVER1', 'localhost'); // Your database server, usually 'localhost'
define('DB_USERNAME1', 'root');   // Your database username
define('DB_PASSWORD1', '');       // Your database password
define('DB_NAME1', 'tms-public'); // The database name we created

// Attempt to connect to MySQL database
$conn_tms = new mysqli(DB_SERVER1, DB_USERNAME1, DB_PASSWORD1, DB_NAME1);

// API Credentials
$api_username = 'gpg_resq'; 
$api_password = 'rF8aX4pZ7oO0vG2k';

// Check connection
if ($conn_tms->connect_error) {
    die("Connection failed: " . $conn_tms->connect_error);
}

// Set charset to utf8mb4 for proper emoji and special character handling
$conn_tms->set_charset("utf8mb4");

// Function to close the database connection (optional, but good practice)
function closeDbTransportConnection() { // Changed the name
    global $conn_tms;
    if ($conn_tms) {
        $conn_tms->close();
    }
}
?>
