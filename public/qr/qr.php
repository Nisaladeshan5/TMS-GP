<?php
// Start the session if it hasn't been started already
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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
    <title>QR Scanners</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    
    <style>
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-gray-50 overflow-hidden"> <div class="h-screen w-[85%] ml-[15%] p-6 flex flex-col">
        
        <div class="text-center mb-6 fade-in shrink-0">
            <h2 class="text-4xl font-extrabold text-gray-800 tracking-tight">
                <span class="bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-purple-600">
                    QR Code Scanners
                </span>
            </h2>
            <p class="text-gray-500 mt-2 font-medium">Select a module to start scanning</p>
        </div>
        
        <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 w-full max-w-6xl mx-auto fade-in content-center">
            
            <a href="staff/barcode_reader.php" 
               class="group relative overflow-hidden bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-4 border border-white/10 min-h-[180px]">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-qrcode text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Staff Transport Scanner</span>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 bg-white/10 rounded-full blur-2xl"></div>
            </a>

            <a href="factory/f_barcode_reader.php" 
               class="group relative overflow-hidden bg-gradient-to-br from-gray-600 to-gray-700 rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-4 border border-white/10 min-h-[180px]">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-truck-loading text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Factory Transport Scanner</span>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 bg-white/10 rounded-full blur-2xl"></div>
            </a>

            <a href="NE/night_emergency_barcode.php" 
               class="group relative overflow-hidden bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-4 border border-white/10 min-h-[180px]">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-ambulance text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Night Emergency Scanner</span>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 bg-white/10 rounded-full blur-2xl"></div>
            </a>

            <a href="manager/own_vehicle_attendance_qr.php" 
               class="group relative overflow-hidden bg-gradient-to-br from-green-600 to-green-700 rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-4 border border-white/10 min-h-[180px]">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-user-tie text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Manager Vehicle Scanner</span>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 bg-white/10 rounded-full blur-2xl"></div>
            </a>

            <a href="other/other_vehicles.php" 
               class="group relative overflow-hidden bg-gradient-to-br from-orange-500 to-red-600 rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-4 border border-white/10 min-h-[180px]">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-shuttle-van text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Other Vehicle Scanner</span>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 bg-white/10 rounded-full blur-2xl"></div>
            </a>

        </div>
    </div>

</body>
</html>