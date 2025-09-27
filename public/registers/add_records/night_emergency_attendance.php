<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Initialize filter variables
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Base SQL query
$sql = "
    SELECT
        nea.vehicle_no,
        nea.date,
        nea.report_time,
        nea.driver_NIC,
        nea.vehicle_status,
        nea.driver_status,
        s.supplier 
    FROM
        night_emergency_attendance AS nea
    JOIN
        supplier AS s ON nea.supplier_code = s.supplier_code 
";
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
$sql .= " ORDER BY date DESC, report_time DESC";

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
    <title>Night Emergency Attendance Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .red-cell {
            background-color: #fca5a5; /* A light red color from Tailwind CSS */
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="../night_emergency.php" class="hover:text-yellow-600">Back to Trips</a>
        <a href="add_night_emergency_vehicle.php" class="hover:text-yellow-600">Add Trip Record</a>
        <a href="night_emergency_attendance.php" class="text-yellow-500 hover:text-yellow-600">Attendance</a>
        <a href="night_emergency_barcode.php" class="hover:text-yellow-600">Barcode</a>
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[36px] font-bold text-gray-800 mt-2">Night Emergency Attendance Register</p>

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
                    <th class="px-4 py-2 text-left">Supplier</th>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Driver License ID</th>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Report Time</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        // Determine the CSS class for the vehicle_no cell
                        $vehicle_cell_class = '';
                        if ($row['vehicle_status'] == 0) {
                            $vehicle_cell_class = 'red-cell';
                        }
                        
                        // Determine the CSS class for the driver_NIC cell
                        $driver_cell_class = '';
                        if ($row['driver_status'] == 0) {
                            $driver_cell_class = 'red-cell';
                        }
                        
                        echo "<tr class='hover:bg-gray-100'>";
                        echo "<td class='border px-4 py-2'>{$row['supplier']}</td>";
                        echo "<td class='border px-4 py-2 {$vehicle_cell_class}'>{$row['vehicle_no']}</td>";
                        echo "<td class='border px-4 py-2 {$driver_cell_class}'>{$row['driver_NIC']}</td>";
                        echo "<td class='border px-4 py-2'>{$row['date']}</td>";
                        echo "<td class='border px-4 py-2'>{$row['report_time']}</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' class='border px-4 py-2 text-center'>No records found.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>