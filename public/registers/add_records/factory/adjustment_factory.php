<?php
// adjustment_factory.php (Factory Payment Reduction Management)
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

// --- HELPER FUNCTION: Get Current Employee ID ---
function get_current_emp_id($conn) {
    if (!isset($_SESSION['user_id'])) return null;
    $current_user_id = $_SESSION['user_id'];
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
$current_employee_id = get_current_emp_id($conn);

// --- FILTERS ---
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$message = '';

// --- CRUD OPERATIONS ---

// 1. DELETE
if (isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];
    // Check Creator
    $check_sql = "SELECT a.emp_id FROM reduction r JOIN admin a ON r.user_id = a.user_id WHERE r.id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $delete_id);
    $check_stmt->execute();
    $creator_data = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if (($creator_data['emp_id'] ?? null) === $current_employee_id) {
        $delete_sql = "DELETE FROM reduction WHERE id = ?";
        $stmt = $conn->prepare($delete_sql);
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            $message = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">Record deleted successfully.</div>';
        } else {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">Error: ' . $conn->error . '</div>';
        }
        $stmt->close();
    } else {
         $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">Permission denied. Only creator can delete.</div>';
    }
    // Redirect to prevent resubmission
    echo "<script>window.location.href='?month=$selected_month&year=$selected_year';</script>";
    exit();
}

// 2. UPDATE
if (isset($_POST['update_reduction'])) {
    $id = (int)$_POST['id'];
    $date = $_POST['date'];
    $amount = (float)$_POST['amount'];
    $reason = $_POST['reason'];

    // Check Creator
    $check_sql = "SELECT a.emp_id FROM reduction r JOIN admin a ON r.user_id = a.user_id WHERE r.id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $creator_data = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if (($creator_data['emp_id'] ?? null) === $current_employee_id) {
        $sql = "UPDATE reduction SET date = ?, amount = ?, reason = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sdsi", $date, $amount, $reason, $id);
        if ($stmt->execute()) {
            $message = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">Record updated successfully.</div>';
        } else {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">Error: ' . $conn->error . '</div>';
        }
        $stmt->close();
    } else {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">Permission denied. Only creator can edit.</div>';
    }
    echo "<script>window.location.href='?month=$selected_month&year=$selected_year';</script>";
    exit();
}

// --- FETCH DATA (Factory Purpose Only) ---
$reduction_sql = "
    SELECT r.id, r.supplier_code, r.route_code, r.date, r.amount, r.reason, r.user_id,
           rt.route AS route_name, s.supplier AS supplier_name,
           a.emp_id AS creator_emp_id, e.calling_name AS entry_by_name
    FROM reduction r
    JOIN route rt ON r.route_code = rt.route_code
    JOIN supplier s ON r.supplier_code = s.supplier_code
    LEFT JOIN admin a ON r.user_id = a.user_id     
    LEFT JOIN employee e ON a.emp_id = e.emp_id  
    WHERE MONTH(r.date) = ? AND YEAR(r.date) = ? AND rt.purpose = 'factory'
    ORDER BY r.date DESC, r.id DESC";

