<?php
// Report all errors for better debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Assuming 'includes/db.php' returns a MySQLi connection object $conn
// NOTE: Make sure 'includes/db.php' is present and works correctly
include('includes/db.php');

// --- 1. CONFIGURATION ---
// *මේවා ඔබගේ සැබෑ අගයන්ට වෙනස් කරන්න*

// Target API (DB2) Details
$target_api_url = "https://bitzit.com.lk/api/post_users.php"; // DB2 API URL එක
$api_key = "9c4e1a7f3d2b8e6f4a0c59b7d1e2f8a3"; // DB2 API යතුර

// --- 2. FUNCTIONS ---

/**
 * DB1 හි Employee Table එකෙන් අවශ්‍ය සියලුම දත්ත ලබා ගනී.
 * Migration තත්ත්වය නොසලකා සියලුම වාර්තා ලබා ගනී.
 * @param mysqli $conn - DB1 සම්බන්ධතාවය (Assumed MySQLi connection)
 * @return array - දත්ත අරායක්
 */
function get_user_data_to_sync($conn) {
    // User Table (U) එකේ සියලුම දත්ත තෝරා ගනී.
    $sql = "SELECT
                U.emp_id,
                U.pin,
                U.purpose,
                E.calling_name,
                U.qr_token,
                U.token_status,
                U.route_code
            FROM
                user U 
            INNER JOIN
                employee E ON U.emp_id = E.emp_id  
            "; 

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("MySQLi Prepare failed: " . $conn->error);
    }

    $stmt->execute();

    // MySQLi result set එක ලබා ගැනීම
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    $stmt->close();

    return $data;
}

/**
 * DB2 API එකට දත්ත POST Request එකක් ලෙස යවයි. (CURL Logic)
 */
function post_data_to_api($api_url, $api_key, $data_array) {
    // ⚠️ NOTE: $data_array is now the full payload including 'active_emp_ids'
    $json_data = json_encode($data_array);

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
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


// --- 4. MAIN EXECUTION ---

echo "Starting FULL USER data synchronization process... " . date("Y-m-d H:i:s") . "\n";

// Get all data from DB1
$raw_user_data = get_user_data_to_sync($conn);

if (empty($raw_user_data)) {
    echo "No user data found in DB1. Exiting.\n";
    // Close the connection
    if ($conn instanceof mysqli) {
        $conn->close();
    }
    exit;
}

echo "Found " . count($raw_user_data) . " user records to transfer/sync.\n";

// DB2 API එකට යැවීමට අවශ්‍ය පරිදි දත්ත සකස් කිරීම
$user_data_to_post = [];
$active_emp_ids = []; // 👈 NEW: Array to store all active emp_ids

foreach ($raw_user_data as $row) {
    // User Table සඳහා තීරු (Data for Upsert)
    $user_data_to_post[] = [
        'emp_id' => $row['emp_id'],
        'calling_name' => $row['calling_name'],
        'route_code' => $row['route_code'],
        'pin' => $row['pin'],
        'purpose' => $row['purpose'],
        'qr_token' => $row['qr_token'],
        'token_status' => $row['token_status'],
    ];
    
    // 👈 NEW: Collect the ID for deletion check
    $active_emp_ids[] = $row['emp_id']; 
}

// 💥 Wrap the data into a single payload for the API
$payload = [
    'users' => $user_data_to_post,
    'active_emp_ids' => $active_emp_ids // This tells the API which IDs MUST be kept
];


// Post data to DB2 API
// ⚠️ NOTE: We send the full $payload now
$api_response = post_data_to_api($target_api_url, $api_key, $payload);

echo "API Response Code: " . $api_response['code'] . "\n";

// The API is expected to handle upsert (INSERT/UPDATE) and DELETION
if ($api_response['code'] === 201) {
    echo "✅ Success! All " . count($raw_user_data) . " records sent for upsert/sync.\n";
    echo "API Response Message: " . ($api_response['response']['message'] ?? 'Data Created/Updated/Cleaned') . "\n";
    
} else {
    echo "❌ User Sync Failed! \n";
    echo "Error Details: " . print_r($api_response['response'], true) . "\n";
}

// Close the connection
if ($conn instanceof mysqli) {
    $conn->close();
}
?>