<?php
// adjustment_factory.php (Staff Payment Reduction Management)
require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

// NOTE: Ensure the path to db.php is correct relative to the location of this script.
include('../../../../includes/db.php');

// --- HELPER FUNCTION: Get the currently logged-in user's Employee ID ---
function get_current_emp_id($conn) {
    // Assuming the session stores the unique user_id used in the 'admin' table
    if (!isset($_SESSION['user_id'])) {
        return null; 
    }
    $current_user_id = $_SESSION['user_id'];
    
    // Find emp_id corresponding to the session user_id
    $sql = "SELECT emp_id FROM admin WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    
    return $data['emp_id'] ?? null;
}

// Get the current logged-in employee's ID once
$current_employee_id = get_current_emp_id($conn);


// --- 1. SETUP FILTERS AND VARIABLES ---
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$message = '';
$editing_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$edit_data = [];

// --- 2. HANDLE FORM SUBMISSIONS (CRUD OPERATIONS) ---

// A. DELETE Operation (Security check added here too, as it modifies data)
if (isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];
    
    // 1. Get the creator's emp_id for comparison
    $check_sql = "
        SELECT a.emp_id 
        FROM reduction r 
        JOIN admin a ON r.user_id = a.user_id 
        WHERE r.id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $delete_id);
    $check_stmt->execute();
    $creator_data = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    $creator_emp_id = $creator_data['emp_id'] ?? null;

    if ($creator_emp_id === $current_employee_id) {
        $delete_sql = "DELETE FROM reduction WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $delete_id);
        
        if ($delete_stmt->execute()) {
            $message = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">Record deleted successfully.</div>';
        } else {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">Error deleting record: ' . $conn->error . '</div>';
        }
        $delete_stmt->close();
    } else {
         $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">Permission denied. You can only delete records you created.</div>';
    }
    header("Location: adjustment_factory.php?month={$selected_month}&year={$selected_year}");
    exit();
}

// B. EDIT (Update) Operation (CRITICAL SECURITY CHECK)
if (isset($_POST['update_reduction'])) {
    $id = (int)$_POST['id'];
    $date = $_POST['date'];
    $amount = (float)$_POST['amount'];
    $reason = $_POST['reason'];

    // 1. Check if the currently logged-in user is the creator
    $check_sql = "
        SELECT a.emp_id 
        FROM reduction r 
        JOIN admin a ON r.user_id = a.user_id 
        WHERE r.id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $creator_data = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    $creator_emp_id = $creator_data['emp_id'] ?? null;

    if ($creator_emp_id === $current_employee_id) {
        $update_sql = "UPDATE reduction SET date = ?, amount = ?, reason = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("sdsi", $date, $amount, $reason, $id);

        if ($update_stmt->execute()) {
            $message = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">Record updated successfully.</div>';
        } else {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">Error updating record: ' . $conn->error . '</div>';
        }
        $update_stmt->close();
    } else {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">Permission denied. You can only edit records you created.</div>';
    }
    
    header("Location: adjustment_factory.php?month={$selected_month}&year={$selected_year}");
    exit();
}

// C. Fetch Data for Editing (Used for populating the modal)
if ($editing_id) {
    // Only fetching base data here. Permissions check happens on POST.
    $edit_sql = "SELECT id, supplier_code, route_code, date, amount, reason FROM reduction WHERE id = ?";
    $edit_stmt = $conn->prepare($edit_sql);
    $edit_stmt->bind_param("i", $editing_id);
    $edit_stmt->execute();
    $edit_result = $edit_stmt->get_result();
    $edit_data = $edit_result->fetch_assoc();
    $edit_stmt->close();
    
    if (!$edit_data) {
        $editing_id = null;
    }
}


// --- 3. FETCH REDUCTION RECORDS FOR DISPLAY ---
$reduction_sql = "
    SELECT 
        r.id, r.supplier_code, r.route_code, r.date, r.amount, r.reason, r.user_id,
        rt.route AS route_name, 
        s.supplier AS supplier_name,
        a.emp_id AS creator_emp_id,   -- Get the creator's emp_id for comparison
        e.calling_name AS entry_by_name
    FROM reduction r
    JOIN route rt ON r.route_code = rt.route_code
    JOIN supplier s ON r.supplier_code = s.supplier_code
    LEFT JOIN admin a ON r.user_id = a.user_id     
    LEFT JOIN employee e ON a.emp_id = e.emp_id  
    WHERE MONTH(r.date) = ? AND YEAR(r.date) = ? AND rt.purpose = 'factory' 
    ORDER BY r.date DESC, r.id DESC
";
$reduction_stmt = $conn->prepare($reduction_sql);
$reduction_stmt->bind_param("ii", $selected_month, $selected_year);
$reduction_stmt->execute();
$reduction_result = $reduction_stmt->get_result();
$reduction_records = $reduction_result->fetch_all(MYSQLI_ASSOC);
$reduction_stmt->close();

// --- 4. HTML TEMPLATE SETUP ---
$page_title = "Factory Payment Reduction Management";
$table_headers = [
    "Date",
    "Route",
    "Supplier",
    "Amount (LKR)", 
    "Reason",
    "Entry By",
    "Actions"
];

