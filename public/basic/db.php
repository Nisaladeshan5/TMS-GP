<?php
// db_config.php
// Database connection parameters
define('DB_SERVER', 'localhost'); // Your database server, usually 'localhost'
define('DB_USERNAME', 'root');   // Your database username
define('DB_PASSWORD', '');       // Your database password
define('DB_NAME', 'transport'); // The database name we created

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// API Credentials
$api_username = 'gpg_resq'; 
$api_password = 'rF8aX4pZ7oO0vG2k';

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4 for proper emoji and special character handling
$conn->set_charset("utf8mb4");

// Function to close the database connection (optional, but good practice)
function closeDbConnection($conn) {
    if ($conn) {
        $conn->close();
    }
}
?>
