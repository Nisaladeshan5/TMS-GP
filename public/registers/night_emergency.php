<?php
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Set timezone to Sri Lanka
date_default_timezone_set('Asia/Colombo');

// Initialize filter variables
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Base SQL query
$sql = "SELECT id, vehicle_no, date, driver, out_time, in_time, description FROM night_emergency_vehicle_register";
$conditions = [];
$params = [];
$types = "";

// Add month and year filters if they are set
if (!empty($filter_month) && !empty($filter_year)) {
    $conditions[] = "MONTH(date) = ? AND YEAR(date) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= "ii";
}

// Append conditions to the query
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY date DESC, out_time DESC";

// Prepare and execute the statement
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Night Emergency Vehicle Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="add_records/night_emergency_attendance.php" class="hover:text-yellow-600">Attendance</a>
        <a href="add_records/add_night_emergency_vehicle.php" class="hover:text-yellow-600">Add Trip Record</a>
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[36px] font-bold text-gray-800 mt-2">Night Emergency Vehicle Register</p>

    <form method="GET" action="" class="mb-6 flex justify-center mt-1">
        <div class="flex items-center">
            <label for="month" class="text-lg font-medium mr-2">Filter by:</label>
            
            <select id="month" name="month" class="border border-gray-300 p-2 rounded-md">
                <?php
                $months = [
                    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June',
                    '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                ];
                foreach ($months as $num => $name) {
                    $selected = ($num == $filter_month) ? 'selected' : '';
                    echo "<option value='{$num}' {$selected}>{$name}</option>";
                }
                ?>
            </select>
            
            <select id="year" name="year" class="border border-gray-300 p-2 rounded-md ml-2">
                <?php
                $current_year = date('Y');
                for ($y = $current_year; $y >= 2020; $y--) {
                    $selected = ($y == $filter_year) ? 'selected' : '';
                    echo "<option value='{$y}' {$selected}>{$y}</option>";
                }
                ?>
            </select>

            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md ml-2 hover:bg-blue-600">Filter</button>
        </div>
    </form>
    
    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6 w-full">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Driver License ID</th>
                    <th class="px-4 py-2 text-left">Out Time</th>
                    <th class="px-4 py-2 text-left">In Time</th>
                    <th class="px-4 py-2 text-left">Description</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-left">Action</th>

                </tr>
            </thead>
            <tbody>
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $is_unavailable = empty($row['in_time']);
                        $status_class = $is_unavailable ? 'bg-red-200 text-red-800' : 'bg-green-200 text-green-800';
                        $status_text = $is_unavailable ? 'Unavailable' : 'Available';

                        echo "<tr class='hover:bg-gray-100'>";
                        echo "<td class='border px-4 py-2'>{$row['vehicle_no']}</td>";
                        echo "<td class='border px-4 py-2'>{$row['date']}</td>";
                        echo "<td class='border px-4 py-2'>{$row['driver']}</td>";
                        echo "<td class='border px-4 py-2'>" . date('H:i', strtotime($row['out_time'])) . "</td>";
                        echo "<td class='border px-4 py-2'>";
                        if (!empty($row['in_time'])) {
                            echo date('H:i', strtotime($row['in_time']));
                        }
                        echo "</td>";
                        echo "<td class='border px-4 py-2'>{$row['description']}</td>";
                        echo "<td class='border px-4 py-2'><span class='p-1 rounded-full text-xs font-semibold {$status_class}'>{$status_text}</span></td>";
                        echo "<td class='border px-4 py-2'>";
                        if ($is_unavailable) {
                            // The 'data-id' attribute can be used to identify the record for the update
                            echo "<button data-id='{$row['id']}' class='log-in-btn bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600 text-sm'>Mark In</button>";
                        }
                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='8' class='border px-4 py-2 text-center'>No records found.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

</body>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('.log-in-btn').on('click', function() {
        var recordId = $(this).data('id');
        var button = $(this);

        $.ajax({
            url: 'update_in_time.php', // Path to your new PHP script
            type: 'POST',
            data: { id: recordId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Update the UI on success
                    button.hide(); // Hide the button
                    var statusCell = button.closest('tr').find('td:nth-child(6) span');
                    statusCell.text('Available').removeClass('bg-red-200 text-red-800').addClass('bg-green-200 text-green-800');
                    alert('Vehicle logged in successfully!');
                    location.reload(); // Refresh the page to show the updated data
                } else {
                    alert('Error: ' + response.error);
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
            }
        });
    });
});
</script>

</html>