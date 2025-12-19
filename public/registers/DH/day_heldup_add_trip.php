<?php
// day_heldup_add.php - Manual entry form for starting a PENDING Day Heldup record (MINIMAL INPUT)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Get the logged-in User ID from the session (Assuming 'loggedin' holds a value if true, or using a separate ID if available)
$logged_in_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true ? 'User' : '');
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- 1. Fetch All Available Reasons for Dropdown (Using GL_NAME as Category) ---
// Join 'reason' table with 'gl' table on gl_code to get gl_name (which acts as the category)
$all_reasons_sql = "
    SELECT 
        r.reason_code, 
        r.reason, 
        g.gl_name AS reason_category, 
        r.gl_code
    FROM 
        reason r
    JOIN 
        gl g ON r.gl_code = g.gl_code
    ORDER BY 
        g.gl_name, r.reason";
        
$all_reasons_result = $conn->query($all_reasons_sql);
$available_reasons = [];
while ($row = $all_reasons_result->fetch_assoc()) {
    $available_reasons[] = $row;
}

// --- 2. Fetch Filtered OP Codes for Dropdown ---
$op_code_sql = "SELECT op_code, vehicle_no FROM op_services WHERE op_code LIKE 'DH-%' ORDER BY op_code ASC";
$op_code_result = $conn->query($op_code_sql);
$available_op_codes = [];
if ($op_code_result) {
    while ($row = $op_code_result->fetch_assoc()) {
        $available_op_codes[] = $row['op_code'];
    }
}
$conn->close();

