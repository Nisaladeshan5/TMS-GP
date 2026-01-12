<?php
// require_once '../../includes/session_check.php';
// Include the database connection
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
// if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
//     header("Location: ../../includes/login.php");
//     exit();
// }

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Non Paid Vehicle Register</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />

    <style>
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Gradient Classes */
        .card-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .card-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .card-yellow { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    </style>

    <script>
        // 9 hours in milliseconds (32,400,000 ms)
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>
<body class="bg-gray-50">

    <div class="min-h-screen w-[85%] ml-[15%] p-10 flex flex-col justify-center items-center">
        
        <div class="text-center mb-10 fade-in">
            <h2 class="text-4xl font-extrabold text-gray-800 tracking-tight">
                <span class="bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-purple-600">
                    Non-Paid Vehicle Register
                </span>
            </h2>
            <p class="text-gray-500 mt-2 font-medium">Select a category to manage</p>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-8 w-full max-w-4xl fade-in">
            
            <a href="non_paid/visitors.php" 
               class="card-blue relative overflow-hidden rounded-2xl p-8 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-4 border border-white/10 group h-64">
                <div class="p-5 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-users text-4xl"></i>
                </div>
                <span class="text-2xl font-bold text-white tracking-wide">Visitors</span>
                <div class="absolute -bottom-6 -right-6 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
            </a>

            <a href="non_paid/own_vehicle_attendance.php" 
               class="card-green relative overflow-hidden rounded-2xl p-8 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-4 border border-white/10 group h-64">
                <div class="p-5 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-user-tie text-4xl"></i>
                </div>
                <span class="text-2xl font-bold text-white tracking-wide">Managers</span>
                <div class="absolute -bottom-6 -right-6 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
            </a>

            <a href="non_paid/external_vehicle.php" 
               class="card-red relative overflow-hidden rounded-2xl p-8 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-4 border border-white/10 group h-64">
                <div class="p-5 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-truck text-4xl"></i>
                </div>
                <span class="text-2xl font-bold text-white tracking-wide">External Vehicle</span>
                <div class="absolute -bottom-6 -right-6 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
            </a>

            <a href="non_paid/carbon_emission.php" 
               class="card-yellow relative overflow-hidden rounded-2xl p-8 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-4 border border-white/10 group h-64">
                <div class="p-5 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-smog text-4xl"></i>
                </div>
                <span class="text-2xl font-bold text-white tracking-wide">Carbon Emission</span>
                <div class="absolute -bottom-6 -right-6 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
            </a>

        </div>
    </div>

</body>
</html>