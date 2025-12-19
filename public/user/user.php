<?php
require_once '../../includes/session_check.php';
// user.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Include necessary files
// IMPORTANT: Ensure db.php, header.php, and navbar.php output NO HTML before the <html> tag.
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Set timezone
date_default_timezone_set('Asia/Colombo');

// Initialize filter variables
$search_emp_id = isset($_GET['search_emp_id']) ? trim($_GET['search_emp_id']) : '';

// *** DO NOT include the AJAX PHP handlers here. They belong in separate files. ***
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

    <style>
		
        .toggle-qr-issued:checked ~ div:nth-of-type(1) {
            background-color: #3b82f6; /* Blue-500 */
        }
        .toggle-qr-issued:checked ~ .dot {
            transform: translateX(0%);
            background-color: #ffffff; /* White dot when checked */
        }
        .dot {
            transition: transform 0.2s ease-in-out;
            /* Base position for 'Not Issued' */
            transform: translateX(0); 
            background-color: #ffffff;
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

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">User Management</div>
    <div class="flex gap-4">
        <a href="add_user.php" class="hover:text-yellow-600">Add User</a>
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[36px] font-bold text-gray-800 mt-2">User Details</p>

    <div class="flex justify-between items-center mb-6 w-full">
        <form id="searchForm" class="flex items-center">
            <label for="search_emp_id" class="text-lg font-medium mr-2">Search Employee ID:</label>
            <input type="text" id="search_emp_id" name="search_emp_id" 
                    value="<?php echo htmlspecialchars($search_emp_id); ?>"
                    placeholder="Enter Employee ID"
                    class="border border-gray-300 p-2 rounded-md w-64">
        </form>

        <form id="pdfForm" action="generate_qr_pdf.php" method="POST" target="_blank">
            <input type="hidden" name="selected_emp_ids" id="selectedEmpIds">
            <button type="submit" id="generateQrBtn" disabled 
                    class="bg-purple-600 text-white px-6 py-2 rounded-md hover:bg-purple-700 disabled:opacity-50">
                <i class="fas fa-qrcode mr-2"></i>Generate QR Labels
            </button>
        </form>
    </div>
    
    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6 w-full">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-2 py-2 text-center w-12">
                        <input type="checkbox" id="selectAll" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                    </th>
                    <th class="px-4 py-2 text-left">Emp ID</th>
                    <th class="px-4 py-2 text-left">Route Code</th>
                    <th class="px-4 py-2 text-left">PIN</th>
                    <th class="px-4 py-2 text-left">Purpose</th>
                    <th class="px-4 py-2 text-center">QR Issued</th> 
					<th class="px-4 py-2 text-center">Action</th>
                </tr>
            </thead>
            <tbody id="userTableBody">
                </tbody>
        </table>
    </div>
</div>

<div id="editPinModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white p-6 rounded-lg shadow-xl w-96">
        <h2 class="text-xl font-bold mb-4">Edit User PIN</h2>
        <form id="editPinForm">
            <input type="hidden" id="edit_user_emp_id" name="emp_id">
            <div class="mb-4">
                <label for="new_pin" class="block text-sm font-medium text-gray-700">New PIN (4 digits):</label>
                <input type="password" id="new_pin" name="new_pin" required minlength="4" maxlength="4" pattern="\d{4}"
                        class="mt-1 block w-full border border-gray-300 p-2 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div class="flex justify-end space-x-2">
                <button type="button" id="cancelEdit" class="bg-gray-300 text-gray-800 px-4 py-2 rounded-md hover:bg-gray-400">Cancel</button>
                <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded-md hover:bg-green-600">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteConfirmModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 hidden items-center justify-center z-50">
    <div class="bg-white p-6 rounded-lg shadow-xl w-96">
        <h2 class="text-xl font-bold mb-4 text-gray-800">Confirm Deletion</h2>
        
        <div class="mb-4">
            <p class="text-gray-700">Are you sure you want to delete user: <strong id="delete_emp_id_display" class="text-red-600"></strong>?</p>
            <p class="text-sm text-gray-500 mt-1">This action cannot be undone.</p>
            <input type="hidden" id="delete_user_emp_id">
        </div>
        
        <div class="flex justify-end space-x-2">
            <button type="button" id="cancelDelete" class="bg-gray-300 text-gray-800 px-4 py-2 rounded-md hover:bg-gray-400">Cancel</button>
            <button type="button" id="confirmDelete" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">Delete</button>
        </div>
    </div>
</div>

</body>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<script>
// --- JQUERY EXTENSION TO SET CURSOR POSITION (Essential for smooth search) ---
jQuery.fn.setCursorPosition = function(pos) {
    this.each(function(index, elem) {
        if (elem.setSelectionRange) {
            elem.setSelectionRange(pos, pos);
        } else if (elem.createTextRange) {
            var range = elem.createTextRange();
            range.collapse(true);
            range.moveEnd('character', pos);
            range.moveStart('character', pos);
            range.select();
        }
    });
    return this;
};

// Function to handle the Toast messages
function showToast(message, isSuccess = true) {
    const iconSVG = isSuccess
        ? `<svg class="w-5 h-5 mr-2 text-green-100" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
             <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
           </svg>`
        : `<svg class="w-5 h-5 mr-2 text-red-100" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
             <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
           </svg>`;

    const backgroundColor = isSuccess ? "#22c55e" : "#ef4444"; // Tailwind green-500 / red-500
    const textColor = "#ffffff";

    Toastify({
        text: `
            <div style="display: flex; align-items: center;">
                ${iconSVG}
                <span style="flex: 1;">${message}</span>
            </div>
        `,
        duration: isSuccess ? 3000 : 5000,
        close: true,
        gravity: "top",
        position: "right",
        stopOnFocus: true,
        escapeMarkup: false, // allows HTML inside the toast
        style: {
            background: backgroundColor,
            color: textColor,
            padding: "12px 16px",
            borderRadius: "10px",
            fontSize: "15px",
            fontWeight: "500",
            boxShadow: "0 4px 14px rgba(0,0,0,0.1)",
            display: "flex",
            alignItems: "center",
            gap: "8px",
        }
    }).showToast();
}


$(document).ready(function() {
    
    let searchTimer = null; // Debounce timer
    
    // Selection Management Variables
    let selectedEmpIds = new Set();
    
    function updateSelection() {
        const idArray = Array.from(selectedEmpIds);
        $('#selectedEmpIds').val(idArray.join(','));
        $('#generateQrBtn').prop('disabled', idArray.length === 0);
    }

    // Function to re-bind selection handlers
    function bindSelectionHandlers() {
        // Master Checkbox
        $('#selectAll').off('change').on('change', function() {
            const isChecked = $(this).is(':checked');
            // Trigger change on all individuals to update the Set correctly
            $('.emp-checkbox').prop('checked', isChecked).trigger('change'); 
        });

        // Individual Checkbox
        $('.emp-checkbox').off('change').on('change', function() {
            const empId = $(this).val();
            
            if ($(this).is(':checked')) {
                selectedEmpIds.add(empId);
            } else {
                selectedEmpIds.delete(empId);
            }
            
            // Sync master checkbox state 
            const total = $('.emp-checkbox').length;
            const checked = $('.emp-checkbox:checked').length;
            $('#selectAll').prop('checked', total > 0 && total === checked);

            updateSelection();
        });

        // Re-check previously selected boxes after AJAX load
        $('.emp-checkbox').each(function() {
            if (selectedEmpIds.has($(this).val())) {
                $(this).prop('checked', true);
            }
        });
        updateSelection(); 
    }

    // Function to re-bind action button events after the table is updated
    function bindActionButtons() {
        // 1. View PIN Button Handler (Existing Logic)
        $('.view-pin-btn').off('click').on('click', function() {
            const empId = $(this).data('id');
            // The PIN cell has the unique ID 'pin-EMP_ID' 
            const pinCell = $(`#pin-${empId}`); 
            const isVisible = $(this).data('is-visible') === 'true';
            
            if (isVisible) {
                // Hide PIN: Use data-masked-pin attribute
                pinCell.text(pinCell.data('masked-pin'));
                $(this).data('is-visible', 'false');
                $(this).find('i').removeClass('fa-eye-slash').addClass('fa-eye');
                $(this).attr('title', 'View PIN');
            } else {
                // Show PIN: Use data-pin attribute from the table row (TR)
                const actualPin = pinCell.closest('tr').data('pin'); 
                pinCell.text(actualPin);
                $(this).data('is-visible', 'true');
                $(this).find('i').removeClass('fa-eye').addClass('fa-eye-slash');
                $(this).attr('title', 'Hide PIN');
            }
        });

        // 2. Edit PIN Button Handler (Existing Logic)
        $('.edit-pin-btn').off('click').on('click', function() {
            const empId = $(this).data('id');
            $('#edit_user_emp_id').val(empId);
            $('#new_pin').val('');
            $('#editPinModal').removeClass('hidden').addClass('flex');
            $('#new_pin').focus();
        });
        
        // 3. Delete User Button Handler (Existing Logic)
        $('.delete-user-btn').off('click').on('click', function() {
            const empId = $(this).data('id');
            $('#delete_user_emp_id').val(empId);
            $('#delete_emp_id_display').text(empId);
            $('#deleteConfirmModal').removeClass('hidden').addClass('flex');
        });

        $('.toggle-status-btn').off('click').on('click', function() {
			const $btn = $(this);
			const empId = $btn.data('id');
			const currentStatus = Number($btn.data('status'));
			const newStatus = currentStatus === 1 ? 0 : 1;

			// Optimistic UI update
			$btn.prop('disabled', true).text('Updating...');

			$.ajax({
				url: 'toggle_qr_issued.php',
				method: 'POST',
				data: { emp_id: empId, issued_status: newStatus },
				dataType: 'json',
				success: function(result) {
					if (result.success) {
						showToast(result.message || 'Status updated.', true);

						// Update button appearance & status
						const newClass = newStatus === 1 ? 'bg-green-500 hover:bg-green-600' : 'bg-red-500 hover:bg-red-600';
						const newText = newStatus === 1 ? 'Issued' : 'Not Issued';

						$btn.removeClass('bg-green-500 hover:bg-green-600 bg-red-500 hover:bg-red-600')
							.addClass(newClass)
							.text(newText)
							.data('status', newStatus);
					} else {
						showToast(result.message || 'Failed to update status.', false);
					}
				},
				error: function() {
					showToast('Network error: Could not update status.', false);
				},
				complete: function() {
					$btn.prop('disabled', false);
				}
			});
		});
    }

    // Function to handle the AJAX request and update the table
    function fetchUsers(query, cursorPosition) {
        // Show loading indicator
        // Colspan is now 7: (Checkbox, Emp ID, Route Code, PIN, Purpose, QR Issued, Action)
        $('#userTableBody').html('<tr><td colspan="7" class="border px-4 py-2 text-center text-gray-500">Searching...</td></tr>'); 

        $.ajax({
            url: 'fetch_users.php', 
            method: 'GET',
            data: { q: query }, 
            success: function(response) {
                $('#userTableBody').html(response); 
                bindActionButtons();      // RE-BIND: Re-enable Edit/View/Delete/Toggle
                bindSelectionHandlers(); // RE-BIND: Re-enable Checkboxes
            },
            error: function() {
                showToast("Error fetching user data.", false);
                $('#userTableBody').html('<tr><td colspan="7" class="border px-4 py-2 text-center text-red-500">Failed to load data.</td></tr>');
            },
            complete: function() {
                // Restore focus and cursor position after the update
                const searchInput = $('#search_emp_id');
                searchInput.focus();
                searchInput.setCursorPosition(cursorPosition !== undefined ? cursorPosition : searchInput.val().length); 
            }
        });
    }


    // --- AJAX LIVE SEARCH IMPLEMENTATION ---
    $('#search_emp_id').on('input', function() {
        const query = $(this).val();
        const cursorPosition = this.selectionStart; 

        clearTimeout(searchTimer);
        
        searchTimer = setTimeout(function() {
            fetchUsers(query, cursorPosition);
        }, 300);
    });
    
    // Initial Load: Load data when the page first loads
    const initialSearchValue = $('#search_emp_id').val();
    fetchUsers(initialSearchValue);

    // --- MODAL & FORM LOGIC (Edit/Delete) ---
    
    // Cancel Edit
    $('#cancelEdit').on('click', function() {
        $('#editPinModal').removeClass('flex').addClass('hidden');
    });

    // Submit Edit
    $('#editPinForm').on('submit', function(e) {
        e.preventDefault();
        const empId = $('#edit_user_emp_id').val();
        const newPin = $('#new_pin').val();

        if (!/^\d{4}$/.test(newPin)) {
             showToast("PIN must be exactly 4 numeric digits.", false);
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
                    showToast(`PIN for ${empId} successfully updated!`, true);
                    fetchUsers($('#search_emp_id').val()); 
                } else {
                    showToast(result.error || `Failed to update PIN for ${empId}.`, false);
                }
            },
            error: function() {
                $('#editPinModal').removeClass('flex').addClass('hidden');
                showToast("Network error: Could not connect to the PIN update server.", false);
            }
        });
    });
    
    // Cancel Delete
    $('#cancelDelete').on('click', function() {
        $('#deleteConfirmModal').removeClass('flex').addClass('hidden');
    });
    
    // Confirm Delete
    $('#confirmDelete').on('click', function() {
        const empIdToDelete = $('#delete_user_emp_id').val();
        
        // AJAX call to delete_user.php
        $.ajax({
            url: 'delete_user.php', 
            method: 'POST',
            data: { emp_id: empIdToDelete },
            dataType: 'json',
            success: function(result) { 
                $('#deleteConfirmModal').removeClass('flex').addClass('hidden');
                if (result.success) {
                    showToast(result.message || `User ${empIdToDelete} deleted successfully!`, true);
                    fetchUsers($('#search_emp_id').val()); 
                } else {
                    showToast(result.message || `Failed to delete user ${empIdToDelete}.`, false);
                }
            },
            error: function() {
                $('#deleteConfirmModal').removeClass('flex').addClass('hidden');
                showToast("Network error: Could not connect to the deletion server.", false);
            }
        });
    });
    
});
</script>
</html>
<?php
// Omit closing PHP tag