<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Initialize filter variables
$filterVehicleNo = '';
$selected_month = date('m');
$selected_year = date('Y');

// Check if a filter is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!empty($_POST['vehicle_no'])) {
        $filterVehicleNo = $_POST['vehicle_no'];
    }
    if (!empty($_POST['month'])) {
        $selected_month = $_POST['month'];
    }
    if (!empty($_POST['year'])) {
        $selected_year = $_POST['year'];
    }
}

// Construct the base SQL query
$sql = "SELECT
            ed.date,
            ed.vehicle_no,
            r.route AS route_name,
            stv.driver_NIC AS driver_NIC,
            ed.distance AS extra_distance,
            ed.remark AS reason
        FROM
            extra_distance ed
        LEFT JOIN
            route r ON ed.route_code = r.route_code
        LEFT JOIN
            staff_transport_vehicle_register stv ON ed.date = stv.date
            AND ed.route_code = stv.route
            AND stv.shift = 'evening'";

// Initialize an array for WHERE clauses and parameters
$whereClauses = [];
$params = [];
$paramTypes = '';

// Conditionally add the WHERE clause for vehicle number
if (!empty($filterVehicleNo)) {
    $whereClauses[] = "ed.vehicle_no = ?";
    $params[] = $filterVehicleNo;
    $paramTypes .= 's';
}

// Conditionally add the WHERE clause for month and year
$filterDate = $selected_year . '-' . $selected_month;
$whereClauses[] = "DATE_FORMAT(ed.date, '%Y-%m') = ?";
$params[] = $filterDate;
$paramTypes .= 's';

// Append WHERE clauses to the query if they exist
if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}

// Prepare the statement
$stmt = $conn->prepare($sql);

// Conditionally bind the parameters
if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$extra_distance_data = $result->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Extra Distance Details (Staff)</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="../Staff transport vehicle register.php" class="hover:text-yellow-600">Staff Register</a>
        <a href="extra_distance.php" class="hover:text-yellow-600">Add Record</a>
    </div>
</div>

<div class="container flex p-4" style="flex-direction: column; align-items: center;">
    <div class="w-[85%] ml-[15%] ">
        <div class="w-full flex justify-center items-center">
            <p class="text-[48px] font-bold text-gray-800">Extra Distance Details (Staff)</p>
        </div>

        <form method="POST" class="mb-6 flex justify-center items-center gap-4">
            <div class="flex items-center">
                <label for="vehicle_no" class="text-lg font-medium mr-2">Filter by Vehicle No:</label>
                <input type="text" id="vehicle_no" name="vehicle_no" class="border border-gray-300 p-2 rounded-md"
                    value="<?php echo htmlspecialchars($filterVehicleNo); ?>" placeholder="Enter vehicle no.">
            </div>
            <div class="flex items-center gap-2">
                <label for="month" class="text-lg font-medium">Month:</label>
                <div class="relative border border-gray-400 rounded-lg">
                    <select name="month" id="month" class="w-full pl-3 pr-10 py-2 text-base border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 ease-in-out appearance-none">
                        <?php for ($m=1; $m<=12; $m++): ?>
                            <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($selected_month == sprintf('%02d', $m)) ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                        <i class="fas fa-chevron-down text-sm"></i>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <label for="year" class="text-lg font-medium">Year:</label>
                <div class="relative border border-gray-400 rounded-lg">
                    <select name="year" id="year" class="w-full pl-3 pr-10 py-2 text-base border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 ease-in-out appearance-none">
                        <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                        <i class="fas fa-chevron-down text-sm"></i>
                    </div>
                </div>
            </div>
            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md hover:bg-blue-600">Filter</button>
        </form>

        <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6">
            <table class="min-w-full table-auto">
                <thead class="bg-blue-600 text-white">
                    <tr>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Vehicle No</th>
                        <th class="px-4 py-2 text-left">Route</th>
                        <th class="px-4 py-2 text-left">Driver NIC</th>
                        <th class="px-4 py-2 text-left">Extra Distance (km)</th>
                        <th class="px-4 py-2 text-left">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($extra_distance_data)) : ?>
                        <tr>
                            <td colspan="6" class="border px-4 py-2 text-center text-gray-500">No extra distance records found.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($extra_distance_data as $row) : ?>
                            <tr>
                                <td class='border px-4 py-2'><?php echo htmlspecialchars($row['date']); ?></td>
                                <td class='border px-4 py-2'><?php echo htmlspecialchars($row['vehicle_no']); ?></td>
                                <td class='border px-4 py-2'><?php echo htmlspecialchars($row['route_name']); ?></td>
                                <td class='border px-4 py-2'><?php echo htmlspecialchars($row['driver_NIC']); ?></td>
                                <td class='border px-4 py-2'><?php echo htmlspecialchars($row['extra_distance']); ?></td>
                                <td class='border px-4 py-2'><?php echo htmlspecialchars($row['reason']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>

<?php 
include('../../../includes/footer.php');
?>