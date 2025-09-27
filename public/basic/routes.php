<?php
// Includes
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

session_start();

if (isset($_GET['purpose_filter']) && in_array($_GET['purpose_filter'], ['staff', 'worker'])) {
    $_SESSION['purpose_filter'] = $_GET['purpose_filter'];
}

// Default to staff only if nothing in session
$purpose_filter = isset($_SESSION['purpose_filter']) ? $_SESSION['purpose_filter'] : 'staff';

// Initialize the payment_type variable
// This line prevents the "Undefined variable" warning
$payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : '1';

// Build the SQL query with a JOIN clause to get the supplier name
$sql = "SELECT r.route_code, r.supplier_code, s.supplier, r.route, r.purpose, r.distance, r.working_days, r.vehicle_no, r.monthly_fixed_rental, r.assigned_person, r.payment_type 
        FROM route r
        JOIN supplier s ON r.supplier_code = s.supplier_code";

// Sanitize the input and add the WHERE clause
$safe_purpose_filter = $conn->real_escape_string($purpose_filter);
$sql .= " WHERE r.purpose = '" . $safe_purpose_filter . "'";

if ($payment_type) {
    $safe_payment_type = $conn->real_escape_string($payment_type);
    $sql .= " AND r.payment_type = '" . $safe_payment_type . "'";
}

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Route Details</title>
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
        <button onclick="openModal()" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300">
            Add New Route
        </button>
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
                        <button onclick='openEditModal(\"$route_code\", \"$route_name\", \"$purpose\", \"$distance\", \"$working_days\", \"$supplier_code\", \"$vehicle_no\", \"$monthly_fixed_rental\", \"$assigned_person\")' class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>Edit</button>
                        <button onclick='deleteRoute(\"$route_code\")' class='bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>Delete</button>
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

<div id="myModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeModal()">&times;</span>
    <h3 class="text-2xl font-semibold mb-1" id="modalTitle">Add New Route</h3>
    <form id="routeForm" onsubmit="handleFormSubmit(event)" class="space-y-4">
      <input type="hidden" name="action" id="action">
      <div>
        <label for="route_code" class="block text-gray-700">Route Code:</label>
        <input type="text" id="route_code" name="route_code" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
      </div>
      <div class="flex">
        <div class="w-[63%] mr-4"> 
          <label for="route" class="block text-gray-700">Route:</label>
          <input type="text" id="route" name="route" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
        </div>
        <div class="w-[33%]">
          <label for="purpose" class="block text-gray-700">Purpose:</label>
          <select id="purpose" name="purpose" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
            <option value="">-- Select Purpose --</option>
            <option value="staff">Staff</option>
            <option value="worker">Worker</option>
          </select>
        </div>
      </div>
      <div class="flex">
        <div class="w-[48%] mr-4">
          <label for="distance" class="block text-gray-700">Distance (km):</label>
          <input type="number" id="distance" name="distance" step="0.01" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
        </div>
        <div class="w-[48%]">
          <label for="workingDays" class="block text-gray-700">Working Days:</label>
          <input type="number" id="workingDays" name="workingDays" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
        </div>
      </div>
      <div class="flex">
        <div class="w-[48%] mr-4">
          <label for="supplier" class="block text-gray-700">Supplier:</label>
          <select id="supplier" name="supplier_code" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
            <option value="">-- Select Supplier --</option>
            <?php
            $supplier_sql = "SELECT supplier_code, supplier FROM supplier ORDER BY supplier_code";
            $supplier_result = $conn->query($supplier_sql);
            if ($supplier_result && $supplier_result->num_rows > 0) {
              while ($supplier_row = $supplier_result->fetch_assoc()) {
                $supplier_code_val = htmlspecialchars($supplier_row["supplier_code"]);
                $supplier_name = htmlspecialchars($supplier_row["supplier"]);
                echo "<option value='{$supplier_code_val}'>{$supplier_name}</option>";
              }
            }
            ?>
          </select>
        </div>
        <div class="w-[48%]">
          <label for="vehicle_no" class="block text-gray-700">Vehicle No:</label>
          <select id="vehicle_no" name="vehicle_no" required 
          class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
            <option value="">-- Select Vehicle No --</option>
            <?php
            $vehicle_sql = "SELECT vehicle_no FROM vehicle ORDER BY vehicle_no";
            $vehicle_result = $conn->query($vehicle_sql);
            if ($vehicle_result && $vehicle_result->num_rows > 0) {
              while ($vehicle_row = $vehicle_result->fetch_assoc()) {
                $vehicle_no_val = htmlspecialchars($vehicle_row["vehicle_no"]);
                echo "<option value='{$vehicle_no_val}'>{$vehicle_no_val}</option>";
              }
            }
            ?>
          </select>
        </div>
      </div>
      <div class="flex">
        <div class="w-[48%] mr-4">
          <label for="monthly_fixed_rental" class="block text-gray-700">Monthly Fixed Rental:</label>
          <input type="number" id="monthly_fixed_rental" name="monthly_fixed_rental" step="0.01" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
        </div>
        <div class="w-[48%]">

        </div>
      </div>
      <div>
        <label for="assigned_person" class="block text-gray-700">Assigned Person:</label>
        <input type="text" id="assigned_person" name="assigned_person" required class="mt-1 p-1 block w-full rounded-md border border-gray-300 shadow-md focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
      </div>
      <div class="flex justify-end">
        <input type="submit" id="submitBtn" value="Add" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md cursor-pointer transition duration-300">
      </div>
    </form>
  </div>
