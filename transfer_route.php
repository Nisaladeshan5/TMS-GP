<?php
// Report all errors for better debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Assuming 'includes/db.php' returns a MySQLi connection object $conn
include('includes/db.php'); 

// --- 2. Target API Details ---
$target_api_url = "https://bitzit.com.lk/api/db2_api.php"; // DB2 API URL
$api_key = "165a7b907edc8b6ca906aaac2aef8646badd67586cbb817034df6bbffb720049"; // DB2 API එකට කලින් සෙට් කළ යතුර

// --- 3. FUNCTIONS ---

/**
 * DB1 හි Tables තුනක් Join කර අවශ්‍ය සියලුම දත්ත ලබා ගනී.
 * Migration තත්ත්වය නොසලකා සියලුම වාර්තා ලබා ගනී.
 * @param mysqli $conn - DB1 සම්බන්ධතාවය (Assumed MySQLi connection)
 * @return array - දත්ත අරායක්
 */
function get_data_to_sync($conn) {
    // Note: R.id is NOT selected, as route_code is the primary key used for tracking.
    $sql = "SELECT
                R.route_code, 
                R.route, 
                R.vehicle_no, 
                V.driver_NIC, 
                D.calling_name 
            FROM 
                route R
            INNER JOIN 
                vehicle V ON R.vehicle_no = V.vehicle_no
            INNER JOIN 
                driver D ON V.driver_NIC = D.driver_NIC
            -- WHERE R.is_migrated = 0 -- REMOVED: Now transfers all records for sync
            "; 
            
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        // Handle prepare error
        die("MySQLi Prepare failed: " . $conn->error);
    }
    
    $stmt->execute();
    
    $result = $stmt->get_result(); // Get the result set object
    
    $data = [];
    while ($row = $result->fetch_assoc()) { // Fetch all rows into an array
        $data[] = $row;
    }
    
    $stmt->close();
    
    return $data;
}

/**
 * DB2 API එකට දත්ත POST Request එකක් ලෙස යවයි.
 * @param string $api_url - API URL
 * @param string $api_key - Authentication Key
 * @param array $data_array - යැවිය යුතු දත්ත අරාය
 * @return array - API වෙතින් ලැබෙන ප්‍රතිචාරය සහ HTTP code එක
 */
function post_data_to_api($api_url, $api_key, $data_array) {
    $json_data = json_encode($data_array);

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        // Authorization Header එක හරහා API Key එක යවයි
        'Authorization: Bearer ' . $api_key 
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return ['code' => 0, 'response' => ['status' => 'error', 'message' => "CURL Error: {$error_msg}"]];
    }
    
    curl_close($ch);
    return ['code' => $http_code, 'response' => json_decode($response, true)];
}

// *** The mark_as_migrated function has been REMOVED as requested ***


// --- 4. MAIN EXECUTION ---

echo "Starting FULL route data synchronization process... " . date("Y-m-d H:i:s") . "\n";

// Get ALL data from DB1 for synchronization
$raw_data = get_data_to_sync($conn);

if (empty($raw_data)) {
    echo "No route data found in DB1. Exiting.\n";
    
    // Close the connection if it's a MySQLi object
    if ($conn instanceof mysqli) {
        $conn->close();
    }
    exit;
}

echo "Found " . count($raw_data) . " records to transfer/sync.\n";

// DB2 API එකට යැවීමට අවශ්‍ය පරිදි දත්ත සකස් කිරීම
$data_to_post = [];
foreach ($raw_data as $row) {
    // Route Table සහ User Table දෙකටම අවශ්‍ය දත්ත සකස් කිරීම
    $data_to_post[] = [
        // Route Table සඳහා තීරු
        'route_code' => $row['route_code'],
        'route' => $row['route'],
        'vehicle_no' => $row['vehicle_no'],
        'driver_NIC' => $row['driver_NIC'],
        'calling_name' => $row['calling_name'],
    ];
}

// API වෙත දත්ත යැවීම
$api_response = post_data_to_api($target_api_url, $api_key, $data_to_post);

echo "API Response Code: " . $api_response['code'] . "\n";

// The API is expected to handle both new records (INSERT) and existing records (UPDATE) via route_code
if ($api_response['code'] === 201) {
    echo "✅ Success! All " . count($raw_data) . " route records sent for upsert/sync.\n";
    echo "API Response Message: " . ($api_response['response']['message'] ?? 'Data Created/Updated') . "\n";
    
    // *** mark_as_migrated call has been REMOVED ***
} else {
    echo "❌ Route Sync Failed! \n";
    echo "Error Details: " . print_r($api_response['response'], true) . "\n";
}

// Close the connection if it's a MySQLi object
if ($conn instanceof mysqli) {
    $conn->close();
}
?>