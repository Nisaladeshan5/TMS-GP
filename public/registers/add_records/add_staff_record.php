<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Fetch routes from the database
$sqlRoutes = "SELECT route_code, route FROM route";  
$resultRoutes = $conn->query($sqlRoutes);

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Collect form data
    $vehicleNo = $_POST['vehicle_no'];
    $date = $_POST['date'];
    $shift = $_POST['shift'];
    $driver = $_POST['driver'];
    $route = $_POST['route']; // Store route_code
    $inTime = $_POST['in_time'];
    $outTime = $_POST['out_time'];

    // Insert data into the database
    $sql = "INSERT INTO staff_transport_vehicle_register 
            (vehicle_no, date, shift, driver, route, in_time, out_time)
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssssss', $vehicleNo, $date, $shift, $driver, $route, $inTime, $outTime);

    if ($stmt->execute()) {
        header("Location: http://localhost/TMS/public/registers/Staff%20transport%20vehicle%20register.php");
        exit();
    } else {
        echo "<p class='text-red-500'>Error adding record: " . $stmt->error . "</p>";
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
    
<div class="container p-6 mt-3" style="width: 40%; margin-left: 38%;">
    <h2 class="text-2xl font-semibold text-center mb-6">Add Staff Transport Record</h2>

    <form method="POST" class="bg-white p-8 rounded-lg shadow-md" onsubmit="return validateTimeRange()">
        <div class="mb-4 flex gap-4">
            <div class="w-1/2">
                <label for="vehicle_no" class="block text-lg font-medium">Vehicle No</label>
                <input type="text" id="vehicle_no" name="vehicle_no" class="w-full border border-gray-300 p-2 rounded-md" required>
            </div>
            <div class="w-1/2">
                <label for="date" class="block text-lg font-medium">Date</label>
                <input type="date" id="date" name="date" class="w-full border border-gray-300 p-2 rounded-md" required>
            </div>
        </div>

        <div class="mb-4">
            <label for="shift" class="block text-lg font-medium">Shift</label>
            <select id="shift" name="shift" class="w-full border border-gray-300 p-2 rounded-md" required onchange="updateTimeLimits()">
                <option value="" disabled selected>Select Shift</option>
                <option value="Morning">Morning</option>
                <option value="Evening">Evening</option>
            </select>
        </div>

        <div class="mb-4">
            <label for="driver" class="block text-lg font-medium">Driver</label>
            <input type="text" id="driver" name="driver" class="w-full border border-gray-300 p-2 rounded-md" required>
        </div>

        <div class="mb-4">
            <label for="route" class="block text-lg font-medium">Route</label>
            <select id="route" name="route" class="w-full border border-gray-300 p-2 rounded-md" required>
                <option value="" disabled selected>Select a Route</option>
                <?php
                if ($resultRoutes->num_rows > 0) {
                    while ($row = $resultRoutes->fetch_assoc()) {
                        echo "<option value='" . $row['route_code'] . "'>" . htmlspecialchars($row['route']) . "</option>";
                    }
                } else {
                    echo "<option value='' disabled>No routes available</option>";
                }
                ?>
            </select>
        </div>

        <div class="mb-4 flex gap-4">
            <div class="w-1/2">
                <label for="in_time" class="block text-lg font-medium">In Time</label>
                <input type="time" id="in_time" name="in_time" class="w-full border border-gray-300 p-2 rounded-md" required>
            </div>
            <div class="w-1/2">
                <label for="out_time" class="block text-lg font-medium">Out Time</label>
                <input type="time" id="out_time" name="out_time" class="w-full border border-gray-300 p-2 rounded-md" required>
            </div>
        </div>

        <p id="timeWarning" class="text-red-500 text-sm mb-4 hidden">Time must be between 04:00–11:59 for Morning or 12:00–23:59 for Evening.</p>

        <!-- Button Section -->
            <div class="flex justify-center gap-4 mt-4">
                <!-- Cancel Button -->
                <a href="http://localhost/TMS/public/registers/Staff%20transport%20vehicle%20register.php"
                class="bg-gray-300 text-gray-800 px-10 py-2 rounded hover:bg-gray-400 transition">
                    Cancel
                </a>
                
                <!-- Save Button -->
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">
                    Add Record
                </button>
            </div>
    </form>
</div>

<!-- JS for shift time limits -->
<script>
function updateTimeLimits() {
    const shift = document.getElementById('shift').value;
    const inTime = document.getElementById('in_time');
    const outTime = document.getElementById('out_time');

    if (shift === 'Morning') {
        inTime.min = '04:00';
        inTime.max = '11:59';
        outTime.min = '04:00';
        outTime.max = '11:59';
    } else if (shift === 'Evening') {
        inTime.min = '12:00';
        inTime.max = '23:59';
        outTime.min = '12:00';
        outTime.max = '23:59';
    } else {
        inTime.removeAttribute('min');
        inTime.removeAttribute('max');
        outTime.removeAttribute('min');
        outTime.removeAttribute('max');
    }
}

function validateTimeRange() {
    const shift = document.getElementById('shift').value;
    const inTime = document.getElementById('in_time').value;
    const outTime = document.getElementById('out_time').value;
    const warning = document.getElementById('timeWarning');

    // Convert to minutes for comparison
    const toMinutes = timeStr => {
        const [h, m] = timeStr.split(':');
        return parseInt(h) * 60 + parseInt(m);
    }

    const inMins = toMinutes(inTime);
    const outMins = toMinutes(outTime);

    let isValid = true;

    if (shift === 'Morning') {
        isValid = inMins >= 240 && inMins <= 719 && outMins >= 240 && outMins <= 719;
    } else if (shift === 'Evening') {
        isValid = inMins >= 720 && inMins <= 1439 && outMins >= 720 && outMins <= 1439;
    }

    if (!isValid) {
        warning.classList.remove('hidden');
        return false;
    }

    warning.classList.add('hidden');
    return true;
}
</script>

</body>
</html>

<?php 
include('../../../includes/footer.php');
?>
