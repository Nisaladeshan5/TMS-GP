<?php
// admin.php (Complete Code with Frontend Logic and JS)
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
// **සැකසුම:** ඔබගේ db.php, header.php, navbar.php ගොනු වල මාර්ගය නිවැරදිව පරීක්ෂා කරන්න.
include('../../includes/db.php'); // Database connection - Assumed MySQLi
include('../../includes/header.php'); // Assumed HTML head opening tags/styles
include('../../includes/navbar.php'); // Assumed sidebar/navigation bar

date_default_timezone_set('Asia/Colombo');

$logged_in_user_role = $_SESSION['user_role'] ?? 'admin'; 
$current_user_emp_id = $_SESSION['user_emp_id'] ?? null; 

// 🔑 FINAL Role Hierarchy: (1 = lowest, 4 = highest)
$role_hierarchy = [
    'admin' => 1,
    'super admin' => 2,
    'manager' => 3,
    'developer' => 4, // Developer is the highest
];

// Function to fetch all admin users
function fetchAdminUsers($conn) {
    $sql = "SELECT 
                a.emp_id, 
                a.role, 
                e.calling_name 
            FROM 
                admin a
            LEFT JOIN 
                employee e ON a.emp_id = e.emp_id
            WHERE a.role != 'developer'
            ORDER BY a.emp_id ASC";
                
    $result = $conn->query($sql);

    if ($result === false) {
        error_log("Database Query Error: " . $conn->error);
        return [];
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    $result->free(); 
    return $data;
}

try {
    $admin_users = fetchAdminUsers($conn);
} catch (Exception $e) {
    $admin_users = [];
    error_log("Admin fetch error: " . $e->getMessage());
}

/**
 * Checks if the logged-in user has permission to perform a specific modification on a target user based on the NEW strict rules.
 * LATEST UPDATE: Prevents ANY promotion to the 'developer' role.
 */
function canModify($logged_in_role, $target_role, $action_type, $target_new_role = null) {
    global $role_hierarchy;

    $logged_in_level = $role_hierarchy[$logged_in_role] ?? 0;
    $target_level = $role_hierarchy[$target_role] ?? 0;
    
    // Rule 0: Cannot modify your own role
    if ($logged_in_level > 0 && $target_level > 0 && $logged_in_level === $target_level) {
        return false; 
    }
    
    // Rule 1: Logged-in user must be strictly higher level than the target user's current level (for Promote/Demote)
    if ($action_type !== 'remove' && $logged_in_level <= $target_level) {
        return false;
    }
    
    // --- SPECIAL RULE FOR REMOVE (Remove 'admin' (L1) only) ---
    if ($action_type === 'remove') {
        // Remove allowed ONLY if the target is 'admin' (L1) AND the logged-in user is higher than 'admin' (L2, L3, L4).
        return ($logged_in_level > 1 && $target_role === 'admin');
    }
    // ------------------------------------------------

    // A. Developer (L4): Can Promote/Demote anyone below them (L1, L2, L3)
    if ($logged_in_role === 'developer') {
        
        // **NEW RULE CHECK for DEVELOPER:** The system cannot promote anyone TO a Developer (L4).
        // This stops the Developer from having a 'Promote' button appear for a Manager (L3).
        if ($action_type === 'promote' && $target_new_role === 'developer') {
            return false;
        }

        // Developer can demote L4 to L3, or L3 to L2, or L2 to L1.
        return true; 
    }

    // B. Admin (L1) - Cannot do anything

    // C. Super Admin (L2) - Cannot Promote or Demote anyone
    if ($logged_in_role === 'super admin') {
        return false;
    }
    
    // D. Manager (L3) Specific Rules (Can only manage L1 and L2)
    if ($logged_in_role === 'manager') {
        
        if ($action_type === 'promote' && $target_new_role) {
            
            // **IMPLEMENTING NEW RULE:** Cannot promote anyone TO 'developer'.
            if ($target_new_role === 'developer') {
                return false;
            }
            
            // Can Promote Admin (L1 -> L2) OR Super Admin (L2 -> L3)
            return (
                ($target_role === 'admin' && $target_new_role === 'super admin') || 
                ($target_role === 'super admin' && $target_new_role === 'manager')
            );
        } elseif ($action_type === 'demote' && $target_new_role) {
            // Can Demote Super Admin (L2 -> L1)
            return ($target_role === 'super admin' && $target_new_role === 'admin');
        }
    }

    return false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Management</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
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

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Admin Management</div>
    <div class="flex gap-4">
        <a href="add_admin.php" class="hover:text-yellow-600">Add Admin</a>
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[36px] font-bold text-gray-800 mt-2">Admin User Details</p>

    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6 w-full mt-4">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left w-1/12">Emp ID</th>
                    <th class="px-4 py-2 text-left w-2/12">Name</th>
                    <th class="px-4 py-2 text-left w-2/12">Role</th>
                    <th class="px-4 py-2 text-center w-7/12">Actions</th>
                </tr>
            </thead>
            <tbody id="adminTableBody">
                <?php if (!empty($admin_users)): ?>
                    <?php foreach ($admin_users as $user): 
                        $emp_id = htmlspecialchars($user['emp_id']);
                        $name = htmlspecialchars($user['calling_name'] ?? 'N/A');
                        $role = htmlspecialchars($user['role']);
                        $is_current_user = ($emp_id == $current_user_emp_id);
                    ?>
                        <tr class="border-b hover:bg-gray-50" data-emp-id="<?php echo $emp_id; ?>" data-role="<?php echo $role; ?>">
                            <td class="px-4 py-3"><?php echo $emp_id; ?></td>
                            <td class="px-4 py-3"><?php echo $name; ?></td>
                            <td class="px-4 py-3 font-semibold capitalize">
                                <?php echo $role; ?>
                                <?php if ($is_current_user): ?>
                                    <span class="text-xs text-red-500">(You)</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($is_current_user): ?>
                                    <span class="text-gray-500 italic">Cannot modify your own role</span>
                                <?php else: ?>
                                    <?php 
                                    // --- 1. Promote Logic ---
                                    $promote_role = null;
                                    switch ($role) {
                                        case 'admin':
                                            $promote_role = 'super admin'; // 1 -> 2
                                            break;
                                        case 'super admin':
                                            $promote_role = 'manager'; // 2 -> 3
                                            break;
                                        case 'manager':
                                            $promote_role = 'developer'; // 3 -> 4 (This will now be blocked by canModify)
                                            break;
                                        default:
                                            break;
                                    }

                                    // Check if a promotion role exists AND the logged-in user can modify
                                    $can_promote = ($promote_role && canModify($logged_in_user_role, $role, 'promote', $promote_role));
                                    
                                    if ($can_promote): ?>
                                        <button data-id="<?php echo $emp_id; ?>" data-new-role="<?php echo $promote_role; ?>" class="action-btn bg-blue-500 text-white px-3 py-1 rounded-md hover:bg-blue-600 mr-2">
                                            Promote to <?php echo ucfirst($promote_role); ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // --- 2. Demote Logic ---
                                    $demote_role = null;
                                    switch ($role) {
                                        case 'developer':
                                            $demote_role = 'manager'; // 4 -> 3
                                            break;
                                        case 'manager':
                                            $demote_role = 'super admin'; // 3 -> 2
                                            break;
                                        case 'super admin':
                                            $demote_role = 'admin'; // 2 -> 1
                                            break;
                                        default:
                                            break;
                                    }

                                    // Check if a demotion role exists AND the logged-in user can modify
                                    $can_demote = ($demote_role && canModify($logged_in_user_role, $role, 'demote', $demote_role));

                                    if ($can_demote): ?>
                                        <button data-id="<?php echo $emp_id; ?>" data-new-role="<?php echo $demote_role; ?>" class="action-btn bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600 mr-2">
                                            Demote to <?php echo ucfirst($demote_role); ?>
                                        </button>
                                    <?php endif; ?>

                                    <?php 
                                    // --- 3. Remove Logic ---
                                    // Button is visible if the logged-in user (L2, L3, L4) can remove the target (L1 only).
                                    $can_remove = canModify($logged_in_user_role, $role, 'remove');

                                    if ($can_remove): ?>
                                        <button data-id="<?php echo $emp_id; ?>" class="delete-admin-btn bg-red-600 text-white px-3 py-1 rounded-md hover:bg-red-700">
                                            Remove Admin
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // If no actions are available
                                    if (!$can_promote && !$can_demote && !$can_remove): ?>
                                        <span class="text-gray-500 italic">No available actions</span>
                                    <?php endif; ?>

                                <?php endif; // End check for is_current_user ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-center text-gray-500">No admin users found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="actionConfirmModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 hidden items-center justify-center z-50">
    <div class="bg-white p-6 rounded-lg shadow-xl w-96">
        <h2 class="text-xl font-bold mb-4 text-gray-800" id="actionModalTitle">Confirm Role Change</h2>
        <div class="mb-4">
            <p class="text-gray-700">Are you sure you want to change the role of user <strong id="target_emp_id_display" class="text-blue-600"></strong> to <strong id="new_role_display" class="text-green-600 capitalize"></strong>?</p>
            <input type="hidden" id="modal_target_emp_id">
            <input type="hidden" id="modal_new_role">
        </div>
        <div class="flex justify-end space-x-2">
            <button type="button" id="cancelAction" class="bg-gray-300 text-gray-800 px-4 py-2 rounded-md hover:bg-gray-400">Cancel</button>
            <button type="button" id="confirmAction" class="bg-green-500 text-white px-4 py-2 rounded-md hover:bg-green-600">Confirm</button>
        </div>
    </div>
</div>

<div id="deleteAdminModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 hidden items-center justify-center z-50">
    <div class="bg-white p-6 rounded-lg shadow-xl w-96">
        <h2 class="text-xl font-bold mb-4 text-gray-800">Confirm Removal</h2>
        <div class="mb-4">
            <p class="text-gray-700">Are you sure you want to remove the admin role for user: <strong id="delete_emp_id_display" class="text-red-600"></strong>?</p>
            <p class="text-sm text-gray-500 mt-1">This will delete the entry from the `admin` table.</p>
            <input type="hidden" id="delete_admin_emp_id">
        </div>
        <div class="flex justify-end space-x-2">
            <button type="button" id="cancelDeleteAdmin" class="bg-gray-300 text-gray-800 px-4 py-2 rounded-md hover:bg-gray-400">Cancel</button>
            <button type="button" id="confirmDeleteAdmin" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">Remove</button>
        </div>
    </div>
</div>


</body>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<script>
// --- Toast Function (for notifications) ---
function showToast(message, isSuccess = true) {
    const iconSVG = isSuccess
        ? `<svg class="w-5 h-5 mr-2 text-green-100" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
             <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
           </svg>`
        : `<svg class="w-5 h-5 mr-2 text-red-100" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
             <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
           </svg>`;

    const backgroundColor = isSuccess ? "#22c55e" : "#ef4444"; 

    Toastify({
        text: `<div style="display: flex; align-items: center;">${iconSVG}<span style="flex: 1;">${message}</span></div>`,
        duration: isSuccess ? 3000 : 5000,
        close: true,
        gravity: "top",
        position: "right",
        stopOnFocus: true,
        escapeMarkup: false, 
        style: {
            background: backgroundColor,
            color: "#ffffff",
            padding: "12px 16px",
            borderRadius: "10px",
            fontSize: "15px",
            fontWeight: "500",
            boxShadow: "0 4px 14px rgba(0,0,0,0.1)", 
            display: "flex",
            alignItems: "center",
            gap: "8px"
        }
    }).showToast();
}

// Function to refresh the page/table
function refreshTable() {
    // Reloads the page to reflect the new roles and button states
    window.location.reload(); 
}

$(document).ready(function() {

    // --- Action Button Handler (Promote/Demote) ---
    $(document).on('click', '.action-btn', function() {
        const empId = $(this).data('id');
        const newRole = $(this).data('new-role');
        const actionText = $(this).text().trim(); 

        $('#modal_target_emp_id').val(empId);
        $('#modal_new_role').val(newRole);
        $('#target_emp_id_display').text(empId);
        $('#new_role_display').text(newRole);
        $('#actionModalTitle').text(actionText); // Title matches the button text

        // Show modal
        $('#actionConfirmModal').removeClass('hidden').addClass('flex');
    });

    // --- Action Confirmation Logic (Calls update_admin_role.php) ---
    $('#confirmAction').on('click', function() {
        const empId = $('#modal_target_emp_id').val();
        const newRole = $('#modal_new_role').val();
        
        // Hide modal
        $('#actionConfirmModal').removeClass('flex').addClass('hidden');

        $.ajax({
            url: 'update_admin_role.php', 
            method: 'POST',
            data: { emp_id: empId, new_role: newRole },
            dataType: 'json',
            success: function(result) { 
                if (result.success) {
                    showToast(result.message || `Role for ${empId} successfully changed to ${newRole}!`, true);
                    refreshTable();
                } else {
                    showToast(result.error || `Failed to update role for ${empId}.`, false);
                }
            },
            error: function() {
                showToast("Network error: Could not connect to the role update server.", false);
            }
        });
    });
    
    // Cancel Action
    $('#cancelAction').on('click', function() {
        $('#actionConfirmModal').removeClass('flex').addClass('hidden');
    });

    // --- Delete Button Handler (Remove Admin) ---
    $(document).on('click', '.delete-admin-btn', function() {
        const empId = $(this).data('id');
        $('#delete_admin_emp_id').val(empId);
        $('#delete_emp_id_display').text(empId);
        
        // Show delete modal
        $('#deleteAdminModal').removeClass('hidden').addClass('flex');
    });

    // --- Delete Confirmation Logic (Calls remove_admin_role.php) ---
    $('#confirmDeleteAdmin').on('click', function() {
        const empIdToDelete = $('#delete_admin_emp_id').val();
        
        // Hide modal
        $('#deleteAdminModal').removeClass('flex').addClass('hidden');

        $.ajax({
            url: 'remove_admin_role.php', 
            method: 'POST',
            data: { emp_id: empIdToDelete },
            dataType: 'json',
            success: function(result) { 
                if (result.success) {
                    showToast(result.message || `Admin role for user ${empIdToDelete} removed successfully!`, true);
                    refreshTable();
                } else {
                    showToast(result.error || `Failed to remove admin role for ${empIdToDelete}.`, false);
                }
            },
            error: function() {
                showToast("Network error: Could not connect to the deletion server.", false);
            }
        });
    });
    
    // Cancel Delete
    $('#cancelDeleteAdmin').on('click', function() {
        $('#deleteAdminModal').removeClass('flex').addClass('hidden');
    });
    
});
</script>
</html>