include('../../../../includes/header.php'); 
include('../../../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4"> 
        <?php if ($is_logged_in): ?>
            <a href="../../factory_transport_vehicle_register.php" class="hover:text-yellow-600">Register</a>
            <a href="../../unmark_factory_route_attendace.php" class="hover:text-yellow-600">Unmark Routes</a>
            <a href="../../factory_route_attendace.php" class="hover:text-yellow-600">Attendance</a>
            <a href="bulk_reduction.php" class="hover:text-yellow-600">bulk</a>
            <a href="add_f_reduction.php" class="hover:text-yellow-600">Add Reduction</a>
        <?php endif; ?>
    </div>
</div>
    <main class="w-[85%] ml-[15%] p-3">
        
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3
         mt-1">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0"><?php echo htmlspecialchars($page_title); ?></h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="adjustment_factory.php" class="flex flex-wrap gap-2 items-center">
                    
                    <!-- <a href="add_reduction.php" 
                        class="px-3 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 text-center"
                        title="Add New Reduction">
                        <i class="fas fa-plus"></i>
                    </a> -->
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="month" id="month" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php for ($m=1; $m<=12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo ($selected_month == $m) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="year" id="year" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="px-3 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200" title="Filter">
                        <i class="fas fa-filter mr-1"></i> 
                    </button>
                    
                </form>
            </div>
        </div>
        
        <?php echo $message; ?>

        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <?php foreach ($table_headers as $header): ?>
                            <th class="py-3 px-6 text-left border-b border-blue-500"><?php echo htmlspecialchars($header); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($reduction_records)): ?>
                        <?php foreach ($reduction_records as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                
                                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($data['date']); ?></td>
                                
                                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($data['route_name']); ?></td>
                                
                                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($data['supplier_name']); ?></td>
                                
                                <td class="py-3 px-6 whitespace-nowrap font-bold text-red-700"><?php echo number_format($data['amount'], 2); ?></td>
                                <td class="py-3 px-6 whitespace-normal max-w-xs"><?php echo htmlspecialchars($data['reason']); ?></td>
                                
                                <td class="py-3 px-6 whitespace-nowrap text-xs text-gray-500">
                                    <?php echo htmlspecialchars($data['entry_by_name'] ?? 'N/A'); ?>
                                </td>
                                
                                <td class="py-3 px-6 whitespace-nowrap text-center flex gap-2">
                                    
                                    <?php 
                                    // CHECK: Only show edit/delete buttons if current user is the creator
                                    $is_creator = ($data['creator_emp_id'] === $current_employee_id);
                                    ?>

                                    <?php if ($is_creator): ?>
                                    <a href="#" 
                                       onclick="openEditModal(this)"
                                       data-id="<?php echo htmlspecialchars($data['id']); ?>"
                                       data-date="<?php echo htmlspecialchars($data['date']); ?>"
                                       data-amount="<?php echo htmlspecialchars($data['amount']); ?>"
                                       data-reason="<?php echo htmlspecialchars($data['reason']); ?>"
                                       data-route="<?php echo htmlspecialchars($data['route_code']); ?>"
                                       data-supplier="<?php echo htmlspecialchars($data['supplier_code']); ?>"
                                       class="text-blue-500 hover:text-blue-700 transition" 
                                       title="Edit Record">
                                        <i class="fas fa-edit fa-lg"></i>
                                    </a>
                                    
                                    <form method="post" action="adjustment_factory.php" onsubmit="return confirm('Are you sure you want to delete this reduction record (ID: <?php echo htmlspecialchars($data['id']); ?>)?');">
                                        <input type="hidden" name="delete_id" value="<?php echo htmlspecialchars($data['id']); ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700 transition" title="Delete Record">
                                            <i class="fas fa-trash-alt fa-lg"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <span class="text-gray-400" title="Only creator can modify">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo count($table_headers); ?>" class="py-12 text-center text-gray-500 text-base font-medium">No reduction records found for the selected period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div id="editModal" class="fixed inset-0 bg-gray-900 bg-opacity-75 z-50 flex items-center justify-center hidden">
        <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-lg mx-4 transform transition-all duration-300">
            <div class="flex justify-between items-center mb-6 border-b pb-3">
                <h3 class="text-2xl font-bold text-gray-800">Edit Reduction</h3>
                <button onclick="closeEditModal()" class="text-gray-500 hover:text-gray-800">
                    <i class="fas fa-times fa-lg"></i>
                </button>
            </div>
            
            <p id="modalInfo" class="mb-4 text-sm font-semibold text-yellow-800 bg-yellow-100 p-2 rounded"></p>

            <form method="post" action="adjustment_factory.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" class="grid grid-cols-1 gap-4">
                <input type="hidden" name="id" id="modal_id">
                <input type="hidden" name="update_reduction" value="1">

                <div>
                    <label for="modal_date" class="block text-sm font-medium text-gray-700">Date</label>
                    <input type="date" name="date" id="modal_date" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-3 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label for="modal_amount" class="block text-sm font-medium text-gray-700">Amount (LKR)</label>
                    <input type="number" step="0.01" name="amount" id="modal_amount" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-3 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label for="modal_reason" class="block text-sm font-medium text-gray-700">Reason</label>
                    <input type="text" name="reason" id="modal_reason" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-3 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div class="pt-4 flex justify-end gap-3">
                    <button type="button" onclick="closeEditModal()" class="px-5 py-2 bg-gray-200 text-gray-700 font-semibold rounded-lg hover:bg-gray-300 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 transition">
                        <i class="fas fa-save mr-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openEditModal(button) {
            const id = button.getAttribute('data-id');
            const date = button.getAttribute('data-date');
            const amount = button.getAttribute('data-amount');
            const reason = button.getAttribute('data-reason');
            const route = button.getAttribute('data-route');
            const supplier = button.getAttribute('data-supplier');
            
            // Populate form fields
            document.getElementById('modal_id').value = id;
            document.getElementById('modal_date').value = date;
            document.getElementById('modal_amount').value = amount;
            document.getElementById('modal_reason').value = reason;
            
            // Update info text
            document.getElementById('modalInfo').textContent = `Editing Record ID: ${id} | Route Code: ${route} | Supplier Code: ${supplier}`;
            
            // Show modal
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }
    </script>
</body>
</html>