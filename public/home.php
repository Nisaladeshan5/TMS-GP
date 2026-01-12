<?php
// Start the session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

include('includes/db.php');
include('includes/header.php');
include('includes/navbar.php'); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registers Dashboard</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    
    <style>
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Force Gradients if Tailwind fails */
        .grad-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .grad-gray { background: linear-gradient(135deg, #4b5563 0%, #1f2937 100%); }
        .grad-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .grad-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .grad-yellow { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .grad-cyan { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); }
        .grad-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .grad-pink { background: linear-gradient(135deg, #ec4899 0%, #db2777 100%); }
    </style>
</head>
<body class="bg-gray-100">

    <div class="min-h-screen w-[85%] ml-[15%] p-10 flex flex-col justify-center items-center">
        
        <div class="text-center mb-10 fade-in">
            <h2 class="text-4xl font-extrabold text-gray-800 tracking-tight">
                <span class="bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-purple-600">
                    System Registers
                </span>
            </h2>
            <p class="text-gray-500 mt-2 font-medium">Select a module to proceed</p>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 w-full max-w-6xl fade-in">
            
            <a href="public/registers/Staff transport vehicle register.php" 
               class="grad-blue relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-bus text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Staff Transport Register</span>
            </a>

            <a href="public/registers/factory_transport_vehicle_register.php" 
               class="grad-gray relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-truck text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Factory Transport Register</span>
            </a>

            <a href="public/registers/DH/day_heldup_register.php" 
               class="grad-green relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-sun text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Day Heldup Register</span>
            </a>

            <a href="public/registers/NH/night_heldup_register.php" 
               class="grad-red relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-moon text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Night Heldup Register</span>
            </a>

            <a href="public/registers/night_emergency.php" 
               class="grad-yellow relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-ambulance text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Night Emergency Register</span>
            </a>

            <?php if ($is_logged_in): ?>
            <a href="public/registers/extra_vehicle.php" 
               class="grad-cyan relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-plus-circle text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Extra Vehicle Register</span>
            </a>

            <a href="public/registers/petty_cash.php" 
               class="grad-purple relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-money-bill-wave text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Petty Cash</span>
            </a>
            <?php endif; ?>

            <a href="public/registers/non_paid_vehicle_register.php" 
               class="grad-gray relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-ban text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Non-Paid Vehicle Register</span>
            </a>

            <?php if ($is_logged_in): ?>
            <a href="public/registers/varification.php" 
               class="grad-pink relative overflow-hidden rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1 flex flex-col items-center justify-center text-center gap-3 border border-white/10 group">
                <div class="p-4 bg-white/20 rounded-full text-white backdrop-blur-sm group-hover:scale-110 transition duration-300">
                    <i class="fas fa-clipboard-check text-3xl"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-wide">Verification</span>
            </a>
            <?php endif; ?>

        </div>
    </div>

</body>
</html>