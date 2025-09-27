<?php
include('../../includes/db.php');

// --- API MODE (AJAX requests) ---
if (isset($_GET['view_employee_no'])) {
    header('Content-Type: application/json');
    $employee_no = $_GET['view_employee_no'];
    $sql = "SELECT * FROM employee WHERE employee_no = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $employee_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();
    echo json_encode($employee ?: null);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    header('Content-Type: application/json');

    try {
        $employee_no = $_POST['employee_no'];
        $name = $_POST['name'];
        $route = $_POST['route'];
        $department = $_POST['department'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $address = $_POST['address'];

        $sql = "UPDATE employee 
                SET name=?, route=?, department=?, phone=?, email=?, address=?
                WHERE employee_no=?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssssss', $name, $route, $department, $phone, $email, $address, $employee_no);

        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Employee updated successfully!']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Database error: '.$stmt->error]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    header('Content-Type: application/json');

    try {
        $employee_no = $_POST['employee_no'];
        $new_status = (int)$_POST['is_active'];

        $sql = "UPDATE employee SET is_active = ? WHERE employee_no = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $new_status, $employee_no);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Employee status updated successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// --- NORMAL PAGE LOAD (HTML) ---
include('../../includes/header.php');
include('../../includes/navbar.php');

$status_filter = $_GET['status'] ?? 'active';

$sql = "SELECT * FROM employee";

if ($status_filter === 'active') {
    $sql .= " WHERE is_active = 1";
} elseif ($status_filter === 'inactive') {
    $sql .= " WHERE is_active = 0";
}

$employees_result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* CSS for modals and toast notifications */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 600px;
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

<div class="container" style="width: 80%; margin-left: 17.5%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-4xl font-bold text-gray-800 mt-6 mb-4">Employee Details</p>
    <div class="w-full flex justify-between items-center mb-6">
        <a href="add_employee.php" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300">
            Add New Employee
        </a>
        <div class="flex items-center space-x-2">
            <select id="status-filter" onchange="filterStatus(this.value)" class="p-2 border rounded-md">
                <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
    </div>
    <div class="overflow-x-auto bg-white shadow-md rounded-md w-full">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Employee No</th>
                    <th class="px-4 py-2 text-left">Name</th>
                    <th class="px-4 py-2 text-left">Route</th>
                    <th class="px-4 py-2 text-left">Department</th>
                    <th class="px-4 py-2 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($employees_result && $employees_result->num_rows > 0): ?>
                    <?php while ($employee = $employees_result->fetch_assoc()): ?>
                        <tr class="hover:bg-gray-100">
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($employee['employee_no']); ?></td>
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($employee['name']); ?></td>
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($employee['route']); ?></td>
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($employee['department']); ?></td>
                            <td class="border px-4 py-2">
                                <button onclick='viewEmployeeDetails("<?php echo htmlspecialchars($employee['employee_no']); ?>")' class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>View</button>
                                <button onclick='openEditModal("<?php echo htmlspecialchars($employee['employee_no']); ?>")' class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>Edit</button>
                                <?php if ($employee['is_active'] == 1): ?>
                                    <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($employee['employee_no']); ?>", 0)' class='bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>Disable</button>
                                <?php else: ?>
                                    <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($employee['employee_no']); ?>", 1)' class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>Enable</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="border px-4 py-2 text-center">No employees found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content !max-w-4xl !p-6 bg-gray-50 rounded-xl shadow-2xl">
        <span class="close" onclick="closeModal('editModal')">&times;</span>
        
        <div class="mb-1 pb-1 border-b border-gray-200">
            <h3 class="text-3xl font-extrabold text-gray-800" id="editModalTitle">Edit Employee</h3>
            <p class="text-lg text-gray-600 mt-1">Employee No: <span id="editEmployeeNoTitle" class="font-semibold"></span></p>
        </div>

        <form id="editForm" onsubmit="handleEditSubmit(event)" class="space-y-3">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" id="edit_employee_no" name="employee_no">
            
            <div class="bg-white p-2 rounded-lg">
                <h4 class="text-xl font-bold mb-4 text-blue-600">Employee Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="edit_name" class="block text-sm font-medium text-gray-700">Name:</label>
                        <input type="text" id="edit_name" name="name" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_route" class="block text-sm font-medium text-gray-700">Route:</label>
                        <input type="text" id="edit_route" name="route" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_department" class="block text-sm font-medium text-gray-700">Department:</label>
                        <input type="text" id="edit_department" name="department" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_phone" class="block text-sm font-medium text-gray-700">Phone No:</label>
                        <input type="text" id="edit_phone" name="phone" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_email" class="block text-sm font-medium text-gray-700">Email:</label>
                        <input type="email" id="edit_email" name="email" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_address" class="block text-sm font-medium text-gray-700">Address:</label>
                        <input type="text" id="edit_address" name="address" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                </div>
            </div>

            <div class="flex justify-end mt-8">
                <button type="submit" id="editSaveChangesButton" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="viewModal" class="modal">
    <div class="modal-content !max-w-4xl !p-6 bg-gray-50 rounded-xl shadow-2xl">
        <span class="close" onclick="closeModal('viewModal')">&times;</span>
        
        <div class="mb-1 pb-1 border-b border-gray-200">
            <h3 class="text-3xl font-extrabold text-gray-800" id="viewModalTitle">Employee Details</h3>
            <p class="text-lg text-gray-600 mt-1">Employee No: <span id="viewEmployeeNo" class="font-semibold"></span></p>
        </div>

        <div id="employeeDetails" class="space-y-2">
            <div class="bg-white p-2 rounded-lg transition-all duration-300 transform hover:scale-[1.01]">
                <h4 class="text-xl font-bold mb-4 text-blue-600">Basic Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Name</p>
                        <p id="viewName" class="text-base font-semibold"></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Route</p>
                        <p id="viewRoute" class="text-base font-semibold"></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Department</p>
                        <p id="viewDepartment" class="text-base font-semibold"></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Phone</p>
                        <p id="viewPhone" class="text-base font-semibold"></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Email</p>
                        <p id="viewEmail" class="text-base font-semibold"></p>
                    </div>
                    <div class="border border-gray-200 rounded-lg p-1">
                        <p class="text-sm font-medium text-gray-500">Address</p>
                        <p id="viewAddress" class="text-base font-semibold"></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end mt-8">
            <button id="closeViewButton" onclick="closeModal('viewModal')" class="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300">Close</button>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script src="employee.js"></script>

</body>
</html>

<?php $conn->close(); ?>