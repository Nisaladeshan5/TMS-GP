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

include('../../includes/db.php');

// Fetch All Reasons using SQL JOIN
$sql = "
    SELECT 
        r.reason_code, 
        r.reason, 
        r.gl_code, 
        g.gl_name 
    FROM 
        reason r
    JOIN 
        gl g ON r.gl_code = g.gl_code
    ORDER BY 
        g.gl_name, r.reason_code ASC";

$result = $conn->query($sql);

$reasons = [];
$reason_groups = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $reasons[] = $row;
        // Collect unique GL Names (Categories) for filtering
        if (!in_array($row['gl_name'], $reason_groups)) {
            $reason_groups[] = $row['gl_name'];
        }
    }
}

$conn->close();

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reason Management</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

    <style>
        body { font-family: 'Inter', sans-serif; }
        /* Custom Scrollbar for Table */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
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
            Reason Management
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-filter text-gray-400 group-focus-within:text-yellow-500 transition-colors"></i>
            </div>
            <select id="group_filter" onchange="filterReasons()"
                    class="pl-10 pr-8 py-1.5 bg-gray-800 border border-gray-600 rounded-md text-sm text-white focus:outline-none focus:ring-1 focus:ring-yellow-500 cursor-pointer appearance-none hover:bg-gray-700 transition w-64">
                <option value="">All Categories</option> 
                <?php foreach ($reason_groups as $category): ?>
                    <option value="<?php echo htmlspecialchars($category); ?>">
                        <?php echo htmlspecialchars($category); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-400">
                <i class="fas fa-chevron-down text-xs"></i>
            </div>
        </div>

        <span class="text-gray-600 text-lg font-thin">|</span>

        <a href="op_services.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
            <i class="fas fa-cogs"></i> Services
        </a>

        <a href="add_reason.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Reason
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 px-2 min-h-screen flex flex-col bg-gray-100">
    
    <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden flex flex-col h-[calc(100vh-6rem)]">
        
        <div class="overflow-auto custom-scrollbar flex-grow">
            <table class="min-w-full text-sm text-left">
                <thead class="bg-blue-600 text-white uppercase text-xs tracking-wider sticky top-0 z-10 shadow-sm">
                    <tr>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500 w-1/6">Reason Code</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500 w-2/6">Reason</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500 w-2/6">Category</th>
                        <th class="px-6 py-4 font-semibold border-b border-blue-500 w-1/6 text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="reason-table-body" class="divide-y divide-gray-100 bg-white">
                    <?php if (!empty($reasons)): ?>
                        <?php foreach ($reasons as $reason): ?>
                            <tr class="reason-item hover:bg-indigo-50/50 transition duration-150 group" 
                                data-group="<?php echo htmlspecialchars($reason['gl_name']); ?>">
                                
                                <td class="px-6 py-4 font-mono text-gray-500 font-bold">
                                    <?php echo htmlspecialchars($reason['reason_code']); ?>
                                </td>
                                
                                <td class="px-6 py-4 font-medium text-gray-800 reason-text-cell">
                                    <?php echo htmlspecialchars($reason['reason']); ?>
                                </td>
                                
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase bg-blue-50 text-blue-700 border border-blue-100">
                                        <?php echo htmlspecialchars($reason['gl_name']); ?>
                                    </span>
                                </td>
                                
                                <td class="px-6 py-4 text-center">
                                    <button class="edit-btn bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1.5 rounded-md shadow-sm transition transform hover:scale-105 flex items-center gap-1 mx-auto"
                                            data-code="<?php echo htmlspecialchars($reason['reason_code']); ?>"
                                            data-reason="<?php echo htmlspecialchars($reason['reason']); ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-gray-500 italic">
                                <div class="flex flex-col items-center justify-center">
                                    <i class="fas fa-search-minus text-4xl mb-3 text-gray-300"></i>
                                    <p>No reasons found.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="bg-gray-50 border-t border-gray-200 px-6 py-2 text-xs text-gray-500 text-right">
            Total Reasons: <?php echo count($reasons); ?>
        </div>
    </div>
</div>

<div id="editReasonModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden items-center justify-center z-[9999] backdrop-blur-sm">
    <div class="bg-white p-6 rounded-xl shadow-2xl w-96 transform transition-all scale-100">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-gray-800">Edit Reason</h2>
            <button id="cancelEdit" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="editReasonForm">
            <input type="hidden" id="edit_reason_code" name="reason_code">
            
            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Reason Code</label>
                <input type="text" id="display_reason_code" disabled class="w-full px-4 py-2 border border-gray-200 bg-gray-50 rounded-lg text-gray-500 font-mono text-sm">
            </div>

            <div class="mb-6">
                <label for="new_reason_name" class="block text-sm font-semibold text-gray-700 mb-2">Reason Name</label>
                <input type="text" id="new_reason_name" name="new_reason_name" required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition"
                       placeholder="Enter new reason...">
            </div>
            
            <div class="flex justify-end gap-3">
                <button type="button" id="cancelEditBtn" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium shadow-md">Update</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<script>
// Toast Function
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

function filterReasons() {
    const selectedCategory = document.getElementById('group_filter').value;
    const reasons = document.querySelectorAll('#reason-table-body .reason-item'); 

    reasons.forEach(reason => {
        const reasonCategory = reason.getAttribute('data-group');
        if (selectedCategory === '' || reasonCategory === selectedCategory) {
            reason.style.display = 'table-row'; 
        } else {
            reason.style.display = 'none';
        }
    });
}

$(document).ready(function() {
    // --- Edit Modal Handling ---
    $('.edit-btn').on('click', function() {
        const code = $(this).data('code');
        const reason = $(this).data('reason');

        $('#edit_reason_code').val(code);
        $('#display_reason_code').val(code);
        $('#new_reason_name').val(reason);

        $('#editReasonModal').removeClass('hidden').addClass('flex');
        setTimeout(() => $('#new_reason_name').focus(), 100);
    });

    $('#cancelEdit, #cancelEditBtn').on('click', function() {
        $('#editReasonModal').removeClass('flex').addClass('hidden');
    });

    // --- AJAX Update Submission ---
    $('#editReasonForm').on('submit', function(e) {
        e.preventDefault();
        
        const code = $('#edit_reason_code').val();
        const newReason = $('#new_reason_name').val();

        $.ajax({
            url: 'update_reason.php', // You need to create this file (see below)
            method: 'POST',
            data: { reason_code: code, reason: newReason },
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    showToast("Reason updated successfully!", true);
                    
                    // Update the table row directly without refreshing
                    const btn = $(`.edit-btn[data-code='${code}']`);
                    btn.data('reason', newReason); // Update button data attribute
                    btn.closest('tr').find('.reason-text-cell').text(newReason); // Update cell text
                    
                    $('#editReasonModal').removeClass('flex').addClass('hidden');
                } else {
                    showToast(result.message || "Failed to update reason.", false);
                }
            },
            error: function() {
                showToast("Network error. Could not update.", false);
            }
        });
    });
});
</script>

</body>
</html>