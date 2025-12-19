<?php
// Start the session to access $_SESSION variables
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is currently logged in
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

// 🟢 FIX: Define $user_role from the session to prevent "Undefined variable" error.
// Assuming 'user_role' is set in the session upon successful login.
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

if ($is_logged_in) {
    $button_text = "Logout";
    // Logout URL for the final confirmation button inside the modal
    $button_href = "/TMS/includes/logout.php"; 
    $button_color = "bg-[#6C757D] hover:bg-[#5A6268]"; // Gray for logout
} else {
    $button_text = "Login";
    $button_href = "/TMS/includes/login.php"; 
    $button_color = "bg-blue-500 hover:bg-blue-600"; // Blue for login
}

// NOTE: I'm assuming 'config.php' and 'BASE_URL' are defined correctly here.
// Please ensure 'config.php' is available for this to work.
include('config.php'); 
date_default_timezone_set('Asia/Colombo');
$page = substr($_SERVER['SCRIPT_NAME'], strrpos($_SERVER['SCRIPT_NAME'],"/")+1);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        /* Global styling fixes */
        a {
            text-decoration: none !important;
        }
        /* Applied to the button/link structure */
        .nav-button {
            border-radius: 0.6rem !important;
        }
        /* Custom style for active link highlighting */
        .active-link {
            background-color: #351FFB; /* Custom blue from your original code */
            color: white; /* Ensure text is white for contrast against blue */
        }
    </style>
    <title>Side Navbar with Tailwind CSS</title>
