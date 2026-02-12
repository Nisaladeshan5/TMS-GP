<?php
// Start the session to access $_SESSION variables
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is currently logged in
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

// Define $user_role
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

if ($is_logged_in) {
    $button_text = "Logout";
    $button_href = "/TMS/includes/logout.php"; 
} else {
    $button_text = "Login";
    $button_href = "/TMS/includes/login.php"; 
}

include('config.php'); 
date_default_timezone_set('Asia/Colombo');
$page = substr($_SERVER['SCRIPT_NAME'], strrpos($_SERVER['SCRIPT_NAME'],"/")+1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Custom Styles for Active State */
        .active-nav-item {
            background: linear-gradient(90deg, #4f46e5 0%, #3730a3 100%); /* Indigo-600 to Indigo-800 */
            color: white !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border-left: 4px solid #818cf8; /* Indigo-400 accent */
        }
        
        /* Custom Scrollbar for Sidebar */
        .sidebar-scroll::-webkit-scrollbar { width: 4px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 4px; }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover { background: #6b7280; }

        /* General Link Resets */
        a { text-decoration: none !important; }
    </style>
    <title>Side Navbar</title>
</head>
<body class="bg-gray-100">
    
    <div class="fixed top-0 left-0 h-screen w-[15%] bg-gradient-to-b from-gray-900 via-[#1e1b4b] to-indigo-950 text-white flex flex-col shadow-2xl z-50">
        
        <div class="h-16 flex items-center justify-center border-b border-white/10 bg-black/10 shrink-0">
            <a href="#" class="text-3xl font-black tracking-widest text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-cyan-300 hover:scale-105 transition-transform duration-300 drop-shadow-md">
                TMS
            </a>
        </div>

        <div class="flex-1 overflow-y-auto sidebar-scroll py-4 px-3 space-y-1">

            <?php 
            // Logic to determine active state
            $is_home = ($page == "index.php" || strpos($_SERVER['REQUEST_URI'], '/registers/') !== false);
            $is_qr = ($page == "qr.php" || strpos($_SERVER['REQUEST_URI'], '/qr/') !== false);
            $is_basic = strpos($_SERVER['REQUEST_URI'], '/basic/') !== false;
            $is_payments = strpos($_SERVER['REQUEST_URI'], '/payments/') !== false;
            $is_report = strpos($_SERVER['REQUEST_URI'], '/report/') !== false;
            $is_checkUp = strpos($_SERVER['REQUEST_URI'], '/checkUp/') !== false;
            $is_notice = strpos($_SERVER['REQUEST_URI'], '/notice/') !== false;
            $is_user = strpos($_SERVER['REQUEST_URI'], '/user/') !== false;
            $is_admin = strpos($_SERVER['REQUEST_URI'], '/admin/') !== false;
            $is_audit = strpos($_SERVER['REQUEST_URI'], '/audit/') !== false;

            // Helper function for classes - Reduced padding to py-2.5
            function getNavClass($isActive) {
                return $isActive 
                    ? 'active-nav-item flex items-center gap-3 px-4 py-2.5 text-sm font-semibold rounded-lg transition-all duration-200' 
                    : 'flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-gray-400 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 group';
            }
            ?>

            <a href="/TMS/index.php" class="<?= getNavClass($is_home); ?>">
                <i class="fas fa-home w-5 text-center text-lg <?= $is_home ? 'text-white' : 'text-gray-500 group-hover:text-white'; ?>"></i>
                <span>Home</span>
            </a>

            <?php if (!$is_logged_in): ?>
            <a href="<?= BASE_URL ?>qr/qr.php" class="<?= getNavClass($is_qr); ?>">
                <i class="fas fa-qrcode w-5 text-center text-lg <?= $is_qr ? 'text-white' : 'text-gray-500 group-hover:text-white'; ?>"></i>
                <span>QR Scanner</span>
            </a>
            <?php endif; ?>
            
            <?php if ($is_logged_in): ?>
                
                <?php if (in_array($user_role, ['viewer', 'manager', 'super admin', 'admin', 'developer'])) { ?>
                <div class="pt-3 pb-1 px-4 text-[10px] font-bold text-gray-500 uppercase tracking-wider">Management</div>
                
                <a href="<?= BASE_URL ?>basic/basic_category.php" class="<?= getNavClass($is_basic); ?>">
                    <i class="fas fa-database w-5 text-center text-lg <?= $is_basic ? 'text-white' : 'text-gray-500 group-hover:text-white'; ?>"></i>
                    <span>Basic Data</span>
                </a>
                <?php } ?>
                <?php if (in_array($user_role, ['manager', 'super admin', 'admin', 'developer'])) { ?>
                <a href="<?= BASE_URL ?>payments/payments_category.php" class="<?= getNavClass($is_payments); ?>">
                    <i class="fas fa-file-invoice-dollar w-5 text-center text-lg <?= $is_payments ? 'text-white' : 'text-gray-500 group-hover:text-white'; ?>"></i>
                    <span>Payments</span>
                </a>
                <?php } ?>
                
                <?php if (in_array($user_role, ['viewer', 'manager', 'super admin', 'admin', 'developer'])) { ?>
                <a href="<?= BASE_URL ?>report/report_main.php" class="<?= getNavClass($is_report); ?>">
                    <i class="fas fa-chart-pie w-5 text-center text-lg <?= $is_report ? 'text-white' : 'text-gray-500 group-hover:text-white'; ?>"></i>
                    <span>Report</span>
                </a>
                
                <a href="<?= BASE_URL ?>checkUp/checkUp_category.php" class="<?= getNavClass($is_checkUp); ?>">
                    <i class="fas fa-clipboard-check w-5 text-center text-lg <?= $is_checkUp ? 'text-white' : 'text-gray-500 group-hover:text-white'; ?>"></i>
                    <span>Inspection</span>
                </a>
                <?php } ?>
                
                <?php if (in_array($user_role, ['viewer', 'manager', 'super admin', 'admin', 'developer'])) { ?>
                <div class="pt-3 pb-1 px-4 text-[10px] font-bold text-gray-500 uppercase tracking-wider">Communication</div>
                <a href="<?= BASE_URL ?>notice/notice.php" class="<?= getNavClass($is_notice); ?>">
                    <i class="fas fa-bullhorn w-5 text-center text-lg <?= $is_notice ? 'text-white' : 'text-gray-500 group-hover:text-white'; ?>"></i>
                    <span>Notice</span>
                </a>
                <?php } ?>
                
                <?php if (in_array($user_role, ['viewer', 'super admin', 'admin', 'developer'])) { ?>
                <a href="<?= BASE_URL ?>user/user.php" class="<?= getNavClass($is_user); ?>">
                    <i class="fas fa-users w-5 text-center text-lg <?= $is_user ? 'text-white' : 'text-gray-500 group-hover:text-white'; ?>"></i>
                    <span>Bus Leaders</span>
                </a>
                <?php } ?>
                
                <?php if (in_array($user_role, ['super admin', 'manager', 'developer'])) { ?>
                <div class="pt-3 pb-1 px-4 text-[10px] font-bold text-gray-500 uppercase tracking-wider">System</div>
                <a href="<?= BASE_URL ?>admin/admin.php" class="<?= getNavClass($is_admin); ?>">
                    <i class="fas fa-cogs w-5 text-center text-lg <?= $is_admin ? 'text-white' : 'text-gray-500 group-hover:text-white'; ?>"></i>
                    <span>Admin</span>
                </a>
                <?php } ?>
                
                <?php if (in_array($user_role, ['manager', 'developer'])) { ?>
                <a href="<?= BASE_URL ?>audit/view_audit_log.php" class="<?= getNavClass($is_audit); ?>">
                    <i class="fas fa-history w-5 text-center text-lg <?= $is_audit ? 'text-white' : 'text-gray-500 group-hover:text-white'; ?>"></i>
                    <span>Audit</span>
                </a>
                <?php } ?>

            <?php endif; ?>
        </div>

        <div class="p-3 border-t border-white/10 bg-black/20 backdrop-blur-sm shrink-0">
            <a href="<?= $button_href; ?>"
               class="flex items-center justify-center w-full py-2 px-4 rounded-lg font-bold shadow-md transition-all duration-300 transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-900 no-loader
               <?= $is_logged_in ? 'bg-red-600 hover:bg-red-700 text-white focus:ring-red-500' : 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-blue-500'; ?>"
               <?php if ($is_logged_in): ?>
               onclick="showLogoutModal(event, '<?= $button_href; ?>')"
               <?php endif; ?>
            >
                <i class="fas <?= $is_logged_in ? 'fa-sign-out-alt' : 'fa-sign-in-alt'; ?> mr-2"></i>
                <?= $button_text; ?>
            </a>
            
            <div class="text-[10px] text-gray-500 text-center mt-2 font-medium tracking-wide uppercase">
                GP Garments (Pvt) Ltd
            </div>
        </div>
    </div>

    <div id="logoutModal" class="fixed inset-0 bg-gray-900/80 backdrop-blur-sm hidden flex items-center justify-center z-[100] transition-opacity duration-300">
        <div class="bg-white rounded-xl shadow-2xl w-96 p-6 transform transition-all duration-300 scale-100 border border-gray-200">
            <div class="flex items-center gap-3 mb-4 text-gray-800">
                <div class="bg-red-100 p-2 rounded-full">
                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                </div>
                <h3 class="text-xl font-bold">Confirm Logout</h3>
            </div>
            
            <p class="text-gray-600 mb-6 text-sm leading-relaxed ml-1">
                Are you sure you want to log out? <br>You will be returned to the login screen.
            </p>
            
            <div class="flex justify-between space-x-3">
                <button onclick="hideLogoutModal()"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition focus:outline-none focus:ring-2 focus:ring-gray-300">
                    Cancel
                </button>
                <a id="confirmLogoutButton" href="#"
                   class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition focus:outline-none focus:ring-2 focus:ring-red-500 shadow-md">
                    Yes, Logout
                </a>
            </div>
        </div>
    </div>
    
    <script>
        const logoutModal = document.getElementById('logoutModal');
        const confirmLogoutButton = document.getElementById('confirmLogoutButton');

        function showLogoutModal(event, logoutUrl) {
            event.preventDefault(); 
            logoutModal.classList.remove('hidden');
            // Small animation for modal entrance
            logoutModal.firstElementChild.classList.add('scale-100');
            logoutModal.firstElementChild.classList.remove('scale-95');
            confirmLogoutButton.href = logoutUrl;
        }

        function hideLogoutModal() {
            logoutModal.classList.add('hidden');
        }
        
        // Close on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                hideLogoutModal();
            }
        });

        // Close when clicking outside the modal
        logoutModal.addEventListener('click', function(e){
            if(e.target === logoutModal){
                hideLogoutModal();
            }
        });
    </script>
</body>
</html>