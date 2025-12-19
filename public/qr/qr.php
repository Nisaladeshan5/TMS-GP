<?php
// Start the session if it hasn't been started already (Best Practice)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in. This relies on $_SESSION['loggedin'] being set.
// *** You must ensure your login process sets this variable: $_SESSION['loggedin'] = true; ***
// $is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

// Include necessary files
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php'); 

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Button Navigation</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100" >

    <div class="h-screen flex justify-center items-center w-[85%] ml-[15%]">
        <div class="w-3xl text-center p-6 bg-white rounded-xl shadow-lg">
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-6 ">
                <a href="staff/barcode_reader.php" class="bg-blue-500 text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">Staff transport vehicle Scanner</a>
                <a href="factory/f_barcode_reader.php" class="bg-gray-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Factory transport vehicle Scanner</a>
                <!-- <a href="public/registers/DH/day_heldup_register.php" class="bg-green-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Day time vehicle register</a> -->
                <!-- <a href="" class="bg-red-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Night time vehicle register</a> -->
                <a href="NE/night_emergency_barcode.php" class="bg-yellow-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Night emergency vehicle Scanner</a>
                <!-- <a href="public/registers/extra_vehicle.php" class="bg-teal-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Extra vehicle register</a> -->

                
                <!-- <a href="public/registers/petty_cash.php" class="bg-[oklch(60.6%_0.25_292.717)] text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Petty cash</a> -->
                <a href="manager/add_own_vehicle_extra_qr.php" class="bg-green-800 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Manager Vehicle Scanner</a>
                <!-- <a href="public/registers/varification.php" class="bg-pink-800 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Varification</a> -->
                
                
            </div>
        </div>
    </div>
</body>
</html>