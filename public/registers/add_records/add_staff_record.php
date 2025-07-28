<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Fetch routes from the database
$sqlRoutes = "SELECT route_id, route FROM route";  
$resultRoutes = $conn->query($sqlRoutes);

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Collect form data
    $vehicleNo = $_POST['vehicle_no'];
    $date = $_POST['date'];
    $driver = $_POST['driver'];
    $route = $_POST['route']; // Store route_id, not route name
    $mInTime = $_POST['m_in_time'];
    $mOutTime = $_POST['m_out_time'];
    $eInTime = $_POST['e_in_time'];
    $eOutTime = $_POST['e_out_time'];

    // Insert data into the database
    $sql = "INSERT INTO staff_transport_vehicle_register (vehicle_no, date, driver, route, m_in_time, m_out_time, e_in_time, e_out_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssss', $vehicleNo, $date, $driver, $route, $mInTime, $mOutTime, $eInTime, $eOutTime);

    if ($stmt->execute()) {
        // Redirect to the records page after successful insert
        header("Location: http://localhost/TMS/public/registers/Staff%20transport%20vehicle%20register.php");
        exit();
    } else {
        echo "<p class='text-red-500'>Error adding record.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Staff Transport Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="container p-6 mt-3" style="width: 70%; margin-left: 22%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
        <h2 class="text-2xl font-semibold text-center mb-6">Add Staff Transport Record</h2>
        
        <!-- Add Record Form -->
        <form method="POST" class="max-w-4xl mx-auto bg-white p-12 rounded-lg shadow-md">
            <div class="mb-2 flex gap-4">
                <div class="w-1/2">
                    <label for="vehicle_no" class="block text-lg font-medium">Vehicle No</label>
                    <input type="text" id="vehicle_no" name="vehicle_no" class="w-full border border-gray-300 p-2 rounded-md" required>
                </div>

                <div class="w-1/2">
                    <label for="date" class="block text-lg font-medium">Date</label>
                    <input type="date" id="date" name="date" class="border border-gray-300 p-2 rounded-md" required>
                </div>
            </div>

            <div class="mb-2">
                <label for="driver" class="block text-lg font-medium">Driver</label>
                <input type="text" id="driver" name="driver" class="w-full border border-gray-300 p-2 rounded-md" required>
            </div>

            <!-- Route Dropdown -->
            <div class="mb-2">
                <label for="route" class="block text-lg font-medium">Route</label>
                <select id="route" name="route" class="w-full border border-gray-300 p-2 rounded-md" required>
                    <option value="" disabled selected>Select a Route</option>
                    <?php
                    // Check if there are any routes from the database
                    if ($resultRoutes->num_rows > 0) {
                        // Loop through the routes and display them in the dropdown
                        while ($row = $resultRoutes->fetch_assoc()) {
                            echo "<option value='" . $row['route'] . "'>" . htmlspecialchars($row['route']) . "</option>";
                        }
                    } else {
                        echo "<option value='' disabled>No routes available</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-2 flex gap-4">
    <!-- Morning In -->
    <div class="w-1/2">
        <label for="m_in_time" class="block text-lg font-medium">Morning In</label>
        <input type="time" id="m_in_time" name="m_in_time" class="w-full border border-gray-300 p-2 rounded-md" required 
               min="06:00" max="11:59" placeholder="Select time (24hr format)">
        <p id="m_in_warning" class="text-red-500 text-sm hidden">Time must be between 06:00 and 11:59 (24-hour format).</p>
    </div>

    <!-- Morning Out -->
    <div class="w-1/2">
        <label for="m_out_time" class="block text-lg font-medium">Morning Out</label>
        <input type="time" id="m_out_time" name="m_out_time" class="w-full border border-gray-300 p-2 rounded-md" required 
               min="06:00" max="11:59" placeholder="Select time (24hr format)">
        <p id="m_out_warning" class="text-red-500 text-sm hidden">Time must be between 06:00 and 11:59 (24-hour format).</p>
    </div>
</div>


            <div class="mb-2 flex gap-4">
    <div class="w-1/2">
        <label for="e_in_time" class="block text-lg font-medium">Evening In</label>
        <input type="time" id="e_in_time" name="e_in_time" class="w-full border border-gray-300 p-2 rounded-md" required 
               min="12:00" max="23:59" placeholder="Select time (24hr format)">
        <p id="e_in_warning" class="text-red-500 text-sm hidden">Time must be between 12:00 and 23:59 (24-hour format).</p>
    </div>
    <div class="w-1/2">
        <label for="e_out_time" class="block text-lg font-medium">Evening Out</label>
        <input type="time" id="e_out_time" name="e_out_time" class="w-full border border-gray-300 p-2 rounded-md" required 
               min="12:00" max="23:59" placeholder="Select time (24hr format)">
        <p id="e_out_warning" class="text-red-500 text-sm hidden">Time must be between 12:00 and 23:59 (24-hour format).</p>
    </div>
</div>

            <div class="flex justify-center mt-4">
                <button type="submit" class="bg-blue-500 text-white px-6 py-3 rounded-md hover:bg-blue-600 transition duration-300">Add Record</button>
            </div>
        </form>
    </div>
</body>
</html>

<?php 
include('../../../includes/footer.php');
?>
