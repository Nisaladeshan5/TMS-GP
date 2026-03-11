<?php
// adjustment_sub.php (Sub Route Payment Reduction Management - With Custom Toast)
require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

include('../../../../includes/db.php');

// --- HELPER: Get Current Employee ID ---
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

// Notification handle කිරීම සඳහා variable එකක්
$alert_type = '';
$alert_msg = '';

// 1. ADD NEW REDUCTION
if (isset($_POST['add_reduction'])) {
    $sub_route_code = $_POST['sub_route_code'];
    $date = $_POST['date'];
    $amount = (float)$_POST['amount'];
    $reason = $_POST['reason'];
    $user_id = $_SESSION['user_id'];

    $sql = "INSERT INTO sub_reduction (sub_route_code, date, amount, reason, user_id) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssdsi", $sub_route_code, $date, $amount, $reason, $user_id);
    if ($stmt->execute()) {
        $alert_type = 'success';
        $alert_msg = 'Reduction added successfully!';
    } else {
        $alert_type = 'error';
        $alert_msg = 'Error adding reduction.';
    }
    $stmt->close();
}

// 2. DELETE
if (isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];
    $check_sql = "SELECT a.emp_id FROM sub_reduction r JOIN admin a ON r.user_id = a.user_id WHERE r.id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $delete_id);
    $check_stmt->execute();
    $creator_data = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if (($creator_data['emp_id'] ?? null) === $current_employee_id) {
        $stmt = $conn->prepare("DELETE FROM sub_reduction WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            $alert_type = 'success';
            $alert_msg = 'Deleted successfully!';
        }
    } else {
        $alert_type = 'error';
        $alert_msg = 'Permission denied!';
    }
}

// 3. UPDATE
if (isset($_POST['update_reduction'])) {
    $id = (int)$_POST['id'];
    $date = $_POST['date'];
    $amount = (float)$_POST['amount'];
    $reason = $_POST['reason'];

    $stmt = $conn->prepare("UPDATE sub_reduction SET date = ?, amount = ?, reason = ? WHERE id = ?");
    $stmt->bind_param("sdsi", $date, $amount, $reason, $id);
    if ($stmt->execute()) {
        $alert_type = 'success';
        $alert_msg = 'Updated successfully!';
    }
    $stmt->close();
}

// --- FETCH DATA ---
$reduction_sql = "
    SELECT r.*, s.sub_route AS route_name, e.calling_name AS entry_by_name, a.emp_id AS creator_emp_id
    FROM sub_reduction r
    JOIN sub_route s ON r.sub_route_code = s.sub_route_code
    LEFT JOIN admin a ON r.user_id = a.user_id
    LEFT JOIN employee e ON a.emp_id = e.emp_id
    WHERE MONTH(r.date) = ? AND YEAR(r.date) = ?
    ORDER BY r.date DESC, r.id DESC";

$stmt = $conn->prepare($reduction_sql);
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$routes_res = $conn->query("SELECT sub_route_code, sub_route FROM sub_route WHERE is_active = 1");

