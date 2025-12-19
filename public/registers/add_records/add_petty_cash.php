<?php
require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

// Ensure this path is correct
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');
include('../../../includes/config.php');

// Handle form submission
$message = null;
$status = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $employeeNo = $_POST['employee_no'];
    $date = $_POST['date'];
    $amount = $_POST['amount'];
    $reason = $_POST['reason'];

    // You may want to add validation here to ensure the employee_no exists in the database.
    // This example assumes a valid employee_no will be entered.

    $sql = "INSERT INTO petty_cash (empNo, date, amount, reason)
            VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssds', $employeeNo, $date, $amount, $reason);

    if ($stmt->execute()) {
        $message = "Petty cash record added successfully!";
        $status = "success";
        // Redirect to prevent form resubmission
        header("Location: " . BASE_URL . "registers/petty_cash.php?status=success&message=" . urlencode($message));
        exit();
    } else {
        $message = "Error saving petty cash record: " . $stmt->error;
        $status = "error";
    }
    $stmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Extra Vehicle Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .toast {
            display: flex;
            align-items: center;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            color: white;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            background-color: #4CAF50;
        }

        .toast.error {
            background-color: #F44336;
        }

        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100 font-sans">
    <div id="toast-container"></div>
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-6">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add Petty Cash</h1>
            <form method="POST" class="space-y-3">
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="employee_no" class="block text-sm font-medium text-gray-700">Employee No:</label>
                        <input type="text" name="employee_no" id="employee_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" />
                    </div>
                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                        <input type="date" name="date" id="date" required value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" />
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700">Amount (LKR):</label>
                        <input type="number" step="0.01" name="amount" id="amount" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" />
                    </div>
                </div>

                <div>
                    <label for="reason" class="block text-sm font-medium text-gray-700">Reason:</label>
                    <textarea name="reason" id="reason" rows="3" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"></textarea>
                </div>

                <div class="flex justify-end gap-4 mt-6">
                    <a href="<?= BASE_URL ?>/registers/petty_cash.php"
                       class="inline-flex items-center px-6 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition duration-300">
                        Cancel
                    </a>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        /**
         * Displays a toast notification.
         * @param {string} message The message to display.
         * @param {string} type The type of toast ('success' or 'error').
         */
        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                    ${type === 'success'
                        ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />'
                        : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />'
                    }
                </svg>
                <span>${message}</span>
            `;
            
            toastContainer.appendChild(toast);

            setTimeout(() => toast.classList.add('show'), 10);

            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 2000);
        }
    </script>
</body>
</html>