<?php
// simple_trip_entry_english.php
// Vehicle No, From, To, Description, Distance (Input), Done=0 fields. Date & Time are captured automatically.

// --- Configuration & Includes ---
// Ensure these paths are correct for your environment
include('../../../../includes/db.php'); // DB connection
include('../../../../includes/header.php'); 
include('../../../../includes/navbar.php'); 

// Set timezone for accurate current date/time values
date_default_timezone_set('Asia/Colombo');

$message = ""; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Sanitize the data for the 5 manual input fields
    $vehicle_no = trim($_POST['vehicle_no'] ?? '');
    $from_location = trim($_POST['from_location'] ?? '');
    $to_location = trim($_POST['to_location'] ?? '');
    $description = trim($_POST['description'] ?? ''); 
    $distance_input = (float)($_POST['distance'] ?? 0.00); 
    
    // --- AUTOMATIC VALUES ---
    $done_flag = 0;         // Fixed to 0 (PENDING)
    $trip_date = date('Y-m-d'); // Current Date is automatically captured
    $trip_time = date('H:i:s'); // Current Time is automatically captured 
    // ---

    $skip_execution = false;
    $missing = [];
    
    // *** VALIDATION CHECK (5 input fields are mandatory) ***
    if (empty($vehicle_no)) $missing[] = 'Vehicle No';
    if (empty($from_location)) $missing[] = 'From Location';
    if (empty($to_location)) $missing[] = 'To Location';
    if (empty($description)) $missing[] = 'Description';
    
    // Validate Distance
    if ($distance_input < 0 || !is_numeric($distance_input)) {
         $missing[] = 'Distance (Invalid or missing)';
    }

    if (!empty($missing) && !$skip_execution) {
        $message = "<div class='bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative text-sm font-bold' role='alert'>
                        🚨 Missing values: <br>
                        <span class='font-normal'>" . implode(', ', $missing) . "</span>
                    </div>";
        $skip_execution = true; 
    } 
    // *** END VALIDATION CHECK ***
    
    if (!$skip_execution) {
        
        try {
            // !!! TABLE NAME and COLUMN LIST MUST MATCH YOUR ACTUAL DATABASE !!!
            // (vehicle_no, from, to, date, time, description, distance, done) - 8 fields
            // NOTE: 'from' and 'to' are reserved keywords in SQL. Using them as column names can cause errors. 
            // Ensure your actual column names are correctly used here (e.g., 'from_location', 'to_location'). 
            // Since your previous code used 'from' and 'to' in the SQL query, I'll keep it for continuity, 
            // but be cautious of potential SQL errors.
            $sql_main = "INSERT INTO temp_extra 
                         (vehicle_no, `from`, `to`, `date`, `time`, `description`, `distance`, `done`) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"; 
            
            // Assuming $conn is a valid mysqli connection object
            $stmt_main = $conn->prepare($sql_main);
            
            // Binding parameters: s(v_no), s(from), s(to), s(date_auto), s(time_auto), s(desc), d(distance), i(done - 0)
            $stmt_main->bind_param("ssssssdi", 
                $vehicle_no, 
                $from_location, 
                $to_location, 
                $trip_date, // Auto-captured
                $trip_time, // Auto-captured
                $description, 
                $distance_input, 
                $done_flag 
            );

            if (!$stmt_main->execute()) {
                throw new Exception("Error inserting simple trip details: " . $stmt_main->error);
            }

            $trip_id = $conn->insert_id;
            $stmt_main->close();

            $message = "<div class='bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative text-sm' role='alert'>✅ Simple Trip (ID: {$trip_id}) successfully added! Status: **PENDING (Done=0)**. (Auto Date: {$trip_date} / Auto Time: {$trip_time})</div>";
            
            // Clear inputs after successful submission (Optional)
            unset($_POST); 

        } catch (Exception $e) {
            // Use '`' around reserved words like `from` and `to` in SQL to potentially fix issues.
            // If the error persists, you must change your database column names.
            $message = "<div class='bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative text-sm' role='alert'>❌ Database Error: " . $e->getMessage() . "</div>";
            error_log($e->getMessage()); 
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Trip Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen">

<div class="w-[85%] ml-[15%]">
    <div class=" bg-white p-6 rounded-xl shadow-xl space-y-6 max-w-4xl mx-auto border border-blue-100 mt-10">
        
        <h1 class="text-3xl font-extrabold text-gray-800 mb-2 text-center text-blue-700">🚗 Vehicle Trip Data Entry</h1>
        <p class="text-center text-blue-500 text-sm mb-6 font-semibold">
            Date & Time automatically captured.
        </p>
        
        <div class="mb-4 max-w-4xl mx-auto">
            <?php echo $message; ?>
        </div>

        <form method="POST" action="" class="p-6 rounded-xl space-y-6 max-w-4xl mx-auto">
            
            <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                <h3 class="text-xl font-bold border-b pb-2 mb-4 text-blue-800 flex items-center">
                    Trip Details
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <?php 
                        $input_class = "mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 text-sm focus:ring-blue-500 focus:border-blue-500 transition duration-150"; 
                        $label_class = "block text-xs font-medium text-gray-700"; 
                        $required_span = "<span class='text-blue-500'>*</span>"; 
                    ?>
                    
                    <div>
                        <label for="vehicle_no" class="<?php echo $label_class; ?>">Vehicle No <?php echo $required_span; ?></label>
                        <input type="text" id="vehicle_no" name="vehicle_no" required class="<?php echo $input_class; ?>">
                    </div>
                    
                    <div>
                        <label for="distance" class="<?php echo $label_class; ?>">Distance (Km) <?php echo $required_span; ?></label>
                        <input type="number" step="0.01" min="0" id="distance" name="distance" required class="<?php echo $input_class; ?>" placeholder="e.g. 15.50">
                    </div>

                    <div>
                        <label for="from_location" class="<?php echo $label_class; ?>">From Location <?php echo $required_span; ?></label>
                        <input type="text" id="from_location" name="from_location" required class="<?php echo $input_class; ?>">
                    </div>
                    
                    <div>
                        <label for="to_location" class="<?php echo $label_class; ?>">To Location <?php echo $required_span; ?></label>
                        <input type="text" id="to_location" name="to_location" required class="<?php echo $input_class; ?>">
                    </div>

                    <div class="md:col-span-2">
                        <label for="description" class="<?php echo $label_class; ?>">Description (Who/Why) <?php echo $required_span; ?></label>
                        <input 
                            type="text" 
                            id="description" 
                            name="description" 
                            required 
                            class="<?php echo $input_class; ?>" 
                            placeholder="e.g. Urgent document transport / Meeting at Colombo"
                        >
                    </div>
                    
                </div>
            </div>
            
            <div class="pt-4 border-t border-gray-200 mt-6 flex justify-between space-x-4">
                <button 
                    type="button" 
                    onclick="window.location.href='../../extra_vehicle.php';"
                    class="bg-gray-300 text-gray-800 px-5 py-2.5 rounded-lg hover:bg-gray-400 transition duration-150 ease-in-out font-semibold text-md shadow-md transform hover:scale-[1.02] active:scale-[0.98]"
                >
                    ⬅️ Back 
                </button>

                <button 
                    type="submit" 
                    class="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700 transition duration-150 ease-in-out font-semibold text-md shadow-lg transform hover:scale-[1.02] active:scale-[0.98]"
                >
                    💾 Save
                </button>
            </div>
        </form>
    </div>
</div>

</body>
</html>