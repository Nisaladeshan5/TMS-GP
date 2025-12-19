<?php
session_start();
// Ensure the user is logged in before allowing a password reset.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Retrieve the error message if it exists, and then immediately clear it 
// so it doesn't reappear on refresh.
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Transport Management System</title>
    <!-- Load Tailwind CSS -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Modern background gradient for a sleek look (matching the example) */
        .login-bg {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
        }
    </style>
</head>
<!-- Apply the custom background and centering utilities to the body -->
<body class="login-bg min-h-screen flex items-center justify-center p-4">

    <!-- Main Content Card -->
    <div class="w-full max-w-lg bg-white rounded-xl shadow-2xl overflow-hidden">
        
        <div class="p-10">
            <h2 class="text-3xl font-extrabold text-gray-900 text-center mb-1">
                🔑 Set a New Password
            </h2>
            <p class="text-gray-500 text-center mb-8">
                Since this is your first time logging in, please secure your account by setting a new password.
            </p>

            <!-- Error Message Display (using the red alert box style) -->
            <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6 transition duration-300" role="alert">
                    <strong class="font-bold">Password Error:</strong>
                    <span class="block sm:inline"><?= htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <form class="space-y-6" action="update_password.php" method="POST">
                
                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-700 text-left">New Password</label>
                    <div class="mt-1">
                        <input id="new_password" name="new_password" type="password" required
                               class="appearance-none block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500 transition duration-150"
                               placeholder="••••••••">
                    </div>
                </div>

                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 text-left">Confirm New Password</label>
                    <div class="mt-1">
                        <input id="confirm_password" name="confirm_password" type="password" required
                               class="appearance-none block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500 transition duration-150"
                               placeholder="••••••••">
                    </div>
                </div>

                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-md text-lg font-semibold text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150 ease-in-out transform hover:scale-[1.01]">
                        Update Password
                    </button>
                </div>
            </form>
        </div>
        
    </div>
</body>
</html>
