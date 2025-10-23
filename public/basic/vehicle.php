<?php
include '../../includes/db.php';

// Define a flag for AJAX requests
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// --- API MODE (AJAX requests) ---

if (isset($_GET['view_vehicle_no'])) {
    if (!$is_ajax) {
        http_response_code(403);
        exit();
    }
    header('Content-Type: application/json');
    $vehicle_no = $_GET['view_vehicle_no'];
    $sql = "SELECT
            vehicle.vehicle_no,
            vehicle.supplier_code,
            supplier.supplier,
            vehicle.capacity,
            vehicle.fuel_efficiency, -- This is the c_id
            ct.c_type,                 -- New: The consumption type
            vehicle.type,
            vehicle.purpose,
            vehicle.license_expiry_date,
            vehicle.insurance_expiry_date,
            vehicle.is_active,
            fr.type AS fuel_type,
            fr.rate_id
        FROM
            vehicle
        LEFT JOIN
            supplier ON vehicle.supplier_code = supplier.supplier_code
        LEFT JOIN
            fuel_rate AS fr ON vehicle.rate_id = fr.rate_id
        LEFT JOIN
            consumption AS ct ON vehicle.fuel_efficiency = ct.c_id -- New: Join to get the consumption type
        WHERE
            vehicle.vehicle_no = ?;";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $vehicle_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $vehicle = $result->fetch_assoc();
    echo json_encode($vehicle ?: null);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    if (!$is_ajax) {
        http_response_code(403);
        exit();
    }
    header('Content-Type: application/json');

    try {
        $vehicle_no = $_POST['vehicle_no'];
        $supplier_code = $_POST['supplier_code'];
        $capacity = (int)$_POST['capacity'];
        $km_per_liter = $_POST['km_per_liter'];
        $type = $_POST['type'];
        $purpose = $_POST['purpose'];
        $license_expiry_date = $_POST['license_expiry_date'];
        $insurance_expiry_date = $_POST['insurance_expiry_date'];
        $rate_id = $_POST['rate_id'];

        $sql = "UPDATE vehicle 
                SET supplier_code=?, capacity=?, fuel_efficiency=?, type=?, purpose=?, 
                    license_expiry_date=?, insurance_expiry_date=?, rate_id=?
                WHERE vehicle_no=?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sisssssss', $supplier_code, $capacity, $km_per_liter, $type, $purpose,
                                     $license_expiry_date, $insurance_expiry_date, $rate_id, $vehicle_no);

        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Vehicle updated successfully!']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Database error: '.$stmt->error]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    if (!$is_ajax) {
        http_response_code(403);
        exit();
    }
    header('Content-Type: application/json');

    try {
        $vehicle_no = $_POST['vehicle_no'];
        $new_status = (int)$_POST['is_active'];

        $sql = "UPDATE vehicle SET is_active = ? WHERE vehicle_no = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $new_status, $vehicle_no);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Vehicle status updated successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// --- NORMAL PAGE LOAD (HTML) ---
include('../../includes/header.php');
include('../../includes/navbar.php');

// Fetch all suppliers for the dropdown
$suppliers_sql = "SELECT supplier_code, supplier FROM supplier ORDER BY supplier";
$suppliers_result = $conn->query($suppliers_sql);
$suppliers = [];
if ($suppliers_result->num_rows > 0) {
    while ($row = $suppliers_result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

$fuel_efficiency = [];
$fuel_efficiency_sql = "SELECT c_id, c_type FROM consumption ORDER BY c_id";
$fuel_efficiency_result = $conn->query($fuel_efficiency_sql);
if ($fuel_efficiency_result) {
    while ($row = $fuel_efficiency_result->fetch_assoc()) {
        $fuel_efficiencies[] = $row;
    }
}

// Fetch all fuel rates for the dropdown
$fuel_rates_sql = "SELECT rate_id, type FROM fuel_rate ORDER BY type";
$fuel_rates_result = $conn->query($fuel_rates_sql);
$fuel_rates = [];
if ($fuel_rates_result->num_rows > 0) {
    while ($row = $fuel_rates_result->fetch_assoc()) {
        $fuel_rates[] = $row;
    }
}

$purpose_filter = $_GET['purpose'] ?? 'staff';
$status_filter = $_GET['status'] ?? 'active';

$sql = "SELECT
        vehicle.*,
        supplier.supplier,
        ct.c_type,
        ct.distance,
        fr.type AS fuel_type
    FROM
        vehicle
    LEFT JOIN
        supplier ON vehicle.supplier_code = supplier.supplier_code
    LEFT JOIN
        consumption AS ct ON vehicle.fuel_efficiency = ct.c_id  -- New: Join for consumption type
    LEFT JOIN
        fuel_rate AS fr ON vehicle.rate_id = fr.rate_id              -- New: Join for fuel rate/type
    WHERE
        vehicle.purpose = ?";
$types = "s";
$params = [$purpose_filter];

if ($status_filter === 'active') {
    $sql .= " AND vehicle.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $sql .= " AND vehicle.is_active = 0";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$vehicles_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* CSS for modals and toast notifications */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 600px;
            position: relative;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
        }

        .toast {
            display: none;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }

        .toast.show {
            display: flex;
            align-items: center;
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            background-color: #4CAF50;
            color: white;
        }

        .toast.error {
            background-color: #F44336;
            color: white;
        }

        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
    <div class="text-lg font-semibold ml-3">Vehicle</div>
    <div class="flex gap-4">
        <a href="https://l.de-1.a.mimecastprotect.com/l?domain=sharepoint.com&t=AQICAHjDNlln1fEnh8m5FGLoT34o0KqKE54tJvXfX_jZUbir7gGYXpGnmbYqnekGpwHsm4lwAAAAzjCBywYJKoZIhvcNAQcGoIG9MIG6AgEAMIG0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDGWcu_dIjrGTJHMvvgIBEICBhumQP8i077SMjhi4DVpB78tXB99JFKuM0tAw4ftXGNnoGXn3ZXHCso8igpWu96ljUepJqL5RUj8zaLpCSs-3S7aA1aRRYgB8sTFqM2GFJQ3mAuZCB4aggIBCB88O_yq3Zjd3uFZGALavn2v4_LixolZWUT1vI-onbON_5AlV-djt1Ct3ag61&r=/s/h9eACJNOQfyjVyrrcVB0vXztkYpznYsocKv1n_" target="_blank" class="hover:text-yellow-600 text-yellow-500 font-bold">View Documents</a>
    </div>
</div>

<div class="container ">
    <div class="w-[85%] ml-[15%] flex flex-col items-center">
        <p class="text-4xl font-bold text-gray-800 mt-6 mb-4 flex items-start">Vehicle Details</p>
        <div class="w-full flex justify-between items-center mb-6">
            <a href="add_vehicle.php" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300">
                Add New Vehicle
            </a>
            <div class="flex items-center space-x-2">
                <label for="status-filter" class="text-gray-700 font-semibold">Filter by Status:</label>
                <select id="status-filter" onchange="filterStatus(this.value)" class="p-2 border rounded-md">
                    <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <label for="purpose-filter" class="text-gray-700 font-semibold">Filter by Purpose:</label>
                <select id="purpose-filter" onchange="filterVehicles(this.value)" class="p-2 border rounded-md">
                    <option value="staff" <?php echo ($purpose_filter === 'staff') ? 'selected' : ''; ?>>Staff</option>
                    <option value="workers" <?php echo ($purpose_filter === 'workers') ? 'selected' : ''; ?>>Workers</option>
                    <option value="night_emergency" <?php echo ($purpose_filter === 'night_emergency') ? 'selected' : ''; ?>>Night Emergency</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto bg-white shadow-md rounded-md w-full">
            <table class="min-w-full table-auto">
                <thead class="bg-blue-600 text-white">
                    <tr>
                        <th class="px-4 py-2 text-left">Vehicle No</th>
                        <th class="px-4 py-2 text-left">Supplier</th>
                        <th class="px-4 py-2 text-left">Capacity</th>
                        <th class="px-4 py-2 text-left">Fuel Efficiency</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($vehicles_result && $vehicles_result->num_rows > 0): ?>
                        <?php while ($vehicle = $vehicles_result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-100">
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($vehicle['vehicle_no']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($vehicle['supplier']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($vehicle['capacity']); ?></td>
                                <td class="border px-4 py-2">
                                    <?php echo htmlspecialchars($vehicle['c_type']); ?> (<?php echo htmlspecialchars($vehicle['distance']); ?>)
                                </td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($vehicle['type']); ?></td>
                                <td class="border px-4 py-2">
                                    <button onclick='viewVehicleDetails("<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>")' class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>View</button>
                                    <button onclick='openEditModal("<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>")' class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>Edit</button>
                                    <?php if ($vehicle['is_active'] == 1): ?>
                                        <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>", 0)' class='bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>Disable</button>
                                    <?php else: ?>
                                        <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>", 1)' class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>Enable</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="border px-4 py-2 text-center">No vehicles found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content !max-w-4xl !p-6 bg-gray-50 rounded-xl shadow-2xl">
        <span class="close" onclick="closeModal('editModal')">&times;</span>
        
        <div class="mb-1 pb-1 border-b border-gray-200">
            <h3 class="text-3xl font-extrabold text-gray-800" id="editModalTitle">Edit Vehicle</h3>
            <p class="text-lg text-gray-600 mt-1">Vehicle No: <span id="editVehicleNoTitle" class="font-semibold"></span></p>
        </div>

        <form id="editForm" onsubmit="handleEditSubmit(event)" class="space-y-3">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" id="edit_vehicle_no" name="vehicle_no">
            
            <div class="bg-white p-2 rounded-lg">
                <h4 class="text-xl font-bold mb-4 text-blue-600">Basic Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="edit_supplier_code" class="block text-sm font-medium text-gray-700">Supplier:</label>
                        <select id="edit_supplier_code" name="supplier_code" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>">
                                    <?php echo htmlspecialchars($supplier['supplier']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="edit_capacity" class="block text-sm font-medium text-gray-700">Capacity:</label>
                        <input type="number" id="edit_capacity" name="capacity" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_km_per_liter" class="block text-sm font-medium text-gray-700">Fuel Efficiency:</label>
                        <select id="edit_km_per_liter" name="km_per_liter" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php foreach ($fuel_efficiencies as $fuel_efficiency): ?>
                                <option value="<?php echo htmlspecialchars($fuel_efficiency['c_id']); ?>">
                                    <?php echo htmlspecialchars($fuel_efficiency['c_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="edit_type" class="block text-sm font-medium text-gray-700">Type:</label>
                        <select id="edit_type" name="type" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="van">Van</option>
                            <option value="bus">Bus</option>
                            <option value="car">Car</option>
                            <option value="wheel">Wheel</option>
                            <option value="motor bike">Motor Bike</option>
                        </select>
                    </div>
                    <div>
                        <label for="edit_rate_id" class="block text-sm font-medium text-gray-700">Fuel Type:</label>
                        <select id="edit_rate_id" name="rate_id" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php foreach ($fuel_rates as $rate): ?>
                                <option value="<?php echo htmlspecialchars($rate['rate_id']); ?>">
                                    <?php echo htmlspecialchars($rate['type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="edit_purpose" class="block text-sm font-medium text-gray-700">Purpose:</label>
                        <select id="edit_purpose" name="purpose" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="staff">Staff</option>
                            <option value="workers">Workers</option>
                            <option value="night_emergency">Night Emergency</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-2 rounded-lg">
                <h4 class="text-xl font-bold mb-4 text-blue-600">Document Expiry Dates</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="edit_license_expiry_date" class="block text-sm font-medium text-gray-700">License Expiry Date:</label>
                        <input type="date" id="edit_license_expiry_date" name="license_expiry_date" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_insurance_expiry_date" class="block text-sm font-medium text-gray-700">Insurance Expiry Date:</label>
                        <input type="date" id="edit_insurance_expiry_date" name="insurance_expiry_date" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                </div>
            </div>

            <div class="flex justify-end mt-8">
                <button type="submit" id="editSaveChangesButton" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="viewModal" class="modal">
    <div class="modal-content !max-w-4xl !p-6 bg-gray-50 rounded-xl shadow-2xl">
        <span class="close" onclick="closeModal('viewModal')">&times;</span>
        
        <div class="mb-1 pb-1 border-b border-gray-200">
            <h3 class="text-3xl font-extrabold text-gray-800" id="viewModalTitle">Vehicle Details</h3>
            <p class="text-lg text-gray-600 mt-1">Vehicle No: <span id="viewVehicleNo" class="font-semibold"></span></p>
        </div>

        <div id="vehicleDetails" class="space-y-2">
            <div class="bg-white p-2 rounded-lg transition-all duration-300 transform hover:scale-[1.01]">
                <h4 class="text-xl font-bold mb-4 text-blue-600">Basic Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Supplier</p>
                        <p id="viewSupplier" class="text-base font-semibold "></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Capacity</p>
                        <p id="viewCapacity" class="text-base font-semibold"></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Fuel Efficiency</p>
                        <p id="viewKmPerLiter" class="text-base font-semibold"></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Type</p>
                        <p id="viewType" class="text-base font-semibold"></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Fuel Type</p>
                        <p id="viewFuelType" class="text-base font-semibold"></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Purpose</p>
                        <p id="viewPurpose" class="text-base font-semibold"></p>
                    </div>
                </div>
            </div>

            <div class="bg-white p-2 rounded-lg transition-all duration-300 transform hover:scale-[1.01]">
                <h4 class="text-xl font-bold mb-4 text-blue-600">Document Expiry Dates</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">License Expiry Date</p>
                        <p id="viewLicenseExpiry" class="text-base font-semibold"></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Insurance Expiry Date</p>
                        <p id="viewInsuranceExpiry" class="text-base font-semibold"></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end mt-8">
            <button id="closeViewButton" onclick="closeModal('viewModal')" class="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300">Close</button>
        </div>
    </div>
</div>

<div id="confirmationModal" class="modal">
    <div class="modal-content p-6 max-w-sm mx-auto bg-white rounded-xl shadow-lg text-center">
        <div class="text-gray-900 mb-4">
            <h4 class="text-xl font-bold" id="confirmationTitle"></h4>
            <p class="text-sm text-gray-600 mt-2" id="confirmationMessage"></p>
        </div>
        <div class="flex justify-center space-x-4">
            <button id="cancelButton" onclick="closeModal('confirmationModal')" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-4 rounded-lg">Cancel</button>
            <button id="confirmButton" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">Confirm</button>
        </div>
    </div>
</div>


<div id="toast-container"></div>

<script src="vehicle.js"></script>

</body>
</html>

<?php $conn->close(); ?>