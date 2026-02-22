<?php
// nh_add_trip.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php'); 

date_default_timezone_set('Asia/Colombo');

// --- Auto Select Logic ---
$schedules = [
    "6.45pm"  => "18:45:00",
    "9.45pm"  => "21:45:00",
    "10.45pm" => "22:45:00",
    "11.45pm" => "23:45:00",
    "1.45am"  => "01:45:00",
    "5.45am"  => "05:45:00"
];

$current_time_str = date("H:i:s");
$closest_schedule = "";
$min_diff = -1;

foreach ($schedules as $label => $time_val) {
    $diff = abs(strtotime($current_time_str) - strtotime($time_val));
    if ($min_diff === -1 || $diff < $min_diff) {
        $min_diff = $diff;
        $closest_schedule = $label;
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_trip'])) {
    
    $vehicle_no = strtoupper(trim($_POST['vehicle_no']));
    $quantity = (int)$_POST['quantity'];
    $schedule_time = $_POST['schedule_time']; 
    $date = date('Y-m-d');
    $time = date('H:i:s');
    
    // 1. Check if Duplicate Entry Exists for Today
    $check_sql = "SELECT id FROM nh_register WHERE vehicle_no = ? AND schedule_time = ? AND date = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('sss', $vehicle_no, $schedule_time, $date);
    $check_stmt->execute();
    $check_stmt->store_result();

    if ($check_stmt->num_rows > 0) {
        // Duplicate Found - Redirect with error message
        echo "<script>window.location.href='nh_add_trip.php?status=error&message=" . urlencode("Error: Vehicle $vehicle_no is already registered for $schedule_time today!") . "';</script>";
        $check_stmt->close();
        exit();
    }
    $check_stmt->close();

    // 2. If No Duplicate, Insert Query
    $op_code = ""; 
    $distance = 0.00;
    $done = 0; 

    $sql = "INSERT INTO nh_register (vehicle_no, quantity, schedule_time, date, time, op_code, distance, done) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param('sissssdi', $vehicle_no, $quantity, $schedule_time, $date, $time, $op_code, $distance, $done);
        
        if ($stmt->execute()) {
            echo "<script>window.location.href='night_heldup_register.php?date=$date&status=success&message=" . urlencode("Trip Added Successfully!") . "';</script>";
            exit();
        } else {
            echo "<script>alert('Error: " . $stmt->error . "');</script>";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Start Night Trip</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
        .toast { display: flex; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s; width: 350px; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
    </style>
</head>
<body class="bg-gray-100 font-sans">

<div id="toast-container"></div>

<div class="w-[85%] ml-[15%] flex justify-center p-3 mt-6">
    <div class="container max-w-2xl bg-white shadow-lg rounded-lg p-8 mt-2">
        
        <h1 class="text-3xl font-extrabold text-gray-900 mb-2 border-b pb-2">Start New Night Trip</h1>
        <p class="text-sm text-gray-600 mb-6">Enter vehicle details. System will prevent duplicate entries for the same schedule.</p>

        <form method="POST" action="" id="addTripForm" class="space-y-6">
            <input type="hidden" name="add_trip" value="1">

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Vehicle No <span class="text-red-500">*</span></label>
                    <input type="text" id="vehicle_no" name="vehicle_no" required placeholder="NPA-XXXX" 
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-3 uppercase border focus:ring-indigo-500 focus:border-indigo-500 font-bold text-gray-800 tracking-wide">
                </div>

                <div>
                    <label for="quantity" class="block text-sm font-medium text-gray-700">Quantity (Pax) <span class="text-red-500">*</span></label>
                    <input type="number" id="quantity" name="quantity" min="0" required placeholder="0" 
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-3 border focus:ring-indigo-500 focus:border-indigo-500">
                </div>
            </div>

            <div>
                <label for="schedule_time" class="block text-sm font-medium text-gray-700">Schedule Time <span class="text-red-500">*</span></label>
                <select id="schedule_time" name="schedule_time" required 
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-3 border focus:ring-indigo-500 focus:border-indigo-500 font-semibold text-gray-700">
                    <option value="" disabled>Select Schedule</option>
                    <?php
                    foreach ($schedules as $label => $time_val) {
                        $selected = ($label == $closest_schedule) ? "selected" : "";
                        echo "<option value='$label' $selected>$label</option>";
                    }
                    ?>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4 bg-indigo-50 p-4 rounded-md border border-indigo-100 mt-4">
                <div>
                    <span class="text-xs font-bold text-gray-500 uppercase block">Date</span>
                    <span class="text-gray-800 font-medium text-lg"><?php echo date('Y-m-d'); ?></span>
                </div>
                <div>
                    <span class="text-xs font-bold text-gray-500 uppercase block">System Time</span>
                    <span class="text-indigo-600 font-bold text-lg"><?php echo date('H:i A'); ?></span>
                </div>
            </div>

            <div class="flex justify-between gap-3 pt-4">
                <a href="night_heldup_register.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300">Cancel</a>
                <button type="submit" id="submitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 flex items-center">
                    Submit
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // --- Toast Logic ---
    function showToast(message, type = 'success', duration = 4000) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type} show`;
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
        toast.innerHTML = `<i class="fas ${icon} mr-2 mt-1"></i><span>${message}</span>`;
        toastContainer.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    // Check for Status in URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('status')) {
        showToast(decodeURIComponent(urlParams.get('message')), urlParams.get('status'));
        // URL එක පිරිසිදු කිරීමට (clean URL)
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    const form = document.getElementById('addTripForm');
    const submitBtn = document.getElementById('submitBtn');

    form.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Checking...';
    });
</script>

</body>
</html>