</div>

<div id="toast-container"></div>

<script>
  var modal = document.getElementById("myModal");
  var form = document.getElementById("routeForm");
  var submitBtn = document.getElementById("submitBtn");
  var modalTitle = document.getElementById("modalTitle");
  var toastContainer = document.getElementById("toast-container");
  const routeCodeInput = document.getElementById('route_code');

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

  function openModal() {
    form.reset();
    document.getElementById('action').value = 'add';
    routeCodeInput.disabled = false;
    routeCodeInput.readOnly = false;
    routeCodeInput.style.backgroundColor = 'white';
    submitBtn.value = "Add";
    modalTitle.textContent = "Add New Route";
    modal.style.display = "flex";
  }

  function openEditModal(code, route, purpose, distance, workingDays, supplier, vehicle_no, monthly_fixed_rental, assigned_person, payment_type) {
    document.getElementById('action').value = 'edit';
    document.getElementById('route_code').value = code;
    routeCodeInput.disabled = true;
    routeCodeInput.readOnly = true;
    routeCodeInput.style.backgroundColor = '#e5e7eb';
    document.getElementById('route').value = route;
    document.getElementById('purpose').value = purpose;
    document.getElementById('distance').value = distance;
    document.getElementById('workingDays').value = workingDays;
    document.getElementById('supplier').value = supplier;
    document.getElementById('vehicle_no').value = vehicle_no;
    document.getElementById('monthly_fixed_rental').value = monthly_fixed_rental;
    document.getElementById('assigned_person').value = assigned_person;

    submitBtn.value = "Save Changes";
    modalTitle.textContent = "Edit Route";
    modal.style.display = "flex";
  }

  function closeModal() {
    modal.style.display = "none";
    routeCodeInput.disabled = false;
    routeCodeInput.readOnly = false;
    routeCodeInput.style.backgroundColor = 'white';
  }

  function handleFormSubmit(event) {
    event.preventDefault();
    const formData = new FormData(form);
    const action = formData.get('action');
    if (action === 'edit') {
      formData.append('route_code', routeCodeInput.value);
    }

    fetch('routes_backend.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.text())
    .then(data => {
      if (data.trim() === "Success") {
        const message = action === 'add' ? "Route added successfully!" : "Route updated successfully!";
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

  function deleteRoute(routeCode) {
    if (confirm("Are you sure you want to delete this route?")) {
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
