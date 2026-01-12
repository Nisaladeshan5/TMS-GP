<?php
require_once '../../includes/session_check.php';
// driver.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

include('../../includes/db.php');

// --- API MODE (AJAX requests) for Status Toggle ONLY ---

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
            error_log("Database Error (driver.php toggle_status): " . $stmt->error);
            echo json_encode(['status' => 'error', 'message' => 'Database error. Could not update status.']);
        }
        $stmt->close();
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
        exit;
    }
}

// --- NORMAL PAGE LOAD (HTML) ---

$message = null;
if (isset($_GET['action_status'])) {
    $message = [
        'status' => $_GET['action_status'],
        'text' => htmlspecialchars($_GET['action_message'] ?? 'Action completed.')
    ];
    
    // Clean URL
    $current_url = explode('?', $_SERVER['REQUEST_URI'])[0];
    $filter_param = isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : '';
}

$status_filter = $_GET['status'] ?? 'active';

include('../../includes/header.php');
include('../../includes/navbar.php');

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Toast Notification Styles */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transform: translateY(-20px); opacity: 0; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; flex-shrink: 0; }
        
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
            Drivers
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <div class="relative">
            <input type="text" id="search-input" onkeyup="searchTable()" placeholder="Search Driver / Vehicle..." 
                   class="bg-gray-700 text-white text-sm rounded-lg pl-3 pr-8 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 w-56 transition-all focus:w-64 placeholder-gray-400 border border-gray-600">
            <i class="fas fa-search absolute right-3 top-2 text-gray-400 text-xs"></i>
        </div>

        <span class="text-gray-400 mx-1">|</span>

        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            <select id="status-filter" onchange="filterStatus(this.value)" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <option value="active" <?php echo (isset($status_filter) && $status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo (isset($status_filter) && $status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>

        <span class="text-gray-600">|</span>

        <a href="add_driver.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Driver
        </a>
        
        <a href="https://l.de-1.a.mimecastprotect.com/l?domain=sharepoint.com&t=AQICAHjDNlln1fEnh8m5FGLoT34o0KqKE54tJvXfX_jZUbir7gGYXpGnmbYqnekGpwHsm4lwAAAAzjCBywYJKoZIhvcNAQcGoIG9MIG6AgEAMIG0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDGWcu_dIjrGTJHMvvgIBEICBhumQP8i077SMjhi4DVpB78tXB99JFKuM0tAw4ftxGNnoGXn3ZXHCso8igpWu96ljUepJqL5RUj8zaLpCSs-3S7aA1aRRYgB8sTFqM2GFJQ3mAuZCB4aggIBCB88O_yq3Zjd3uFZGALavn2v4_LixolZWUT1vI-onbON_5AlV-djt1Ct3ag61&r=/s/h9eACJNOQfyjVyrrcVB0vXztkYpznYsocKv1n_" target="_blank" class="text-yellow-400 hover:text-yellow-300 text-xs font-bold border border-yellow-500/50 px-2 py-1 rounded bg-yellow-500/10">
            <i class="fas fa-file-contract mr-1"></i> Docs
        </a>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-h-[88vh]">
        <table class="w-full table-auto border-collapse" id="driverTable">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">License ID</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Calling Name</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Full Name</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Phone No</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Vehicle No</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">License Expiry</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm" style="min-width: 140px;">Actions</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php if ($drivers_result && $drivers_result->num_rows > 0): ?>
                    <?php while ($driver = $drivers_result->fetch_assoc()): ?>
                        <tr class="hover:bg-indigo-50 border-b border-gray-100 transition duration-150">
                            <td class="px-4 py-3 font-mono font-medium text-blue-600"><?php echo htmlspecialchars($driver['driver_NIC']); ?></td>
                            <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($driver['calling_name']); ?></td>
                            <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($driver['full_name']); ?></td>
                            <td class="px-4 py-3"><?php echo htmlspecialchars($driver['phone_no']); ?></td>
                            <td class="px-4 py-3 font-bold uppercase"><?php echo htmlspecialchars($driver['vehicle_no'] ?? 'N/A'); ?></td>
                            <td class="px-4 py-3 text-red-600 font-medium"><?php echo htmlspecialchars($driver['license_expiry_date']); ?></td>
                            
                            <td class="px-4 py-3 text-center">
                                <div class="flex justify-center gap-2">
                                    <a href="edit_driver.php?driver_NIC=<?php echo urlencode($driver['driver_NIC']); ?>&filter_status=<?php echo urlencode($status_filter); ?>" 
                                       class='bg-yellow-500 hover:bg-yellow-600 text-white py-1 px-2 rounded-md shadow-sm transition' title='Edit'>
                                        <i class='fas fa-edit text-xs'></i>
                                    </a>
                                    
                                    <?php if ($driver['is_active'] == 1): ?>
                                        <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($driver['driver_NIC']); ?>", 0)' class='bg-red-500 hover:bg-red-600 text-white py-1 px-2 rounded-md shadow-sm transition' title="Disable">
                                            <i class='fas fa-ban text-xs'></i>
                                        </button>
                                    <?php else: ?>
                                        <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($driver['driver_NIC']); ?>", 1)' class='bg-green-500 hover:bg-green-600 text-white py-1 px-2 rounded-md shadow-sm transition' title="Enable">
                                            <i class='fas fa-check text-xs'></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                            No drivers found
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
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    // Toast Function
    function showToast(status, message) {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'toast ' + status + ' show';
        
        const icon = status === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
        
        toast.innerHTML = `
            <i class="fas ${icon} toast-icon"></i>
            <span>${message}</span>`;
        
        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Filter Function
    function filterStatus(status) {
        window.location.href = `driver.php?status=${status}`;
    }

    // Search Function (Includes Vehicle Number)
    function searchTable() {
        const input = document.getElementById('search-input');
        const filter = input.value.toUpperCase();
        const table = document.getElementById('driverTable');
        const tr = table.getElementsByTagName('tr');

        for (let i = 1; i < tr.length; i++) { 
            // 0 = License ID, 1 = Calling Name, 4 = Vehicle No
            const tdNIC = tr[i].getElementsByTagName('td')[0];
            const tdName = tr[i].getElementsByTagName('td')[1];
            const tdVehicle = tr[i].getElementsByTagName('td')[4]; 
            
            if (tdNIC || tdName || tdVehicle) {
                const txtNIC = tdNIC ? (tdNIC.textContent || tdNIC.innerText) : "";
                const txtName = tdName ? (tdName.textContent || tdName.innerText) : "";
                const txtVehicle = tdVehicle ? (tdVehicle.textContent || tdVehicle.innerText) : "";
                
                if (txtNIC.toUpperCase().indexOf(filter) > -1 || 
                    txtName.toUpperCase().indexOf(filter) > -1 || 
                    txtVehicle.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = '';
                } else {
                    tr[i].style.display = 'none';
                }
            }
        }
    }

    // Toggle Status Logic
    function confirmToggleStatus(driverNic, newStatus) {
        const statusText = newStatus === 1 ? 'Enable' : 'Disable';
        const verb = newStatus === 1 ? 'activating' : 'disabling';

        document.getElementById('confirmationTitle').textContent = `${statusText} Driver?`;
        document.getElementById('confirmationMessage').textContent = `Are you sure you want to proceed with ${verb} driver ${driverNic}?`;
        
        const confirmButton = document.getElementById('confirmButton');
        
        const newBtn = confirmButton.cloneNode(true);
        confirmButton.parentNode.replaceChild(newBtn, confirmButton);
        
        newBtn.onclick = function() {
            toggleDriverStatus(driverNic, newStatus);
            closeModal('confirmationModal');
        };

        openModal('confirmationModal');
    }

    async function toggleDriverStatus(driverNic, newStatus) {
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('driver_NIC', driverNic);
        formData.append('is_active', newStatus);

        try {
            const response = await fetch('driver.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.status === 'success') {
                showToast('success', result.message);
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast('error', result.message);
            }
        } catch (error) {
            showToast('error', 'An unexpected network error occurred.');
            console.error('Fetch error:', error);
        }
    }
    
    // Check for success/error messages passed via URL
    document.addEventListener('DOMContentLoaded', function() {
        const phpMessage = <?php echo json_encode($message ?? null); ?>;
        if (phpMessage && phpMessage.status && phpMessage.text) {
            showToast(phpMessage.status, phpMessage.text);
        }
    });
</script>

</body>
</html>

<?php if (isset($conn)) { $conn->close(); } ?>