<?php
// dh_attendance_manual_handler.php - Form to mark daily Op Code / Vehicle Attendance (Manual Time Input)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- Fetch Filtered OP Codes for Dropdown ---
$op_code_sql = "SELECT op_code FROM op_services WHERE op_code LIKE 'DH-%' ORDER BY op_code ASC";
$op_code_result = $conn->query($op_code_sql);
$available_op_codes = [];
if ($op_code_result) {
    while ($row = $op_code_result->fetch_assoc()) {
        $available_op_codes[] = $row['op_code'];
    }
}
$conn->close();

// Set default values for the form fields
$today_date = date('Y-m-d');
$current_time = date('H:i'); 

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mark DH Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
        .toast { display: flex; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div id="toast-container"></div>

    <div class="w-[85%] ml-[15%] flex justify-center p-6">
        <div class="container max-w-2xl bg-white shadow-lg rounded-lg p-8 mt-6">
            
            <h1 class="text-3xl font-extrabold text-gray-900 mb-2 border-b pb-3">
                Mark Daily DH Attendance (Manual)
            </h1>
            <p class="text-sm text-gray-600 mb-6">
                Please confirm the date and time of the attendance record.
            </p>

            <form id="attendanceForm" class="space-y-6">
                <input type="hidden" name="action" value="mark_attendance">
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="op_code" class="block text-sm font-medium text-gray-700">Op Code<span class="text-red-500">*</span></label>
                        <select id="op_code" name="op_code" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
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
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700">Date<span class="text-red-500">*</span></label>
                        <input type="date" id="date" name="date" required value="<?php echo $today_date; ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                    </div>

                    <div>
                        <label for="time" class="block text-sm font-medium text-gray-700">Time<span class="text-red-500">*</span></label>
                        <input type="time" id="time" name="time" required value="" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                    </div>
                </div>                
                

                <div class="flex justify-between pt-4">
                    <a href="day_heldup_register.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300">
                        Cancel
                    </a>
                    <button type="submit" id="submitBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300">
                        Mark Attendance
                    </button>
                </div>
            </form>

        </div>
    </div>

    <script>
        // Global variables for JS access
        const opCodeSelect = document.getElementById('op_code'); 
        const vehicleNoInput = document.getElementById('vehicle_no');
        
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
        
        // --- FETCH Vehicle No Logic ---
        opCodeSelect.addEventListener('change', async function() {
            const opCode = this.value;
            if (!opCode) {
                vehicleNoInput.value = '';
                vehicleNoInput.placeholder = 'NPA-XXXX';
                vehicleNoInput.disabled = false;
                return;
            }

            vehicleNoInput.value = 'Fetching...';
            vehicleNoInput.disabled = true;

            const formData = new FormData();
            formData.append('op_code', opCode);

            try {
                const response = await fetch('day_heldup_fetch_details.php', {
                    method: 'POST',
                    body: new URLSearchParams(formData),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });

                const data = await response.json();

                if (data.success && data.vehicle_no) {
                    vehicleNoInput.value = data.vehicle_no;
                    vehicleNoInput.placeholder = data.vehicle_no;
                    showToast(data.message, 'success', 1500);
                } else {
                    vehicleNoInput.value = '';
                    vehicleNoInput.placeholder = 'Vehicle Not Assigned (Enter Manually)';
                    showToast(data.message || 'Vehicle not found for this Op Code.', 'error', 3000);
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

        // --- Form Submission Handler ---
        document.getElementById('attendanceForm').addEventListener('submit', async function(event) {
            event.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';

            const formData = new FormData(this);
            
            try {
                const response = await fetch('dh_attendance_manual_handler.php', { 
                    method: 'POST',
                    body: new URLSearchParams(formData),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });

                const data = await response.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    document.getElementById('attendanceForm').reset();
                    vehicleNoInput.value = '';
                } else {
                    showToast(data.message, 'error');
                }

            } catch (error) {
                console.error('AJAX Submission Error:', error);
                showToast('An unexpected error occurred during submission.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save mr-1"></i> Mark Attendance';
            }
        });

    </script>
</body>
</html>
