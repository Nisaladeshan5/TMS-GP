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
            border-radius: 0.6rem !important;
        }
    </style>
    <title>Side Navbar with Tailwind CSS</title>
</head>
<body class="bg-gray-100">
    <?php 
        $page = substr($_SERVER['SCRIPT_NAME'], strrpos($_SERVER['SCRIPT_NAME'],"/")+1);

        include('config.php');
        date_default_timezone_set('Asia/Colombo');
    ?>

    <!-- Side Navbar -->
    <div class="fixed top-0 left-0 h-screen w-[15%] bg-[#1B0E8C] p-3 pt-0 flex flex-col justify-between">
        <!-- Navbar Links -->
        <div class="p-2">
            <!-- TMS Text with Gradient and Hover Effect -->
            <<a href="#" class="text-4xl font-extrabold text-blue-400 hover:scale-105 transition-all duration-300 block mb-6">
                TMS
            </a>

            <!-- Navbar Links with Hover and Active States -->
            <a href="<?= BASE_URL ?>index.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-30 rounded block transition duration-300 transform 
            <?= $page == "index.php" || strpos($_SERVER['REQUEST_URI'], '/registers/') !== false ? 'active bg-[#351FFB] text-black':'';?>">
                Home
            </a>
            <a href="<?= BASE_URL ?>basic/basic_category.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform 
                <?= strpos($_SERVER['REQUEST_URI'], '/basic/') !== false ? 'active bg-[#351FFB] text-white' : ''; ?>">
                Basic Data
            </a>
            <a href="<?= BASE_URL ?>payments/payments_category.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform 
                <?= strpos($_SERVER['REQUEST_URI'], '/payments/') !== false ? 'active bg-[#351FFB] text-white' : ''; ?>">
                Payments
            </a>
            <a href="<?= BASE_URL ?>report/report_main.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform 
                <?= strpos($_SERVER['REQUEST_URI'], '/report/') !== false ? 'active bg-[#351FFB] text-white' : ''; ?>">
                Report
            </a>
            <a href="<?= BASE_URL ?>checkUp/checkUp_category.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform 
                <?= strpos($_SERVER['REQUEST_URI'], '/checkUp/') !== false ? 'active bg-[#351FFB] text-white' : ''; ?>">
                Inspection
            </a>
            <a href="<?= BASE_URL ?>notice/notice.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform 
                <?= strpos($_SERVER['REQUEST_URI'], '/notice/') !== false ? 'active bg-[#351FFB] text-white' : ''; ?>">
                Notice
            </a>
            <a href="<?= BASE_URL ?>user/user.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform 
                <?= strpos($_SERVER['REQUEST_URI'], '/user/') !== false ? 'active bg-[#351FFB] text-white' : ''; ?>">
                Users
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
