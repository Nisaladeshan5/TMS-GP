<?php
require_once '../../includes/session_check.php';
// op_services.php
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

// 1. Database Connection 
include('../../includes/db.php'); 

// Define a flag for AJAX requests
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// --- 2. API MODE (AJAX requests for Toggle Status) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    if (!$is_ajax) {
        http_response_code(403);
        exit();
    }
    header('Content-Type: application/json');

    try {
        $op_code = $_POST['op_code'];
        $vehicle_no = $_POST['vehicle_no']; 
        $new_status = (int)$_POST['is_active'];

        $sql = "UPDATE op_services SET is_active = ? WHERE op_code = ? AND vehicle_no = ?";
        $params = [$new_status, $op_code, $vehicle_no];
        $types = "iss"; 

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Service rate status updated successfully!']);
        } else {
            error_log("SQL Error: " . $stmt->error);
            echo json_encode(['status' => 'error', 'message' => 'Database error: Update failed.']);
        }
        exit;
    } catch (Exception $e) {
        error_log("Exception: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}


// --- 3. NORMAL PAGE LOAD (Data Fetching and HTML) ---
include('../../includes/header.php');
include('../../includes/navbar.php');

// Get selected filter status
$incoming_status = $_GET['status'] ?? '';
$filter_status = 1; // Default to Active (1)

// Note: If status is 'success' or 'error' (from redirect), it falls to default (1) which is fine.
if ($incoming_status === '0') {
    $filter_status = 0; 
} elseif ($incoming_status === '1') {
    $filter_status = 1; 
}

// SQL Query
$sql = "SELECT
            os.op_code,
            os.vehicle_no,
            s.supplier,
            os.slab_limit_distance,
            os.day_rate,
            os.extra_rate,
            os.is_active,
            os.extra_rate_ac  
        FROM
            op_services AS os
        LEFT JOIN
            supplier AS s ON os.supplier_code = s.supplier_code"; 

// Add Filtering condition 
if ($filter_status === 1) {
    $sql .= " WHERE os.is_active = 1"; // Only Active
} elseif ($filter_status === 0) {
    $sql .= " WHERE os.is_active = 0"; // Only Inactive
}

$sql .= " ORDER BY os.op_code, os.vehicle_no;"; 
            
$op_services_result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operational Service Rates</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Modal & Toast CSS */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0, 0, 0, 0.4); justify-content: center; align-items: center; }
        .modal-content { background-color: #ffffff; padding: 24px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); position: relative; }
        
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; color: white; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast.info { background-color: #2196F3; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
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

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Operational Service Rates
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <div class="relative">
            <select id="status_filter" onchange="applyFilter()" class="bg-gray-700 text-white text-xs border border-gray-600 rounded-md px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-yellow-500 cursor-pointer shadow-sm hover:bg-gray-600 transition">
                <option value="1" <?php echo ($filter_status == 1) ? 'selected' : ''; ?>>Active Only</option>
                <option value="0" <?php echo ($filter_status == 0) ? 'selected' : ''; ?>>Inactive Only</option>
            </select>
        </div>

        <span class="text-gray-600 text-lg font-thin">|</span>

        <button onclick="generateServiceQrPdf()" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-green-500">
            <i class="fas fa-qrcode"></i> Generate QR
        </button>

        <a href="reason.php" class="flex items-center gap-2 bg-rose-600 hover:bg-rose-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-rose-500">
            Reasons
        </a>

        <a href="add_op_service.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-blue-500">
            Add Service
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-16 pt-2">
    
    <div class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-h-[88vh]">
        <table class="w-full table-auto border-collapse">
            <thead class="text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-2 py-3 text-center w-10 shadow-sm">
                        <input type="checkbox" id="select-all-rates" onclick="toggleAllCheckboxes()" class="cursor-pointer">
                    </th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Code</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Vehicle No.</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Supplier</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm">Slab Limit</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Day Rate (Rs.)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Extra Rate (Non-AC)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Extra Rate (AC)</th> 
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm">Actions</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php if ($op_services_result && $op_services_result->num_rows > 0): ?>
                    <?php while ($rate = $op_services_result->fetch_assoc()): 
                        $op_code = htmlspecialchars($rate['op_code']);
                        $raw_vehicle_no = $rate['vehicle_no'];
                        $vehicle_no = htmlspecialchars($raw_vehicle_no);
                        $supplier = htmlspecialchars($rate['supplier'] ?? 'N/A');
                        $day_rate = number_format((float)$rate['day_rate'], 2);
                        $extra_rate_non_ac = number_format((float)$rate['extra_rate'], 2);
                        $extra_rate_ac = number_format((float)($rate['extra_rate_ac'] ?? 0), 2); 
                        $is_active = (int)$rate['is_active'];
                        
                        $limit_distance = ($rate['slab_limit_distance'] > 0) 
                            ? htmlspecialchars($rate['slab_limit_distance']) . ' km' 
                            : '-';

                        $day_rate_display = $day_rate;
                        $extra_rate_non_ac_display = ($extra_rate_non_ac !== '0.00' && (float)$rate['extra_rate'] > 0) ? $extra_rate_non_ac : 'N/A';
                        $extra_rate_ac_display = ($extra_rate_ac !== '0.00' && (float)($rate['extra_rate_ac'] ?? 0) > 0) ? $extra_rate_ac : 'N/A';

                        $op_code_prefix = substr($op_code, 0, 2);
                        if ($op_code_prefix === 'EV') {
                            $day_rate_display = 'N/A';
                            $limit_distance = 'N/A';
                        } 
                    ?>
                        <tr class="hover:bg-indigo-50 border-b border-gray-100 transition duration-150 group">
                            <td class='px-2 py-3 text-center'>
                                <input type='checkbox' name='selected_rates[]' value='<?php echo $op_code . "|" . $raw_vehicle_no; ?>' class='rate-checkbox cursor-pointer'>
                            </td>
                            <td class="px-4 py-3 font-mono text-blue-600 font-bold text-xs"><?php echo $op_code; ?></td>
                            <td class="px-4 py-3 font-medium text-gray-800"><?php echo $vehicle_no; ?></td>
                            <td class="px-4 py-3 text-gray-600"><?php echo $supplier; ?></td>
                            <td class="px-4 py-3 text-center"><?php echo $limit_distance; ?></td>
                            <td class="px-4 py-3 text-right font-mono text-green-600 font-bold"><?php echo $day_rate_display; ?></td>
                            <td class="px-4 py-3 text-right font-mono text-orange-600"><?php echo $extra_rate_non_ac_display; ?></td>
                            <td class="px-4 py-3 text-right font-mono text-orange-600"><?php echo $extra_rate_ac_display; ?></td>
                            
                            <td class="px-4 py-3 text-center">
                                <div class="flex justify-center gap-2">
                                    <button 
                                        onclick='openEditModal(<?php echo json_encode($op_code); ?>, <?php echo json_encode($raw_vehicle_no); ?>)' 
                                        class='bg-yellow-500 hover:bg-yellow-600 text-white p-1.5 rounded shadow-sm transition duration-300' title="Edit">
                                        <i class="fas fa-edit text-xs"></i>
                                    </button>

                                    <?php if ($is_active == 1): ?>
                                        <button 
                                            onclick='confirmToggleStatus(<?php echo json_encode($op_code); ?>, <?php echo json_encode($raw_vehicle_no); ?>, 0, <?php echo $filter_status; ?>)' 
                                            class='bg-red-500 hover:bg-red-600 text-white p-1.5 rounded shadow-sm transition duration-300' title="Disable">
                                            <i class="fas fa-ban text-xs"></i>
                                        </button>
                                    <?php else: ?>
                                        <button 
                                            onclick='confirmToggleStatus(<?php echo json_encode($op_code); ?>, <?php echo json_encode($raw_vehicle_no); ?>, 1, <?php echo $filter_status; ?>)' 
                                            class='bg-green-500 hover:bg-green-600 text-white p-1.5 rounded shadow-sm transition duration-300' title="Enable">
                                            <i class="fas fa-check text-xs"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="px-6 py-4 text-center text-gray-500 italic">
                            No Operational Service Rates found for the selected status.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
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
    // --- JavaScript Functions ---
    function showModal(id) {
        document.getElementById(id).style.display = 'flex';
    }
    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        const iconPath = type === 'success' 
            ? '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />'
            : '<path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.487 0l5.501 9.77c.76 1.353-.243 3.01-1.743 3.01H4.499c-1.5 0-2.503-1.657-1.743-3.01l5.501-9.77zM11 15a1 1 0 10-2 0 1 1 0 002 0zm-1-6a1 1 0 00-1 1v3a1 1 0 002 0v-3a1 1 0 00-1-1z" clip-rule="evenodd" />';
            
        toast.innerHTML = `<svg class="toast-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">${iconPath}</svg><span>${message}</span>`;
        container.prepend(toast);
        
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function openEditModal(opCode, vehicleNo) {
        const editUrl = `add_op_service.php?op_code=${encodeURIComponent(opCode)}&vehicle_no=${encodeURIComponent(vehicleNo)}`;
        window.location.href = editUrl;
    }

    function confirmToggleStatus(opCode, vehicleNo, newStatus, currentFilterStatus) {
        const statusText = (newStatus === 1) ? 'Active (Enable)' : 'Inactive (Disable)';
        const title = (newStatus === 1) ? 'Activate Rate?' : 'Deactivate Rate?';
        const message = `Are you sure you want to change the status of rate ${opCode} (Vehicle: ${vehicleNo}) to ${statusText}?`;

        document.getElementById('confirmationTitle').textContent = title;
        document.getElementById('confirmationMessage').textContent = message;
        
        const confirmButton = document.getElementById('confirmButton');
        confirmButton.onclick = () => handleToggleStatus(opCode, vehicleNo, newStatus, currentFilterStatus);
        
        showModal('confirmationModal');
    }

    function handleToggleStatus(opCode, vehicleNo, newStatus, currentFilterStatus) {
        closeModal('confirmationModal');

        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('op_code', opCode);
        formData.append('vehicle_no', vehicleNo);
        formData.append('is_active', newStatus);

        fetch(window.location.href, { 
            method: 'POST',
            body: formData,
            headers: {'X-Requested-With': 'XMLHttpRequest'} 
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showToast(data.message, 'success');
                // Reload with current filter to refresh list
                let reloadUrl = window.location.pathname + '?status=' + currentFilterStatus;
                setTimeout(() => window.location.href = reloadUrl, 500);
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error toggling status:', error);
            showToast('An unexpected error occurred. Check PHP error logs.', 'error');
        });
    }

    function toggleAllCheckboxes() {
        const selectAll = document.getElementById('select-all-rates');
        const checkboxes = document.querySelectorAll('.rate-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
    }

    function generateServiceQrPdf() {
        const selectedRates = Array.from(document.querySelectorAll('.rate-checkbox:checked'))
                                     .map(checkbox => checkbox.value);

        if (selectedRates.length === 0) {
            showToast("Please select at least one service rate to generate the PDF.", 'error');
            return;
        }

        const rateCodesString = selectedRates.join(',');

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'generate_op_qr_pdf.php'; 
        form.target = '_blank'; 

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_service_rates'; 
        input.value = rateCodesString;

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    function applyFilter() {
        const status = document.getElementById('status_filter').value;
        window.location.href = window.location.pathname + '?status=' + status;
    }

    // --- NEW: TRIGGER TOAST FROM URL PARAMETERS ---
    // This code checks if 'message' and 'status' are in the URL and shows the toast
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (isset($_GET['status']) && isset($_GET['message']) && ($_GET['status'] == 'success' || $_GET['status'] == 'error')): ?>
            // Decode the message (just in case)
            var message = "<?php echo addslashes(htmlspecialchars($_GET['message'])); ?>";
            var status = "<?php echo htmlspecialchars($_GET['status']); ?>";
            
            showToast(message, status);
            
            // Clean the URL (remove status/message parameters) without reloading
            // We want to keep the filter status if possible, but 'success' overwrites it in logic above.
            // Let's reset the URL to clean state (default active or whatever logical default)
            
            const newUrl = window.location.pathname + '?status=1'; // Defaulting to Active view after clean
            window.history.replaceState({}, document.title, newUrl);
        <?php endif; ?>
    });
</script>

</body>
</html>

<?php $conn->close(); ?>