include('../../../../includes/header.php'); 
include('../../../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sub Route Reductions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .uppercase-input { text-transform: uppercase; }
        .readonly-field { background-color: #f3f4f6; cursor: not-allowed; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <div id="toast-container"></div>

    <div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-4 sticky top-0 z-40 border-b border-gray-700">
        <div class="flex items-center gap-2">
            <a href="sub_route_payments.php" class="text-lg font-bold bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">Sub Route Payments</a>
            <i class="fa-solid fa-angle-right text-gray-400 text-xs mt-1"></i>
            <span class="text-sm font-bold uppercase tracking-wider">Adjustment (Reductions)</span>
        </div>

        <div class="flex items-center gap-4">
            <form method="get" class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600">
                <select name="month" onchange="this.form.submit()" class="bg-transparent text-white text-sm focus:outline-none cursor-pointer px-2">
                    <?php for ($m=1; $m<=12; $m++): ?>
                        <option value="<?= $m ?>" <?= ($selected_month == $m) ? 'selected' : '' ?> class="text-black"><?= date('M', mktime(0,0,0,$m,10)) ?></option>
                    <?php endfor; ?>
                </select>
                <span class="text-gray-500">|</span>
                <select name="year" onchange="this.form.submit()" class="bg-transparent text-white text-sm focus:outline-none cursor-pointer px-2">
                    <?php for ($y=date('Y'); $y>=2023; $y--): ?>
                        <option value="<?= $y ?>" <?= ($selected_year == $y) ? 'selected' : '' ?> class="text-black"><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>
            <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-bold transition">
                Add Reduction
            </button>
            <a href="sub_route_payments.php" class="hover:text-yellow-600 text-sm font-bold border-l pl-4 border-gray-600">Back</a>
        </div>
    </div>

    <main class="w-[85%] ml-[15%] p-2">
        <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="bg-blue-600 text-white text-left font-bold uppercase tracking-wider">
                        <th class="py-3 px-6">Date</th>
                        <th class="py-3 px-6">Sub Route</th>
                        <th class="py-3 px-6 text-right">Amount (LKR)</th>
                        <th class="py-3 px-6">Reason</th>
                        <th class="py-3 px-6">Entry By</th>
                        <th class="py-3 px-6 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (!empty($records)): ?>
                        <?php foreach ($records as $data): ?>
                            <tr class="hover:bg-blue-50 transition">
                                <td class="py-3 px-6"><?= $data['date'] ?></td>
                                <td class="py-3 px-6 font-medium"><?= $data['route_name'] ?></td>
                                <td class="py-3 px-6 text-right font-bold text-red-600">- <?= number_format($data['amount'], 2) ?></td>
                                <td class="py-3 px-6 italic text-gray-500">"<?= htmlspecialchars($data['reason']) ?>"</td>
                                <td class="py-3 px-6 text-xs text-gray-400"><?= $data['entry_by_name'] ?></td>
                                <td class="py-3 px-6 text-center">
                                    <?php if ($data['creator_emp_id'] === $current_employee_id): ?>
                                        <div class="flex justify-center gap-2">
                                            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($data)) ?>)" class="text-blue-500 hover:bg-blue-100 p-1 rounded transition"><i class="fas fa-edit"></i></button>
                                            <form method="post" onsubmit="return confirm('Delete this reduction?');">
                                                <input type="hidden" name="delete_id" value="<?= $data['id'] ?>">
                                                <button type="submit" class="text-red-500 hover:bg-red-100 p-1 rounded transition"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <i class="fas fa-lock text-gray-300" title="Locked"></i>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="py-4 text-center text-gray-400 italic">No reduction records found for this month.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div id="addModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-white p-6 rounded-xl shadow-2xl w-full max-w-md">
            <h3 class="text-xl font-bold mb-4 border-b pb-2">Add New Reduction</h3>
            <form method="post" class="space-y-4">
                <input type="hidden" name="add_reduction" value="1">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">SUB ROUTE</label>
                    <select name="sub_route_code" required class="w-full border border-gray-300 rounded p-2 text-sm">
                        <option value="">-- Select --</option>
                        <?php $routes_res->data_seek(0); while($r = $routes_res->fetch_assoc()): ?>
                            <option value="<?= $r['sub_route_code'] ?>"><?= $r['sub_route_code'] ?> - <?= $r['sub_route'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">DATE</label>
                        <input type="date" name="date" required value="<?= date('Y-m-d') ?>" class="w-full border border-gray-300 rounded p-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">AMOUNT</label>
                        <input type="number" step="0.01" name="amount" required class="w-full border border-gray-300 rounded p-2 text-sm font-bold text-red-600">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">REASON</label>
                    <textarea name="reason" rows="2" required class="w-full border border-gray-300 rounded p-2 text-sm"></textarea>
                </div>
                <div class="flex justify-between gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="px-4 py-2 text-gray-500 text-sm">Cancel</button>
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold text-sm">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-white p-6 rounded-xl shadow-2xl w-full max-w-md">
            <h3 class="text-xl font-bold mb-4 border-b pb-2">Edit Reduction</h3>
            <form method="post" class="space-y-4">
                <input type="hidden" name="update_reduction" value="1">
                <input type="hidden" name="id" id="edit_id">
                <div class="text-xs bg-gray-50 p-2 rounded mb-2 text-indigo-600 font-bold" id="edit_info"></div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">DATE</label>
                    <input type="date" name="date" id="edit_date" required class="w-full border border-gray-300 rounded p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">AMOUNT</label>
                    <input type="number" step="0.01" name="amount" id="edit_amount" required class="w-full border border-gray-300 rounded p-2 text-sm font-bold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">REASON</label>
                    <textarea name="reason" id="edit_reason" rows="2" required class="w-full border border-gray-300 rounded p-2 text-sm"></textarea>
                </div>
                <div class="flex justify-between gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="px-4 py-2 text-gray-500 text-sm">Cancel</button>
                    <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-lg font-bold text-sm shadow-md">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // 1. Toast Function (මෙහි CSS animations කලින් එකට වඩා ටිකක් වෙනස් කර ලස්සන කර ඇත)
        function showToast(message, type) {
            const container = document.getElementById("toast-container");
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
            toast.innerHTML = `<i class="fas ${icon} mr-3 text-lg"></i><span class="font-medium">${message}</span>`;
            container.appendChild(toast);
            
            // Show animation
            setTimeout(() => toast.classList.add('show'), 100);
            
            // Hide and remove
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 400);
            }, 3000);
        }

        // 2. PHP එකෙන් එන පණිවිඩ Toast එකක් විදිහට පෙන්වීම
        window.onload = function() {
            <?php if ($alert_type != ''): ?>
                showToast("<?= $alert_msg ?>", "<?= $alert_type ?>");
            <?php endif; ?>
        };

        // 3. Modal Functions
        function openEditModal(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_date').value = data.date;
            document.getElementById('edit_amount').value = data.amount;
            document.getElementById('edit_reason').value = data.reason;
            document.getElementById('edit_info').textContent = "Route: " + data.sub_route_code;
            document.getElementById('editModal').classList.remove('hidden');
        }
    </script>
</body>
</html>