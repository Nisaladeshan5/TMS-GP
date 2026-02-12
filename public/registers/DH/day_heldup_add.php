<?php
// day_heldup_add.php - Manual entry form for Day Heldup records

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

$logged_in_user_id = $_SESSION['user_id'] ?? null; 

// --- 1. Fetch Reasons ---
$all_reasons_sql = "
    SELECT r.reason_code, r.reason, g.gl_name AS reason_category 
    FROM reason r
    JOIN gl g ON r.gl_code = g.gl_code
    ORDER BY g.gl_name, r.reason";
        
$all_reasons_result = $conn->query($all_reasons_sql);
$available_reasons = [];
while ($row = $all_reasons_result->fetch_assoc()) {
    $available_reasons[] = $row;
}

// --- 2. Fetch OP Codes ---
$op_code_sql = "SELECT op_code, vehicle_no FROM op_services WHERE op_code LIKE 'DH-%' ORDER BY op_code ASC";
$op_code_result = $conn->query($op_code_sql);
$available_op_codes = [];
if ($op_code_result) {
    while ($row = $op_code_result->fetch_assoc()) {
        $available_op_codes[] = $row['op_code']; 
    }
}
$conn->close();

$today_date = date('Y-m-d');

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Day Heldup Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
        .toast { display: flex; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s; align-items: center; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #10B981; }
        .toast.error { background-color: #EF4444; }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div id="toast-container"></div>

    <div class="w-[85%] ml-[15%] flex justify-center p-3">
        <div class="container max-w-2xl bg-white shadow-lg rounded-lg p-8 mt-2">
            
            <div class="flex justify-between items-start border-b pb-2 mb-4">
                <div>
                    <h1 class="text-3xl font-extrabold text-gray-900">Add New Trip</h1>
                    <p class="text-sm text-gray-600">Log manual day heldup incidents.</p>
                </div>
                <div id="mode-badge" class="px-3 py-1 bg-blue-100 text-blue-800 text-xs font-bold rounded-full uppercase">
                    Standard Mode
                </div>
            </div>

            <form id="addHeldupForm" class="space-y-6">
                <input type="hidden" name="action" value="add_heldup_trip">
                <input type="hidden" id="loggedInUserId" value="<?php echo htmlspecialchars($logged_in_user_id); ?>"> 

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="op_code" class="block text-sm font-medium text-gray-700">Op Code<span class="text-red-500">*</span></label>
                        <select id="op_code" name="op_code" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="" disabled selected>Select Op Code</option>
                            <?php foreach ($available_op_codes as $code): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No<span class="text-red-500">*</span></label>
                        <input type="text" id="vehicle_no" name="vehicle_no" required placeholder="NPA-XXXX" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 uppercase">
                    </div>
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700">Date<span class="text-red-500">*</span></label>
                        <input type="date" id="date" name="date" required value="<?php echo $today_date; ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                    </div>
                    <div>
                        <label for="out_time" class="block text-sm font-medium text-gray-700">Out Time<span class="text-red-500">*</span></label>
                        <input type="time" id="out_time" name="out_time" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                    </div>
                    <div>
                        <label for="in_time" class="block text-sm font-medium text-gray-700">In Time<span class="text-red-500">*</span></label>
                        <input type="time" id="in_time" name="in_time" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                    </div>
                </div>

                <div>
                    <label for="distance" class="block text-sm font-medium text-gray-700">Distance (km)</label>
                    <input type="number" step="0.1" min="0" id="distance" name="distance" placeholder="Optional" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                </div>
                
                <div class="space-y-4 border border-indigo-200 p-4 rounded-md bg-indigo-50" id="reason-entry-container">
                    <div class="flex justify-between items-center">
                        <h3 class="text-md font-semibold text-indigo-700">Employee & Reason Details</h3>
                        <button type="button" id="addReasonEntryBtn" class="text-indigo-600 hover:text-indigo-800 text-sm font-bold">
                            <i class="fas fa-plus-circle mr-1"></i>Add Row
                        </button>
                    </div>
                    
                    <div id="reason-entry-0" class="grid md:grid-cols-3 gap-4 reason-entry">
                        <div>
                            <input type="text" name="emp_id[]" placeholder="Employee ID" class="block w-full rounded-md border-gray-300 shadow-sm p-2 uppercase text-sm">
                        </div>
                        <div class="md:col-span-2">
                            <select name="reason_code[]" class="block w-full rounded-md border-gray-300 shadow-sm p-2 text-sm">
                                <option value="" selected>Select Reason</option>
                                <?php 
                                $current_cat = '';
                                foreach ($available_reasons as $r):
                                    if ($r['reason_category'] !== $current_cat): 
                                        if ($current_cat !== '') echo '</optgroup>';
                                        echo '<optgroup label="' . htmlspecialchars($r['reason_category']) . '">';
                                        $current_cat = $r['reason_category'];
                                    endif;
                                ?>
                                    <option value="<?= htmlspecialchars($r['reason_code']) ?>"><?= htmlspecialchars($r['reason']) ?></option>
                                <?php endforeach; if ($current_cat !== '') echo '</optgroup>'; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-4 border-t">
                    <button type="button" onclick="window.location.href='day_heldup_register.php'" class="text-gray-500 bg-gray-100 hover:text-gray-700 font-medium px-4 py-2 rounded-md">Cancel</button>
                    
                    <div class="flex items-center space-x-2">
                        <div class="flex flex-col">
                            <label class="text-[10px] uppercase font-bold text-gray-400 ml-1">After Submit:</label>
                            <select id="after_submit_action" class="rounded-md border-gray-300 text-sm px-6 py-2 bg-gray-50 focus:ring-indigo-500">
                                <option value="redirect">Single</option>
                                <option value="stay">Multiple</option>
                            </select>
                        </div>

                        <button type="submit" id="submitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-8 rounded-md shadow-md transition duration-300">
                            Submit
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        const opCodeSelect = document.getElementById('op_code'); 
        const vehicleNoInput = document.getElementById('vehicle_no'); 
        const addHeldupForm = document.getElementById('addHeldupForm');
        const submitBtn = document.getElementById('submitBtn');
        const reasonContainer = document.getElementById('reason-entry-container');
        const addReasonEntryBtn = document.getElementById('addReasonEntryBtn');
        const afterSubmitSelect = document.getElementById('after_submit_action');
        const modeBadge = document.getElementById('mode-badge');
        
        let entryCounter = 1;
        const availableReasons = <?php echo json_encode($available_reasons); ?>; 
        const loggedInUserId = document.getElementById('loggedInUserId').value;

        // Update badge UI based on dropdown
        afterSubmitSelect.addEventListener('change', function() {
            if(this.value === 'stay') {
                modeBadge.innerText = "Multi-Entry Mode";
                modeBadge.className = "px-3 py-1 bg-green-100 text-green-800 text-xs font-bold rounded-full uppercase";
            } else {
                modeBadge.innerText = "Standard Mode";
                modeBadge.className = "px-3 py-1 bg-blue-100 text-blue-800 text-xs font-bold rounded-full uppercase";
            }
        });

        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type} show`;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i><span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Fetch vehicle logic
        opCodeSelect.addEventListener('change', async function() {
            if (!this.value) return;
            vehicleNoInput.value = '...';
            try {
                const response = await fetch('day_heldup_fetch_details.php', {
                    method: 'POST',
                    body: new URLSearchParams({ op_code: this.value }),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                const data = await response.json();
                if (data.success) vehicleNoInput.value = data.vehicle_no;
            } catch (e) { showToast('Error fetching vehicle', 'error'); }
        });

        // Add reason row
        addReasonEntryBtn.addEventListener('click', () => {
            const id = entryCounter++;
            let options = '<option value="">Select Reason</option>';
            let cat = '';
            availableReasons.forEach(r => {
                if (r.reason_category !== cat) {
                    if (cat !== '') options += '</optgroup>';
                    options += `<optgroup label="${r.reason_category}">`;
                    cat = r.reason_category;
                }
                options += `<option value="${r.reason_code}">${r.reason}</option>`;
            });
            const html = `
                <div id="reason-entry-${id}" class="grid md:grid-cols-3 gap-4 reason-entry pt-2 border-t border-indigo-100 mt-2">
                    <input type="text" name="emp_id[]" placeholder="Employee ID" class="block w-full rounded-md border-gray-300 shadow-sm p-2 uppercase text-sm">
                    <div class="md:col-span-2 flex items-center space-x-2">
                        <select name="reason_code[]" class="block w-full rounded-md border-gray-300 shadow-sm p-2 text-sm">${options}</select>
                        <button type="button" onclick="this.parentElement.parentElement.remove()" class="text-red-500 hover:text-red-700"><i class="fas fa-times"></i></button>
                    </div>
                </div>`;
            reasonContainer.insertAdjacentHTML('beforeend', html);
        });

        // Form Submit
        addHeldupForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const action = afterSubmitSelect.value;
            
            // Collect Reason Data
            const reasonData = [];
            const rows = reasonContainer.querySelectorAll('.reason-entry');
            let valid = true;

            rows.forEach(row => {
                const eid = row.querySelector('[name="emp_id[]"]').value.trim();
                const rcode = row.querySelector('[name="reason_code[]"]').value;
                if (eid || rcode) {
                    if (!eid || !rcode) {
                        showToast('Both Employee ID and Reason are required for each row.', 'error');
                        valid = false;
                        return;
                    }
                    reasonData.push({ emp_id: eid.toUpperCase(), reason_code: rcode });
                }
            });

            if (!valid || reasonData.length === 0) {
                if(reasonData.length === 0) showToast('Add at least one employee/reason.', 'error');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';

            const fd = new FormData(this);
            fd.set('reason_data_json', JSON.stringify(reasonData));
            fd.delete('emp_id[]'); fd.delete('reason_code[]');
            fd.append('user_id', loggedInUserId);

            try {
                const res = await fetch('day_heldup_add_handler.php', {
                    method: 'POST',
                    body: new URLSearchParams(fd),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                const data = await res.json();

                if (data.success) {
                    showToast(data.message);
                    if (action === 'redirect') {
                        setTimeout(() => window.location.href = 'day_heldup_register.php', 1000);
                    } else {
                        // MULTI-ENTRY LOGIC: Keep core details, clear the rest
                        const savedOp = opCodeSelect.value;
                        const savedVeh = vehicleNoInput.value;
                        const savedDate = document.getElementById('date').value;

                        addHeldupForm.reset();
                        
                        // Restore core info
                        opCodeSelect.value = savedOp;
                        vehicleNoInput.value = savedVeh;
                        document.getElementById('date').value = savedDate;
                        afterSubmitSelect.value = 'stay'; // Keep dropdown on 'stay'

                        // Reset Rows
                        reasonContainer.querySelectorAll('.reason-entry:not(#reason-entry-0)').forEach(r => r.remove());
                        entryCounter = 1;
                    }
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Server connection error.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Submit';
            }
        });
    </script>
</body>
</html>