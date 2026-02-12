<?php
require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

include('../../../../includes/db.php');
include('../../../../includes/config.php');

$errorMessage = '';
$successMessage = '';
$factory_transport_supplier_code = '';

// Fetch routes
$sqlRoutes = "SELECT route_code, route 
                FROM route 
                WHERE purpose='factory' AND is_active=1
                ORDER BY CAST(REPLACE(SUBSTRING_INDEX(route_code, '-', -1), 'V', '') AS UNSIGNED) ASC;";
$resultRoutes = $conn->query($sqlRoutes);
$routes = [];
if ($resultRoutes) {
    while ($row = $resultRoutes->fetch_assoc()) {
        $routes[] = $row;
    }
}

// Fetch drivers
$sqlDrivers = "SELECT driver_NIC, calling_name FROM driver";
$resultDrivers = $conn->query($sqlDrivers);
$drivers = [];
if ($resultDrivers) {
    while ($row = $resultDrivers->fetch_assoc()) {
        $drivers[] = $row;
    }
}

// Handle submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $route = $_POST['route'];
    $actualVehicleNo = $_POST['actual_vehicle_no'];
    $driver = $_POST['driver'];
    $date = $_POST['date'];
    $shift = $_POST['shift'];
    $inTime = $_POST['in_time'];

    $conn->autocommit(false);

    try {
        // Fetch assigned vehicle & supplier
        $sqlAssignedDetails = "
            SELECT 
                r.vehicle_no, 
                v.driver_NIC, 
                v.supplier_code
            FROM route r 
            JOIN vehicle v ON r.vehicle_no = v.vehicle_no 
            WHERE r.route_code = ?";
        $stmt = $conn->prepare($sqlAssignedDetails);
        $stmt->bind_param('s', $route);
        $stmt->execute();
        $resultAssignedDetails = $stmt->get_result();
        $routeDetails = $resultAssignedDetails->fetch_assoc();
        $stmt->close();

        if (!$routeDetails) throw new Exception("Route details not found.");

        $assignedVehicle = $routeDetails['vehicle_no'];
        $assignedDriver = $routeDetails['driver_NIC'];
        $factory_transport_supplier_code = $routeDetails['supplier_code'];

        if (empty($factory_transport_supplier_code)) {
            throw new Exception("Supplier code missing for assigned vehicle.");
        }

        $vehicleStatus = ($assignedVehicle === $actualVehicleNo) ? 1 : 0;
        $driverStatus = ($assignedDriver === $driver) ? 1 : 0;

        // Prevent duplicates
        $checkSql = "SELECT COUNT(*) FROM factory_transport_vehicle_register 
                     WHERE route = ? AND date = ? AND shift = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param('sss', $route, $date, $shift);
        $checkStmt->execute();
        $row = $checkStmt->get_result()->fetch_row();
        $checkStmt->close();

        if ($row[0] > 0) {
            throw new Exception("Duplicate entry for this route/date/shift.");
        }

        // INSERT main record
        $sql = "INSERT INTO factory_transport_vehicle_register 
                (supplier_code, vehicle_no, actual_vehicle_no, vehicle_status, driver_NIC, driver_status, date, shift, route, in_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            'sssissssss',
            $factory_transport_supplier_code,
            $assignedVehicle,
            $actualVehicleNo,
            $vehicleStatus,
            $driver,
            $driverStatus,
            $date,
            $shift,
            $route,
            $inTime
        );

        if (!$stmt->execute()) {
            throw new Exception("DB Insert Error: " . $stmt->error);
        }
        $stmt->close();

        $conn->commit();
        header("Location: " . BASE_URL . "registers/factory_transport_vehicle_register.php?status=success&message=" . urlencode("Record added successfully."));
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $errorMessage = "Transaction Failed: " . $e->getMessage();
    }

    $conn->autocommit(true);
}

