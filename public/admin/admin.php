<?php
// admin.php (Updated with Viewer Role Logic)
require_once '../../includes/session_check.php';

// 1. Session and Login Check
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php"); 
    exit();
}

// 2. Includes and Timezone
include('../../includes/db.php'); 
include('../../includes/header.php'); 
include('../../includes/navbar.php'); 

date_default_timezone_set('Asia/Colombo');

$logged_in_user_role = $_SESSION['user_role'] ?? 'viewer'; // Default to lowest if not set
$current_user_emp_id = $_SESSION['user_emp_id'] ?? null; 

// --- CHANGE 1: Updated Hierarchy with Viewer at bottom ---
$role_hierarchy = [
    'viewer' => 0,      // Lowest Level
    'admin' => 1,
    'super admin' => 2,
    'manager' => 3,
    'developer' => 4,
];

// Function to fetch all admin users (including viewers)
function fetchAdminUsers($conn) {
    // Fetch everyone except developers to show in the list
    $sql = "SELECT a.emp_id, a.role, e.calling_name FROM admin a LEFT JOIN employee e ON a.emp_id = e.emp_id WHERE a.role != 'developer' ORDER BY a.role ASC, a.emp_id ASC";
    $result = $conn->query($sql);
    if ($result === false) { return []; }
    $data = [];
    while ($row = $result->fetch_assoc()) { $data[] = $row; }
    $result->free(); 
    return $data;
}

try {
    $admin_users = fetchAdminUsers($conn);
} catch (Exception $e) {
    $admin_users = [];
}

// --- CHANGE 2: Updated Permission Logic ---
function canModify($logged_in_role, $target_role, $action_type, $target_new_role = null) {
    global $role_hierarchy;
    $logged_in_level = $role_hierarchy[$logged_in_role] ?? 0;
    $target_level = $role_hierarchy[$target_role] ?? 0;
    
    // Self check
    if ($logged_in_level > 0 && $target_level > 0 && $logged_in_level === $target_level) return false; 
    
    // Basic hierarchy check (cannot modify someone higher or equal)
    if ($action_type !== 'remove' && $logged_in_level <= $target_level) return false;

    // --- REMOVE LOGIC ---
    if ($action_type === 'remove') {
        // Requirement: Viewer can be removed by Super Admin (2) and above
        if ($target_role === 'viewer') {
            return $logged_in_level >= 2; 
        }
        // Admin can be removed by Super Admin (2) and above
        if ($target_role === 'admin') {
            return $logged_in_level >= 2;
        }
        return false;
    }

    // Developer Overrides
    if ($logged_in_role === 'developer') {
        if ($action_type === 'promote' && $target_new_role === 'developer') return false;
        return true; 
    }
    
    if ($logged_in_role === 'super admin') {
        // Super admin can promote viewer -> admin, or demote admin -> viewer
        if ($target_role === 'viewer' || $target_role === 'admin') return true;
        return false;
    }
    
    if ($logged_in_role === 'manager') {
        if ($action_type === 'promote' && $target_new_role) {
            if ($target_new_role === 'developer') return false;
            // Can manage admins, viewers, and promote to super admin
            return true;
        } elseif ($action_type === 'demote' && $target_new_role) {
            return ($target_role === 'super admin' && $target_new_role === 'admin') || ($target_role === 'admin' && $target_new_role === 'viewer');
        }
    }
    return false;
}