// Include HTML head and navigation
include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Start Day Heldup Trip</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Toast and general styling */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
        .toast { display: flex; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div id="toast-container"></div>

    <div class="w-[85%] ml-[15%] flex justify-center p-3 mt-6">
        <div class="container max-w-2xl bg-white shadow-lg rounded-lg p-8 mt-2">
            
            <h1 class="text-3xl font-extrabold text-gray-900 mb-2 border-b pb-2">
                Start New Day Heldup Trip
            </h1>
            <p class="text-sm text-gray-600 mb-4">
                Enter initial details and the reasons for the heldup. Date and Start Time are set automatically.
            </p>

            <form id="addHeldupForm" class="space-y-6">
                
                <input type="hidden" name="action" value="add_heldup_trip">
                <input type="hidden" id="loggedInUserId" value="<?php echo htmlspecialchars($logged_in_user_id); ?>"> 

                <div class="grid md:grid-cols-2 gap-4">
                    
                    <div>
                        <label for="op_code" class="block text-sm font-medium text-gray-700">Op Code<span class="text-red-500">*</span></label>
                        <select id="op_code" name="op_code" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                            <option value="" disabled selected>Select Op Code</option>
                            <?php if (!empty($available_op_codes)): ?>
                                <?php foreach ($available_op_codes as $code): ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($code); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No codes found</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No<span class="text-red-500">*</span></label>
                        <input type="text" id="vehicle_no" name="vehicle_no" required placeholder="NPA-XXXX " class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 uppercase">
                    </div>
                </div>

                <div class="space-y-4 border border-indigo-200 p-4 rounded-md bg-indigo-50" id="reason-entry-container">
                    <h3 class="text-md font-semibold text-indigo-700">Employee and Reason Details <span class="text-red-500">*</span></h3>
                    
                    <div id="reason-entry-0" class="grid md:grid-cols-3 gap-4 reason-entry">
                        <div>
                            <label for="emp_id_0" class="block text-sm font-medium text-gray-700">Employee ID</label>
                            <input type="text" id="emp_id_0" name="emp_id[]" placeholder="GPxxxxxx" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 uppercase">
                        </div>
                        <div class="md:col-span-2 relative">
                            <label for="reason_code_0" class="block text-sm font-medium text-gray-700">Reason</label>
                            <select id="reason_code_0" name="reason_code[]" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                                <option value="" selected>Select Reason</option>
                                <?php 
                                $current_category = '';
                                foreach ($available_reasons as $reason):
                                    // Use 'reason_category' (which is now gl_name from the JOIN) for optgroup
                                    if ($reason['reason_category'] !== $current_category): 
                                        if ($current_category !== '') echo '</optgroup>';
                                        echo '<optgroup label="' . htmlspecialchars($reason['reason_category']) . '">';
                                        $current_category = $reason['reason_category'];
                                    endif;
                                ?>
                                    <option value="<?php echo htmlspecialchars($reason['reason_code']); ?>">
                                        <?php echo htmlspecialchars($reason['reason']); ?>
                                    </option>
                                <?php endforeach; 
                                if ($current_category !== '') echo '</optgroup>';
                                ?>
                            </select>
                        </div>
                    </div>
                    
                </div>


                <div class="flex justify-between pt-1">
                    <a href="day_heldup_register.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300">
                        Cancel
                    </a>
                    <button type="button" id="addReasonEntryBtn" class="bg-indigo-300 text-indigo-900 font-semibold py-1 px-3 rounded-md text-sm hover:bg-indigo-400">
                                <i class="fas fa-plus-circle mr-1"></i> Add Another Employee/Reason
                            </button>
                    <div class="flex space-x-3">
                        
                        <button type="submit" id="submitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300">
                            Start Trip
                        </button>
                    </div>
                </div>
            </form>

        </div>
    </div>

    <script>
        // Global variables
        const opCodeSelect = document.getElementById('op_code'); 
        const vehicleNoInput = document.getElementById('vehicle_no'); 
        const addHeldupForm = document.getElementById('addHeldupForm');
        const submitBtn = document.getElementById('submitBtn');
        const reasonContainer = document.getElementById('reason-entry-container');
        const addReasonEntryBtn = document.getElementById('addReasonEntryBtn');
        let entryCounter = 1;
        const availableReasons = <?php echo json_encode($available_reasons); ?>; // Updated structure here
        const loggedInUserId = document.getElementById('loggedInUserId').value;


        // --- Utility Functions ---
        function showToast(message, type = 'success', duration = 3000) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.classList.add('toast', type, 'show');
            const iconHtml = type === 'success' ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-triangle mr-2"></i>';
            toast.innerHTML = iconHtml + `<span>${message}</span>`;
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, duration);
        }

        function createReasonDropdown(id) {
            let options = '<option value="" selected>Select Reason</option>';
            let currentCategory = '';
            
            // Loop uses the PHP generated data (which now includes gl_name as reason_category)
            availableReasons.forEach(reason => {
                if (reason.reason_category !== currentCategory) {
                    if (currentCategory !== '') options += '</optgroup>';
                    // Use reason_category (gl_name) for the optgroup label
                    options += `<optgroup label="${reason.reason_category}">`; 
                    currentCategory = reason.reason_category;
                }
                options += `<option value="${reason.reason_code}">${reason.reason}</option>`;
            });
            if (currentCategory !== '') options += '</optgroup>';
            
            return `<select id="reason_code_${id}" name="reason_code[]" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">${options}</select>`;
        }

        function addReasonEntry() {
            const newEntryId = entryCounter++;
            const newEntryHtml = `
                <div id="reason-entry-${newEntryId}" class="grid md:grid-cols-3 gap-4 reason-entry pt-4 border-t border-indigo-100">
                    <div>
                        <label for="emp_id_${newEntryId}" class="block text-sm font-medium text-gray-700">Employee ID</label>
                        <input type="text" id="emp_id_${newEntryId}" name="emp_id[]" placeholder="GPxxxxxx" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 uppercase">
                    </div>
                    <div class="md:col-span-2 flex items-end relative">
                        <div class="flex-grow">
                            <label for="reason_code_${newEntryId}" class="block text-sm font-medium text-gray-700">Reason</label>
                            ${createReasonDropdown(newEntryId)}
                        </div>
                        <button type="button" data-entry-id="${newEntryId}" class="remove-reason-btn bg-red-500 text-white p-2 rounded-md ml-2 hover:bg-red-700 self-end">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            `;
            reasonContainer.insertAdjacentHTML('beforeend', newEntryHtml);
        }

        // --- FETCH Vehicle No Logic (Remains the same) ---
        opCodeSelect.addEventListener('change', async function() {
            const opCode = this.value;
            if (!opCode) {
                vehicleNoInput.value = '';
                vehicleNoInput.placeholder = 'NPA-XXXX (Auto-filled)';
                return;
            }

            vehicleNoInput.value = 'Fetching...';
            vehicleNoInput.disabled = true;

            const formData = new FormData();
            formData.append('op_code', opCode);

            try {
                const response = await fetch('day_heldup_fetch_attendance_details.php', {
                    method: 'POST',
                    body: new URLSearchParams(formData),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });

                const data = await response.json();

                if (data.success && data.vehicle_no) {
                    vehicleNoInput.value = data.vehicle_no;
                    vehicleNoInput.placeholder = data.vehicle_no;
                } else {
                    vehicleNoInput.value = '';
                    vehicleNoInput.placeholder = 'Vehicle Not Found! Enter Manually.';
                    showToast('Assigned vehicle not found for this Op Code.', 'error', 3000);
                }

            } catch (error) {
                console.error('Vehicle Fetch Error:', error);
                vehicleNoInput.value = '';
                vehicleNoInput.placeholder = 'Error fetching vehicle.';
                showToast('Network error during vehicle lookup.', 'error', 3000);
            } finally {
                vehicleNoInput.disabled = false;
            }
        });


        // --- Event Listeners (Remains the same) ---
        addReasonEntryBtn.addEventListener('click', addReasonEntry);

        // Delegation for remove buttons (handles dynamically added buttons)
        reasonContainer.addEventListener('click', function(event) {
            const removeBtn = event.target.closest('.remove-reason-btn');
            if (removeBtn) {
                const entryElement = removeBtn.closest('.reason-entry');
                if (entryElement && reasonContainer.querySelectorAll('.reason-entry').length > 1) {
                     entryElement.remove();
                } else if (entryElement) {
                     // If it's the last entry, just clear the fields
                     const empIdInput = entryElement.querySelector('input[name="emp_id[]"]');
                     const reasonSelect = entryElement.querySelector('select[name="reason_code[]"]');
                     if (empIdInput) empIdInput.value = '';
                     if (reasonSelect) reasonSelect.selectedIndex = 0;
                     showToast('Cannot remove the last entry; fields have been cleared instead.', 'error', 4000);
                }
            }
        });


        // --- Form Submission Handler (Remains the same) ---
        addHeldupForm.addEventListener('submit', async function(event) {
            event.preventDefault();
            
            // Final validation: Ensure Vehicle No is filled
            if (!vehicleNoInput.value.trim()) {
                 showToast('Vehicle No is mandatory.', 'error');
                 vehicleNoInput.focus();
                 return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';

            // Custom Validation: Ensure Employee IDs have reasons
            let valid = true;
            const entries = reasonContainer.querySelectorAll('.reason-entry');
            const reasonData = [];
            
            entries.forEach(entry => {
                const empId = entry.querySelector('input[name="emp_id[]"]').value.trim();
                const reasonCode = entry.querySelector('select[name="reason_code[]"]').value;

                if (empId) {
                    if (!reasonCode) {
                        showToast(`Employee ${empId} must have a reason selected.`, 'error');
                        valid = false;
                    }
                    reasonData.push({ emp_id: empId.toUpperCase(), reason_code: reasonCode });
                } else if (reasonCode) {
                     showToast("Reason selected without an Employee ID.", 'error');
                     valid = false;
                }
            });

            if (!valid || reasonData.length === 0) {
                if (reasonData.length === 0 && valid) {
                    showToast('Please add at least one employee and reason.', 'error');
                }
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-play mr-1"></i> Start Trip';
                return;
            }
            
            const formData = new FormData(this);
            
            // Append processed JSON reason data
            formData.set('reason_data_json', JSON.stringify(reasonData));
            formData.delete('emp_id[]'); 
            formData.delete('reason_code[]');
            
            // Append the user ID (Backend handler will use this for transaction context)
            formData.append('user_id', loggedInUserId);
            
            try {
                // Submit to the handler
                const response = await fetch('day_heldup_add_trip_handler.php', { 
                    method: 'POST',
                    body: new URLSearchParams(formData),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });

                const data = await response.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    // Redirect back to the register view after success
                    setTimeout(() => {
                        window.location.href = 'day_heldup_register.php';
                    }, 1500); 
                } else {
                    showToast(data.message, 'error');
                }

            } catch (error) {
                console.error('AJAX Submission Error:', error);
                showToast('An unexpected error occurred during submission.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-play mr-1"></i> Start Trip';
            }
        });

    </script>
</body>
</html>