<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

include('../../includes/db.php');

$message = null; 
if (isset($_GET['status']) && isset($_GET['message'])) {
    $message = [
        'status' => $_GET['status'],
        'text' => htmlspecialchars(urldecode($_GET['message']))
    ];
}

// --- API MODE (AJAX requests) for Toggle Status ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    header('Content-Type: application/json');

    try {
        $supplier_code = $_POST['supplier_code'];
        $new_status = (int)$_POST['is_active'];

        $sql = "UPDATE supplier SET is_active = ? WHERE supplier_code = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $new_status, $supplier_code);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Supplier status updated successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// --- NORMAL PAGE LOAD ---
include('../../includes/header.php');
include('../../includes/navbar.php');

$status_filter = $_GET['status'] ?? 'active';

$sql = "SELECT * FROM supplier WHERE 1=1";
$types = "";
$params = [];

if ($status_filter === 'active') {
    $sql .= " AND is_active = 1";
} elseif ($status_filter === 'inactive') {
    $sql .= " AND is_active = 0";
}

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$suppliers_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* CSS for toast notifications */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 

        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>

<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Suppliers
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <div class="relative">
            <input type="text" id="search-input" onkeyup="searchTable()" placeholder="Search Supplier..." 
                   class="bg-gray-700 text-white text-sm rounded-lg pl-3 pr-8 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 w-48 transition-all focus:w-64 placeholder-gray-400 border border-gray-600">
            <i class="fas fa-search absolute right-3 top-2 text-gray-400 text-xs"></i>
        </div>

        <span class="text-gray-400 mx-1">|</span>

        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            <select id="status-filter" onchange="filterStatus(this.value)" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <option value="active" <?php echo (isset($status_filter) && $status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo (isset($status_filter) && $status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>

        <span class="text-gray-600">|</span>

        <a href="add_supplier.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
           Add Supplier
        </a>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-h-[88vh]">
        <table class="w-full table-auto border-collapse" id="supplierTable">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Supplier Code</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Supplier</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Phone No</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Email</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm" style="min-width: 140px;">Actions</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php if ($suppliers_result && $suppliers_result->num_rows > 0): ?>
                    <?php while ($supplier = $suppliers_result->fetch_assoc()): ?>
                        <tr class="hover:bg-indigo-50 border-b border-gray-100 transition duration-150">
                            <td class="px-4 py-3 font-mono font-medium text-blue-600"><?php echo htmlspecialchars($supplier['supplier_code']); ?></td>
                            <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($supplier['supplier']); ?></td>
                            <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($supplier['s_phone_no']); ?></td>
                            <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($supplier['email']); ?></td>
                            
                            <td class="px-4 py-3 text-center">
                                <div class="flex justify-center gap-2">
                                    <a href="view_supplier.php?code=<?php echo urlencode($supplier['supplier_code']); ?>" class='bg-green-500 hover:bg-green-600 text-white py-1 px-2 rounded-md shadow-sm transition' title='View'>
                                        <i class='fas fa-eye text-xs'></i>
                                    </a>
                                    
                                    <a href="edit_supplier.php?code=<?php echo urlencode($supplier['supplier_code']); ?>" class='bg-yellow-500 hover:bg-yellow-600 text-white py-1 px-2 rounded-md shadow-sm transition' title='Edit'>
                                        <i class='fas fa-edit text-xs'></i>
                                    </a>
                                    
                                    <?php if ($supplier['is_active'] == 1): ?>
                                        <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($supplier['supplier_code']); ?>", 0)' class='bg-red-500 hover:bg-red-600 text-white py-1 px-2 rounded-md shadow-sm transition' title="Disable">
                                            <i class='fas fa-ban text-xs'></i>
                                        </button>
                                    <?php else: ?>
                                        <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($supplier['supplier_code']); ?>", 1)' class='bg-green-500 hover:bg-green-600 text-white py-1 px-2 rounded-md shadow-sm transition' title="Enable">
                                            <i class='fas fa-check text-xs'></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                            No suppliers found
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

<script>
function filterStatus(status) {
    window.location.href = 'suppliers.php?status=' + status;
}

// Search Function
function searchTable() {
    const input = document.getElementById("search-input");
    const filter = input.value.toUpperCase();
    const table = document.getElementById("supplierTable");
    const tr = table.getElementsByTagName("tr");

    // Loop through all table rows, and hide those who don't match the search query
    for (let i = 1; i < tr.length; i++) { // Start from 1 to skip header
        // Column 0 is Code, Column 1 is Name
        const tdCode = tr[i].getElementsByTagName("td")[0];
        const tdName = tr[i].getElementsByTagName("td")[1];
        
        if (tdCode || tdName) {
            const txtCode = tdCode.textContent || tdCode.innerText;
            const txtName = tdName.textContent || tdName.innerText;
            
            if (txtCode.toUpperCase().indexOf(filter) > -1 || txtName.toUpperCase().indexOf(filter) > -1) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }       
    }
}

function showToast(status, message) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast ' + status + ' show';
    
    const icon = status === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
    
    toast.innerHTML = `
        <i class="fas ${icon} toast-icon"></i>
        <span>${message}</span>`;
    
    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function confirmToggleStatus(code, newStatus) {
    const action = newStatus === 1 ? 'Enable' : 'Disable';
    if (confirm(`Are you sure you want to ${action} supplier ${code}?`)) {
        toggleStatus(code, newStatus);
    }
}

function toggleStatus(code, newStatus) {
    fetch('suppliers.php', { 
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            'action': 'toggle_status',
            'supplier_code': code,
            'is_active': newStatus
        })
    })
    .then(response => response.json())
    .then(data => {
        showToast(data.status, data.message);
        if (data.status === 'success') {
            setTimeout(() => window.location.reload(), 500);
        }
    })
    .catch(error => {
        console.error('Error toggling status:', error);
        showToast('error', 'An unexpected error occurred.');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const phpMessage = <?php echo json_encode($message ?? null); ?>;
    if (phpMessage && phpMessage.status && phpMessage.text) {
        showToast(phpMessage.status, phpMessage.text);
    }
});
</script>

</body>
</html>

<?php $conn->close(); ?>