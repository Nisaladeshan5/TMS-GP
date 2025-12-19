<?php
// vehicle.php (Main List Page)
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
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
        // Decode the URL message and then sanitize for safety
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
        /* CSS for toast notifications and confirmation modal */
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

        /* ... [Keep all your existing toast and confirmation modal styles] ... */
        
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
<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
    <div class="text-lg font-semibold ml-3">Vehicle</div>
    <div class="flex gap-4">
        <a href="https://l.de-1.a.mimecastprotect.com/l?domain=sharepoint.com&t=AQICAHjDNlln1fEnh8m5FGLoT34o0KqKE54tJvXfX_jZUbir7gGYXpGnmbYqnekGpwHsm4lwAAAAzjCBywYJKoZIhvcNAQcGoIG9MIG6AgEAMIG0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDGWcu_dIjrGTJHMvvgIBEICBhumQP8i077SMjhi4DVpB78tXB99JFKuM0tAw4ftxGNnoGXn3ZXHCso8igpWu96ljUepJqL5RUj8zaLpCSs-3S7aA1aRRYgB8sTFqM2GFJQ3mAuZCB4aggIBCB88O_yq3Zjd3uFZGALavn2v4_LixolZWUT1vI-onbON_5AlV-djt1Ct3ag61&r=/s/h9eACJNOQfyjVyrrcVB0vXztkYpznYsocKv1n_" target="_blank" class="hover:text-yellow-600 text-yellow-500 font-bold">View Documents</a>
    </div>
</div>

<div class="container ">
    <div class="w-[85%] ml-[15%] flex flex-col items-center">
        <p class="text-4xl font-bold text-gray-800 mt-6 mb-4 flex items-start">Vehicle Details</p>
        <div class="w-full flex justify-between items-center mb-6">

            <div class="flex items-center space-x-4">
                <a 
                    href="add_vehicle.php" 
                    class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300"
                    title="Add New Vehicle"
                >
                    Add New Vehicle
                </a>
                
                <input 
                    type="text" 
                    id="search-input" 
                    onkeyup="searchTable()" 
                    placeholder="Search by Vehicle No..." 
                    class="p-2 border rounded-md shadow-sm w-64 focus:ring-blue-500 focus:border-blue-500"
                >
            </div>
            
            <div class="flex items-center space-x-2">
                <label for="status-filter" class="text-gray-700 font-semibold">Filter by Status:</label>
                <select id="status-filter" onchange="filterStatus(this.value)" class="p-2 border rounded-md">
                    <option value="active" <?php echo (isset($status_filter) && $status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo (isset($status_filter) && $status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <label for="purpose-filter" class="text-gray-700 font-semibold">Filter by Purpose:</label>
                <select id="purpose-filter" onchange="filterVehicles(this.value)" class="p-2 border rounded-md">
                    <option value="staff" <?php echo (isset($purpose_filter) && $purpose_filter === 'staff') ? 'selected' : ''; ?>>Staff</option>
                    <option value="factory" <?php echo (isset($purpose_filter) && $purpose_filter === 'factory') ? 'selected' : ''; ?>>Factory</option>
                    <option value="night_emergency" <?php echo (isset($purpose_filter) && $purpose_filter === 'night_emergency') ? 'selected' : ''; ?>>Night Emergency</option>
                    <option value="sub_route" <?php echo (isset($purpose_filter) && $purpose_filter === 'sub_route') ? 'selected' : ''; ?>>Sub Route</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto bg-white shadow-md rounded-md w-full">
            <table class="min-w-full table-auto" id="vehicleTable">
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
                                <td class="border px-4 py-2 flex space-x-2">
                                    <a href='view_vehicle.php?vehicle_no=<?php echo urlencode($vehicle['vehicle_no']); ?>' class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>View</a>
                                    
                                    <a href='edit_vehicle.php?vehicle_no=<?php echo urlencode($vehicle['vehicle_no']); ?>' class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>Edit</a>
                                    
                                    <?php if ($vehicle['is_active'] == 1): ?>
                                        <button onclick="confirmToggleStatus('<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>', 0)" class="bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300">Disable</button>
                                    <?php else: ?>
                                        <button onclick="confirmToggleStatus('<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>', 1)" class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>Enable</button>
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

<script>
    // Simplified Modal functions for the remaining confirmation modal
    function openModal(id) {
        document.getElementById(id).style.display = 'flex';
    }
    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    // Toast Function (essential for AJAX response)
    function showToast(status, message) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast ' + status;
    toast.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" class="toast-icon" viewBox="0 0 20 20" fill="currentColor">
        ${status === 'success' ? '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />' : 
                             '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />'}
    </svg>
    <span>${message}</span>`;
    container.appendChild(toast);

    // Show and hide logic
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300); // Remove after transition
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
    
    // 🎯 New Search Function (Client-side filtering)
    function searchTable() {
        const input = document.getElementById('search-input');
        const filter = input.value.toUpperCase();
        const table = document.getElementById('vehicleTable');
        const tr = table.getElementsByTagName('tr');

        // Loop through all table rows, and hide those who don't match the search query
        for (let i = 1; i < tr.length; i++) { // Start from 1 to skip the header row
            const td = tr[i].getElementsByTagName('td')[0]; // [0] is the Vehicle No column
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

    // Toggle Status Logic (AJAX)
    function confirmToggleStatus(vehicleNo, newStatus) {
        const statusText = newStatus === 1 ? 'Enable' : 'Disable';
        const verb = newStatus === 1 ? 'activating' : 'disabling';

        document.getElementById('confirmationTitle').textContent = `${statusText} Vehicle?`;
        document.getElementById('confirmationMessage').textContent = `Are you sure you want to proceed with ${verb} the vehicle ${vehicleNo}?`;
        
        const confirmButton = document.getElementById('confirmButton');
        confirmButton.onclick = function() {
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
                'X-Requested-With': 'XMLHttpRequest' // Mark as AJAX request
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showToast(data.message, 'success');
                // Reload the page to show the updated status in the table
                setTimeout(() => {
                    // Preserve filters when reloading
                    const currentPurpose = document.getElementById('purpose-filter').value;
                    const currentStatus = document.getElementById('status-filter').value;
                    window.location.href = `vehicle.php?purpose=${currentPurpose}&status=${currentStatus}`;
                }, 500);
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error toggling status:', error);
            showToast('An error occurred while updating the status.', 'error');
        });
    }
document.addEventListener('DOMContentLoaded', function() {
    // Check for success/error messages passed via URL from edit_supplier.php or toggleStatus
    const phpMessage = <?php echo json_encode($message ?? null); ?>;

    if (phpMessage && phpMessage.status && phpMessage.text) {
        // Use the existing showToast function
        showToast(phpMessage.status, phpMessage.text);
    }
});
</script>

</body>
</html>
<?php $stmt->close(); $conn->close(); ?>