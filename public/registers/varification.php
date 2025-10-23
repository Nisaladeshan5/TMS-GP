<?php

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Set the filter date to today's date by default
$filterDate = date('Y-m-d');

// If a date is submitted via the form, use that date for the filter
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['date'])) {
    $filterDate = $_POST['date'];
}

// Initialize records to an empty array and an error message variable
$records = [];
$connection_error = null;

// 🔑 CRITICAL FIX: Check if the connection object ($conn) is valid before using it
if (isset($conn) && $conn instanceof mysqli && $conn->connect_error === null) {
    // Connection is good, proceed with query
   
    // Fetch Running Chart details
    $sql = "SELECT r.id, r.route, r.actual_vehicle_no, r.driver_NIC, r.time, r.shift
            FROM cross_check r
            WHERE DATE(r.date) = ?";
           
    $stmt = $conn->prepare($sql);
   
    if ($stmt === false) {
        // Handle SQL preparation error (usually a syntax issue or table name error)
        $connection_error = "Database query failed to prepare. Check SQL syntax or table name.";
    } else {
        $stmt->bind_param('s', $filterDate);
        $stmt->execute();
        $result = $stmt->get_result();
        // $records will be an empty array if no rows are found (graceful "no data" handling)
        $records = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

} else {
    // Connection failed (The original Fatal Error cause). Set a message.
    $connection_error = "FATAL: Database connection failed. Please check `db_public.php` configuration and server status.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vehicle Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>

<style>
    /* CSS for toast */
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
        max-width: 400px;
    }

    .toast.show {
        transform: translateY(0);
        opacity: 1;
    }

    .toast.success {
        background-color: #4CAF50;
    }
    .toast.warning {
        background-color: #ff9800;
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

<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]"></div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[48px] font-bold text-gray-800 mt-2">Staff Transport Vehicle Details</p>

    <?php if ($connection_error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 w-full max-w-4xl" role="alert">
            <strong class="font-bold">Database Error!</strong>
            <span class="block sm:inline"><?php echo htmlspecialchars($connection_error); ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" class="mb-6 flex justify-center">
        <div class="flex items-center">
            <label for="date" class="text-lg font-medium mr-2">Filter by Date:</label>
            <input type="date" id="date" name="date" class="border border-gray-300 p-2 rounded-md"
                   value="<?php echo htmlspecialchars($filterDate); ?>" required>
            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md ml-2 hover:bg-blue-600">Filter</button>
        </div>
    </form>

    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Route Code</th>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Driver NIC (Licence ID)</th>
                    <th class="px-4 py-2 text-left">Time</th>
                    <th class="px-4 py-2 text-left">Shift</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (count($records) > 0) {
                    foreach ($records as $row) {
                        $time = ($row['time'] !== null) ? date('H:i', strtotime($row['time'])) : '-';
                        $time_display = "{$time}";

                        echo "<tr>
                            <td class='border px-4 py-2'>{$row['route']}</td>
                            <td class='border px-4 py-2'>{$row['actual_vehicle_no']}</td>
                            <td class='border px-4 py-2'>{$row['driver_NIC']}</td>
                            <td class='border px-4 py-2'>{$time_display}</td>
                            <td class='border px-4 py-2'>" . ucfirst($row['shift']) . "</td>
                        </tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' class='text-center py-4 text-gray-500'>No records found for the selected date: " . htmlspecialchars($filterDate) . "</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

</body>

<script>
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
       
        let iconPath;
        switch (type) {
            case 'success':
                iconPath = '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />';
                break;
            case 'warning':
            case 'error':
                iconPath = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />';
                break;
            default:
                iconPath = '';
        }

        toast.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                ${iconPath}
            </svg>
            <span>${message}</span>
        `;
       
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 5000);
    }

    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    const message = urlParams.get('message');
   
    if (status && message) {
        showToast(decodeURIComponent(message), status);
        window.history.replaceState(null, null, window.location.pathname);
    }
</script>

</html>
