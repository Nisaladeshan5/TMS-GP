<?php

// fetch_and_store.php

// Include the configuration file for database and API credentials
include 'db.php'; 

// API endpoint URL
$apiUrl = 'https://gpgarmentsapi.peopleshr.com/api/commonapi/getinformation?id=2&date1&date2&empnumber=******'; 

// Initialize cURL session
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC); // Use Basic Authentication
curl_setopt($ch, CURLOPT_USERPWD, "$api_username:$api_password"); // Set the username and password
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// Execute cURL request
$apiResponse = curl_exec($ch);

// Check for cURL errors
if (curl_errno($ch)) {
    die("cURL Error: " . curl_error($ch));
}

// Close cURL session
curl_close($ch);

// Decode the JSON data
$data = json_decode($apiResponse, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die('Error decoding JSON data.');
}

// The rest of the script is the same for database operations
// Create database connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Step 1: Get all current GP emp_ids from the database
$existing_gp_emp_ids = [];
$result = $conn->query("SELECT emp_id FROM employee");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $existing_gp_emp_ids[] = strtoupper(trim($row['emp_id'])); // Normalize
    }
    $result->free();
}

$active_gp_emp_ids = [];

if (!empty($data)) {
    $sql = "INSERT INTO employee (emp_id, calling_name, department, near_bus_stop, direct, gender, route) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE calling_name = ?";
    $stmt_insert = $conn->prepare($sql);
    
    if ($stmt_insert) {
        $stmt_insert->bind_param("ssssssss", $empId, $callingName, $department, $near_bus_stop, $direct, $gender, $route, $callingName);

        foreach ($data as $record) {
            if (isset($record['Active']) && strtoupper(trim($record['Active'])) === 'ACTIVE') {
                
                $callingName = trim($record['Alias']);
                $department = trim($record['Cost Centre']);
                $near_bus_stop = trim($record['Nearest Bus or Railway Stop']);
                $direct = trim($record['Direct Status']);
                $gender = trim($record['Gender']);
                $route = trim($record['Commuting Route']);
                $empId = strtoupper(trim($record['EPF No']));

                $active_gp_emp_ids[] = $empId;

                if (!$stmt_insert->execute()) {
                    echo "Error executing insert/update for $empId: " . $stmt_insert->error . "<br>";
                }
            }
        }
        $stmt_insert->close();
    } else {
        echo "Error preparing insert statement: " . $conn->error . "<br>";
    }
}

// Step 2: Identify and delete inactive employees
$existing_gp_emp_ids = array_unique($existing_gp_emp_ids);
$active_gp_emp_ids = array_unique($active_gp_emp_ids);

// Debugging arrays
echo "<pre>";
echo "Existing GP IDs in DB:\n";
print_r($existing_gp_emp_ids);
echo "\nActive GP IDs from file:\n";
print_r($active_gp_emp_ids);
echo "</pre>";

$emp_ids_to_delete = array_diff($existing_gp_emp_ids, $active_gp_emp_ids);

// Extra debug — show why not deleted
if (empty($emp_ids_to_delete)) {
    echo "No employees to delete. All GP IDs in DB are still active.<br>";
} else {
    echo "Attempting to delete the following GP employee IDs: <br>";
    print_r($emp_ids_to_delete);
    echo "<br>";

    $placeholders = implode(',', array_fill(0, count($emp_ids_to_delete), '?'));
    $sql_delete = "DELETE FROM employee WHERE emp_id IN ($placeholders)";
    
    $stmt_delete = $conn->prepare($sql_delete);
    
    if ($stmt_delete) {
        $types = str_repeat('s', count($emp_ids_to_delete));
        $stmt_delete->bind_param($types, ...$emp_ids_to_delete);

        if ($stmt_delete->execute()) {
            $rows_affected = $stmt_delete->affected_rows;
            echo "Successfully deleted $rows_affected inactive employees.<br>";
        } else {
            echo "Error deleting employees: " . $stmt_delete->error . "<br>";
        }
        $stmt_delete->close();
    } else {
        echo "Error preparing delete statement: " . $conn->error . "<br>";
    }
}


// Close the database connection
$conn->close();

echo "Data successfully fetched and stored for active employees.";

?>