</head>
<body class="bg-gray-100">
    <div class="fixed top-0 left-0 h-screen w-[15%] bg-[#1B0E8C] p-3 pt-0 flex flex-col justify-between">
        <div class="p-2">
            <a href="#" class="text-4xl font-extrabold text-blue-400 hover:scale-105 transition-all duration-300 block mb-6">
                TMS
            </a>

            <?php 
            $active_home = ($page == "index.php" || strpos($_SERVER['REQUEST_URI'], '/registers/') !== false) ? 'active-link' : '';
            $active_qr = ($page == "qr.php" || strpos($_SERVER['REQUEST_URI'], '/qr/') !== false) ? 'active-link' : '';
            $active_basic = strpos($_SERVER['REQUEST_URI'], '/basic/') !== false ? 'active-link' : '';
            $active_payments = strpos($_SERVER['REQUEST_URI'], '/payments/') !== false ? 'active-link' : '';
            $active_report = strpos($_SERVER['REQUEST_URI'], '/report/') !== false ? 'active-link' : '';
            $active_checkUp = strpos($_SERVER['REQUEST_URI'], '/checkUp/') !== false ? 'active-link' : '';
            $active_notice = strpos($_SERVER['REQUEST_URI'], '/notice/') !== false ? 'active-link' : '';
            $active_user = strpos($_SERVER['REQUEST_URI'], '/user/') !== false ? 'active-link' : '';
            $active_admin = strpos($_SERVER['REQUEST_URI'], '/admin/') !== false ? 'active-link' : '';
            $active_audit = strpos($_SERVER['REQUEST_URI'], '/audit/') !== false ? 'active-link' : '';
            ?>

            <a href="/TMS/index.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-30 rounded block transition duration-300 transform <?= $active_home; ?>">
                Home
            </a>
            <?php if (!$is_logged_in): ?>
            <a href="<?= BASE_URL ?>qr/qr.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-30 rounded block transition duration-300 transform <?= $active_qr; ?>">
                QR Scanner
            </a>
            <?php endif; ?>
            
            <?php if ($is_logged_in): ?>
                <?php
                // Now $user_role is safely defined
                if ($user_role === 'manager' || $user_role === 'super admin' || $user_role === 'admin' || $user_role === 'developer') {
                ?>
                <a href="<?= BASE_URL ?>basic/basic_category.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform <?= $active_basic; ?>">
                    Basic Data
                </a>
                <a href="<?= BASE_URL ?>payments/payments_category.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform <?= $active_payments; ?>">
                    Payments
                </a>
                <?php
                }
                ?>
                <?php
                // Now $user_role is safely defined
                if ($user_role === 'developer') {
                ?>
                <a href="<?= BASE_URL ?>report/report_main.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform <?= $active_report; ?>">
                    Report
                </a>
                <?php
                }
                ?>
                <?php
                // Now $user_role is safely defined
                if ($user_role === 'manager' || $user_role === 'super admin' || $user_role === 'admin' || $user_role === 'developer') {
                ?>
                <a href="<?= BASE_URL ?>checkUp/checkUp_category.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform <?= $active_checkUp; ?>">
                    Inspection
                </a>
                <?php
                }
                ?>
                <?php
                // Now $user_role is safely defined
                if ($user_role === 'viewer' || $user_role === 'manager' || $user_role === 'super admin' || $user_role === 'admin' || $user_role === 'developer') {
                ?>
                <a href="<?= BASE_URL ?>notice/notice.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform <?= $active_notice; ?>">
                    Notice
                </a>
                <?php
                }
                ?>
                <?php
                // Now $user_role is safely defined
                if ($user_role === 'viewer' || $user_role === 'super admin' || $user_role === 'admin' || $user_role === 'developer') {
                ?>
                <a href="<?= BASE_URL ?>user/user.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform <?= $active_user; ?>">
                    Bus Leaders
                </a>
                 <?php
                }
                ?>
                <?php
                // Now $user_role is safely defined
                if ($user_role === 'super admin' || $user_role === 'manager' || $user_role === 'developer') {
                ?>
                <a href="<?= BASE_URL ?>admin/admin.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform <?= $active_admin; ?>">
                    Admin
                </a>
                    <?php
                }
                ?>
                <?php
                // Now $user_role is safely defined
                if ($user_role === 'manager' || $user_role === 'developer') {
                ?>
                <a href="<?= BASE_URL ?>audit/view_audit_log.php" class="text-lg py-2 text-white px-4 hover:bg-gray-700 hover:bg-opacity-10 rounded block transition duration-300 transform <?= $active_audit; ?>">
                    Audit
                </a>
                    <?php
                }
                ?>
            <?php endif; ?>
            </div>

        <div class="flex flex-col items-center pb-4">
            <a href="<?= $button_href; ?>"
               class="nav-button text-white py-2 px-4 w-3/5 text-center
                      <?= $button_color; ?> 
                      transition duration-300 focus:outline-none font-semibold"
               <?php if ($is_logged_in): ?>
               onclick="showLogoutModal(event, '<?= $button_href; ?>')"
               <?php endif; ?>
            >
                <?= $button_text; ?>
            </a>
            <div class="text-xs text-white mx-auto mt-2">GP Garments (Pvt) Ltd</div>
        </div>
    </div>

    <div id="logoutModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl w-96 p-6 transform transition-all duration-300 scale-100">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-gray-800">Confirm Logout</h3>
                <button onclick="hideLogoutModal()" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <p class="text-gray-600 mb-6">Are you sure you want to log out from the system?</p>
            
            <div class="flex justify-end space-x-3">
                <button onclick="hideLogoutModal()"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500">
                    Cancel
                </button>
                <a id="confirmLogoutButton" href="#"
                   class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 text-center">
                    Logout
                </a>
            </div>
        </div>
    </div>
    
    <script>
        const logoutModal = document.getElementById('logoutModal');
        const confirmLogoutButton = document.getElementById('confirmLogoutButton');

        // Function to show the modal
        function showLogoutModal(event, logoutUrl) {
            // Prevent the default link action (immediate logout)
            event.preventDefault(); 
            
            // Show the modal
            logoutModal.classList.remove('hidden');

            // Set the correct logout URL for the confirmation button
            confirmLogoutButton.href = logoutUrl;
        }

        // Function to hide the modal
        function hideLogoutModal() {
            // Hide the modal
            logoutModal.classList.add('hidden');
        }
        
        // Close the modal when the ESC key is pressed
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                hideLogoutModal();
            }
        });
    </script>
</body>
</html>