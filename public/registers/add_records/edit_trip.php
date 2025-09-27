<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Check if an ID is passed in the URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Error: No record ID specified.");
}

$id = $_GET['id'];
$message = null;
$status = null;

// Handle form submission for updating the record
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Correctly get data from form fields that were in your original error code
    $department = $_POST['department'];
    $date = $_POST['date'];
    $amount = $_POST['amount'];
    $reason = $_POST['reason'];
    $route_code = $_POST['route_code'];

    // If the route code from the form is an empty string, set it to a PHP null value.
    $route_code_param = empty($route_code) ? null : $route_code;

    // This query is now updated to match the fields in your form
    $update_sql = "UPDATE trip SET department = ?, date = ?, amount = ?, reason = ?, route_code = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);

    // Bind parameters: ssdssi
    $stmt->bind_param("ssdssi", $department, $date, $amount, $reason, $route_code_param, $id);

    if ($stmt->execute()) {
        $message = "Trip record updated successfully!";
        $status = "success";
        header("Location: trip.php?status=" . urlencode($status) . "&message=" . urlencode($message));
        exit();
    } else {
        $message = "Error updating record: " . $stmt->error;
        $status = "error";
    }
}

// Fetch the existing record data for pre-filling the form
$sql = "SELECT tr.id, tr.department, tr.date, tr.amount, tr.reason, tr.route_code, r.route AS route_name
        FROM trip tr
        LEFT JOIN route r ON tr.route_code = r.route_code
        WHERE tr.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$record = $result->fetch_assoc();

if (!$record) {
    die("Error: Record not found.");
}

// Fetch all routes to populate the dropdown
$routes_sql = "SELECT route_code, route FROM route ORDER BY route";
$routes_result = $conn->query($routes_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Petty Cash Record</title>
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
<body class="bg-gray-100 font-sans">
    <div id="toast-container"></div>
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-4xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Edit Additional Trip Record</h1>
            <form method="POST" class="space-y-6">
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="department" class="block text-sm font-medium text-gray-700">Department:</label>
                        <input type="text" id="department" name="department" value="<?= htmlspecialchars($record['department']) ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" required>
                    </div>

                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700">Date:</label>
                        <input type="date" id="date" name="date" value="<?= htmlspecialchars($record['date']) ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" required>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label for="route_code" class="block text-sm font-medium text-gray-700">Route:</label>
                        <select id="route_code" name="route_code" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                            <option value="">No Route</option>
                            <?php while ($route = $routes_result->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($route['route_code']) ?>" <?= ($route['route_code'] == $record['route_code']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($route['route']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700">Amount (LKR):</label>
                        <input type="number" step="0.01" id="amount" name="amount" value="<?= htmlspecialchars($record['amount']) ?>" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" required>
                    </div>
                </div>

                <div>
                    <label for="reason" class="block text-sm font-medium text-gray-700">Reason:</label>
                    <textarea id="reason" name="reason" rows="3" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" required><?= htmlspecialchars($record['reason']) ?></textarea>
                </div>
                
                <div class="flex justify-end gap-4 mt-6">
                    <a href="trip.php"
                       class="inline-flex items-center px-6 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition duration-300">
                        Cancel
                    </a>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Update Record
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
                        : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />'
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

        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const status = urlParams.get('status');
            const message = urlParams.get('message');
            if (status && message) {
                showToast(decodeURIComponent(message), status);
            }
        });
    </script>
</body>
</html>