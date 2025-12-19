<?php
// routes_staff.php

// Includes
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Handle Purpose Filter
if (isset($_GET['purpose_filter']) && in_array($_GET['purpose_filter'], ['staff', 'worker'])) {
    $_SESSION['purpose_filter'] = $_GET['purpose_filter'];
}

// Default to staff only if nothing in session
$purpose_filter = isset($_SESSION['purpose_filter']) ? $_SESSION['purpose_filter'] : 'staff';

// Build the SQL query with a JOIN clause to get the supplier name
$sql = "SELECT r.route_code, r.supplier_code, s.supplier, r.route, r.purpose, r.distance, r.working_days, r.vehicle_no, r.monthly_fixed_rental, r.assigned_person
        FROM route r
        JOIN supplier s ON r.supplier_code = s.supplier_code";

// Sanitize the input and add the WHERE clause
$safe_purpose_filter = $conn->real_escape_string($purpose_filter);
$sql .= " WHERE r.purpose = '" . $safe_purpose_filter . "'";

$result = $conn->query($sql);

// Check for and store toast message from session before outputting HTML
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']); // Clear the session variable immediately
}

// Fetch Suppliers and Vehicles for the Edit functionality (if you decide to use AJAX later, but kept here for modal-to-page transition logic)
// For now, we only need them in add_route.php and a potential edit_route.php
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Toast Notifications CSS (KEPT FOR DELETE AND REDIRECTION FEEDBACK) */
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
<body class="bg-gray-100">

<div class="containerl flex justify-center">
    <div class="w-[85%] ml-[15%]">
        <div class="p-3">
            <h1 class="text-4xl mx-auto font-bold text-gray-800 mt-3 mb-3 text-center">Route Details</h1>
            <div class="w-full flex justify-between items-center mb-6">
                <a href="add_route.php" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300">
                    Add New Route
                </a>
                
                <div class="flex items-center space-x-2">
                    <div class="flex space-x-2">
                        <a href=""
                            class="px-3 py-2 rounded-md font-semibold text-white bg-blue-600 hover:bg-blue-700">
                            Method 1
                        </a>
                        <a href="routes_staff2.php"
                            class="px-3 py-2 rounded-md font-semibold text-white bg-gray-400 hover:bg-gray-500">
                            Method 2
                        </a>
                    </div>
                    <div class="flex items-center space-x-1">
                        <label for="purpose-filter" class="text-gray-700 font-semibold">Filter by Purpose:</label>
                        <select id="purpose-filter" onchange="filterRoutes(this.value)" class="p-2 border rounded-md">
                            <option value="staff" <?php echo ($purpose_filter === 'staff') ? 'selected' : ''; ?>>Staff</option>
                            <option value="worker" <?php echo ($purpose_filter === 'worker') ? 'selected' : ''; ?>>Workers</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto bg-white shadow-md rounded-md w-full">
                <table class="min-w-full table-auto">
                    <thead class="bg-blue-600 text-white">
                        <tr>
                            <th class="px-2 py-2 text-left">Route Code</th>
                            <th class="px-2 py-2 text-left">Supplier</th>
                            <th class="px-2 py-2 text-left">Route</th>
                            <th class="px-2 py-2 text-left">Distance (km)</th>
                            <th class="px-2 py-2 text-left">Working Days</th>
                            <th class="px-2 py-2 text-left">Vehicle No</th>
                            <th class="px-2 py-2 text-left">Fixed Rental</th>
                            <th class="px-2 py-2 text-left">Assigned Person</th>
                            <th class="px-2 py-2 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($result && $result->num_rows > 0) {
                            while($row = $result->fetch_assoc()) {
                                $route_code = htmlspecialchars($row["route_code"]);
                                $supplier_code = htmlspecialchars($row["supplier_code"]);
                                $supplier_name = htmlspecialchars($row["supplier"]);
                                $route_name = htmlspecialchars($row["route"]);
                                $purpose = htmlspecialchars($row["purpose"]);
                                $distance = htmlspecialchars($row["distance"]);
                                $working_days = htmlspecialchars($row["working_days"]);
                                $vehicle_no = htmlspecialchars($row["vehicle_no"]);
                                $monthly_fixed_rental = htmlspecialchars($row["monthly_fixed_rental"]);
                                $assigned_person = htmlspecialchars($row["assigned_person"]);

                                echo "<tr>";
                                echo "<td class='border px-2 py-2'>" . $route_code . "</td>";
                                echo "<td class='border px-2 py-2'>" . $supplier_name . "</td>";
                                echo "<td class='border px-2 py-2'>" . $route_name . "</td>";
                                echo "<td class='border px-2 py-2'>" . $distance . "</td>";
                                echo "<td class='border px-2 py-2'>" . $working_days . "</td>";
                                echo "<td class='border px-2 py-2'>" . $vehicle_no . "</td>";
                                echo "<td class='border px-2 py-2'>" . $monthly_fixed_rental . "</td>";
                                echo "<td class='border px-2 py-2'>" . $assigned_person . "</td>";
                                echo "<td class='border px-2 py-2'>
                                            <a href='edit_route.php?route_code=" . urlencode($route_code) . "' class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>Edit</a>
                                            <button onclick='deleteRoute(\"$route_code\")' class='bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>Delete</button>
                                            
                                            <a href='generate_qr_route_pdf.php?code=" . urlencode($route_code) . "&route=" . urlencode($route_name) . "' class='bg-purple-600 hover:bg-purple-700 text-white font-bold py-1 px-2 rounded text-sm transition duration-300' target='_blank'>Generate QR PDF</a>
                                        </td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='10' class='border px-4 py-2 text-center'>No routes found for this purpose.</td></tr>";
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
    var toastContainer = document.getElementById("toast-container");

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

    // NEW: Check for and display toast message from session on page load
    <?php if ($toast): ?>
        showToast("<?php echo htmlspecialchars($toast['message']); ?>", "<?php echo htmlspecialchars($toast['type']); ?>");
    <?php endif; ?>

    // The 'Edit' button now redirects, so no openEditModal needed.
    // The 'Add' form submits to add_route.php, so no handleFormSubmit needed here.

    function deleteRoute(routeCode) {
        if (confirm("Are you sure you want to delete this route?")) {
            // Note: If routes_backend.php handles deletion, ensure it's secure.
            fetch('routes_backend.php?delete_code=' + encodeURIComponent(routeCode))
            .then(response => response.text())
            .then(data => {
                if (data.trim() === "Success") {
                    showToast("Route deleted successfully!", 'success');
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

    function filterRoutes(purpose) {
        window.location.href = `?purpose_filter=${purpose}`;
    }
</script>

</body>
</html>

<?php $conn->close(); ?>