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
$night_emergency_vehicle = null; // Changed to a single variable

// Fetch a single vehicle and driver from night_emergency_attendance for the current date
$attendance_sql = "SELECT vehicle_no, driver_NIC FROM night_emergency_attendance WHERE date = ? ORDER BY vehicle_no LIMIT 1";
$stmt_attendance = $conn->prepare($attendance_sql);

if ($stmt_attendance) {
    $stmt_attendance->bind_param('s', $today_date);
    $stmt_attendance->execute();
    $attendance_result = $stmt_attendance->get_result();
    
    // Fetch the single row
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
    <title>Add Night Emergency Vehicle Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .toast {
            display: flex;
            align-items: center;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            color: white;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            background-color: #4CAF50;
        }

        .toast.error {
            background-color: #F44336;
        }

        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add Night Emergency Vehicle Record</h1>

            <?php if (empty($night_emergency_vehicle)): ?>
                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4" role="alert">
                    <p class="font-bold">No Attendance Found</p>
                    <p>Night emergency attendance is not recorded for today. No vehicle is available to be selected.</p>
                </div>
            <?php else: ?>
                <form id="addVehicleForm" class="space-y-6">
                    <input type="hidden" name="add_record" value="1">

                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No:</label>
                            <input type="text" id="vehicle_no" name="vehicle_no" required readonly class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 bg-gray-200" value="<?php echo htmlspecialchars($night_emergency_vehicle['vehicle_no']); ?>">
                        </div>
                        <div>
                            <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                            <input type="date" id="date" name="date" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="driver" class="block text-sm font-medium text-gray-700">Driver License ID:</label>
                            <input type="text" id="driver" name="driver" required readonly class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 bg-gray-200" value="<?php echo htmlspecialchars($night_emergency_vehicle['driver_NIC']); ?>">
                        </div>
                        <div>
                            <label for="out_time" class="block text-sm font-medium text-gray-700">Out Time:</label>
                            <input type="time" id="out_time" name="out_time" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                        </div>
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700">Description:</label>
                        <textarea id="description" name="description" rows="3" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"></textarea>
                    </div>

                    <div class="flex justify-end mt-6">
                        <a href="../night_emergency.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105 mr-3">
                            Cancel
                        </a>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                            Add Record
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
        // Show toast notification
        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                    ${type === 'success'
                        ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />'
                        : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />'
                    }
                </svg>
                <span>${message}</span>
            `;

            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);

            // Hide and remove
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 2000);
        }

        // Handle form submit via AJAX
        const form = document.getElementById('addVehicleForm');
        if (form) {
            form.addEventListener('submit', async function(event) {
                event.preventDefault();
                const formData = new FormData(this);

                try {
                    const response = await fetch('add_night_emergency_vehicle.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    const result = await response.json();

                    if (result.status === 'success') {
                        showToast(result.message, 'success');
                        setTimeout(() => window.location.href = '../night_emergency.php', 2000);
                    } else {
                        showToast(result.message, 'error');
                    }
                } catch (error) {
                    console.error('Submission error:', error);
                    showToast('An unexpected error occurred.', 'error');
                }
            });
        }

        // Set today's date
        document.getElementById('date').value = '<?php echo $today_date; ?>';
    </script>
</body>
</html>