<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        a {
            text-decoration: none !important;
        }
        button {
            border-radius: 1.25rem !important;
        }
    </style>
    <title>Side Navbar with Tailwind CSS</title>
</head>
<body class="bg-gray-100">
    <?php 
        $page = substr($_SERVER['SCRIPT_NAME'], strrpos($_SERVER['SCRIPT_NAME'],"/")+1);
    ?>

    <!-- Side Navbar -->
    <div class="fixed top-0 left-0 h-full w-[15%] bg-[#1B0E8C] p-3 pt-4 flex flex-col justify-between">
        <!-- Navbar Links -->
        <div class="p-2">
            <!-- TMS Text with Gradient and Hover Effect -->
            <a href="#" class="text-4xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-blue-300 to-purple-600 hover:scale-105 transition-all duration-300 block mb-6">
                TMS
            </a>

            <!-- Navbar Links with Hover and Active States -->
            <a href="http://localhost/TMS/public/index.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-30 rounded block transition duration-300 transform 
            <?= $page == "index.php"? 'active bg-[#351FFB] text-black':'';?>">
                Home
            </a>
            <a href="http://localhost/TMS/public/vehicle.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform 
            <?= $page == "vehicle.php"? 'active bg-[#351FFB] text-white':'';?>">
                Vehicle Details
            </a>
        </div>

        <!-- Login Button Positioned at the Bottom -->
        <button class="bg-blue-500 text-white py-2 px-4 w-3/5 mx-auto mt-auto hover:bg-blue-600 transition duration-300 focus:outline-none">
            Login
        </button>
        <div class="text-xs text-white mx-auto mt-2 ">GP Garments (Pvt) Ltd</div>
    </div>

    <!-- Main Content Area -->
    <!-- <div class="ml-64 p-8">
        <h1 class="text-3xl font-semibold">Welcome to TMS</h1>
        <p class="mt-4 text-lg">This is where the main content will go.</p>
    </div> -->

</body>
</html>
