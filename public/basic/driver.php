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

// IMPORTANT: This include defines the $conn variable.
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

// **CORRECTED FIX: This block handles the toast message from redirects (e.g., edit_driver.php).**
// We check for 'action_status' and 'action_message' instead of 'status' and 'message' 
// to avoid conflicting with the filter parameter named 'status'.
$message = null;
if (isset($_GET['action_status'])) {
    $message = [
        'status' => $_GET['action_status'],
        'text' => htmlspecialchars($_GET['action_message'] ?? 'Action completed.')
    ];
    
    // Clean the URL of toast parameters but preserve the filter parameter 'status'.
    $current_url = explode('?', $_SERVER['REQUEST_URI'])[0];
    
    // Rebuild query string, including only the filter parameter 'status' if present.
    $filter_param = isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : '';
    
    header("Location: $current_url$filter_param");
    exit();
}

// This is the driver filter parameter, which now works correctly because the redirect above is conditional.
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
    <style>
        /* Toast Notification Styles */
        #toast-container {
            position: fixed; top: 1rem; right: 1rem; z-index: 2000;
            display: flex; flex-direction: column; align-items: flex-end;
        }
        .toast {
            display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white;
            transform: translateY(-20px); opacity: 0;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            max-width: 350px;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; flex-shrink: 0; }
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

<div id="toast-container"></div>

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
    <div class="text-lg font-semibold ml-3">Driver</div>
    <div class="flex gap-4">
        <a href="https://l.de-1.a.mimecastprotect.com/l?domain=sharepoint.com&t=AQICAHjDNlln1fEnh8m5FGLoT34o0KqKE54tJvXfX_jZUbir7gGYXpGnmbYqnekGpwHsm4lwAAAAzjCBywYJKoZIhvcNAQcGoIG9MIG6AgEAMIG0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDGWcu_dIjrGTJHMvvgIBEICBhumQP8i077SMjhi4DVpB78tXB99JFKuM0tAw4ftxGNnoGXn3ZXHCso8igpWu96ljUepJqL5RUj8zaLpCSs-3S7aA1aRRYgB8sTFqM2GFJQ3mAuZCB4aggIBCB88O_yq3Zjd3uFZGALavn2v4_LixolZWUT1vI-onbON_5AlV-djt1Ct3ag61&r=/s/h9eACJNOQfyjVyrrcVB0vXztkYpznYsocKv1n_" target="_blank" class="hover:text-yellow-600 text-yellow-500 font-bold">View Documents</a>
    </div>
</div>
<div class="w-[85%] ml-[15%]">
    <div class="w-full p-4 pt-1">
        <div class="mx-auto flex flex-col items-center p-3">
            <p class="text-4xl font-bold text-gray-800 mb-4">Driver Details</p>
            
            <div class="w-full flex justify-between items-center mb-6">
                <a 
                    href="add_driver.php" 
                    class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300"
                    title="Add New Driver>"
                >
                    Add New Driver
                </a>
                
                <div class="flex items-center space-x-2">
                    <select id="status-filter" onchange="filterStatus(this.value)" class="p-2 border rounded-md">
                        <option value="active" <?php echo (isset($status_filter) && $status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo (isset($status_filter) && $status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
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
                                    <td class="border px-4 py-2 whitespace-nowrap">
                                        <a 
                                            href="edit_driver.php?driver_NIC=<?php echo urlencode($driver['driver_NIC']); ?>&filter_status=<?php echo urlencode($status_filter); ?>"
                                            class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'
                                        >
                                            Edit
                                        </a>
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
                                <td colspan="7" class="border px-4 py-2 text-center">No drivers found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div> 
</div>
<script>
    // --- Toast Function ---
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

    // Redirect for status filtering
    window.filterStatus = function(status) {
        window.location.href = `driver.php?status=${status}`;
    };
    
    // Confirmation dialog before status toggle
    window.confirmToggleStatus = function(driverNic, newStatus) {
        const statusText = newStatus === 1 ? 'Enable' : 'Disable';
        if (confirm(`Are you sure you want to ${statusText} driver ${driverNic}?`)) {
            toggleDriverStatus(driverNic, newStatus);
        }
    };

    // AJAX call to toggle driver status
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
                // IMPORTANT: showToast function expects (status, message)
                showToast('success', result.message); 
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast('error', result.message);
            }
        } catch (error) {
            showToast('error', 'An unexpected network error occurred during status update.');
            console.error('Fetch error:', error);
        }
    }
    
    // **INITIALIZATION: Display messages passed via URL parameters (from redirect)**
    const phpMessage = <?php echo json_encode($message ?? null); ?>;

    document.addEventListener('DOMContentLoaded', function() {
        if (phpMessage && phpMessage.status && phpMessage.text) {
            // Use the existing showToast function
            showToast(phpMessage.status, phpMessage.text);
        }
    });
</script>

</body>
</html>

<?php 
if (isset($conn)) {
    $conn->close(); 
}
?>