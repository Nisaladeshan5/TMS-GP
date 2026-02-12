<?php
session_start();
include('../../../includes/db.php');

// Disable error display (good practice for production)
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

// Handle AJAX form submission for adding a new record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $vehicle_no = trim($_POST['vehicle_no']);
    $date = trim($_POST['date']);
    $driver = trim($_POST['driver']);
    $out_time = trim($_POST['out_time']);
    $description = trim($_POST['description']);

    if (empty($vehicle_no) || empty($date) || empty($driver) || empty($out_time)) {
        echo json_encode(['status' => 'error', 'message' => 'Vehicle No, Date, Driver, and Out Time are required.']);
        $conn->close();
        exit();
    }

    $sql = "INSERT INTO night_emergency_vehicle_register (vehicle_no, date, driver, out_time, description) 
            VALUES (?, ?, ?, ?, ?)";
    
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('sssss', $vehicle_no, $date, $driver, $out_time, $description);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Record added successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

// Non-AJAX request: page load
$today_date = date('Y-m-d');
$night_emergency_vehicle = null; 

// Fetch a single vehicle and driver from night_emergency_attendance for the current date
$attendance_sql = "SELECT vehicle_no, driver_NIC FROM night_emergency_attendance WHERE date = ? ORDER BY vehicle_no LIMIT 1";
$stmt_attendance = $conn->prepare($attendance_sql);

if ($stmt_attendance) {
    $stmt_attendance->bind_param('s', $today_date);
    $stmt_attendance->execute();
    $attendance_result = $stmt_attendance->get_result();
    $night_emergency_vehicle = $attendance_result->fetch_assoc();
    $stmt_attendance->close();
}

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Night Emergency Record</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Toast CSS */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
    </style>
</head>

<body class="bg-gray-100">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Night Emergency Register
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="../night_emergency.php" class="text-gray-300 hover:text-white transition">Register</a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-24 min-h-screen flex flex-col items-center bg-gray-100">
    
    <div class="w-full max-w-3xl bg-white rounded-xl shadow-lg border border-gray-200 p-8">
        
        <h2 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-4 flex items-center gap-2">
            <i class="fas fa-plus-circle text-indigo-600"></i> Add Vehicle Record
        </h2>

        <?php if (empty($night_emergency_vehicle)): ?>
            <div class="bg-amber-50 border-l-4 border-amber-500 text-amber-700 p-4 rounded-md shadow-sm" role="alert">
                <div class="flex items-center gap-3">
                    <i class="fas fa-exclamation-triangle text-xl"></i>
                    <div>
                        <p class="font-bold">No Attendance Found</p>
                        <p class="text-sm">Night emergency attendance is not recorded for today. Please add attendance first.</p>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="add_night_emergency_attendance.php" class="text-sm font-bold underline text-red-700 hover:text-red-900">Go to Add Attendance &rarr;</a>
                </div>
            </div>
        <?php else: ?>
            <form id="addVehicleForm" class="space-y-6">
                <input type="hidden" name="add_record" value="1">

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="vehicle_no" class="block text-sm font-semibold text-gray-700 mb-1">Vehicle No</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-car text-gray-400"></i>
                            </div>
                            <input type="text" id="vehicle_no" name="vehicle_no" required readonly 
                                   class="pl-10 block w-full rounded-md border-gray-300 bg-gray-100 text-gray-500 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2.5 cursor-not-allowed font-mono" 
                                   value="<?php echo htmlspecialchars($night_emergency_vehicle['vehicle_no']); ?>">
                        </div>
                    </div>

                    <div>
                        <label for="date" class="block text-sm font-semibold text-gray-700 mb-1">Date</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-calendar-alt text-gray-400"></i>
                            </div>
                            <input type="date" id="date" name="date" required 
                                   class="pl-10 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2.5">
                        </div>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="driver" class="block text-sm font-semibold text-gray-700 mb-1">Driver License ID</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-id-card text-gray-400"></i>
                            </div>
                            <input type="text" id="driver" name="driver" required readonly 
                                   class="pl-10 block w-full rounded-md border-gray-300 bg-gray-100 text-gray-500 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2.5 cursor-not-allowed font-mono" 
                                   value="<?php echo htmlspecialchars($night_emergency_vehicle['driver_NIC']); ?>">
                        </div>
                    </div>

                    <div>
                        <label for="out_time" class="block text-sm font-semibold text-gray-700 mb-1">Out Time</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-clock text-gray-400"></i>
                            </div>
                            <input type="time" id="out_time" name="out_time" required 
                                   class="pl-10 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2.5">
                        </div>
                    </div>
                </div>

                <div>
                    <label for="description" class="block text-sm font-semibold text-gray-700 mb-1">Description</label>
                    <textarea id="description" name="description" rows="3" placeholder="Enter trip details..." 
                              class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-3"></textarea>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-gray-100">
                    <a href="../night_emergency.php" class="bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 font-bold py-2 px-6 rounded-md shadow-sm transition duration-300">
                        Cancel
                    </a>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105 flex items-center gap-2">
                        <i class="fas fa-save"></i> Save Record
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div id="toast-container"></div>

<script>
    // --- Toast Notification Logic ---
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const icon = type === 'success' 
            ? '<i class="fas fa-check-circle text-lg mr-3"></i>' 
            : '<i class="fas fa-exclamation-circle text-lg mr-3"></i>';

        toast.innerHTML = `${icon} <span class="font-medium">${message}</span>`;

        toastContainer.appendChild(toast);
        
        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });

        // Hide and remove after delay
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 3000);
    }

    // --- Form Handling ---
    const form = document.getElementById('addVehicleForm');
    if (form) {
        // Set today's date automatically
        document.getElementById('date').value = '<?php echo $today_date; ?>';

        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            // Loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            try {
                // Determine current URL for AJAX
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                // Handle JSON response
                // Note: Ensure your PHP script doesn't output HTML before JSON
                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (e) {
                    console.error("Invalid JSON response:", text);
                    throw new Error("Invalid server response.");
                }

                if (result.status === 'success') {
                    showToast(result.message, 'success');
                    setTimeout(() => window.location.href = '../night_emergency.php', 1500);
                } else {
                    showToast(result.message, 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            } catch (error) {
                console.error('Submission error:', error);
                showToast(error.message || 'An unexpected error occurred.', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    }
</script>

</body>
</html>