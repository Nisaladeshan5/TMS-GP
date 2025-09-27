<?php
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Set the filter date to today's date by default
$filterDate = date('Y-m-d');

// If a date is submitted via the form, use that date for the filter
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['date'])) {
    $filterDate = $_POST['date'];
}

// Fetch Running Chart details including actual vehicle/driver and their statuses
$sql = "SELECT s.id, s.vehicle_no, s.actual_vehicle_no, s.vehicle_status, s.shift, s.driver_NIC, s.driver_status, r.route AS route_name, s.in_time, s.out_time, s.date
        FROM staff_transport_vehicle_register s
        JOIN route r ON s.route = r.route_code
        WHERE DATE(s.date) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $filterDate);
$stmt->execute();
$result = $stmt->get_result();

// Group and merge logic
$grouped = [];
while ($row = $result->fetch_assoc()) {
    $key = $row['date'] . '-' . $row['route_name'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'date' => $row['date'],
            'route_name' => $row['route_name'],
            'morning_vehicle' => null,
            'morning_actual_vehicle' => null,
            'morning_vehicle_status' => null,
            'morning_driver' => null,
            'morning_driver_status' => null,
            'morning_in' => null,
            'morning_out' => null,
            'evening_vehicle' => null,
            'evening_actual_vehicle' => null,
            'evening_vehicle_status' => null,
            'evening_driver' => null,
            'evening_driver_status' => null,
            'evening_in' => null,
            'evening_out' => null
        ];
    }

    if ($row['shift'] === 'morning') {
        $grouped[$key]['morning_vehicle'] = $row['vehicle_no'];
        $grouped[$key]['morning_actual_vehicle'] = $row['actual_vehicle_no'];
        $grouped[$key]['morning_vehicle_status'] = $row['vehicle_status'];
        $grouped[$key]['morning_driver'] = $row['driver_NIC'];
        $grouped[$key]['morning_driver_status'] = $row['driver_status'];
        $grouped[$key]['morning_in'] = $row['in_time'];
        $grouped[$key]['morning_out'] = $row['out_time'];
    } elseif ($row['shift'] === 'evening') {
        $grouped[$key]['evening_vehicle'] = $row['vehicle_no'];
        $grouped[$key]['evening_actual_vehicle'] = $row['actual_vehicle_no'];
        $grouped[$key]['evening_vehicle_status'] = $row['vehicle_status'];
        $grouped[$key]['evening_driver'] = $row['driver_NIC'];
        $grouped[$key]['evening_driver_status'] = $row['driver_status'];
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
        <a href="add_records/trip.php" class="hover:text-yellow-600">Trip</a>
        <a href="add_records/absent_staff.php" class="hover:text-yellow-600">Absent</a>
        <a href="add_records/add_staff_record.php" class="hover:text-yellow-600">Add Record</a>
        <a href="add_records/view_extra_distance.php" class="hover:text-yellow-600">Extra Distance</a>
        <a href="add_records/barcode_reader.php" class="hover:text-yellow-600">Barcode</a>
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[48px] font-bold text-gray-800 mt-2">Staff Transport Vehicle Details</p>

    <form method="POST" class="mb-6 flex justify-center">
        <div class="flex items-center">
            <label for="date" class="text-lg font-medium mr-2">Filter by Date:</label>
            <input type="date" id="date" name="date" class="border border-gray-300 p-2 rounded-md"
                   value="<?php echo htmlspecialchars($filterDate); ?>" required>
            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md ml-2 hover:bg-blue-600">Filter</button>
        </div>
    </form>

    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Route</th>
                    <th class="px-4 py-2 text-left">Assigned Vehicle No</th>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Driver</th>
                    <th class="px-4 py-2 text-left">Morning IN</th>
                    <th class="px-4 py-2 text-left">Morning OUT</th>
                    <th class="px-4 py-2 text-left">Evening IN</th>
                    <th class="px-4 py-2 text-left">Evening OUT</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($grouped as $entry) {
                    $morning_in_time = ($entry['morning_in'] !== null) ? date('H:i', strtotime($entry['morning_in'])) : 'N/A';
                    $morning_out_time = ($entry['morning_out'] !== null) ? date('H:i', strtotime($entry['morning_out'])) : 'N/A';
                    $evening_in_time = ($entry['evening_in'] !== null) ? date('H:i', strtotime($entry['evening_in'])) : 'N/A';
                    $evening_out_time = ($entry['evening_out'] !== null) ? date('H:i', strtotime($entry['evening_out'])) : 'N/A';

                    // Determine cell colors based on statuses (0 for unknown/red)
                    $morning_vehicle_cell_class = ($entry['morning_vehicle_status'] == 0) ? 'bg-red-200' : '';
                    $morning_driver_cell_class = ($entry['morning_driver_status'] == 0) ? 'bg-red-200' : '';
                    $evening_vehicle_cell_class = ($entry['evening_vehicle_status'] == 0) ? 'bg-red-200' : '';
                    $evening_driver_cell_class = ($entry['evening_driver_status'] == 0) ? 'bg-red-200' : '';

                    // Check if both morning and evening have records
                    if ($entry['morning_vehicle'] !== null && $entry['evening_vehicle'] !== null) {
                        // Check if vehicle and driver are the same for both shifts
                        if ($entry['morning_actual_vehicle'] === $entry['evening_actual_vehicle'] && $entry['morning_driver'] === $entry['evening_driver']) {
                            echo "<tr>
                                <td class='border px-4 py-2'>{$entry['date']}</td>
                                <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                <td class='border px-4 py-2'>{$entry['morning_vehicle']}</td>
                                <td class='border px-4 py-2 {$morning_vehicle_cell_class}'>{$entry['morning_actual_vehicle']}</td>
                                <td class='border px-4 py-2 {$morning_driver_cell_class}'>{$entry['morning_driver']}</td>
                                <td class='border px-4 py-2'>{$morning_in_time}</td>
                                <td class='border px-4 py-2'>{$morning_out_time}</td>
                                <td class='border px-4 py-2'>{$evening_in_time}</td>
                                <td class='border px-4 py-2'>{$evening_out_time}</td>
                                </tr>";
                        } else {
                            // If different, show morning and evening details on two separate rows
                            echo "<tr>
                                <td class='border px-4 py-2'>{$entry['date']}</td>
                                <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                <td class='border px-4 py-2'>{$entry['morning_vehicle']}</td>
                                <td class='border px-4 py-2 {$morning_vehicle_cell_class}'>{$entry['morning_actual_vehicle']}</td>
                                <td class='border px-4 py-2 {$morning_driver_cell_class}'>{$entry['morning_driver']}</td>
                                <td class='border px-4 py-2'>{$morning_in_time}</td>
                                <td class='border px-4 py-2'>{$morning_out_time}</td>
                                <td class='border px-4 py-2'>N/A</td>
                                <td class='border px-4 py-2'>N/A</td>
                                </tr>";
                            echo "<tr>
                                <td class='border px-4 py-2'>{$entry['date']}</td>
                                <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                <td class='border px-4 py-2'>{$entry['evening_vehicle']}</td>
                                <td class='border px-4 py-2 {$evening_vehicle_cell_class}'>{$entry['evening_actual_vehicle']}</td>
                                <td class='border px-4 py-2 {$evening_driver_cell_class}'>{$entry['evening_driver']}</td>
                                <td class='border px-4 py-2'>N/A</td>
                                <td class='border px-4 py-2'>N/A</td>
                                <td class='border px-4 py-2'>{$evening_in_time}</td>
                                <td class='border px-4 py-2'>{$evening_out_time}</td>
                                </tr>";
                        }
                    } else if ($entry['morning_vehicle'] !== null) {
                        // Only a morning record exists
                        echo "<tr>
                            <td class='border px-4 py-2'>{$entry['date']}</td>
                            <td class='border px-4 py-2'>{$entry['route_name']}</td>
                            <td class='border px-4 py-2'>{$entry['morning_vehicle']}</td>
                            <td class='border px-4 py-2 {$morning_vehicle_cell_class}'>{$entry['morning_actual_vehicle']}</td>
                            <td class='border px-4 py-2 {$morning_driver_cell_class}'>{$entry['morning_driver']}</td>
                            <td class='border px-4 py-2'>{$morning_in_time}</td>
                            <td class='border px-4 py-2'>{$morning_out_time}</td>
                            <td class='border px-4 py-2'>N/A</td>
                            <td class='border px-4 py-2'>N/A</td>
                            </tr>";
                    } else if ($entry['evening_vehicle'] !== null) {
                        // Only an evening record exists
                        echo "<tr>
                            <td class='border px-4 py-2'>{$entry['date']}</td>
                            <td class='border px-4 py-2'>{$entry['route_name']}</td>
                            <td class='border px-4 py-2'>{$entry['evening_vehicle']}</td>
                            <td class='border px-4 py-2 {$evening_vehicle_cell_class}'>{$entry['evening_actual_vehicle']}</td>
                            <td class='border px-4 py-2 {$evening_driver_cell_class}'>{$entry['evening_driver']}</td>
                            <td class='border px-4 py-2'>N/A</td>
                            <td class='border px-4 py-2'>N/A</td>
                            <td class='border px-4 py-2'>{$evening_in_time}</td>
                            <td class='border px-4 py-2'>{$evening_out_time}</td>
                            </tr>";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>