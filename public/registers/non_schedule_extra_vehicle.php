<?php
// non_schedule_extra_vehicle.php
// Displays all pending trips (done=0) and allows setting them to done=1.

// --- Configuration & Includes ---
// Ensure these paths are correct for your environment
include('../../includes/db.php'); // DB connection
include('../../includes/header.php'); 
include('../../includes/navbar.php'); 

$message = ""; 

// --- 1. HANDLE TRIP COMPLETION (UPDATE done=1) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_done'])) {
    
    // Sanitize the trip ID passed from the form
    $trip_id_to_update = intval($_POST['trip_id']);
    
    if ($trip_id_to_update > 0) {
        try {
            // Prepared statement to update the 'done' status
            $sql_update = "UPDATE temp_extra SET `done` = 1 WHERE id = ? AND `done` = 0";
            
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("i", $trip_id_to_update);
            
            if (!$stmt_update->execute()) {
                throw new Exception("Error updating trip status: " . $stmt_update->error);
            }

            if ($stmt_update->affected_rows > 0) {
                $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative text-sm font-bold' role='alert'>
                            ✅ Trip ID {$trip_id_to_update} marked as DONE.
                        </div>";
            } else {
                 $message = "<div class='bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative text-sm font-bold' role='alert'>
                            ⚠️ Trip ID {$trip_id_to_update} was already marked as done or does not exist.
                        </div>";
            }
            $stmt_update->close();

        } catch (Exception $e) {
            $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative text-sm' role='alert'>❌ Database Error: " . $e->getMessage() . "</div>";
            error_log($e->getMessage()); 
        }
    }
}
// --- END HANDLE TRIP COMPLETION ---


// --- 2. FETCH PENDING TRIPS (done=0) ---
$pending_trips = [];
try {
    $sql_select = "SELECT id, vehicle_no, `date`, `time`, `from`, `to`, `description`, `distance` 
                   FROM temp_extra 
                   WHERE `done` = 0 
                   ORDER BY `date` ASC, `time` ASC";
    
    $result = $conn->query($sql_select);

    if ($result === FALSE) {
        throw new Exception("Error fetching pending trips: " . $conn->error);
    }
    
    while ($row = $result->fetch_assoc()) {
        $pending_trips[] = $row;
    }
    
} catch (Exception $e) {
    $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative text-sm' role='alert'>❌ Fetch Error: " . $e->getMessage() . "</div>";
    error_log($e->getMessage()); 
}
// --- END FETCH PENDING TRIPS ---
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Non-Scheduled Extra Vehicle Trips</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen">
<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] fixed top-0 z-10">
    <div class="text-lg font-semibold ml-3">Extra Vehicle Register</div>
    <div class="flex gap-4">
        <a href="add_records/extra_vehicle/add_non_schedule_extra_vehicle.php" class="hover:text-yellow-400 font-medium transition duration-150">Add Non Schedule Extra</a>
        <a href="schedule_extra_vehicle.php" class="hover:text-yellow-400 font-medium transition duration-150">Schedule Extra</a>
        <a href="extra_vehicle.php" class="hover:text-yellow-400 font-medium transition duration-150">View Extra</a>
    </div>
</div>
<div class="w-[85%] ml-[15%]">
    <div class="bg-white p-6 rounded-xl shadow-xl space-y-6 max-w-6xl mx-auto border border-blue-100 mt-16">
        
        <h1 class="text-3xl font-extrabold text-gray-800 mb-2 text-center text-blue-700">📋 Pending Extra Vehicle Trips (Non-Scheduled)</h1>
        <p class="text-center text-blue-500 text-sm mb-6 font-semibold">
            All trips awaiting completion.
        </p>
        
        <div class="mb-4 max-w-6xl mx-auto">
            <?php echo $message; ?>
        </div>

        <?php if (empty($pending_trips)): ?>
            <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4" role="alert">
                <p class="font-bold">No Pending Trips</p>
                <p>There are currently no non-scheduled trips marked as pending (Done = 0).</p>
            </div>
        <?php else: ?>
            
            <div class="overflow-x-auto shadow-md rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-blue-600 text-white">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">ID</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Vehicle No</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Date/Time</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">From/To</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Description</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Distance (Km)</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200 text-sm">
                        <?php foreach ($pending_trips as $trip): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap font-bold text-gray-900"><?php echo htmlspecialchars($trip['id']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($trip['vehicle_no']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-xs text-gray-900"><?php echo htmlspecialchars($trip['date']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars(substr($trip['time'], 0, 5)); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-xs font-semibold text-blue-600">From: <?php echo htmlspecialchars($trip['from']); ?></div>
                                    <div class="text-xs text-gray-500">To: <?php echo htmlspecialchars($trip['to']); ?></div>
                                </td>
                                <td class="px-6 py-4 max-w-xs overflow-hidden text-gray-700"><?php echo htmlspecialchars($trip['description']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap font-mono text-center text-green-700"><?php echo htmlspecialchars(number_format($trip['distance'], 2)); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <form method="POST" action="non_schedule_extra_vehicle.php" onsubmit="return confirm('Are you sure you want to mark Trip ID <?php echo htmlspecialchars($trip['id']); ?> as DONE?');">
                                        <input type="hidden" name="trip_id" value="<?php echo htmlspecialchars($trip['id']); ?>">
                                        <button 
                                            type="submit" 
                                            name="mark_done" 
                                            class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-green-500 hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-150"
                                        >
                                            ✅ Done
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>
</div>

</body>
</html>