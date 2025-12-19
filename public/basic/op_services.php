<?php
require_once '../../includes/session_check.php';
// op_services.php
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

// ✅ FIXED LOGIC START: Get selected filter status
$incoming_status = $_GET['status'] ?? '';
$filter_status = 1; // Default to Active (1)

if ($incoming_status === '0') {
    $filter_status = 0; // Explicitly set to Inactive if '0' is passed
} elseif ($incoming_status === '1') {
    $filter_status = 1; // Explicitly set to Active if '1' is passed
}
// Any other value (like 'success', 'error', or non-set) defaults to 1 (Active)
// ✅ FIXED LOGIC END

// Base SQL Query (UPDATED to include extra_rate_ac)
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
            vehicle AS v ON os.vehicle_no = v.vehicle_no
        LEFT JOIN
            supplier AS s ON v.supplier_code = s.supplier_code";

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
    <style>
        /* CSS styles (same as before) */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0, 0, 0, 0.4); justify-content: center; align-items: center; }
        .modal-content { background-color: #ffffff; padding: 24px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); position: relative; }
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; }
        .toast { display: none; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; color: white; }
        .toast.show { display: flex; align-items: center; transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
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
    <div class="text-lg font-semibold ml-3">Operational Service</div>
    <a href="reason.php" class="hover:text-yellow-600">Reasons</a>
</div>

<div class="container ">
    <div class="w-[85%] ml-[15%] flex flex-col items-center">
        <p class="text-4xl font-bold text-gray-800 mt-6 mb-4 flex items-start">Service Details</p>
        
        <div class="w-full flex justify-between items-center mb-6">
            <?php
            // Assuming $user_role is defined elsewhere.
            // Set the flag for easier use in the HTML
            $is_manager = ($user_role === 'manager'); 

            // --- Reusable Classes and Attributes ---
            $disabled_state_classes = 'opacity-50 cursor-not-allowed pointer-events-none'; // CSS to make it look and act disabled
            $disabled_href = 'javascript:void(0)'; // Prevent navigation for disabled links
            $add_service_title = $is_manager ? 'Access denied for Manager role' : 'Add New Service';
            $generate_pdf_title = $is_manager ? 'Access denied for Manager role' : 'Generate Service QR PDF';
            $hover_anchor_classes = $is_manager ? '' : 'hover:bg-blue-600'; // Only apply hover if not manager
            $hover_button_classes = $is_manager ? '' : 'hover:bg-green-800'; // Only apply hover if not manager
            ?>

            <div class="flex space-x-4">
                <a 
                    href="add_op_service.php" 
                    class="bg-blue-500 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300"
                    title="Add New Service"
                >
                    Add New Service
                </a>
                
                <button 
                    onclick="generateServiceQrPdf()" 
                    class="bg-green-700 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 "
                    title="Generate Service QR PDF"
                >
                    Generate Service QR PDF
                </button>
            </div>

            <form method="GET" class="flex items-center space-x-2">
                <label for="status_filter" class="font-medium text-gray-700">Filter Status:</label>
                <select id="status_filter" name="status" onchange="this.form.submit()" class="border border-gray-300 p-2 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="1" <?php echo ($filter_status == 1) ? 'selected' : ''; ?>>Active</option>
                    <option value="0" <?php echo ($filter_status == 0) ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </form>
        </div>
        
        <div class="overflow-x-auto bg-white shadow-md rounded-md w-full">
            <table class="min-w-full table-auto">
                <thead class="bg-blue-600 text-white">
                    <tr>
                        <th class="px-2 py-2 text-center w-10">
                            <input type="checkbox" id="select-all-rates" onclick="toggleAllCheckboxes()">
                        </th>
                        <th class="px-4 py-2 text-left">Code</th>
                        <th class="px-4 py-2 text-left">Vehicle No.</th>
                        <th class="px-4 py-2 text-left">Supplier</th>
                        <th class="px-4 py-2 text-center">Slab Limit</th>
                        <th class="px-4 py-2 text-right">Day Rate (Rs.)</th>
                        <th class="px-4 py-2 text-right">Extra Rate (Non-AC)</th>
                        <th class="px-4 py-2 text-right">Extra Rate (AC)</th> 
                        <th class="px-4 py-2 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
    <?php if ($op_services_result && $op_services_result->num_rows > 0): ?>
        <?php while ($rate = $op_services_result->fetch_assoc()): 
            $op_code = htmlspecialchars($rate['op_code']);
            $raw_vehicle_no = $rate['vehicle_no'];
            $vehicle_no = htmlspecialchars($raw_vehicle_no);
            $supplier = htmlspecialchars($rate['supplier'] ?? 'N/A');
            $day_rate = number_format((float)$rate['day_rate'], 2);
            $extra_rate_non_ac = number_format((float)$rate['extra_rate'], 2);
            // NEW: Fetch and format the new AC rate
            $extra_rate_ac = number_format((float)($rate['extra_rate_ac'] ?? 0), 2); 
            $is_active = (int)$rate['is_active'];
            
            // Slab Limit Display Logic
            $limit_distance = ($rate['slab_limit_distance'] > 0) 
                ? htmlspecialchars($rate['slab_limit_distance']) . ' km' 
                : '-';

            // Default display values
            $day_rate_display = $day_rate;
            $extra_rate_non_ac_display = ($extra_rate_non_ac !== '0.00' && (float)$rate['extra_rate'] > 0) ? $extra_rate_non_ac : 'N/A';
            $extra_rate_ac_display = ($extra_rate_ac !== '0.00' && (float)($rate['extra_rate_ac'] ?? 0) > 0) ? $extra_rate_ac : 'N/A';

            // EV, NE specific handling
            $op_code_prefix = substr($op_code, 0, 2);
            if ($op_code_prefix === 'EV') {
                $day_rate_display = 'N/A';
                $limit_distance = 'N/A';
                // For EV, the default extra_rate (index 5) is often used for the single rate, 
                // so we show both non-AC and AC if they exist.
                // If you want EV to show only one rate, you'd adjust this logic.
            } elseif ($op_code_prefix === 'NE') {
                // NE might also have specific logic, often similar to standard rates
            }
        ?>
            <tr class="hover:bg-gray-100">
                <td class='border px-2 py-2 text-center'>
                    <input type='checkbox' name='selected_rates[]' value='<?php echo $op_code . "|" . $raw_vehicle_no; ?>' class='rate-checkbox'>
                </td>
                <td class="border px-4 py-2 font-bold"><?php echo $op_code; ?></td>
                <td class="border px-4 py-2"><?php echo $vehicle_no; ?></td>
                <td class="border px-4 py-2"><?php echo $supplier; ?></td>
                <td class="border px-4 py-2 text-center"><?php echo $limit_distance; ?></td>
                <td class="border px-4 py-2 text-right text-green-700"><?php echo $day_rate_display; ?></td>
                <td class="border px-4 py-2 text-right text-red-700"><?php echo $extra_rate_non_ac_display; ?></td>
                <td class="border px-4 py-2 text-right text-red-700"><?php echo $extra_rate_ac_display; ?></td>
                <td class="border px-4 py-2 text-center">
                    <button 
                        onclick='openEditModal(<?php echo json_encode($op_code); ?>, <?php echo json_encode($raw_vehicle_no); ?>)' 
                        class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>
                        Edit
                    </button>

                    <?php if ($is_active == 1): ?>
                        <button 
                            onclick='confirmToggleStatus(<?php echo json_encode($op_code); ?>, <?php echo json_encode($raw_vehicle_no); ?>, 0, <?php echo $filter_status; ?>)' 
                            class='bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>
                            Disable
                        </button>
                    <?php else: ?>
                        <button 
                            onclick='confirmToggleStatus(<?php echo json_encode($op_code); ?>, <?php echo json_encode($raw_vehicle_no); ?>, 1, <?php echo $filter_status; ?>)' 
                            class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>
                            Enable
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="10" class="border px-4 py-2 text-center">No Operational Service Rates found for the selected status.</td>
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
    // --- JavaScript Functions (Unchanged) ---
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
                
                // Redirect back, preserving the current filter status (0 or 1)
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

    // --- QR PDF GENERATION LOGIC (Unchanged) ---

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
</script>

</body>
</html>

<?php $conn->close(); ?>