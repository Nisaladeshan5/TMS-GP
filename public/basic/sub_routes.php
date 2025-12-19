<?php
// sub_routes.php
// Includes
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Handle status filter
$status_filter = isset($_GET['status_filter']) && in_array($_GET['status_filter'], ['active', 'inactive']) ? $_GET['status_filter'] : 'active';

// SQL query for SUB-ROUTES
// UPDATE 1: Added r.vehicle_no to the SELECT list
$sub_routes_sql = "SELECT sr.sub_route_code, sr.route_code, sr.supplier_code, s.supplier, 
                          sr.sub_route, sr.distance, sr.per_day_rate, sr.is_active, sr.vehicle_no
                   FROM sub_route sr
                   JOIN route r ON sr.route_code = r.route_code
                   JOIN supplier s ON sr.supplier_code = s.supplier_code";

if ($status_filter === 'active') {
    $sub_routes_sql .= " WHERE sr.is_active = 1";
} else {
    $sub_routes_sql .= " WHERE sr.is_active = 0";
}

$sub_routes_result = $conn->query($sub_routes_sql);

// Check for and store toast message from session
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sub-Route Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Toast Notifications CSS */
        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
        }
        .toast {
            display: none;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }
        .toast.show {
            display: flex;
            align-items: center;
            transform: translateY(0);
            opacity: 1;
        }
        .toast.success {
            background-color: #4CAF50;
            color: white;
        }
        .toast.error {
            background-color: #F44336;
            color: white;
        }
        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
<script>
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100">

<div class="containerl flex justify-center">
    <div class="w-[85%] ml-[15%]">
        <div class="p-3 px-4">
            <h1 class="text-4xl mx-auto font-bold text-gray-800 mt-3 mb-3 text-center">Sub-Route Details</h1>
            <div class="w-full flex justify-between items-center mb-6">
                
                <a href="add_sub_route.php" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300">
                    Add New Sub-Route
                </a>

                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-1">
                        <label for="status-filter" class="text-gray-700 font-semibold">Filter by Status:</label>
                        <select id="status-filter" onchange="filterData()" class="p-2 border rounded-md">
                            <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div id="table-container" class="overflow-x-auto bg-white shadow-md rounded-md w-full">
                <table class="min-w-full table-auto">
                    <thead class="bg-blue-600 text-white">
                        <tr>
                            <th class="px-2 py-2 text-left">Sub-Route Code</th>
                            <th class="px-2 py-2 text-left">Route Code</th>
                            <th class="px-2 py-2 text-left">Supplier</th>
                            <th class="px-2 py-2 text-left">Vehicle No</th> 
                            <th class="px-2 py-2 text-left">Sub-Route</th>
                            <th class="px-2 py-2 text-left">Distance (km)</th>
                            <th class="px-2 py-2 text-left">Per Day Rate</th>
                            <th class="px-2 py-2 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($sub_routes_result && $sub_routes_result->num_rows > 0) {
                            while ($row = $sub_routes_result->fetch_assoc()) {
                                $is_active = htmlspecialchars($row["is_active"]);
                                $status_text = ($is_active == 1) ? 'Active' : 'Disabled';
                                $toggle_button_text = ($is_active == 1) ? 'Disable' : 'Enable';
                                $toggle_button_color = ($is_active == 1) ? 'bg-red-500 hover:bg-red-600' : 'bg-green-500 hover:bg-green-600';

                                echo "<tr>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["sub_route_code"]) . "</td>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["route_code"]) . "</td>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["supplier"]) . "</td>";
                                
                                // UPDATE 3: Display Vehicle No
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["vehicle_no"]) . "</td>";
                                
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["sub_route"]) . "</td>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["distance"]) . "</td>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["per_day_rate"]) . "</td>";
                                echo "<td class='border px-2 py-2'>
                                        <a href='edit_sub_route.php?code=" . urlencode($row['sub_route_code']) . "' 
                                           class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>Edit</a>
                                        
                                        <button onclick='toggleStatus(\"{$row['sub_route_code']}\", {$is_active})' 
                                            class='" . $toggle_button_color . " text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>$toggle_button_text</button>
                                      </td>";
                                echo "</tr>";
                            }
                        } else {
                            $message = ($status_filter === 'active') ? "No active sub-routes found." : "No inactive sub-routes found.";
                            // Increased colspan to 9 because we added a column
                            echo "<tr><td colspan='9' class='border px-4 py-2 text-center'>{$message}</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script>
    const toastContainer = document.getElementById("toast-container");

    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.classList.add('toast', type);
        toast.innerHTML = `
            <svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                ${type === 'success' ? `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                ` : `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                `}
            </svg>
            <span>${message}</span>
        `;
        toastContainer.appendChild(toast);
        setTimeout(() => { toast.classList.add('show'); }, 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => { toast.remove(); }, 300);
        }, 3000);
    }

    <?php if ($toast): ?>
        showToast("<?php echo htmlspecialchars($toast['message']); ?>", "<?php echo htmlspecialchars($toast['type']); ?>");
    <?php endif; ?>

    function filterData() {
        const status = document.getElementById('status-filter').value;
        window.location.href = `?status_filter=${status}`;
    }

    function toggleStatus(subRouteCode, currentStatus) {
        const newStatus = currentStatus === 1 ? 0 : 1;
        const actionText = newStatus === 1 ? 'enable' : 'disable';
        if (confirm(`Are you sure you want to ${actionText} this sub-route?`)) {
            fetch(`sub_routes_backend.php?toggle_status=true&sub_route_code=${encodeURIComponent(subRouteCode)}&new_status=${newStatus}`)
            .then(response => response.text())
            .then(data => {
                if (data.trim() === "Success") {
                    showToast(`Sub-route ${actionText}d successfully!`, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 2300);
                } else {
                    showToast("Error: " + data, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast("An error occurred. Please try again.", 'error');
            });
        }
    }
</script>

</body>
</html>

<?php $conn->close(); ?>