<?php
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Fetch Running Chart details
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $filterDate = $_POST['date'];
    $sql = "SELECT s.id, s.vehicle_no, s.shift, s.driver, r.route AS route_name, s.in_time, s.out_time, s.date
            FROM staff_transport_vehicle_register s
            JOIN route r ON s.route = r.route_code
            WHERE DATE(s.date) = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $filterDate);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $sql = "SELECT s.id, s.vehicle_no, s.shift, s.driver, r.route AS route_name, s.in_time, s.out_time, s.date
            FROM staff_transport_vehicle_register s
            JOIN route r ON s.route = r.route_code";
    $result = $conn->query($sql);
}

// Group and merge logic
$grouped = [];
while ($row = $result->fetch_assoc()) {
    $key = $row['date'] . '-' . $row['route_name'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'date' => $row['date'],
            'route_name' => $row['route_name'],
            'morning_vehicle' => null,
            'morning_driver' => null,
            'morning_in' => null,
            'morning_out' => null,
            'evening_vehicle' => null,
            'evening_driver' => null,
            'evening_in' => null,
            'evening_out' => null
        ];
    }

    if ($row['shift'] === 'morning') {
        $grouped[$key]['morning_vehicle'] = $row['vehicle_no'];
        $grouped[$key]['morning_driver'] = $row['driver'];
        $grouped[$key]['morning_in'] = $row['in_time'];
        $grouped[$key]['morning_out'] = $row['out_time'];
    } elseif ($row['shift'] === 'evening') {
        $grouped[$key]['evening_vehicle'] = $row['vehicle_no'];
        $grouped[$key]['evening_driver'] = $row['driver'];
        $grouped[$key]['evening_in'] = $row['in_time'];
        $grouped[$key]['evening_out'] = $row['out_time'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vehicle Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="add_records/add_staff_record.php" class="hover:text-yellow-600">Add Record</a>
        <a href="add_records/extra_distance.php" class="hover:text-yellow-600">Extra Distance</a>
        <a href="add_records/barcode_reader.php" class="hover:text-yellow-600">Barcode Details</a>
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[48px] font-bold text-gray-800 mt-2">Staff Transport Vehicle Details</p>

    <!-- Date Filter Form -->
    <form method="POST" class="mb-6 flex justify-center">
        <div class="flex items-center">
            <label for="date" class="text-lg font-medium mr-2">Filter by Date:</label>
            <input type="date" id="date" name="date" class="border border-gray-300 p-2 rounded-md"
                   value="<?php echo isset($_POST['date']) ? $_POST['date'] : date('Y-m-d'); ?>" required>
            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md ml-2 hover:bg-blue-600">Filter</button>
        </div>
    </form>

    <!-- Vehicle Details Table -->
    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Driver</th>
                    <th class="px-4 py-2 text-left">Route</th>
                    <th class="px-4 py-2 text-left">Morning IN</th>
                    <th class="px-4 py-2 text-left">Morning OUT</th>
                    <th class="px-4 py-2 text-left">Evening IN</th>
                    <th class="px-4 py-2 text-left">Evening OUT</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($grouped as $entry) {
                    $same_driver = $entry['morning_driver'] === $entry['evening_driver'];
                    $same_vehicle = $entry['morning_vehicle'] === $entry['evening_vehicle'];

                    if ($entry['morning_vehicle'] && $entry['evening_vehicle'] && $same_driver && $same_vehicle) {
                        echo "<tr>
                            <td class='border px-4 py-2'>{$entry['date']}</td>
                            <td class='border px-4 py-2'>{$entry['morning_vehicle']}</td>
                            <td class='border px-4 py-2'>{$entry['morning_driver']}</td>
                            <td class='border px-4 py-2'>{$entry['route_name']}</td>
                            <td class='border px-4 py-2'>" . date('H:i', strtotime($entry['morning_in'])) . "</td>
                            <td class='border px-4 py-2'>" . date('H:i', strtotime($entry['morning_out'])) . "</td>
                            <td class='border px-4 py-2'>" . date('H:i', strtotime($entry['evening_in'])) . "</td>
                            <td class='border px-4 py-2'>" . date('H:i', strtotime($entry['evening_out'])) . "</td>
                        </tr>";
                    } else {
                        if ($entry['morning_vehicle']) {
                            echo "<tr>
                                <td class='border px-4 py-2'>{$entry['date']}</td>
                                <td class='border px-4 py-2'>{$entry['morning_vehicle']}</td>
                                <td class='border px-4 py-2'>{$entry['morning_driver']}</td>
                                <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                <td class='border px-4 py-2'>" . date('H:i', strtotime($entry['morning_in'])) . "</td>
                                <td class='border px-4 py-2'>" . date('H:i', strtotime($entry['morning_out'])) . "</td>
                                <td class='border px-4 py-2'></td>
                                <td class='border px-4 py-2'></td>
                            </tr>";
                        }
                        if ($entry['evening_vehicle']) {
                            echo "<tr>
                                <td class='border px-4 py-2'>{$entry['date']}</td>
                                <td class='border px-4 py-2'>{$entry['evening_vehicle']}</td>
                                <td class='border px-4 py-2'>{$entry['evening_driver']}</td>
                                <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                <td class='border px-4 py-2'></td>
                                <td class='border px-4 py-2'></td>
                                <td class='border px-4 py-2'>" . date('H:i', strtotime($entry['evening_in'])) . "</td>
                                <td class='border px-4 py-2'>" . date('H:i', strtotime($entry['evening_out'])) . "</td>
                            </tr>";
                        }
                    }
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
