<?php
// basic_category.php

require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Close the database connection immediately as it's not needed for the menu itself
if (isset($conn)) {
    mysqli_close($conn);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Basic Data</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />

    <script>
        // 9 hours in milliseconds (32,400,000 ms)
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>

    <style>
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Gradient Classes (Fallback or Specific Customization) */
        .card-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-teal { background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); }
        .card-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .card-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .card-red { background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%); }
        .card-yellow { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-cyan { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); }
        .card-indigo { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }
        .card-gray { background: linear-gradient(135deg, #4b5563 0%, #1f2937 100%); }
    </style>
</head>
<body class="bg-gray-50">

    <div class="min-h-screen w-[85%] ml-[15%] p-10 flex flex-col justify-center items-center">
        
        <div class="text-center mb-10 fade-in">
            <h2 class="text-4xl font-extrabold text-gray-800 tracking-tight">
                <span class="bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-purple-600">
                    Basic Data Management
                </span>
            </h2>
            <p class="text-gray-500 mt-2 font-medium">Manage core system data</p>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 w-full max-w-6xl fade-in">
            
            <a href="routes_staff2.php" 
               class="card-blue relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-route text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Routes</span>
            </a>

            <a href="sub_routes.php" 
               class="card-teal relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-map-signs text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Sub Routes</span>
            </a>

            <a href="suppliers.php" 
               class="card-purple relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-handshake text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Suppliers</span>
            </a>

            <a href="vehicle.php" 
               class="card-green relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-bus text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Vehicles</span>
            </a>

            <a href="driver.php" 
               class="card-red relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-id-card text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Drivers</span>
            </a>

            <a href="employee.php" 
               class="card-yellow relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-users text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Employees</span>
            </a>

            <a href="own_vehicle.php" 
               class="card-cyan relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-gas-pump text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Fuel Allowance</span>
            </a>

            <a href="op_services.php" 
               class="card-indigo relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-cogs text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Operational Services</span>
            </a>

            <a href="fuel.php" 
               class="card-gray relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-oil-can text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Fuel</span>
            </a>

        </div>
    </div>

</body>
</html>