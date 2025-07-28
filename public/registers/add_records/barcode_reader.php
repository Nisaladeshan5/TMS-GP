<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// If a route code is provided from the barcode scan (through GET or POST), fetch the related data
$routeCode = isset($_GET['route_code']) ? $_GET['route_code'] : '';

$routeName = '';
$vehicleNo = '';
$driverName = '';

// If route code is provided, fetch the relevant details
if ($routeCode) {
    $sql = "SELECT r.route, v.vehicle_no, d.calling_name AS driver_name
            FROM route r
            JOIN vehicle v ON r.vehicle_no = v.vehicle_no
            JOIN driver d ON v.driver_NIC = d.driver_NIC
            WHERE r.route_code = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $routeCode);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $routeName = $row['route'];
        $vehicleNo = $row['vehicle_no'];
        $driverName = $row['driver_name'];
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

<div class="container" style="width: 40%; margin-left: 38%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[40px] font-bold text-gray-800 mt-10 mb-4">Staff Transport Vehicle Details</p>

    <!-- Vehicle Details Form -->
    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6 w-full">
        <form method="POST" class="p-6">
            <div class="grid grid-cols-2 gap-4">
                <!-- Route Code (from Barcode) -->
                <div>
                    <label for="routeCode" class="block text-lg">Route Code (Scan Barcode)</label>
                    <input type="text" id="routeCode" name="routeCode" class="w-full p-2 border border-gray-300 rounded-md" 
                           value="<?= htmlspecialchars($routeCode) ?>" oninput="fetchRouteDetails(this.value)" autofocus required>
                </div>
                
                <!-- Route Name (Auto-filled based on Route Code) -->
                <div>
                    <label for="routeName" class="block text-lg">Route</label>
                    <input type="text" id="routeName" name="routeName" class="w-full p-2 border border-gray-300 rounded-md" 
                           value="<?= htmlspecialchars($routeName) ?>" readonly>
                </div>

                <!-- Vehicle No (Auto-filled based on Route Code) -->
                <div>
                    <label for="vehicleNo" class="block text-lg">Vehicle No</label>
                    <input type="text" id="vehicleNo" name="vehicleNo" class="w-full p-2 border border-gray-300 rounded-md" 
                           value="<?= htmlspecialchars($vehicleNo) ?>">
                </div>

                <!-- Driver Name (Auto-filled based on Route Code) -->
                <div>
                    <label for="driverName" class="block text-lg">Driver</label>
                    <input type="text" id="driverName" name="driverName" class="w-full p-2 border border-gray-300 rounded-md" 
                           value="<?= htmlspecialchars($driverName) ?>">
                </div>
            </div>

            <div class="flex justify-center mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">Submit</button>
            </div>
        </form>
    </div>
</div>

<script>
// Fetch route details based on scanned route code
function fetchRouteDetails(routeCode) {
    if (routeCode.length > 0) {
        // Make an AJAX call to fetch the details based on the route code
        fetch('fetch_route_details.php?route_code=' + routeCode)
            .then(response => response.json())
            .then(data => {
                document.getElementById('routeName').value = data.route || '';
                document.getElementById('vehicleNo').value = data.vehicle_no || '';
                document.getElementById('driverName').value = data.driver_name || '';
            })
            .catch(error => console.error('Error fetching route details:', error));
    }
}
</script>

</body>
</html>

<?php 
include('../../../includes/footer.php');
?>
