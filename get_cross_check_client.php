<?php
// get_cross_check_client.php (DB1 Server Script - FINAL VERSION WITH DB INSERTION)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- DB1 LOCAL DATABASE CONNECTION ---
// ⚠️ Set your actual DB1 connection details (WAMP/Local) ⚠️
$db_host_local = "localhost"; 
$db_name_local = "transport"; 
$db_user_local = "root";       
$db_pass_local = "";       
$db_port_local = 3306; 

$conn_local = new mysqli($db_host_local, $db_user_local, $db_pass_local, $db_name_local, $db_port_local);

if ($conn_local->connect_error) {
    die("❌ Fatal Error: DB1 Local Database connection failed: " . $conn_local->connect_error);
}

// --- CONFIGURATION ---
$target_get_api_base_url = "https://bitzit.com.lk/api/get_cross_check.php";
$api_key = "b7e4c9d2a1f6e8b34c0a59f7d2e3a9c1"; 
$sync_file = 'C:\wamp64\www\TMS\cross_check_last_id.txt'; 
$local_table = 'cross_check'; // 🔑 Local table name

// --- FILE/SYNC FUNCTIONS ---

function get_last_synced_id() {
    global $sync_file;
    if (file_exists($sync_file)) {
        $id = (int)trim(file_get_contents($sync_file));
        return max(0, $id);
    }
    file_put_contents($sync_file, 0); 
    return 0; 
}

function update_last_synced_id($new_id) {
    global $sync_file;
    file_put_contents($sync_file, $new_id);
    echo "✅ Local Sync File Updated to ID: {$new_id}\n";
}

function get_data_from_api($api_url) {
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET"); 
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        curl_close($ch);
        return ['success' => false, 'message' => "CURL Error: " . curl_error($ch), 'new_last_id' => 0];
    }
    curl_close($ch);
    $data = json_decode($response, true);
    
    if ($http_code === 200 && ($data['status'] ?? '') === 'success') {
        return ['success' => true, 'data' => $data['data'], 'new_last_id' => $data['new_last_id'] ?? 0];
    } else {
        $message = $data['message'] ?? 'Unknown API Error';
        return ['success' => false, 'message' => "API Error (Code: {$http_code}): {$message}", 'new_last_id' => 0];
    }
}


// --- MAIN EXECUTION ---
echo "Starting Cross Check data retrieval... " . date("Y-m-d H:i:s") . "\n";

$last_synced_id = get_last_synced_id();
$target_url = $target_get_api_base_url . "?last_id=" . $last_synced_id . "&auth_key=" . $api_key;

$api_results = get_data_from_api($target_url);

if ($api_results['success']) {
    $cross_check_data = $api_results['data'];
    $count = count($cross_check_data);
    $new_last_id = $api_results['new_last_id'];
    $records_inserted = 0;
    
    if ($count > 0) {
        echo "✅ Success! Received {$count} NEW cross_check records. Inserting into local DB...\n";
        
        // --- DATABASE INSERTION LOGIC ---
        $sql = "INSERT INTO {$local_table} 
                (id, actual_vehicle_no, date, shift, driver_NIC, route, time, purpose)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                
        $stmt_insert = $conn_local->prepare($sql);
        
        if ($stmt_insert === false) {
             echo "❌ Database Prepare Failed for INSERT: " . $conn_local->error . "\n";
        } else {
             $stmt_insert->bind_param("isssssss", $id, $actual_vehicle_no, $date, $shift, $driver_NIC, $route, $time, $purpose);
             
             foreach ($cross_check_data as $row) {
                 $id = $row['id'];
                 $actual_vehicle_no = $row['actual_vehicle_no'];
                 $date = $row['date'];
                 $shift = $row['shift'];
                 $driver_NIC = $row['driver_NIC'];
                 $route = $row['route'];
                 $time = $row['time'];
                 $purpose = $row['purpose'];
                 
                 if ($stmt_insert->execute()) {
                     $records_inserted++;
                 } else {
                     if ($conn_local->errno == 1062) {
                         // Duplicate entry error
                         echo "⚠️ Warning: Record ID {$id} already exists (Skipped).\n";
                     } else {
                         echo "❌ DB Insert Error for ID {$id}: " . $stmt_insert->error . "\n";
                     }
                 }
             }
             $stmt_insert->close();
             echo "✅ Successfully inserted {$records_inserted} records.\n";
        }
        
        if ($new_last_id > $last_synced_id) {
            update_last_synced_id($new_last_id);
        }
        
    } else {
        echo "ℹ️ No new cross_check records found since ID {$last_synced_id}.\n";
    }
    
} else {
    echo "❌ Failed to retrieve cross_check data: " . $api_results['message'] . "\n";
}

$conn_local->close();
?>