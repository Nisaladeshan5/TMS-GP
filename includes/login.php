<?php
session_start();
// The include for the header/navbar is commented out 
// as login pages typically don't have them.

// Check if an error message was set by login_process.php
$error_message = $_SESSION['error_message'] ?? '';
// Clear the session variable after retrieving it so it doesn't reappear on refresh
unset($_SESSION['error_message']); 

// Check if user is already logged in (if so, redirect to home)
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("Location: ../index.php"); // Adjust to your main dashboard page
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Transport Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Modern background gradient for a sleek look */
        .login-bg {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
        }
    </style>
</head>
<body class="login-bg min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-lg bg-white rounded-xl shadow-2xl overflow-hidden">
        
        <div class="p-10">
            <h2 class="text-3xl font-extrabold text-gray-900 text-center mb-1">
                👋 Transport System Login
            </h2>
            <p class="text-gray-500 text-center mb-8">
                Enter your Employee ID and password to continue.
            </p>

            <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6 transition duration-300" role="alert">
                    <strong class="font-bold">Login Failed:</strong>
                    <span class="block sm:inline"><?= htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <form action="login_process.php" method="POST" class="space-y-6">
                
                <div>
                    <label for="empNo" class="block text-sm font-medium text-gray-700">Employee ID</label>
                    <div class="mt-1">
                        <input id="empNo" name="empNo" type="text" autocomplete="username" required
                               class="appearance-none block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 transition duration-150"
                               placeholder="e.g., GP000000">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <div class="mt-1">
                        <input id="password" name="password" type="password" autocomplete="current-password" required
                               class="appearance-none block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 transition duration-150"
                               placeholder="••••••••">
                    </div>
                </div>

                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-md text-lg font-semibold text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out transform hover:scale-[1.01]">
                        Sign in to TMS
                    </button>
                </div>
            </form>
        </div>
        
    </div>
</body>
</html>