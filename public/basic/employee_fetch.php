<?php
// fetch_and_store_truncate_simplified.php

// Include the configuration file, which also establishes the $conn variable
include 'db.php'; 

// --- Configuration ---
$tableName = 'employee';
$apiUrl = 'https://gpgarmentsapi.peopleshr.com/api/commonapi/getinformation?id=2&date1&date2&empnumber=******'; // ⚠️ Set your actual API endpoint

// 1. Check if $conn was successfully established in db.php
// (The die() in db.php should handle failures, but this is a safety check)
if (!isset($conn) || $conn->connect_error) {
    die("FATAL ERROR: Database connection failed in db.php.");
}


// --- 2. TRUNCATE the Table (Delete All Data) ---
// This step clears all existing details.
$sql_truncate = "TRUNCATE TABLE $tableName";

if ($conn->query($sql_truncate) === TRUE) {
    echo "✅ Success! All existing data from table '$tableName' has been deleted (Truncated).\n\n";
} else {
    // If truncate fails, we must stop, as the refresh logic won't work correctly.
    $conn->close();
    die("❌ FATAL ERROR: Could not truncate table '$tableName': " . $conn->error);
}


// --- 3. Fetch Data from API using cURL ---
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => "$api_username:$api_password", // API creds from db.php
    CURLOPT_SSL_VERIFYPEER => false,
]);

$apiResponse = curl_exec($ch);

if (curl_errno($ch)) {
    $curlError = curl_error($ch);
    curl_close($ch);
    $conn->close();
    die("cURL Error: " . $curlError);
}
curl_close($ch);

$data = json_decode($apiResponse, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $conn->close();
    die('Error decoding JSON data: ' . json_last_error_msg());
}

if (empty($data)) {
    echo "API returned no data. Table remains empty.\n";
    $conn->close();
    exit();
}

// --- 4. Insert New/Active Employees ---

$insert_count = 0;
$error_count = 0;

// Prepare the statement for inserting new employees
$sql_insert = "INSERT INTO employee (emp_id, calling_name, department, near_bus_stop, direct, gender, route) 
               VALUES (?, ?, ?, ?, ?, ?, ?)";
            
$stmt_insert = $conn->prepare($sql_insert);

if ($stmt_insert) {
    $stmt_insert->bind_param("sssssss", $empId, $callingName, $department, $near_bus_stop, $direct, $gender, $route);

    foreach ($data as $record) {
        // Only process ACTIVE records
        if (isset($record['Active']) && strtoupper(trim($record['Active'])) === 'ACTIVE') {
            
            // Assign variables from API data
            $callingName = trim($record['Alias']);
            $department = trim($record['Cost Centre']);
            $near_bus_stop = trim($record['Nearest Bus or Railway Stop']);
            $direct = trim($record['Direct Status']);
            $gender = trim($record['Gender']);
            $route = trim($record['Commuting Route']);
            $empId = strtoupper(trim($record['EPF No']));

            if ($stmt_insert->execute()) {
                $insert_count++;
            } else {
                echo "Error executing insert for $empId: " . $stmt_insert->error . "<br>";
                $error_count++;
            }
        }
    }
    $stmt_insert->close();
    
    echo "--- Insert Results ---\n";
    echo "Inserted: $insert_count active employees.\n";
    if ($error_count > 0) {
        echo "Errors: $error_count encountered during insert.\n";
    }

} else {
    echo "Error preparing insert statement: " . $conn->error . "<br>";
}

// --- 5. Close Connection and Finish ---
$conn->close();

echo "\nScript finished. Table successfully refreshed with active employee data. 🚀";

?>