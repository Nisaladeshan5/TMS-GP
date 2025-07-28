<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $vehicleNo = $_POST['vehicle_no'];
    $date = $_POST['date'];
    $distance = $_POST['distance'];
    $remark = $_POST['remark'];

    $sql = "INSERT INTO extra_distance (vehicle_no, date, distance, remark)
            VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssds', $vehicleNo, $date, $distance, $remark);

    if ($stmt->execute()) {
        header("Location: http://localhost/TMS/public/registers/Staff%20transport%20vehicle%20register.php");
        exit();
    } else {
        echo "<p class='text-red-500'>Error saving extra distance record.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Extra Distance</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="container p-6 mt-3" style="width: 30%; margin-left: 42%;">
        <h2 class="text-2xl font-bold mb-4 text-center">Add Extra Distance Record</h2>
        <form method="POST" class="bg-white p-8 rounded-lg shadow-md space-y-4">
            <div>
                <label for="vehicle_no" class="block text-gray-700 font-semibold">Vehicle No</label>
                <input type="text" name="vehicle_no" id="vehicle_no" required
                       class="w-full border p-2 rounded-md" />
            </div>

            <div>
                <label for="date" class="block text-gray-700 font-semibold">Date</label>
                <input type="date" name="date" id="date" required
                       value="<?php echo date('Y-m-d'); ?>"
                       class="w-full border p-2 rounded-md" />
            </div>

            <div>
                <label for="distance" class="block text-gray-700 font-semibold">Distance (in km)</label>
                <input type="number" step="0.01" name="distance" id="distance" required
                       class="w-full border p-2 rounded-md" />
            </div>

            <div>
                <label for="remark" class="block text-gray-700 font-semibold">Remark</label>
                <input type="text" name="remark" id="remark"
                       class="w-full border p-2 rounded-md" />
            </div>

            <!-- Button Section -->
            <div class="flex justify-center gap-4 mt-4">
                <!-- Cancel Button -->
                <a href="http://localhost/TMS/public/registers/Staff%20transport%20vehicle%20register.php"
                class="bg-gray-300 text-gray-800 px-6 py-2 rounded hover:bg-gray-400 transition">
                    Cancel
                </a>
                
                <!-- Save Button -->
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">
                    Save
                </button>
            </div>
        </form>
    </div>
</body>
</html>

<?php include('../../../includes/footer.php'); ?>