include('../../../../includes/header.php');
include('../../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Factory Transport Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; }
        .toast { padding: 1rem; border-radius: .5rem; color: white; margin-bottom: .5rem; opacity:0; transform:translateY(-20px); transition:.3s; }
        .toast.show { opacity:1; transform:translateY(0); }
        .success { background:#4CAF50; }
        .error { background:#F44336; }

    </style>
</head>

<script>
const SESSION_TIMEOUT_MS = 32400000; 
setTimeout(() => {
    alert("Session expired. Login again.");
    window.location.href = "/TMS/includes/client_logout.php";
}, SESSION_TIMEOUT_MS);
</script>

<body class="bg-gray-100">

<div id="toast-container"></div>

<?php if (!empty($errorMessage)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    showToast("<?= htmlspecialchars($errorMessage) ?>", 'error');
});
</script>
<?php endif; ?>

<div class="w-[85%] ml-[15%]">
    <div class="max-w-3xl mx-auto p-8 bg-white shadow-md rounded mt-10">
        <h1 class="text-3xl font-bold mb-6">Add Factory Transport Record</h1>

        <form method="POST" class="space-y-6" onsubmit="return validateTimeRange()">

            <div class="grid grid-cols-2 gap-6">
                <div>
                    <label>Route:</label>
                    <select id="route" name="route" class="p-2 w-full border rounded" onchange="fetchVehicleAndDriver()" required>
                        <option value="" disabled selected>Select</option>
                        <?php foreach ($routes as $r): ?>
                            <option value="<?= $r['route_code'] ?>">
                                <?= $r['route'] ?> (<?= $r['route_code'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Assigned Vehicle No:</label>
                    <input id="vehicle_no" name="vehicle_no" readonly class="p-2 w-full border rounded bg-gray-200">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <div>
                    <label>Actual Vehicle No:</label>
                    <input type="text" id="actual_vehicle_no" name="actual_vehicle_no" required class="p-2 w-full border rounded">
                </div>

                <div>
                    <label>Driver:</label>
                    <select id="driver" name="driver" class="p-2 w-full border rounded" required>
                        <option value="" disabled selected>Select Driver</option>
                        <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['driver_NIC'] ?>"><?= $d['calling_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <div>
                    <label>Date:</label>
                    <input type="date" name="date" required value="<?= date('Y-m-d') ?>" class="p-2 w-full border rounded">
                </div>

                <div>
                    <label>Shift:</label>
                    <select id="shift" name="shift" class="p-2 w-full border rounded" onchange="updateTimeLimits()" required>
                        <option disabled selected>Select</option>
                        <option value="Morning">Morning</option>
                        <option value="Evening">Evening</option>
                    </select>
                </div>
            </div>

            <div>
                <label>In Time:</label>
                <input type="time" id="in_time" name="in_time" class="p-2 w-full border rounded" required>
                <p id="timeWarning" class="hidden text-red-500 text-sm mt-1">
                    Time must match shift range.
                </p>
            </div>

            <div class="flex justify-between gap-4">
                <a href="<?= BASE_URL ?>/registers/factory_transport_vehicle_register.php" class="px-5 py-2 border rounded">Cancel</a>
                <button class="px-6 py-2 bg-indigo-600 text-white rounded">Add Record</button>
            </div>
        </form>
    </div>
</div>

<script>
function showToast(message, type) {
    const box = document.getElementById("toast-container");
    const t = document.createElement("div");
    t.className = `toast ${type}`;
    t.innerText = message;
    box.appendChild(t);
    setTimeout(() => t.classList.add("show"), 10);
    setTimeout(() => { t.classList.remove("show"); t.remove(); }, 2500);
}

function fetchVehicleAndDriver() {
    const routeCode = document.getElementById('route').value;
    fetch(`../get_vehicle_driver.php?route_code=${routeCode}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('vehicle_no').value = data.vehicle_no || "";
            if (!document.getElementById('actual_vehicle_no').value)
                document.getElementById('actual_vehicle_no').value = data.vehicle_no || "";
            if (data.driver)
                document.getElementById('driver').value = data.driver;
        })
        .catch(() => showToast("Error loading vehicle/driver", "error"));
}

function updateTimeLimits() {
    const shift = document.getElementById('shift').value;
    const time = document.getElementById('in_time');

    if (shift === "Morning") {
        time.min = "04:00";
        time.max = "11:59";
    } else {
        time.min = "12:00";
        time.max = "23:59";
    }
}

function validateTimeRange() {
    const shift = document.getElementById('shift').value;
    const time = document.getElementById('in_time').value;
    const warning = document.getElementById('timeWarning');

    let min = shift === "Morning" ? "04:00" : "12:00";
    let max = shift === "Morning" ? "11:59" : "23:59";

    if (time < min || time > max) {
        warning.classList.remove("hidden");
        return false;
    } else {
        warning.classList.add("hidden");
        return true;
    }
}
</script>

</body>
</html>
