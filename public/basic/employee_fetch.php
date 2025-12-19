<?php
// fetch_and_store_upsert.php

// Include the configuration file, which also establishes the $conn variable
include 'db.php'; 

// --- Configuration ---
$tableName = 'employee';
$apiUrl = 'https://gpgarmentsapi.peopleshr.com/api/commonapi/getinformation?id=2&date1&date2&empnumber=******'; // ⚠️ Set your actual API endpoint

// 1. Check Database Connection
if (!isset($conn) || $conn->connect_error) {
    die("FATAL ERROR: Database connection failed in db.php.");
}

// --- 2. NO TRUNCATE STEP HERE! Data is safe until the insert is successful. ---


// --- 3. Fetch Data from API using cURL (Same as before) ---
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => "$api_username:$api_password", // API creds from db.php
    CURLOPT_SSL_VERIFYPEER => false,
]);

$apiResponse = curl_exec($ch);

// ... (Error handling for cURL, JSON decode - same as before) ...
if (curl_errno($ch)) { /* ... */ }
curl_close($ch);
$data = json_decode($apiResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) { /* ... */ }
if (empty($data)) {
    echo "API returned no data. Existing table data is preserved.\n";
    $conn->close();
    exit();
}


// --- 4. UPSERT (Update OR Insert) New/Active Employees ---

$upsert_count = 0;
$error_count = 0;
$active_emp_ids = []; // To track all currently active employees from API

// The core change: Use ON DUPLICATE KEY UPDATE
// If emp_id already exists, it UPDATES the other fields.
// If emp_id is new, it INSERTs a new row.
$sql_upsert = "INSERT INTO employee (emp_id, calling_name, department, near_bus_stop, direct, gender, route) 
               VALUES (?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE 
               calling_name = VALUES(calling_name), 
               department = VALUES(department), 
               near_bus_stop = VALUES(near_bus_stop), 
               direct = VALUES(direct), 
               gender = VALUES(gender), 
               route = VALUES(route)"; 
           
$stmt_upsert = $conn->prepare($sql_upsert);

if ($stmt_upsert) {
    $stmt_upsert->bind_param("sssssss", $empId, $callingName, $department, $near_bus_stop, $direct, $gender, $route);

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
            $empId = strtoupper(trim($record['EPF No'])); // This is the key field!
            
            $active_emp_ids[] = $empId; // Track this ID

            if ($stmt_upsert->execute()) {
                // MySQL's execute() returns 1 for INSERT, 2 for UPDATE
                // We'll just count all successful operations
                $upsert_count++;
            } else {
                echo "Error executing UPSERT for $empId: " . $stmt_upsert->error . "<br>";
                $error_count++;
            }
        }
    }
    $stmt_upsert->close();
    
    // --- 5. Handle employees who are no longer ACTIVE (i.e., they've left) ---
    // If the API only provides active employees, you need to remove the ones 
    // in your table that were NOT in the new API data.

    if (!empty($active_emp_ids)) {
        // Create a comma-separated list of EPF Nos to exclude from deletion
        $id_list = "'" . implode("', '", $active_emp_ids) . "'";
        
        // Delete records from the table where emp_id is NOT in the new active list
        $sql_delete_inactive = "DELETE FROM $tableName WHERE emp_id NOT IN ($id_list)";
        
        if ($conn->query($sql_delete_inactive)) {
            $deleted_count = $conn->affected_rows;
            echo "\n✅ Success! Deleted $deleted_count inactive employee records from the table.\n";
        } else {
            echo "\n❌ Warning: Could not delete inactive records: " . $conn->error . "\n";
        }
    }


    echo "\n--- Refresh Results ---\n";
    echo "Total records processed (Insert/Update): $upsert_count.\n";
    if ($error_count > 0) {
        echo "Errors: $error_count encountered during process.\n";
    }

} else {
    echo "Error preparing UPSERT statement: " . $conn->error . "<br>";
}

// --- 6. Close Connection and Finish ---
$conn->close();

echo "\nScript finished. Table successfully refreshed using safe UPSERT strategy. 🚀";

?>