// --- CHANGE 3: Added Badge for Viewer ---
function getRoleBadge($role) {
    switch ($role) {
        case 'manager': return 'bg-purple-100 text-purple-800 border-purple-200';
        case 'super admin': return 'bg-indigo-100 text-indigo-800 border-indigo-200';
        case 'admin': return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'viewer': return 'bg-gray-200 text-gray-700 border-gray-300'; // Viewer Style
        default: return 'bg-gray-100 text-gray-800 border-gray-200';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Management</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
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

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Admin Management
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="add_admin.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-user-shield"></i> Add User
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 px-2 min-h-screen flex flex-col bg-gray-100">
    
    <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden flex flex-col">
        
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-left">
                <thead class="bg-blue-600 text-white uppercase text-xs tracking-wider sticky top-0 z-10 shadow-md">
                    <tr>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500 w-1/12">Emp ID</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500 w-3/12">Name</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500 w-2/12">Role</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500 text-center w-6/12">Actions</th>
                    </tr>
                </thead>
                <tbody id="adminTableBody" class="divide-y divide-gray-100 bg-white">
                    <?php if (!empty($admin_users)): ?>
                        <?php foreach ($admin_users as $user): 
                            $emp_id = htmlspecialchars($user['emp_id']);
                            $name = htmlspecialchars($user['calling_name'] ?? 'N/A');
                            $role = htmlspecialchars($user['role']);
                            $is_current_user = ($emp_id == $current_user_emp_id);
                            $badgeClass = getRoleBadge($role);
                        ?>
                            <tr class="hover:bg-indigo-50/50 transition duration-150 group" data-emp-id="<?php echo $emp_id; ?>" data-role="<?php echo $role; ?>">
                                <td class="px-6 py-4 font-mono text-gray-500 font-bold"><?php echo $emp_id; ?></td>
                                <td class="px-6 py-4 font-medium text-gray-800">
                                    <?php echo $name; ?>
                                    <?php if ($is_current_user): ?>
                                        <span class="ml-2 text-[10px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded border border-red-200 font-bold">YOU</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase border <?php echo $badgeClass; ?>">
                                        <?php echo $role; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <?php if ($is_current_user): ?>
                                        <span class="text-gray-400 text-xs italic bg-gray-50 px-2 py-1 rounded">No actions allowed</span>
                                    <?php else: ?>
                                        <div class="flex justify-center gap-2">
                                            <?php 
                                            // --- CHANGE 4: Promote Logic including Viewer ---
                                            $promote_role = null;
                                            switch ($role) {
                                                case 'viewer': $promote_role = 'admin'; break; // Viewer -> Admin
                                                case 'admin': $promote_role = 'super admin'; break;
                                                case 'super admin': $promote_role = 'manager'; break;
                                                case 'manager': $promote_role = 'developer'; break;
                                            }
                                            $can_promote = ($promote_role && canModify($logged_in_user_role, $role, 'promote', $promote_role));
                                            
                                            if ($can_promote): ?>
                                                <button data-id="<?php echo $emp_id; ?>" data-new-role="<?php echo $promote_role; ?>" 
                                                        class="action-btn bg-green-500 hover:bg-green-600 text-white px-3 py-1.5 rounded shadow-sm text-xs font-semibold transition flex items-center gap-1">
                                                    <i class="fas fa-arrow-up"></i> <?php echo ucfirst($promote_role); ?>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php 
                                            // --- CHANGE 5: Demote Logic including Viewer ---
                                            $demote_role = null;
                                            switch ($role) {
                                                case 'developer': $demote_role = 'manager'; break;
                                                case 'manager': $demote_role = 'super admin'; break;
                                                case 'super admin': $demote_role = 'admin'; break;
                                                case 'admin': $demote_role = 'viewer'; break; // Admin -> Viewer
                                            }
                                            $can_demote = ($demote_role && canModify($logged_in_user_role, $role, 'demote', $demote_role));

                                            if ($can_demote): ?>
                                                <button data-id="<?php echo $emp_id; ?>" data-new-role="<?php echo $demote_role; ?>" 
                                                        class="action-btn bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1.5 rounded shadow-sm text-xs font-semibold transition flex items-center gap-1">
                                                    <i class="fas fa-arrow-down"></i> <?php echo ucfirst($demote_role); ?>
                                                </button>
                                            <?php endif; ?>

                                            <?php 
                                            // Remove Logic
                                            $can_remove = canModify($logged_in_user_role, $role, 'remove');
                                            if ($can_remove): ?>
                                                <button data-id="<?php echo $emp_id; ?>" 
                                                        class="delete-admin-btn bg-red-500 hover:bg-red-600 text-white px-3 py-1.5 rounded shadow-sm text-xs font-semibold transition flex items-center gap-1">
                                                    <i class="fas fa-trash-alt"></i> Remove
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if (!$can_promote && !$can_demote && !$can_remove): ?>
                                                <span class="text-gray-400 text-xs italic">Restricted</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-gray-500 italic">
                                No admin/viewer users found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="actionConfirmModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden items-center justify-center z-[9999] backdrop-blur-sm">
    <div class="bg-white p-6 rounded-xl shadow-2xl w-96 transform transition-all scale-100 text-center">
        <div class="bg-blue-100 text-blue-600 w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-4 text-xl">
            <i class="fas fa-user-cog"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-800 mb-2" id="actionModalTitle">Confirm Change</h2>
        <div class="mb-6 text-sm text-gray-600">
            <p>Change role of <strong id="target_emp_id_display" class="text-gray-900"></strong>?</p>
            <p class="mt-1">New Role: <strong id="new_role_display" class="text-blue-600 capitalize bg-blue-50 px-2 py-0.5 rounded"></strong></p>
            <input type="hidden" id="modal_target_emp_id">
            <input type="hidden" id="modal_new_role">
        </div>
        <div class="flex justify-center gap-3">
            <button type="button" id="cancelAction" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium">Cancel</button>
            <button type="button" id="confirmAction" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium shadow-md">Confirm</button>
        </div>
    </div>
</div>

<div id="deleteAdminModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden items-center justify-center z-[9999] backdrop-blur-sm">
    <div class="bg-white p-6 rounded-xl shadow-2xl w-96 transform transition-all scale-100 text-center">
        <div class="bg-red-100 text-red-500 w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-4 text-xl">
            <i class="fas fa-user-times"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-800 mb-2">Remove User?</h2>
        <div class="mb-6 text-sm text-gray-600">
            <p>Remove privileges for <strong id="delete_emp_id_display" class="text-gray-900"></strong>?</p>
            <p class="text-xs text-red-500 mt-2">This user will lose access to the panel.</p>
            <input type="hidden" id="delete_admin_emp_id">
        </div>
        <div class="flex justify-center gap-3">
            <button type="button" id="cancelDeleteAdmin" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium">Cancel</button>
            <button type="button" id="confirmDeleteAdmin" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium shadow-md">Remove</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<script>
function showToast(message, isSuccess = true) {
    const backgroundColor = isSuccess ? "#10b981" : "#ef4444"; 
    Toastify({
        text: message,
        duration: 3000,
        close: true,
        gravity: "top",
        position: "right",
        style: {
            background: backgroundColor,
            borderRadius: "8px",
            boxShadow: "0 4px 6px -1px rgba(0, 0, 0, 0.1)",
            fontSize: "14px",
            fontWeight: "600"
        }
    }).showToast();
}

function refreshTable() { window.location.reload(); }

$(document).ready(function() {

    // Action Button Handler
    $(document).on('click', '.action-btn', function() {
        const empId = $(this).data('id');
        const newRole = $(this).data('new-role');
        const actionText = $(this).text().trim(); 

        $('#modal_target_emp_id').val(empId);
        $('#modal_new_role').val(newRole);
        $('#target_emp_id_display').text(empId);
        $('#new_role_display').text(newRole);
        $('#actionModalTitle').text(actionText.replace('Promote to ', 'Promote User').replace('Demote to ', 'Demote User'));

        $('#actionConfirmModal').removeClass('hidden').addClass('flex');
    });

    // Confirm Action
    $('#confirmAction').on('click', function() {
        const empId = $('#modal_target_emp_id').val();
        const newRole = $('#modal_new_role').val();
        $('#actionConfirmModal').removeClass('flex').addClass('hidden');

        $.ajax({
            url: 'update_admin_role.php', 
            method: 'POST',
            data: { emp_id: empId, new_role: newRole },
            dataType: 'json',
            success: function(result) { 
                if (result.success) {
                    showToast(result.message || `Role updated successfully!`, true);
                    refreshTable();
                } else {
                    showToast(result.error || `Failed to update role.`, false);
                }
            },
            error: function() { showToast("Network error.", false); }
        });
    });
    
    $('#cancelAction').on('click', function() { $('#actionConfirmModal').removeClass('flex').addClass('hidden'); });

    // Delete Handler
    $(document).on('click', '.delete-admin-btn', function() {
        const empId = $(this).data('id');
        $('#delete_admin_emp_id').val(empId);
        $('#delete_emp_id_display').text(empId);
        $('#deleteAdminModal').removeClass('hidden').addClass('flex');
    });

    // Confirm Delete
    $('#confirmDeleteAdmin').on('click', function() {
        const empIdToDelete = $('#delete_admin_emp_id').val();
        $('#deleteAdminModal').removeClass('flex').addClass('hidden');

        $.ajax({
            url: 'remove_admin_role.php', 
            method: 'POST',
            data: { emp_id: empIdToDelete },
            dataType: 'json',
            success: function(result) { 
                if (result.success) {
                    showToast(result.message || `User removed successfully!`, true);
                    refreshTable();
                } else {
                    showToast(result.error || `Failed to remove user.`, false);
                }
            },
            error: function() { showToast("Network error.", false); }
        });
    });
    
    $('#cancelDeleteAdmin').on('click', function() { $('#deleteAdminModal').removeClass('flex').addClass('hidden'); });
    
});
</script>
</body>
</html>