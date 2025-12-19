<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
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
        // Decode the URL message and then sanitize for safety
        'text' => htmlspecialchars(urldecode($_GET['message']))
    ];
}

// --- API MODE (AJAX requests) for Toggle Status (Keep this here for AJAX) ---

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

// --- NORMAL PAGE LOAD (HTML) ---
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
    <style>
        /* Only keep CSS for toast notifications and other general page styles */
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
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100">

<div class="container" style="width: 80%; margin-left: 17.5%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-4xl font-bold text-gray-800 mt-6 mb-4">Supplier Details</p>
    <div class="w-full flex justify-between items-center mb-6">
        <a 
            href="add_supplier.php" 
            class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300"
            title="Add New Supplier"
        >
            Add New Supplier
        </a>
        
        <div class="flex items-center space-x-2">
            <select id="status-filter" onchange="filterStatus(this.value)" class="p-2 border rounded-md">
                <option value="active" <?php echo (isset($status_filter) && $status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo (isset($status_filter) && $status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
    </div>
    <div class="overflow-x-auto bg-white shadow-md rounded-md w-full">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Supplier Code</th>
                    <th class="px-4 py-2 text-left">Supplier</th>
                    <th class="px-4 py-2 text-left">Phone No</th>
                    <th class="px-4 py-2 text-left">Email</th>
                    <th class="px-4 py-2 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($suppliers_result && $suppliers_result->num_rows > 0): ?>
                    <?php while ($supplier = $suppliers_result->fetch_assoc()): ?>
                        <tr class="hover:bg-gray-100">
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($supplier['supplier_code']); ?></td>
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($supplier['supplier']); ?></td>
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($supplier['s_phone_no']); ?></td>
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($supplier['email']); ?></td>
                            <td class="border px-4 py-2">
                                <a href="view_supplier.php?code=<?php echo urlencode($supplier['supplier_code']); ?>" class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>View</a>
                                
                                <a href="edit_supplier.php?code=<?php echo urlencode($supplier['supplier_code']); ?>" class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>Edit</a>
                                
                                <?php if ($supplier['is_active'] == 1): ?>
                                    <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($supplier['supplier_code']); ?>", 0)' class='bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>Disable</button>
                                <?php else: ?>
                                    <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($supplier['supplier_code']); ?>", 1)' class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>Enable</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="border px-4 py-2 text-center">No suppliers found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

<script>
function filterStatus(status) {
    // Changed redirect to suppliers.php
    window.location.href = 'suppliers.php?status=' + status;
}

function showToast(status, message) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast ' + status;
    toast.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" class="toast-icon" viewBox="0 0 20 20" fill="currentColor">
        ${status === 'success' ? '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />' : 
                          '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />'}
    </svg>
    <span>${message}</span>`;
    container.appendChild(toast);

    // Show and hide logic
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300); // Remove after transition
    }, 3000);
}

function confirmToggleStatus(code, newStatus) {
    const action = newStatus === 1 ? 'Enable' : 'Disable';
    if (confirm(`Are you sure you want to ${action} supplier ${code}?`)) {
        toggleStatus(code, newStatus);
    }
}

function toggleStatus(code, newStatus) {
    // Keeping the endpoint the same for toggle status (which is suppliers.php)
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
            // Reload page or update table row if successful
            setTimeout(() => window.location.reload(), 500);
        }
    })
    .catch(error => {
        console.error('Error toggling status:', error);
        showToast('error', 'An unexpected error occurred.');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Check for success/error messages passed via URL from edit_supplier.php or toggleStatus
    const phpMessage = <?php echo json_encode($message ?? null); ?>;

    if (phpMessage && phpMessage.status && phpMessage.text) {
        // Use the existing showToast function
        showToast(phpMessage.status, phpMessage.text);
    }
});
</script>

</body>
</html>

<?php $conn->close(); ?>