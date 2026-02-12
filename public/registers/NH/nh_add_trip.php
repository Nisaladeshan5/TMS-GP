<?php
// nh_add_trip.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php'); 

date_default_timezone_set('Asia/Colombo');

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_trip'])) {
    
    // Get Inputs (Only Vehicle & Qty)
    $vehicle_no = strtoupper(trim($_POST['vehicle_no']));
    $quantity = (int)$_POST['quantity'];
    
    // Auto Generate Date & Time
    $date = date('Y-m-d');
    $time = date('H:i:s');

    // Default values for other columns (to be filled later during 'Complete')
    $op_code = ""; // Empty for now
    $distance = 0.00;
    $direct_count = 0;
    $indirect_count = 0;
    $done = 0; 

    // Insert Query
    $sql = "INSERT INTO nh_register (vehicle_no, quantity, date, time, op_code, distance, done) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param('sisssdd', $vehicle_no, $quantity, $date, $time, $op_code, $distance, $done);
        
        if ($stmt->execute()) {
            // Redirect with Success
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
</head>
<style>
    /* Toast Styles */
    #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
    .toast { display: flex; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s; }
    .toast.show { opacity: 1; }
    .toast.success { background-color: #4CAF50; }
    .toast.error { background-color: #F44336; }
</style>
<body class="bg-gray-100 font-sans">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="night_heldup_register.php" class="hover:text-yellow-600">Register</a>
    </div>
</div>

<div id="toast-container"></div>

<div class="w-[85%] ml-[15%] flex justify-center p-3 mt-6">
    <div class="container max-w-2xl bg-white shadow-lg rounded-lg p-8 mt-2">
        
        <h1 class="text-3xl font-extrabold text-gray-900 mb-2 border-b pb-2">
            Start New Night Trip
        </h1>
        <p class="text-sm text-gray-600 mb-6">
            Enter the vehicle number and quantity. Date and Time are recorded automatically.
        </p>

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

            <div class="grid grid-cols-2 gap-4 bg-indigo-50 p-4 rounded-md border border-indigo-100 mt-4">
                <div>
                    <span class="text-xs font-bold text-gray-500 uppercase block">Date</span>
                    <span class="text-gray-800 font-medium text-lg"><?php echo date('Y-m-d'); ?></span>
                </div>
                <div>
                    <span class="text-xs font-bold text-gray-500 uppercase block">Start Time</span>
                    <span class="text-indigo-600 font-bold text-lg"><?php echo date('H:i A'); ?></span>
                </div>
            </div>

            <div class="flex justify-between gap-3 pt-4">
                <a href="night_heldup_register.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300">
                    Cancel
                </a>
                <button type="submit" id="submitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 flex items-center">
                    Submit
                </button>
            </div>

        </form>
    </div>
</div>

<script>
    const submitBtn = document.getElementById('submitBtn');
    const form = document.getElementById('addTripForm');

    // --- Toast Logic ---
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

    // --- Form Submit Animation ---
    form.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Saving...';
    });

    // Check URL Parameters for Messages (e.g. error redirect)
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    const message = urlParams.get('message');

    if (status && message) {
        showToast(decodeURIComponent(message), status);
        window.history.replaceState(null, null, window.location.pathname);
    }
</script>

</body>
</html>