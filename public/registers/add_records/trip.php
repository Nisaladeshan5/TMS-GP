<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Set the filter month and year to the current values by default
$filterYear = date('Y');
$filterMonth = date('m');

// If month and year are submitted via the form, use those values for the filter
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['month']) && !empty($_POST['year'])) {
    $filterYear = $_POST['year'];
    $filterMonth = $_POST['month'];
}

// Fetch Petty Cash details based on the determined filter month and year
$sql = "SELECT tr.id, tr.department, tr.date, tr.amount, tr.reason, r.route AS route_name
        FROM trip tr
        LEFT JOIN route r ON tr.route_code = r.route_code
        WHERE MONTH(tr.date) = ? AND YEAR(tr.date) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $filterMonth, $filterYear);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Petty Cash Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="../Staff transport vehicle register.php" class="hover:text-yellow-600">Staff Register</a>
        <a href="additional_trip.php" class="hover:text-yellow-600">Add Record</a>
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[48px] font-bold text-gray-800 mt-2">Additional Trip Details</p>

    <form method="POST" class="mb-6 flex justify-center">
    <div class="flex items-center">
        <label for="month" class="text-lg font-medium mr-2">Filter by:</label>
        
        <select id="month" name="month" class="border border-gray-300 p-2 rounded-md">
            <?php
            $months = [
                '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June',
                '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
            ];
            foreach ($months as $num => $name) {
                // Use the $filterMonth variable to determine the selected option
                $selected = ($num == $filterMonth) ? 'selected' : '';
                echo "<option value='{$num}' {$selected}>{$name}</option>";
            }
            ?>
        </select>
        
        <select id="year" name="year" class="border border-gray-300 p-2 rounded-md ml-2">
            <?php
            // Loop for the last 5 years and the next year, for example
            $currentYear = date('Y');
            for ($i = $currentYear; $i >= $currentYear - 5; $i--) {
                // Use the $filterYear variable to determine the selected option
                $selected = ($i == $filterYear) ? 'selected' : '';
                echo "<option value='{$i}' {$selected}>{$i}</option>";
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
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Department</th>
                    <th class="px-4 py-2 text-left">Amount</th>
                    <th class="px-4 py-2 text-left">Reason</th>
                    <th class="px-4 py-2 text-left">Route</th>
                    <th class="px-4 py-2 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr id='row-{$row['id']}' class='hover:bg-gray-100'>";
                        echo "<td class='border px-4 py-2'>{$row['date']}</td>";
                        echo "<td class='border px-4 py-2'>{$row['department']}</td>";
                        echo "<td class='border px-4 py-2'>{$row['amount']}</td>";
                        echo "<td class='border px-4 py-2'>{$row['reason']}</td>";
                        
                        // Check if route_name is null and display "No Route" instead
                        $routeName = $row['route_name'] ?? 'No Route';
                        echo "<td class='border px-4 py-2'>{$routeName}</td>";
                        
                        echo "<td class='border px-4 py-2'>
                                <a href='edit_trip.php?id={$row['id']}' class='text-blue-500 hover:text-blue-700 font-bold'>Edit</a>
                            </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' class='border px-4 py-2 text-center'>No records found for this date.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
