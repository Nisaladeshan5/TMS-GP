<?php
// vehicle.php (Main List Page)
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

include '../../includes/db.php';

// PHP Message check for direct post-load messages
$message = null; 
if (isset($_GET['status']) && isset($_GET['message'])) {
    $message = [
        'status' => $_GET['status'],
        'text' => htmlspecialchars(urldecode($_GET['message']))
    ];
}

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

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

include('../../includes/header.php');
include('../../includes/navbar.php');

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
        consumption AS ct ON vehicle.fuel_efficiency = ct.c_id
    LEFT JOIN
        fuel_rate AS fr ON vehicle.rate_id = fr.rate_id
    WHERE
        vehicle.purpose = ?";

if ($status_filter === 'active') {
    $sql .= " AND vehicle.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $sql .= " AND vehicle.is_active = 0";
}

$sql .= " GROUP BY vehicle.vehicle_no"; 

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $purpose_filter);
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
        .modal { display: none; position: fixed; z-index: 3000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0, 0, 0, 0.5); justify-content: center; align-items: center; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>

<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">Vehicles</div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        <div class="relative">
            <input type="text" id="search-input" onkeyup="searchTable()" placeholder="Search Vehicle No..." class="bg-gray-700 text-white text-sm rounded-lg pl-3 pr-8 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 w-48 transition-all focus:w-64 border border-gray-600">
            <i class="fas fa-search absolute right-3 top-2 text-gray-400 text-xs"></i>
        </div>
        <span class="text-gray-400 mx-1">|</span>
        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner space-x-2">
            <select id="purpose-filter" onchange="filterVehicles(this.value)" class="bg-transparent text-white text-sm font-medium border-none outline-none cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <option value="staff" <?= $purpose_filter === 'staff' ? 'selected' : ''; ?> class="text-gray-900 bg-white">Staff</option>
                <option value="factory" <?= $purpose_filter === 'factory' ? 'selected' : ''; ?> class="text-gray-900 bg-white">Factory</option>
                <option value="held_up" <?= $purpose_filter === 'held_up' ? 'selected' : ''; ?> class="text-gray-900 bg-white">Held Up</option>
                <option value="extra" <?= $purpose_filter === 'extra' ? 'selected' : ''; ?> class="text-gray-900 bg-white">Extra</option>
                <option value="night_emergency" <?= $purpose_filter === 'night_emergency' ? 'selected' : ''; ?> class="text-gray-900 bg-white">Night Emergency</option>
                <option value="sub_route" <?= $purpose_filter === 'sub_route' ? 'selected' : ''; ?> class="text-gray-900 bg-white">Sub Route</option>
            </select>
            <span class="text-gray-400">|</span>
            <select id="status-filter" onchange="filterStatus(this.value)" class="bg-transparent text-white text-sm font-medium border-none outline-none cursor-pointer py-1 pl-1 pr-2 appearance-none hover:text-yellow-200 transition">
                <option value="active" <?= $status_filter === 'active' ? 'selected' : ''; ?> class="text-gray-900 bg-white">Active</option>
                <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : ''; ?> class="text-gray-900 bg-white">Inactive</option>
            </select>
        </div>
        <a href="add_vehicle.php" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md font-semibold text-xs tracking-wide">Add Vehicle</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    <div class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-h-[88vh]">
        <table class="w-full table-auto border-collapse" id="vehicleTable">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left">Vehicle No</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left">Supplier</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left">Capacity</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left">Fuel Efficiency</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left">Type</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php if ($vehicles_result && $vehicles_result->num_rows > 0): ?>
                    <?php while ($vehicle = $vehicles_result->fetch_assoc()): ?>
                        <tr class="hover:bg-indigo-50 border-b border-gray-100 transition duration-150">
                            <td class="px-4 py-3 font-mono font-medium text-blue-600"><?= htmlspecialchars($vehicle['vehicle_no']); ?></td>
                            <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($vehicle['supplier']); ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($vehicle['capacity']); ?></td>
                            <td class="px-4 py-3 text-gray-600">
                                <span class="font-semibold"><?= htmlspecialchars($vehicle['c_type']); ?></span> 
                                <span class="text-xs text-gray-500">(<?= htmlspecialchars($vehicle['distance']); ?>)</span>
                            </td>
                            <td class="px-4 py-3"><?= htmlspecialchars($vehicle['type']); ?></td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex justify-center gap-2">
                                    <a href='view_vehicle.php?vehicle_no=<?= urlencode($vehicle['vehicle_no']); ?>&purpose=<?= $purpose_filter ?>&status=<?= $status_filter ?>' class='bg-green-500 hover:bg-green-600 text-white py-1 px-2 rounded-md shadow-sm transition' title='View'><i class='fas fa-eye text-xs'></i></a>
                                    <a href='edit_vehicle.php?vehicle_no=<?= urlencode($vehicle['vehicle_no']) ?>&purpose=<?= $purpose_filter ?>&status=<?= $status_filter ?>' class='bg-yellow-500 hover:bg-yellow-600 text-white py-1 px-2 rounded-md shadow-sm transition' title='Edit'><i class='fas fa-edit text-xs'></i></a>
                                    <button onclick="confirmToggleStatus('<?= htmlspecialchars($vehicle['vehicle_no']); ?>', <?= $vehicle['is_active'] == 1 ? 0 : 1 ?>)" class="<?= $vehicle['is_active'] == 1 ? 'bg-red-500 hover:bg-red-600' : 'bg-green-500 hover:bg-green-600' ?> text-white py-1 px-2 rounded-md shadow-sm transition" title="<?= $vehicle['is_active'] == 1 ? 'Disable' : 'Enable' ?>">
                                        <i class='fas <?= $vehicle['is_active'] == 1 ? 'fa-ban' : 'fa-check' ?> text-xs'></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="px-6 py-4 text-center text-gray-500">No vehicles found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="confirmationModal" class="modal">
    <div class="bg-white rounded-lg shadow-2xl p-6 w-full max-w-sm text-center">
        <div class="mb-4">
            <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-2"></i>
            <h4 class="text-xl font-bold text-gray-800" id="confirmationTitle">Confirm Action</h4>
            <p class="text-sm text-gray-600 mt-2" id="confirmationMessage"></p>
        </div>
        <div class="flex justify-center gap-3">
            <button onclick="closeModal('confirmationModal')" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded-lg transition">Cancel</button>
            <button id="confirmButton" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition shadow-md">Confirm</button>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script>
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    function showToast(status, message) {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'toast ' + status + ' show';
        const icon = status === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        toast.innerHTML = `<i class="fas ${icon} toast-icon"></i><span>${message}</span>`;
        container.appendChild(toast);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Filter Functions
    function filterVehicles(purpose) {
        const status = document.getElementById('status-filter').value;
        window.location.href = `vehicle.php?purpose=${purpose}&status=${status}`;
    }

    function filterStatus(status) {
        const purpose = document.getElementById('purpose-filter').value;
        window.location.href = `vehicle.php?purpose=${purpose}&status=${status}`;
    }
    
    function searchTable() {
        const filter = document.getElementById('search-input').value.toUpperCase();
        const tr = document.getElementById('vehicleTable').getElementsByTagName('tr');
        for (let i = 1; i < tr.length; i++) {
            const td = tr[i].getElementsByTagName('td')[0];
            if (td) {
                tr[i].style.display = td.textContent.toUpperCase().indexOf(filter) > -1 ? '' : 'none';
            }
        }
    }

    function confirmToggleStatus(vehicleNo, newStatus) {
        const statusText = newStatus === 1 ? 'Enable' : 'Disable';
        document.getElementById('confirmationTitle').textContent = `${statusText} Vehicle?`;
        document.getElementById('confirmationMessage').textContent = `Proceed with ${statusText.toLowerCase()}ing vehicle ${vehicleNo}?`;
        const btn = document.getElementById('confirmButton');
        btn.onclick = () => { toggleVehicleStatus(vehicleNo, newStatus); closeModal('confirmationModal'); };
        openModal('confirmationModal');
    }

    function toggleVehicleStatus(vehicleNo, newStatus) {
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('vehicle_no', vehicleNo);
        formData.append('is_active', newStatus);
        fetch('vehicle.php', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                showToast('success', data.message);
                setTimeout(() => window.location.reload(), 800);
            } else {
                showToast('error', data.message);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const status = urlParams.get('status');
        const message = urlParams.get('message');

        if (status && message) {
            showToast(status, decodeURIComponent(message));
            // WADAGATHMA KALLA: URL eka clean karanwa filters nathi wenne nathuwa
            const newUrl = window.location.pathname + window.location.search.split('&status')[0].split('?status')[0];
            window.history.replaceState({}, document.title, newUrl || 'vehicle.php');
        }
    });
</script>
</body>
</html>
<?php $stmt->close(); $conn->close(); ?>