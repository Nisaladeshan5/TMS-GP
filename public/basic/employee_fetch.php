<?php
// fetch_and_store_upsert.php

include 'db.php'; 

// --- Configuration ---
$tableName = 'employee';
$apiUrl = 'https://gpgarmentsapi.peopleshr.com/api/commonapi/getinformation?id=2&date1&date2&empnumber=******'; 

// 1. Check Database Connection
if (!isset($conn) || $conn->connect_error) {
    die("FATAL ERROR: Database connection failed in db.php.");
}

// --- 2. Fetch Data from API ---
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => "$api_username:$api_password",
    CURLOPT_SSL_VERIFYPEER => false,
]);

$apiResponse = curl_exec($ch);

if (curl_errno($ch)) {
    echo 'cURL Error: ' . curl_error($ch);
    curl_close($ch);
    exit;
}
curl_close($ch);

$data = json_decode($apiResponse, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error decoding JSON: " . json_last_error_msg());
}

if (empty($data)) {
    echo "API returned no data. No changes made.\n";
    $conn->close();
    exit();
}

// --- 3. UPSERT (Update OR Insert) ---

$upsert_count = 0;
$error_count = 0;

// UPDATED SQL: Added to_home_distance and vacated
$sql_upsert = "INSERT INTO employee (emp_id, calling_name, department, near_bus_stop, direct, gender, route, line, emp_category, is_active, to_home_distance, vacated) 
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE 
               calling_name = VALUES(calling_name), 
               department = VALUES(department), 
               near_bus_stop = VALUES(near_bus_stop), 
               direct = VALUES(direct), 
               gender = VALUES(gender), 
               route = VALUES(route),
               line = VALUES(line),
               emp_category = VALUES(emp_category),
               is_active = VALUES(is_active),
               to_home_distance = VALUES(to_home_distance),
               vacated = VALUES(vacated)"; 
            
$stmt_upsert = $conn->prepare($sql_upsert);

if ($stmt_upsert) {
    // UPDATED bind_param:
    // Added 'di' at the end: 'd' for distance (double/float), 'i' for vacated (integer)
    // Format: sssssssssi di -> sssssssssi di (Total 12 parameters)
    $stmt_upsert->bind_param("sssssssssidi", $empId, $callingName, $department, $near_bus_stop, $direct, $gender, $route, $line, $empCategory, $isActive, $distance, $vacated);

    foreach ($data as $record) {
        
        // 1. Determine Active Status
        $isActive = (isset($record['Active']) && strtoupper(trim($record['Active'])) === 'ACTIVE') ? 1 : 0;

        // 2. Determine Vacated Status (1 if 'Vacated', 0 if empty/else)
        $vacated = (isset($record['Vacated']) && strtoupper(trim($record['Vacated'])) === 'VACATED') ? 1 : 0;

        // 3. Assign variables
        $callingName   = trim($record['Alias'] ?? '');
        $department    = trim($record['Cost Centre'] ?? '');
        $near_bus_stop = trim($record['Nearest Bus or Railway Stop'] ?? '');
        $direct        = trim($record['Direct Status'] ?? '');
        $gender        = trim($record['Gender'] ?? '');
        $route         = trim($record['Commuting Route'] ?? '');
        $empId         = strtoupper(trim($record['EPF No'] ?? ''));
        $line          = trim($record['Band Id'] ?? '');
        $empCategory   = trim($record['Emp Category'] ?? '');
        
        // Map Distance (Ensuring it's treated as a number)
        $distance      = isset($record['Distance from Residence to work KM']) ? (float)$record['Distance from Residence to work KM'] : 0.0;

        // 4. Execute
        if ($stmt_upsert->execute()) {
            $upsert_count++;
        } else {
            echo "Error executing UPSERT for $empId: " . $stmt_upsert->error . "<br>";
            $error_count++;
        }
    }
    $stmt_upsert->close();

    echo "\n--- Refresh Results ---\n";
    echo "Total records processed: $upsert_count.\n";
    if ($error_count > 0) {
        echo "Errors: $error_count encountered.\n";
    }

} else {
    echo "Error preparing UPSERT statement: " . $conn->error . "<br>";
}

$conn->close();
echo "\nScript finished. Distance and Vacated status updated! 🚀";
?>