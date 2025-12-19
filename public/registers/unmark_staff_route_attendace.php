<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

include('../../includes/db.php'); 
include('../../includes/header.php');
include('../../includes/navbar.php');

// --- 1. Get Filter Parameters (Date and Shift) ---

$filterDate = date('Y-m-d'); // Default to today (YYYY-MM-DD)
$filterShift = $_POST['shift'] ?? 'morning'; // Default to morning shift

// If a form is submitted, update the filters
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $filterDate = $_POST['filter_date'] ?? date('Y-m-d'); 
    $filterShift = $_POST['shift'] ?? 'morning'; 
}

$displayDate = date('F j, Y', strtotime($filterDate));

// --- 2. Fetch All Eligible Routes ---
// This list is the baseline: all routes that SHOULD be running (like '____S%').

$routes = [];
$all_route_codes = []; 
$sql_routes = "SELECT route_code, route 
                FROM route 
                WHERE route_code LIKE '____S%'
                ORDER BY route ASC";
$result_routes = $conn->query($sql_routes);
while ($row = $result_routes->fetch_assoc()) {
    $routes[] = $row;
    // Store all codes and names for the absent route check
    $all_route_codes[$row['route_code']] = $row['route']; 
}


// --- 3. Fetch ATTENDED Route Codes for the SPECIFIC DATE/Shift ---
// These are the routes that DID run (i.e., exist in the register for the chosen date/shift).

$attended_route_codes_for_shift = []; 
$sql_attended_routes = "SELECT 
                            DISTINCT route
                        FROM 
                            staff_transport_vehicle_register 
                        WHERE 
                            DATE(date) = ? AND 
                            shift = ? AND is_active = 1";
                            
$stmt_attended_routes = $conn->prepare($sql_attended_routes);
$stmt_attended_routes->bind_param('ss', $filterDate, $filterShift); 
$stmt_attended_routes->execute();
$result_attended_routes = $stmt_attended_routes->get_result();

while ($row = $result_attended_routes->fetch_assoc()) {
    $attended_route_codes_for_shift[] = $row['route'];
}
$stmt_attended_routes->close();


// --- 4. Determine ABSENT Routes (All Routes - Attended Routes) ---

// Get the difference between all eligible routes and the attended routes
$absent_route_codes = array_diff(array_keys($all_route_codes), $attended_route_codes_for_shift);

// Create a final list of absent routes with their names
$absent_routes_list = [];
foreach ($absent_route_codes as $code) {
    if (isset($all_route_codes[$code])) {
        $absent_routes_list[$code] = $all_route_codes[$code];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Absent Route List</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<script>
    // ... (Session timeout script remains the same) ...
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php";

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100">
<div class="w-[85%] ml-[15%]">
<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4"> 
        <?php if ($is_logged_in): ?>
            <a href="staff transport vehicle register.php" class="hover:text-yellow-600">Back</a>
            <p class="text-yellow-400 font-bold">Unmark Routes</p>
            <a href="staff_route_attendace.php" class="hover:text-yellow-600">Attendance</a>
            <?php
            // Now $user_role is safely defined
            if ($user_role === 'manager' || $user_role === 'super admin' || $user_role === 'admin' || $user_role === 'developer') {
            ?>
            <a href="add_records/adjustment_staff.php" class="hover:text-yellow-600">Adjustments</a>
            <a href="add_records/add_staff_record.php" class="hover:text-yellow-600">Add Record</a>
            <?php
            }
            ?>
        <?php endif; ?>
    </div>
</div>

<div class="container " style="display: flex; flex-direction: column; align-items: center;">
    <div class="p-4 bg-white shadow-xl rounded-xl border border-gray-200 mt-3">
        <p class="text-3xl font-bold text-gray-800 mt-2 mb-4">Route Vehicle Absent List</p>

        <form method="POST" class="mb-6 flex justify-center items-center p-4 bg-white shadow-lg rounded-xl border border-gray-200">
            <div class="flex items-center space-x-4">
                
                <label for="filter_date" class="text-lg font-medium">Select Date:</label>
                <input type="date" id="filter_date" name="filter_date" class="border border-gray-300 p-2 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500"
                    value="<?php echo htmlspecialchars($filterDate); ?>" required>
                
                <label for="shift" class="text-lg font-medium">Select Shift:</label>
                <select id="shift" name="shift" class="border border-gray-300 p-2 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="morning" <?php echo $filterShift === 'morning' ? 'selected' : ''; ?>>Morning</option>
                    <option value="evening" <?php echo $filterShift === 'evening' ? 'selected' : ''; ?>>Evening</option>
                </select>

                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg shadow-md hover:bg-blue-700 transition duration-150 font-semibold">Generate List</button>
            </div>
        </form>
        <h3 class="text-2xl font-bold text-red-700 mb-4 p-3 bg-red-100 border border-red-300 rounded-lg shadow-md text-center">
            ❌ Absent Routes (<?php echo htmlspecialchars(ucfirst($filterShift)); ?> Shift) on <?php echo htmlspecialchars($displayDate); ?>
        </h3>
        
        <p class="text-xl font-semibold text-gray-800 mb-4 text-center">Total Absent Routes: <span class="text-red-600 font-extrabold"><?php echo count($absent_routes_list); ?></span></p>

        <?php if (!empty($absent_routes_list)): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 p-5 bg-white shadow-inner rounded-lg border border-gray-200">
                <?php foreach ($absent_routes_list as $code => $name): ?>
                    <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-base font-medium text-gray-900 shadow-sm transition duration-150 hover:bg-red-100">
                        <span class="text-red-600 font-bold"><?php echo htmlspecialchars($code); ?>:</span> <?php echo htmlspecialchars($name); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="p-6 bg-green-100 text-green-700 text-xl font-semibold border-2 border-green-400 rounded-xl mb-6 text-center shadow-lg">
                ✅ Records exist for all Routes in the <?php echo htmlspecialchars($filterShift); ?> Shift on <?php echo htmlspecialchars($displayDate); ?>.
            </p>
        <?php endif; ?>
        </div> </div> </div> </body>
</html>