$stmt = $conn->prepare($reduction_sql);
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include('../../../../includes/header.php'); 
include('../../../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Factory Reductions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-2 sticky top-0 z-40 border-b border-gray-700">
        
        <div class="flex items-center gap-3">
            <div class="flex items-center space-x-2 p-3 w-fit">
                <a href="../../factory_transport_vehicle_register.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
                    Factory Transport Vehicle Registers
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Adjustment
                </span>
            </div>
        </div>

        <div class="flex items-center gap-4 text-sm font-medium"> 
            
            <form method="get" class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
                <select name="month" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                    <?php for ($m=1; $m<=12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo ($selected_month == $m) ? 'selected' : ''; ?> class="text-gray-900 bg-white">
                            <?php echo date('M', mktime(0, 0, 0, $m, 10)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                
                <span class="text-gray-400 mx-1">|</span>

                <select name="year" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-1 pr-2 appearance-none hover:text-yellow-200 transition">
                    <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?> class="text-gray-900 bg-white">
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </form>

            <?php if ($is_logged_in): ?>
                <a href="../../factory_transport_vehicle_register.php" class="hover:text-yellow-600 text-gray-300">Register</a>
                
                <a href="bulk_reduction.php" class="hover:text-yellow-600 text-gray-300">
                    Bulk
                </a>

                <a href="add_f_reduction.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-md shadow-md transition transform hover:scale-105">
                    Add Reduction
                </a>
            <?php endif; ?>
        </div>
    </div>

    <main class="w-[85%] ml-[15%] p-2 mt-2">
        
        <?php echo $message; ?>

        <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider">
                        <th class="py-2 px-6 text-left border-b border-blue-500">Date</th>
                        <th class="py-2 px-6 text-left border-b border-blue-500">Route</th>
                        <th class="py-2 px-6 text-left border-b border-blue-500">Supplier</th>
                        <th class="py-2 px-6 text-left border-b border-blue-500">Amount (LKR)</th>
                        <th class="py-2 px-6 text-left border-b border-blue-500">Reason</th>
                        <th class="py-2 px-6 text-left border-b border-blue-500">Entry By</th>
                        <th class="py-2 px-6 text-center border-b border-blue-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($records)): ?>
                        <?php foreach ($records as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150">
                                <td class="py-3 px-6 whitespace-nowrap font-medium"><?php echo htmlspecialchars($data['date']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($data['route_name']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($data['supplier_name']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap font-bold text-red-600 bg-red-50 rounded-md">
                                    - <?php echo number_format($data['amount'], 2); ?>
                                </td>
                                <td class="py-3 px-6 whitespace-normal max-w-xs"><?php echo htmlspecialchars($data['reason']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap text-xs text-gray-500">
                                    <span class="bg-gray-100 px-2 py-1 rounded-full border border-gray-200">
                                        <?php echo htmlspecialchars($data['entry_by_name'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-6 whitespace-nowrap text-center">
                                    <?php if ($data['creator_emp_id'] === $current_employee_id): ?>
                                        <div class="flex justify-center gap-2">
                                            <button onclick="openEditModal(this)"
                                               data-id="<?php echo htmlspecialchars($data['id']); ?>"
                                               data-date="<?php echo htmlspecialchars($data['date']); ?>"
                                               data-amount="<?php echo htmlspecialchars($data['amount']); ?>"
                                               data-reason="<?php echo htmlspecialchars($data['reason']); ?>"
                                               data-route="<?php echo htmlspecialchars($data['route_code']); ?>"
                                               data-supplier="<?php echo htmlspecialchars($data['supplier_code']); ?>"
                                               class="text-blue-500 hover:text-blue-700 p-1 hover:bg-blue-100 rounded transition" 
                                               title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <form method="post" onsubmit="return confirm('Delete this record?');" style="display:inline;">
                                                <input type="hidden" name="delete_id" value="<?php echo htmlspecialchars($data['id']); ?>">
                                                <button type="submit" class="text-red-500 hover:text-red-700 p-1 hover:bg-red-100 rounded transition" title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs italic">Locked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="py-3 text-center text-gray-500">
                                No reduction records found for <?php echo date('F Y', mktime(0,0,0,$selected_month, 1, $selected_year)); ?>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div id="editModal" class="fixed inset-0 bg-gray-900 bg-opacity-75 z-50 flex items-center justify-center hidden backdrop-blur-sm">
        <div class="bg-white p-6 rounded-xl shadow-2xl w-full max-w-md mx-4 transform transition-all scale-100">
            <div class="flex justify-between items-center mb-4 border-b pb-2">
                <h3 class="text-xl font-bold text-gray-800">Edit Reduction</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="text-xs text-gray-500 mb-4 bg-gray-50 p-2 rounded border border-gray-100">
                <span id="modalInfo"></span>
            </div>

            <form method="post" class="space-y-4">
                <input type="hidden" name="id" id="modal_id">
                <input type="hidden" name="update_reduction" value="1">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="date" id="modal_date" required class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (LKR)</label>
                    <input type="number" step="0.01" name="amount" id="modal_amount" required class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reason</label>
                    <textarea name="reason" id="modal_reason" rows="2" required class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"></textarea>
                </div>
                
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 transition text-sm">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition text-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(btn) {
            document.getElementById('modal_id').value = btn.dataset.id;
            document.getElementById('modal_date').value = btn.dataset.date;
            document.getElementById('modal_amount').value = btn.dataset.amount;
            document.getElementById('modal_reason').value = btn.dataset.reason;
            document.getElementById('modalInfo').textContent = `Route: ${btn.dataset.route} | Supplier: ${btn.dataset.supplier}`;
            document.getElementById('editModal').classList.remove('hidden');
        }
        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }
    </script>
</body>
</html>