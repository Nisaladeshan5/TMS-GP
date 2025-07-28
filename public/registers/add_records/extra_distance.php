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
    $distance = $_POST['distance'];
    $remark = $_POST['remark'];

    // Insert data into the database
    $sql = "INSERT INTO staff_transport_vehicle_register (vehicle_no, date, driver, route, m_in_time, m_out_time, e_in_time, e_out_time, distance, remark)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssssss', $vehicleNo, $date, $driver, $route, $mInTime, $mOutTime, $eInTime, $eOutTime, $distance, $remark);

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
        <h2 class="text-2xl font-semibold text-center mb-6">Extra Distance Transport Record</h2>
        
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

            <!-- Distance and Remark Section -->
            <div class="mb-2 flex gap-4">
                <!-- Distance -->
                <div class="w-full">
                    <label for="distance" class="block text-lg font-medium">Distance (in km)</label>
                    <input type="number" id="distance" name="distance" class="w-full border border-gray-300 p-2 rounded-md" required>
                </div>
            </div>

            <!-- Remark -->
            <div class="mb-2 flex gap-4">
                <div class="w-full">
                    <label for="remark" class="block text-lg font-medium">Location Remark</label>
                    <input type="text" id="remark" name="remark" class="w-full border border-gray-300 p-2 rounded-md" required>
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
