<?php
// sub_routes.php
// Includes
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

session_start();

// Handle status filter
$status_filter = isset($_GET['status_filter']) && in_array($_GET['status_filter'], ['active', 'inactive']) ? $_GET['status_filter'] : 'active';

// SQL query for SUB-ROUTES
$sub_routes_sql = "SELECT sr.sub_route_code, sr.route_code, r.supplier_code, s.supplier, sr.sub_route, sr.distance, sr.per_day_rate, sr.is_active
                   FROM sub_route sr
                   JOIN route r ON sr.route_code = r.route_code
                   JOIN supplier s ON r.supplier_code = s.supplier_code";
if ($status_filter === 'active') {
    $sub_routes_sql .= " WHERE sr.is_active = 1";
} else {
    $sub_routes_sql .= " WHERE sr.is_active = 0";
}

$sub_routes_result = $conn->query($sub_routes_sql);

// Fetch all routes and suppliers for dropdowns
$all_routes = [];
$routes_dropdown_sql = "SELECT route_code, route FROM route";
$routes_dropdown_result = $conn->query($routes_dropdown_sql);
while ($row = $routes_dropdown_result->fetch_assoc()) {
    $all_routes[] = $row;
}

$all_suppliers = [];
$suppliers_dropdown_sql = "SELECT supplier_code, supplier FROM supplier";
$suppliers_dropdown_result = $conn->query($suppliers_dropdown_sql);
while ($row = $suppliers_dropdown_result->fetch_assoc()) {
    $all_suppliers[] = $row;
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
        /* Modal CSS */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 500px;
            position: relative;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
        }
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
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
            background-color: #4CAF50; /* Green for success */
            color: white;
        }
        .toast.error {
            background-color: #F44336; /* Red for errors */
            color: white;
        }
        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
        .readonly-field {
            background-color: #e5e7eb;
            cursor: not-allowed;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="containerl flex justify-center">
    <div class="w-[85%] ml-[15%]">
        <div class="p-3 px-4">
            <h1 class="text-4xl mx-auto font-bold text-gray-800 mt-3 mb-3 text-center">Sub-Route Details</h1>
            <div class="w-full flex justify-between items-center mb-6">
                <button onclick="openAddModal()" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300">
                    Add New Sub-Route
                </button>
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
                                $status_color = ($is_active == 1) ? 'text-green-600' : 'text-red-600';
                                $toggle_button_text = ($is_active == 1) ? 'Disable' : 'Enable';
                                $toggle_button_color = ($is_active == 1) ? 'bg-red-500 hover:bg-red-600' : 'bg-green-500 hover:bg-green-600';

                                $rowDataJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                                
                                echo "<tr>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["sub_route_code"]) . "</td>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["route_code"]) . "</td>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["supplier"]) . "</td>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["sub_route"]) . "</td>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["distance"]) . "</td>";
                                echo "<td class='border px-2 py-2'>" . htmlspecialchars($row["per_day_rate"]) . "</td>";
                                echo "<td class='border px-2 py-2'>
                                        <button data-row='{$rowDataJson}' onclick='openViewModal(this.dataset.row)' 
                                            class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>View</button>
                                        <button data-row='{$rowDataJson}' onclick='openEditModal(this.dataset.row)' 
                                            class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>Edit</button>
                                        <button onclick='toggleStatus(\"{$row['sub_route_code']}\", {$is_active})' 
                                            class='" . $toggle_button_color . " text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>$toggle_button_text</button>
                                      </td>";
                                echo "</tr>";
                            }
                        } else {
                            $message = ($status_filter === 'active') ? "No active sub-routes found." : "No inactive sub-routes found.";
                            echo "<tr><td colspan='8' class='border px-4 py-2 text-center'>{$message}</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="subRouteModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('subRouteModal')">&times;</span>
        <h3 class="text-2xl font-semibold mb-1" id="subRouteModalTitle">Add New Sub-Route</h3>
        <form id="subRouteForm" onsubmit="handleFormSubmit(event)" class="space-y-4">
            <input type="hidden" name="action" id="subRouteAction">
            <div>
                <label for="sub_route_code" class="block text-gray-700">Sub-Route Code:</label>
                <input type="text" id="sub_route_code" name="sub_route_code" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
            </div>
            <div>
                <label for="sub_route" class="block text-gray-700">Sub-Route:</label>
                <input type="text" id="sub_route" name="sub_route" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
            </div>
            <div>
                <label for="route_code_sub" class="block text-gray-700">Route Code:</label>
                <select id="route_code_sub" name="route_code" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    <option value="">-- Select Route Code --</option>
                    <?php foreach ($all_routes as $route) {
                        echo "<option value='" . htmlspecialchars($route['route_code']) . "'>" . htmlspecialchars($route['route']) . "</option>";
                    } ?>
                </select>
            </div>
            <div>
                <label for="supplier_code_sub" class="block text-gray-700">Supplier Code:</label>
                <select id="supplier_code_sub" name="supplier_code" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    <option value="">-- Select Supplier Code --</option>
                    <?php foreach ($all_suppliers as $supplier) {
                        echo "<option value='" . htmlspecialchars($supplier['supplier_code']) . "'>" . htmlspecialchars($supplier['supplier_code']) . " - " . htmlspecialchars($supplier['supplier']) . "</option>";
                    } ?>
                </select>
            </div>
            <div>
                <label for="distance_sub" class="block text-gray-700">Distance (km):</label>
                <input type="number" id="distance_sub" name="distance" step="0.01" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
            </div>
            <div>
                <label for="per_day_rate_sub" class="block text-gray-700">Per Day Rate:</label>
                <input type="number" id="per_day_rate_sub" name="per_day_rate" step="0.01" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
            </div>
            <div class="flex justify-end">
                <input type="submit" id="subRouteSubmitBtn" value="Add" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md cursor-pointer transition duration-300">
            </div>
        </form>
    </div>
</div>

<div id="toast-container"></div>

<script>
    const subRouteModal = document.getElementById("subRouteModal");
    const subRouteForm = document.getElementById("subRouteForm");
    const subRouteSubmitBtn = document.getElementById("subRouteSubmitBtn");
    const subRouteModalTitle = document.getElementById("subRouteModalTitle");
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

    function filterData() {
        const status = document.getElementById('status-filter').value;
        window.location.href = `?status_filter=${status}`;
    }

    function setFormState(form, isReadOnly) {
        const formFields = form.querySelectorAll('input, select');
        formFields.forEach(field => {
            if (field.id !== 'subRouteAction') {
                field.readOnly = isReadOnly;
                field.disabled = isReadOnly;
                if (isReadOnly) {
                    field.classList.add('readonly-field');
                } else {
                    field.classList.remove('readonly-field');
                }
            }
        });
        const submitBtn = form.querySelector('input[type="submit"]');
        if (submitBtn) {
            submitBtn.style.display = isReadOnly ? 'none' : 'block';
        }
    }

    function closeModal() {
        subRouteModal.style.display = "none";
        document.getElementById('sub_route_code').disabled = false;
        document.getElementById('sub_route_code').readOnly = false;
        document.getElementById('sub_route_code').style.backgroundColor = 'white';
    }

    function openAddModal() {
        subRouteForm.reset();
        setFormState(subRouteForm, false);
        document.getElementById('subRouteAction').value = 'add';
        subRouteModalTitle.textContent = "Add New Sub-Route";
        subRouteModal.style.display = "flex";
    }

    function openEditModal(rowData) {
    const data = JSON.parse(rowData);
    setFormState(subRouteForm, false);
    document.getElementById('subRouteAction').value = 'edit';
    subRouteModalTitle.textContent = "Edit Sub-Route";
    
    document.getElementById('sub_route_code').value = data.sub_route_code;
    document.getElementById('sub_route_code').readOnly = true;
    document.getElementById('sub_route_code').disabled = false;
    
    document.getElementById('sub_route_code').style.backgroundColor = '#e5e7eb';
    
    document.getElementById('sub_route').value = data.sub_route;
    document.getElementById('route_code_sub').value = data.route_code;
    document.getElementById('supplier_code_sub').value = data.supplier_code;
    document.getElementById('distance_sub').value = data.distance;
    document.getElementById('per_day_rate_sub').value = data.per_day_rate;
    
    subRouteSubmitBtn.value = "Save Changes";
    subRouteModal.style.display = "flex";
}

    function openViewModal(rowData) {
        const data = JSON.parse(rowData);
        setFormState(subRouteForm, true);
        subRouteModalTitle.textContent = "View Sub-Route Details";
        
        document.getElementById('sub_route_code').value = data.sub_route_code;
        document.getElementById('sub_route').value = data.sub_route;
        document.getElementById('route_code_sub').value = data.route_code;
        document.getElementById('supplier_code_sub').value = data.supplier_code;
        document.getElementById('distance_sub').value = data.distance;
        document.getElementById('per_day_rate_sub').value = data.per_day_rate;
        
        subRouteModal.style.display = "flex";
    }

    function handleFormSubmit(event) {
        event.preventDefault();
        const action = document.getElementById('subRouteAction').value;
        const formData = new FormData(subRouteForm);
        formData.append('action', action);

        fetch('sub_routes_backend.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            if (data.trim() === "Success") {
                const message = action === 'add' ? "Sub-route added successfully!" : "Sub-route updated successfully!";
                showToast(message, 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast("Error: " + data, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast("An error occurred. Please try again.", 'error');
        });
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