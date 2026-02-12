<?php
require_once '../../includes/session_check.php';
// user.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// Set timezone
date_default_timezone_set('Asia/Colombo');

// --- 1. Routes Table එකෙන් Dropdown එකට Data ගැනීම ---
$route_list = []; 

// 'route' table එකෙන් code එක සහ නම (route) දෙකම ගන්නවා
$route_sql = "SELECT DISTINCT route_code, route FROM route ORDER BY route_code ASC";

$route_result = $conn->query($route_sql);
if ($route_result) {
    while ($row = $route_result->fetch_assoc()) {
        $route_list[] = [
            'code' => $row['route_code'],
            'name' => $row['route']
        ];
    }
}
// ----------------------------------------------------

// Initialize filter variables
$search_emp_id = isset($_GET['search_emp_id']) ? trim($_GET['search_emp_id']) : '';

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

    <style>
        body { font-family: 'Inter', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>

<body class="bg-gray-100">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Bus Leaders Management
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <div class="relative group">
            <select id="route_filter" class="pl-3 pr-8 py-1.5 bg-gray-800 border border-gray-600 rounded-md text-sm text-white focus:outline-none focus:ring-1 focus:ring-yellow-500 transition cursor-pointer appearance-none min-w-[150px]">
                <option value="">All Routes</option>
                <?php foreach ($route_list as $r): ?>
                    <option value="<?php echo htmlspecialchars($r['code']); ?>">
                        <?php echo htmlspecialchars($r['code'] . ' - ' . $r['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none">
                <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
            </div>
        </div>

        <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-search text-gray-400 group-focus-within:text-yellow-500 transition-colors"></i>
            </div>
            <input type="text" id="search_emp_id" name="search_emp_id" 
                   value="<?php echo htmlspecialchars($search_emp_id); ?>"
                   placeholder="Search Employee ID..."
                   class="pl-10 pr-4 py-1.5 bg-gray-800 border border-gray-600 rounded-md text-sm text-white focus:outline-none focus:ring-1 focus:ring-yellow-500 w-64 transition placeholder-gray-500">
        </div>

        <span class="text-gray-600 text-lg font-thin">|</span>

        <form id="pdfForm" action="generate_qr_pdf.php" method="POST" target="_blank" class="flex items-center">
            <input type="hidden" name="selected_emp_ids" id="selectedEmpIds">
            <button type="submit" id="generateQrBtn" disabled 
                    class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-qrcode"></i> Generate QR
            </button>
        </form>

        <a href="add_user.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-user-plus"></i> Add User
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 px-2 min-h-screen flex flex-col bg-gray-100">
    <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden flex flex-col h-[calc(100vh-6rem)]">
        
        <div class="overflow-auto custom-scrollbar flex-grow">
            <table class="min-w-full text-sm text-left">
                <thead class="bg-blue-600 text-white uppercase text-xs tracking-wider sticky top-0 z-10 shadow-sm">
                    <tr>
                        <th class="px-4 py-4 text-center w-12 border-b border-blue-500">
                            <input type="checkbox" id="selectAll" class="h-4 w-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500 cursor-pointer accent-blue-200">
                        </th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500">Emp ID</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500">Name</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500">Route Code</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500">PIN</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500">Purpose</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500 text-center">QR Issued</th> 
                        <th class="px-6 py-4 font-semibold border-b border-blue-500 text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="userTableBody" class="divide-y divide-gray-100 bg-white">
                    </tbody>
            </table>
        </div>
        
        <div class="bg-gray-50 border-t border-gray-200 px-6 py-2 text-xs text-gray-500 text-right">
            Manage your users efficiently.
        </div>
    </div>
</div>

<div id="editPinModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden items-center justify-center z-[9999] backdrop-blur-sm">
    <div class="bg-white p-6 rounded-xl shadow-2xl w-96 transform transition-all scale-100">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-gray-800">Update User PIN</h2>
            <button id="cancelEdit" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-times"></i></button>
        </div>
        <form id="editPinForm">
            <input type="hidden" id="edit_user_emp_id" name="emp_id">
            <div class="mb-6">
                <label for="new_pin" class="block text-sm font-semibold text-gray-700 mb-2">New PIN (4 Digits)</label>
                <input type="password" id="new_pin" name="new_pin" required minlength="4" maxlength="4" pattern="\d{4}" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg text-center text-lg tracking-widest focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" id="cancelEditBtn" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition shadow-md">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteConfirmModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden items-center justify-center z-[9999] backdrop-blur-sm">
    <div class="bg-white p-6 rounded-xl shadow-2xl w-96 transform transition-all scale-100 text-center">
        <div class="bg-red-100 text-red-500 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-trash-alt text-2xl"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-800 mb-2">Confirm Deletion</h2>
        <p class="text-gray-500 text-sm mb-6">Are you sure you want to remove user <strong id="delete_emp_id_display" class="text-gray-800"></strong>?<br>This action cannot be undone.</p>
        <input type="hidden" id="delete_user_emp_id">
        
        <div class="flex justify-center gap-3">
            <button type="button" id="cancelDelete" class="px-5 py-2.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium">Cancel</button>
            <button type="button" id="confirmDelete" class="px-5 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium shadow-md">Delete User</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<script>
// Toast Helper
function showToast(message, isSuccess = true) {
    Toastify({ 
        text: message, 
        duration: 3000, 
        style: { 
            background: isSuccess ? "#10b981" : "#ef4444",
            borderRadius: "8px",
            boxShadow: "0 4px 6px -1px rgba(0, 0, 0, 0.1)"
        } 
    }).showToast();
}

$(document).ready(function() {
    let searchTimer = null;
    let selectedEmpIds = new Set();
    
    // --- 1. Selection Logic (Checkbox) ---
    function updateSelection() {
        const idArray = Array.from(selectedEmpIds);
        $('#selectedEmpIds').val(idArray.join(','));
        $('#generateQrBtn').prop('disabled', idArray.length === 0);
        if(idArray.length > 0) {
            $('#generateQrBtn').removeClass('opacity-50 cursor-not-allowed').addClass('hover:scale-105');
        } else {
            $('#generateQrBtn').addClass('opacity-50 cursor-not-allowed').removeClass('hover:scale-105');
        }
    }

    function bindSelectionHandlers() {
        $('#selectAll').off('change').on('change', function() {
            const isChecked = $(this).is(':checked');
            $('.emp-checkbox').prop('checked', isChecked).trigger('change'); 
        });

        $('.emp-checkbox').off('change').on('change', function() {
            const empId = $(this).val();
            if ($(this).is(':checked')) {
                selectedEmpIds.add(empId);
            } else {
                selectedEmpIds.delete(empId);
            }
            // Update Select All checkbox state
            const total = $('.emp-checkbox').length;
            const checked = $('.emp-checkbox:checked').length;
            $('#selectAll').prop('checked', total > 0 && total === checked);

            updateSelection();
        });

        // Re-check boxes if they are in the Set (when pagination/search happens)
        $('.emp-checkbox').each(function() {
            if (selectedEmpIds.has($(this).val())) {
                $(this).prop('checked', true);
            }
        });
        updateSelection(); 
    }

    // --- 2. Action Buttons Binding (The Logic) ---
    function bindActionButtons() {
        // A. Toggle Status (Pending <-> Issued)
        $('.toggle-status-btn').off('click').on('click', function() {
            const $btn = $(this);
            const empId = $btn.data('id');
            const currentStatus = Number($btn.data('status'));
            const newStatus = currentStatus === 1 ? 0 : 1;

            $btn.prop('disabled', true).addClass('opacity-75');

            $.ajax({
                url: 'toggle_qr_issued.php', // Check this file exists
                method: 'POST',
                data: { emp_id: empId, issued_status: newStatus },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        showToast(result.message || 'Status updated.', true);
                        
                        // UI Update
                        const newClass = newStatus === 1 
                            ? 'bg-green-100 text-green-700 border-green-200 hover:bg-green-200' 
                            : 'bg-yellow-100 text-yellow-700 border-yellow-200 hover:bg-yellow-200';
                        const newText = newStatus === 1 ? 'Issued' : 'Pending';
                        const newIcon = newStatus === 1 ? '<i class="fas fa-check-circle mr-1"></i>' : '<i class="fas fa-clock mr-1"></i>';

                        $btn.removeClass('bg-green-100 text-green-700 border-green-200 bg-yellow-100 text-yellow-700 border-yellow-200 hover:bg-green-200 hover:bg-yellow-200')
                            .addClass(newClass)
                            .html(newIcon + ' ' + newText)
                            .data('status', newStatus);
                    } else {
                        showToast(result.message || 'Failed to update.', false);
                    }
                },
                error: function() { showToast('Network error.', false); },
                complete: function() { $btn.prop('disabled', false).removeClass('opacity-75'); }
            });
        });
        
        // B. View PIN
        $('.view-pin-btn').off('click').on('click', function() {
            const empId = $(this).data('id');
            const pinCell = $(`#pin-${empId}`);
            if ($(this).data('visible')) {
                pinCell.text(pinCell.data('masked-pin'));
                $(this).data('visible', false).find('i').removeClass('fa-eye-slash').addClass('fa-eye');
            } else {
                pinCell.text(pinCell.closest('tr').data('pin'));
                $(this).data('visible', true).find('i').removeClass('fa-eye').addClass('fa-eye-slash');
            }
        });

        // C. Open Edit Modal
        $('.edit-pin-btn').click(function() {
            $('#edit_user_emp_id').val($(this).data('id'));
            $('#new_pin').val('');
            $('#editPinModal').removeClass('hidden').addClass('flex');
            setTimeout(() => $('#new_pin').focus(), 100);
        });

        // D. Open Delete Modal
        $('.delete-user-btn').click(function() {
            const empId = $(this).data('id');
            $('#delete_user_emp_id').val(empId);
            $('#delete_emp_id_display').text(empId);
            $('#deleteConfirmModal').removeClass('hidden').addClass('flex');
        });
    }

    // --- 3. Fetch Users Logic ---
    function fetchUsers(query) {
        const selectedRoute = $('#route_filter').val(); 

        $('#userTableBody').html('<tr><td colspan="8" class="py-8 text-center text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i> Loading...</td></tr>'); 

        $.ajax({
            url: 'fetch_users.php', 
            method: 'GET',
            data: { q: query, route: selectedRoute }, 
            success: function(response) {
                $('#userTableBody').html(response); 
                bindActionButtons();      
                bindSelectionHandlers(); 
            },
            error: function() {
                $('#userTableBody').html('<tr><td colspan="8" class="py-8 text-center text-red-500">Failed to load data.</td></tr>');
            }
        });
    }

    // --- 4. Event Listeners ---
    
    // Search
    $('#search_emp_id').on('input', function() {
        const query = $(this).val();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { fetchUsers(query); }, 300);
    });
    
    // Dropdown
    $('#route_filter').on('change', function() {
        fetchUsers($('#search_emp_id').val());
    });

    // Close Modals
    $('#cancelEdit, #cancelEditBtn').click(() => $('#editPinModal').addClass('hidden').removeClass('flex'));
    $('#cancelDelete').click(() => $('#deleteConfirmModal').addClass('hidden').removeClass('flex'));

    // Submit Edit PIN
    $('#editPinForm').on('submit', function(e) {
        e.preventDefault();
        const empId = $('#edit_user_emp_id').val();
        const newPin = $('#new_pin').val();

        if (!/^\d{4}$/.test(newPin)) {
             showToast("PIN must be exactly 4 digits.", false);
             return;
        }

        $.ajax({
            url: 'update_user_pin.php', 
            method: 'POST',
            data: { emp_id: empId, new_pin: newPin },
            dataType: 'json',
            success: function(result) { 
                $('#editPinModal').removeClass('flex').addClass('hidden');
                if (result.success) {
                    showToast("PIN updated successfully!", true);
                    fetchUsers($('#search_emp_id').val()); 
                } else {
                    showToast(result.error || "Failed to update.", false);
                }
            }
        });
    });

    // Submit Delete
    $('#confirmDelete').on('click', function() {
        const empId = $('#delete_user_emp_id').val();
        $.ajax({
            url: 'delete_user.php',
            method: 'POST',
            data: { emp_id: empId },
            dataType: 'json',
            success: function(result) {
                $('#deleteConfirmModal').removeClass('flex').addClass('hidden');
                if (result.success) {
                    showToast("User deleted successfully!", true);
                    fetchUsers($('#search_emp_id').val());
                } else {
                    showToast(result.message || "Failed to delete.", false);
                }
            }
        });
    });

    // Initial Load
    fetchUsers($('#search_emp_id').val());
});
</script>
</body>
</html>