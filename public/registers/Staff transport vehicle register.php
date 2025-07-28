<?php
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php'); // Include the top navbar

// Fetch Running Chart details
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get the date filter from the form input
    $filterDate = $_POST['date'];

    // Prepare the query to fetch data based on the date filter
    $sql = "SELECT id, vehicle_no, driver, route, m_in_time, m_out_time, e_in_time, e_out_time 
            FROM staff_transport_vehicle_register
            WHERE DATE(date)=?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $filterDate);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    // Default query to show all data
    $sql = "SELECT id, vehicle_no, driver, route, m_in_time, m_out_time, e_in_time, e_out_time 
            FROM staff_transport_vehicle_register";
    $result = $conn->query($sql);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Details</title>
    <!-- Tailwind CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] ">
        <div class="text-lg font-semibold ml-3">Registers</div>
        <div class="flex gap-4">
            <!-- Add Transport Details Button -->
            <a href="add_records/add_staff_record.php" class="hover:text-yellow-600">
                Add Record
            </a>
            <!-- Extra Distance Details Button -->
            <a href="add_records/extra_distance.php" class="hover:text-yellow-600">
                Extra Distance
            </a>
            <!-- Barcode reader Details Button -->
            <a href="extra_distance.php" class="hover:text-yellow-600">
                Barcode Details
            </a>
        </div>
    </div>
    <div class="container" style="width: 70%; margin-left: 22%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
        <p class="text-[48px] font-bold text-gray-800 mt-2">Staff Transport Vehicle Details</p>

        <!-- Date Filter Form -->
        <form method="POST" class="mb-6 flex justify-center">
            <div class="flex items-center">
                <label for="date" class="text-lg font-medium">Filter by Date:</label>
                <input type="date" id="date" name="date" class="border border-gray-300 p-2 rounded-md" 
                    value="<?php echo isset($_POST['date']) ? $_POST['date'] : date('Y-m-d'); ?>" required>
                <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md m-1 hover:bg-blue-600">Filter</button>
            </div>
        </form>

        <!-- Vehicle Details Table -->
        <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6">
            <table class="min-w-full table-auto">
                <thead class="bg-blue-600 text-white">
                    <tr>
                        <th class="px-4 py-2 text-left">ID</th>
                        <th class="px-4 py-2 text-left">Vehicle No</th>
                        <th class="px-4 py-2 text-left">Driver</th>
                        <th class="px-4 py-2 text-left">Route</th>
                        <th class="px-4 py-2 text-left">Morning In</th>
                        <th class="px-4 py-2 text-left">Morning Out</th>
                        <th class="px-4 py-2 text-left">Evening In</th>
                        <th class="px-4 py-2 text-left">Evening Out</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            echo "<tr>
                                    <td class='border px-4 py-2'>" . htmlspecialchars($row['id']) . "</td>
                                    <td class='border px-4 py-2'>" . htmlspecialchars($row['vehicle_no']) . "</td>
                                    <td class='border px-4 py-2'>" . htmlspecialchars($row['driver']) . "</td>
                                    <td class='border px-4 py-2'>" . htmlspecialchars($row['route']) . "</td>
                                    <td class='border px-4 py-2'>" . date("H:i", strtotime($row['m_in_time'])) . "</td>
                                    <td class='border px-4 py-2'>" . date("H:i", strtotime($row['m_out_time'])) . "</td>
                                    <td class='border px-4 py-2'>" . date("H:i", strtotime($row['e_in_time'])) . "</td>
                                    <td class='border px-4 py-2'>" . date("H:i", strtotime($row['e_out_time'])) . "</td>
                                  </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='8' class='border text-center py-2'>No records found.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

    </div>

</body>
</html>

<?php 
include('../../includes/footer.php');
?>
