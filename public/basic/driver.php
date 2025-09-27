<?php
include('../../includes/db.php');

// --- API MODE (AJAX requests) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    header('Content-Type: application/json');

    try {
        $driver_nic = $_POST['driver_NIC'];
        $calling_name = $_POST['calling_name'];
        $full_name = $_POST['full_name'];
        $phone_no = $_POST['phone_no'];
        $license_expiry_date = $_POST['license_expiry_date'];
        $vehicle_no = $_POST['vehicle_no'] ?? null;

        // Start a transaction to ensure both updates succeed or fail together
        $conn->begin_transaction();

        // 1. Update the driver details
        $sql_driver = "UPDATE driver SET calling_name=?, full_name=?, phone_no=?, license_expiry_date=? WHERE driver_NIC=?";
        $stmt_driver = $conn->prepare($sql_driver);
        $stmt_driver->bind_param('sssss', $calling_name, $full_name, $phone_no, $license_expiry_date, $driver_nic);
        
        if (!$stmt_driver->execute()) {
            throw new Exception('Driver update error: ' . $stmt_driver->error);
        }

        // 2. Update the vehicle table to assign the driver
        // First, set all other vehicles with this driver_NIC to NULL
        $sql_reset_vehicle = "UPDATE vehicle SET driver_NIC = NULL WHERE driver_NIC = ?";
        $stmt_reset_vehicle = $conn->prepare($sql_reset_vehicle);
        $stmt_reset_vehicle->bind_param('s', $driver_nic);
        if (!$stmt_reset_vehicle->execute()) {
            throw new Exception('Vehicle reset error: ' . $stmt_reset_vehicle->error);
        }
        
        // Then, update the selected vehicle
        $sql_update_vehicle = "UPDATE vehicle SET driver_NIC = ? WHERE vehicle_no = ?";
        $stmt_update_vehicle = $conn->prepare($sql_update_vehicle);
        $stmt_update_vehicle->bind_param('ss', $driver_nic, $vehicle_no);
        if (!$stmt_update_vehicle->execute()) {
            throw new Exception('Vehicle update error: ' . $stmt_update_vehicle->error);
        }

        // Commit the transaction
        $conn->commit();

        echo json_encode(['status'=>'success','message'=>'Driver updated successfully!']);
        exit;

    } catch (Exception $e) {
        // Rollback the transaction on error
        $conn->rollback();
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    header('Content-Type: application/json');

    try {
        $driver_nic = $_POST['driver_NIC'];
        $new_status = (int)$_POST['is_active'];

        $sql = "UPDATE driver SET is_active = ? WHERE driver_NIC = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $new_status, $driver_nic);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Driver status updated successfully!']);
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

$status_filter = $_GET['status'] ?? 'active';

$sql = "SELECT d.*, v.vehicle_no FROM driver d LEFT JOIN vehicle v ON d.driver_NIC = v.driver_NIC";

if ($status_filter === 'active') {
    $sql .= " WHERE d.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $sql .= " WHERE d.is_active = 0";
}

$drivers_result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
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
<div class="container">
    <div class="w-[85%] ml-[15%] flex flex-col items-center">
        <p class="text-4xl font-bold text-gray-800 mt-6 mb-4">Driver Details</p>
        <div class="w-full flex justify-between items-center mb-6">
            <a href="add_driver.php" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300">
                Add New Driver
            </a>
            <div class="flex items-center space-x-2">
                <select id="status-filter" onchange="filterStatus(this.value)" class="p-2 border rounded-md">
                    <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto bg-white shadow-md rounded-md w-full">
            <table class="min-w-full table-auto">
                <thead class="bg-blue-600 text-white">
                    <tr>
                        <th class="px-4 py-2 text-left">License ID</th>
                        <th class="px-4 py-2 text-left">Calling Name</th>
                        <th class="px-4 py-2 text-left">Full Name</th>
                        <th class="px-4 py-2 text-left">Phone No</th>
                        <th class="px-4 py-2 text-left">Vehicle No</th>
                        <th class="px-4 py-2 text-left">License Expiry Date</th>
                        <th class="px-4 py-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($drivers_result && $drivers_result->num_rows > 0): ?>
                        <?php while ($driver = $drivers_result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-100">
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($driver['driver_NIC']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($driver['calling_name']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($driver['full_name']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($driver['phone_no']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($driver['vehicle_no'] ?? 'N/A'); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($driver['license_expiry_date']); ?></td>
                                <td class="border px-4 py-2">
                                    <button 
                                        onclick='openEditModal(this)' 
                                        class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'
                                        data-driver='<?php echo htmlspecialchars(json_encode($driver)); ?>'
                                    >
                                        Edit
                                    </button>
                                    <?php if ($driver['is_active'] == 1): ?>
                                        <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($driver['driver_NIC']); ?>", 0)' class='bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>Disable</button>
                                    <?php else: ?>
                                        <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($driver['driver_NIC']); ?>", 1)' class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>Enable</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="border px-4 py-2 text-center">No drivers found</td>
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
            <h3 class="text-3xl font-extrabold text-gray-800" id="editModalTitle">Edit Driver</h3>
            <p class="text-lg text-gray-600 mt-1">License ID: <span id="editDriverNicTitle" class="font-semibold"></span></p>
        </div>

        <form id="editForm" onsubmit="handleEditSubmit(event)" class="space-y-3">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" id="edit_driver_NIC" name="driver_NIC">
            
            <div class="bg-white p-2 rounded-lg">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="edit_calling_name" class="block text-sm font-medium text-gray-700">Calling Name:</label>
                        <input type="text" id="edit_calling_name" name="calling_name" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_full_name" class="block text-sm font-medium text-gray-700">Full Name:</label>
                        <input type="text" id="edit_full_name" name="full_name" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_phone_no" class="block text-sm font-medium text-gray-700">Phone No:</label>
                        <input type="text" id="edit_phone_no" name="phone_no" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_license_expiry_date" class="block text-sm font-medium text-gray-700">License Expiry Date:</label>
                        <input type="date" id="edit_license_expiry_date" name="license_expiry_date" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_vehicle_no" class="block text-sm font-medium text-gray-700">Assign Vehicle:</label>
                        <select id="edit_vehicle_no" name="vehicle_no" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">-- Unassign --</option>
                            <?php
                            $sql_vehicles = "SELECT vehicle_no FROM vehicle WHERE driver_NIC IS NULL OR driver_NIC = ?";
                            $stmt_vehicles = $conn->prepare($sql_vehicles);
                            $stmt_vehicles->bind_param('s', $driver_nic);
                            $stmt_vehicles->execute();
                            $vehicles_result = $stmt_vehicles->get_result();
                            while ($v = $vehicles_result->fetch_assoc()):
                            ?>
                                <option value="<?php echo htmlspecialchars($v['vehicle_no']); ?>"><?php echo htmlspecialchars($v['vehicle_no']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="flex justify-end mt-8">
                <button type="submit" id="editSaveChangesButton" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="toast-container"></div>

<script src="driver.js"></script>

</body>
</html>

<?php $conn->close(); ?>