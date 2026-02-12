<?php
// vehicle.php (Main List Page)
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
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

include '../../includes/db.php';

$message = null; 
if (isset($_GET['status']) && isset($_GET['message'])) {
    $message = [
        'status' => $_GET['status'],
        'text' => htmlspecialchars(urldecode($_GET['message']))
    ];
}

// Define a flag for AJAX requests
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// --- API MODE (AJAX requests for TOGGLE STATUS only) ---

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

// Fetching filters and data as before
$purpose_filter = $_GET['purpose'] ?? 'staff';
$status_filter = $_GET['status'] ?? 'active';

// Updated SQL with GROUP BY to prevent duplicates
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

$types = "s";
$params = [$purpose_filter];

if ($status_filter === 'active') {
    $sql .= " AND vehicle.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $sql .= " AND vehicle.is_active = 0";
}

// Prevent Duplicate Rows
$sql .= " GROUP BY vehicle.vehicle_no"; 

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Toast CSS */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
        
        /* Modal Styling */
        .modal { display: none; position: fixed; z-index: 3000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0, 0, 0, 0.5); justify-content: center; align-items: center; }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 
        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>

<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Vehicles
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <div class="relative">
            <input type="text" id="search-input" onkeyup="searchTable()" placeholder="Search Vehicle No..." 
                   class="bg-gray-700 text-white text-sm rounded-lg pl-3 pr-8 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 w-48 transition-all focus:w-64 placeholder-gray-400 border border-gray-600">
            <i class="fas fa-search absolute right-3 top-2 text-gray-400 text-xs"></i>
        </div>

        <span class="text-gray-400 mx-1">|</span>

        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner space-x-2">
            
            <select id="purpose-filter" onchange="filterVehicles(this.value)" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <option value="staff" <?php echo (isset($purpose_filter) && $purpose_filter === 'staff') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Staff</option>
                <option value="factory" <?php echo (isset($purpose_filter) && $purpose_filter === 'factory') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Factory</option>
                <option value="held_up" <?php echo (isset($purpose_filter) && $purpose_filter === 'held_up') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Held Up</option>
                <option value="extra" <?php echo (isset($purpose_filter) && $purpose_filter === 'extra') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Extra</option>
                <option value="night_emergency" <?php echo (isset($purpose_filter) && $purpose_filter === 'night_emergency') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Night Emergency</option>
                <option value="sub_route" <?php echo (isset($purpose_filter) && $purpose_filter === 'sub_route') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Sub Route</option>
            </select>
            
            <span class="text-gray-400">|</span>

            <select id="status-filter" onchange="filterStatus(this.value)" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-1 pr-2 appearance-none hover:text-yellow-200 transition">
                <option value="active" <?php echo (isset($status_filter) && $status_filter === 'active') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Active</option>
                <option value="inactive" <?php echo (isset($status_filter) && $status_filter === 'inactive') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Inactive</option>
            </select>

        </div>

        <span class="text-gray-600">|</span>

        <a href="add_vehicle.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Vehicle
        </a>
        
        <a href="https://l.de-1.a.mimecastprotect.com/l?domain=sharepoint.com&t=AQICAHjDNlln1fEnh8m5FGLoT34o0KqKE54tJvXfX_jZUbir7gGYXpGnmbYqnekGpwHsm4lwAAAAzjCBywYJKoZIhvcNAQcGoIG9MIG6AgEAMIG0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDGWcu_dIjrGTJHMvvgIBEICBhumQP8i077SMjhi4DVpB78tXB99JFKuM0tAw4ftxGNnoGXn3ZXHCso8igpWu96ljUepJqL5RUj8zaLpCSs-3S7aA1aRRYgB8sTFqM2GFJQ3mAuZCB4aggIBCB88O_yq3Zjd3uFZGALavn2v4_LixolZWUT1vI-onbON_5AlV-djt1Ct3ag61&r=/s/h9eACJNOQfyjVyrrcVB0vXztkYpznYsocKv1n_" target="_blank" class="text-yellow-400 hover:text-yellow-300 text-xs font-bold border border-yellow-500/50 px-2 py-1 rounded bg-yellow-500/10">
            <i class="fas fa-file-contract mr-1"></i> Docs
        </a>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-h-[88vh]">
        <table class="w-full table-auto border-collapse" id="vehicleTable">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Vehicle No</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Supplier</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Capacity</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Fuel Efficiency</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Type</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm" style="min-width: 160px;">Actions</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php if ($vehicles_result && $vehicles_result->num_rows > 0): ?>
                    <?php while ($vehicle = $vehicles_result->fetch_assoc()): ?>
                        <tr class="hover:bg-indigo-50 border-b border-gray-100 transition duration-150">
                            <td class="px-4 py-3 font-mono font-medium text-blue-600"><?php echo htmlspecialchars($vehicle['vehicle_no']); ?></td>
                            <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($vehicle['supplier']); ?></td>
                            <td class="px-4 py-3"><?php echo htmlspecialchars($vehicle['capacity']); ?></td>
                            <td class="px-4 py-3 text-gray-600">
                                <span class="font-semibold"><?php echo htmlspecialchars($vehicle['c_type']); ?></span> 
                                <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($vehicle['distance']); ?>)</span>
                            </td>
                            <td class="px-4 py-3"><?php echo htmlspecialchars($vehicle['type']); ?></td>
                            
                            <td class="px-4 py-3 text-center">
                                <div class="flex justify-center gap-2">
                                    <a href='view_vehicle.php?vehicle_no=<?php echo urlencode($vehicle['vehicle_no']); ?>' class='bg-green-500 hover:bg-green-600 text-white py-1 px-2 rounded-md shadow-sm transition' title='View'>
                                        <i class='fas fa-eye text-xs'></i>
                                    </a>
                                    
                                    <a href='edit_vehicle.php?vehicle_no=<?php echo urlencode($vehicle['vehicle_no']); ?>' class='bg-yellow-500 hover:bg-yellow-600 text-white py-1 px-2 rounded-md shadow-sm transition' title='Edit'>
                                        <i class='fas fa-edit text-xs'></i>
                                    </a>
                                    
                                    <?php if ($vehicle['is_active'] == 1): ?>
                                        <button onclick="confirmToggleStatus('<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>', 0)" class="bg-red-500 hover:bg-red-600 text-white py-1 px-2 rounded-md shadow-sm transition" title="Disable">
                                            <i class='fas fa-ban text-xs'></i>
                                        </button>
                                    <?php else: ?>
                                        <button onclick="confirmToggleStatus('<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>', 1)" class='bg-green-500 hover:bg-green-600 text-white py-1 px-2 rounded-md shadow-sm transition' title="Enable">
                                            <i class='fas fa-check text-xs'></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                            No vehicles found
                        </td>
                    </tr>
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
    // Modal Functions
    function openModal(id) {
        document.getElementById(id).style.display = 'flex';
    }
    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    // Toast Function
    function showToast(status, message) {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'toast ' + status + ' show';
        
        const icon = status === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        
        toast.innerHTML = `
            <i class="fas ${icon} toast-icon"></i>
            <span>${message}</span>`;
        
        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Filter Functions
    function filterVehicles(purpose) {
        const currentStatus = document.getElementById('status-filter').value;
        window.location.href = `vehicle.php?purpose=${purpose}&status=${currentStatus}`;
    }

    function filterStatus(status) {
        const currentPurpose = document.getElementById('purpose-filter').value;
        window.location.href = `vehicle.php?purpose=${currentPurpose}&status=${status}`;
    }
    
    // Search Function
    function searchTable() {
        const input = document.getElementById('search-input');
        const filter = input.value.toUpperCase();
        const table = document.getElementById('vehicleTable');
        const tr = table.getElementsByTagName('tr');

        for (let i = 1; i < tr.length; i++) { 
            const td = tr[i].getElementsByTagName('td')[0]; // Search by Vehicle No
            if (td) {
                const txtValue = td.textContent || td.innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = '';
                } else {
                    tr[i].style.display = 'none';
                }
            }
        }
    }

    // Toggle Status Logic
    function confirmToggleStatus(vehicleNo, newStatus) {
        const statusText = newStatus === 1 ? 'Enable' : 'Disable';
        const verb = newStatus === 1 ? 'activating' : 'disabling';

        document.getElementById('confirmationTitle').textContent = `${statusText} Vehicle?`;
        document.getElementById('confirmationMessage').textContent = `Are you sure you want to proceed with ${verb} vehicle ${vehicleNo}?`;
        
        const confirmButton = document.getElementById('confirmButton');
        
        // Remove previous onclick to prevent multiple bindings
        const newBtn = confirmButton.cloneNode(true);
        confirmButton.parentNode.replaceChild(newBtn, confirmButton);
        
        newBtn.onclick = function() {
            toggleVehicleStatus(vehicleNo, newStatus);
            closeModal('confirmationModal');
        };

        openModal('confirmationModal');
    }

    function toggleVehicleStatus(vehicleNo, newStatus) {
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('vehicle_no', vehicleNo);
        formData.append('is_active', newStatus);

        fetch('vehicle.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showToast('success', data.message);
                setTimeout(() => {
                    const currentPurpose = document.getElementById('purpose-filter').value;
                    const currentStatus = document.getElementById('status-filter').value;
                    window.location.href = `vehicle.php?purpose=${currentPurpose}&status=${currentStatus}`;
                }, 1000);
            } else {
                showToast('error', data.message);
            }
        })
        .catch(error => {
            console.error('Error toggling status:', error);
            showToast('error', 'An error occurred while updating the status.');
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const phpMessage = <?php echo json_encode($message ?? null); ?>;
        if (phpMessage && phpMessage.status && phpMessage.text) {
            showToast(phpMessage.status, phpMessage.text);
        }
    });
</script>

</body>
</html>
<?php $stmt->close(); $conn->close(); ?>