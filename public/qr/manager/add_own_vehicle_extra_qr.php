<?php
// Include the database connection
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
// if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
//     header("Location: ../../../includes/login.php");
//     exit();
// }

include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');
// Close the database connection
mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Button Navigation</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
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
<body class="bg-gray-100 m-0">

    <div class="h-screen flex justify-center items-center w-[85%] ml-[15%]">
        <div class="w-4xl text-center p-6 bg-white rounded-xl shadow-lg">
            <h2 class="text-4xl font-bold text-gray-800 pb-4">QR Scan</h2>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 ">
                <a href="own_vehicle_attendance_qr.php" class="bg-blue-600 text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">ATTENDANCE</a>
                <a href="own_vehicle_extra_qr_out.php" class="bg-teal-500 text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">OUT</a>
                <a href="own_vehicle_extra_qr_in.php" class="bg-purple-600 text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">IN</a>
                <!-- <a href="vehicle.php" class="bg-green-600 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Vehicles</a>
                <a href="driver.php" class="bg-red-600 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Drivers</a>
                <a href="employee.php" class="bg-yellow-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Employees</a>
                <a href="own_vehicle.php" class="bg-cyan-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Managers</a>
                <a href="op_services.php" class="bg-indigo-600 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Operational Services</a>
                <a href="fuel.php" class="bg-gray-800 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Fuel</a> -->
            </div>
        </div>
    </div>
</body>
</html>