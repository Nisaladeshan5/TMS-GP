<?php
// day_heldup_edit_reasons.php

// Ensure session starts early and output buffering is on globally for safety
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();

// --- CRITICAL AJAX HANDLER AT THE TOP ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reason'])) {
    // Include DB and other files only when we know it's an AJAX request
    include('../../../includes/db.php');
    date_default_timezone_set('Asia/Colombo');

    // Clean any prior output (especially helpful if a warning/space was generated)
    if (ob_get_length() > 0) ob_clean(); 
    header('Content-Type: application/json');

    $action_reason = $_POST['action_reason'];
    $response = ['success' => false, 'message' => 'Invalid Request.'];

    try {
        // Retrieve context data from hidden fields
        $target_trip_id = (int)($_POST['trip_id_hidden'] ?? $_POST['trip_id_context'] ?? 0); 
        $record_id = (int)($_POST['record_id'] ?? 0); // Used for delete

        if ($target_trip_id === 0) {
            throw new Exception("Trip ID context lost or missing.");
        }

        // --- ADD REASON LOGIC (Remains the same as it stores reason_code) ---
        if ($action_reason === 'add_reason') {
            $emp_id = strtoupper(trim($_POST['emp_id'] ?? ''));
            $reason_code = trim($_POST['reason_code'] ?? '');

            if (empty($emp_id) || empty($reason_code)) {
                throw new Exception("Employee ID and Reason must be selected.");
            }

            $insert_sql = "INSERT INTO dh_emp_reason (trip_id, emp_id, reason_code) VALUES (?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("iss", $target_trip_id, $emp_id, $reason_code);

            if (!$insert_stmt->execute()) {
                if ($conn->errno == 1062) {
                    throw new Exception("Error: This employee/reason combination is already added for this trip.");
                } else {
                    throw new Exception("DB Insertion Failed: " . $insert_stmt->error);
                }
            }
            $insert_stmt->close();
            $response['success'] = true;
            $response['message'] = "Reason added successfully for Employee {$emp_id}.";

        // --- DELETE REASON LOGIC (Remains the same as it uses ID) ---
        } elseif ($action_reason === 'delete_reason') {
            
            if ($record_id === 0) {
                throw new Exception("Record ID missing for deletion.");
            }

            $delete_sql = "DELETE FROM dh_emp_reason WHERE id = ? AND trip_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("ii", $record_id, $target_trip_id);
            
            if (!$delete_stmt->execute()) {
                throw new Exception("DB Deletion Failed: " . $delete_stmt->error);
            }
            $delete_stmt->close();
            $response['success'] = true;
            $response['message'] = "Reason record deleted successfully.";

        } else {
            throw new Exception("Invalid reason action specified.");
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    if (isset($conn)) $conn->close();
    echo json_encode($response);
    exit();
}


// --- STANDARD PAGE LOAD ---

// Proceed with standard session checks and includes for HTML generation
require_once '../../../includes/session_check.php';
// Re-check login status if not already set (safety measure)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

$target_trip_id = (int)($_GET['trip_id'] ?? 0);

if ($target_trip_id === 0) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Error: Trip ID not specified for editing.</div>");
}

// Check if user role allows action (for display consistency)
$user_role = $_SESSION['user_role'] ?? 'guest';
$can_edit = in_array($user_role, ['super admin', 'admin', 'developer', 'manager']); 

if (!$can_edit) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Permission Denied to edit records.</div>");
}

// --- 1. Fetch Trip Base Data (Remains the same) ---
$trip_sql = "
    SELECT 
        dhr.op_code, dhr.vehicle_no, dhr.date, dhr.out_time, dhr.in_time, dhr.done
    FROM 
        day_heldup_register dhr
    WHERE dhr.trip_id = ? 
    LIMIT 1
";
$stmt = $conn->prepare($trip_sql);
$stmt->bind_param("i", $target_trip_id);
$stmt->execute();
$trip_details = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$trip_details) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Error: Trip ID {$target_trip_id} not found.</div>");
}

// --- 2. Fetch Existing Employee Reasons (UPDATED: JOIN with gl to get gl_name) ---
$existing_reasons_sql = "
    SELECT 
        dher.id,
        dher.emp_id,
        dher.reason_code,
        r.reason,
        g.gl_name AS reason_category
    FROM 
        dh_emp_reason dher
    JOIN 
        reason r ON dher.reason_code = r.reason_code
    LEFT JOIN
        gl g ON r.gl_code = g.gl_code -- JOIN with gl to get the GL Name/Category
    WHERE dher.trip_id = ?
    ORDER BY dher.id ASC
";
$stmt = $conn->prepare($existing_reasons_sql);
$stmt->bind_param("i", $target_trip_id);
$stmt->execute();
$existing_reasons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


// --- 3. Fetch All Available Reasons for Dropdown (UPDATED: JOIN with gl to get gl_name) ---
$all_reasons_sql = "
    SELECT 
        r.reason_code, 
        r.reason, 
        g.gl_name AS reason_category 
    FROM 
        reason r
    JOIN 
        gl g ON r.gl_code = g.gl_code
    ORDER BY 
        g.gl_name, r.reason";

$all_reasons_result = $conn->query($all_reasons_sql);
$available_reasons = [];
while ($row = $all_reasons_result->fetch_assoc()) {
    $available_reasons[] = $row;
}
$conn->close();


ob_end_clean(); // Clear buffer from includes if needed
include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Heldup Reasons - Trip <?php echo $target_trip_id; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Toast styling */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
        .toast { display: flex; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .form-group { border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.375rem; }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div id="toast-container"></div>

    <div class="w-[85%] ml-[15%] flex justify-center p-6">
        <div class="container max-w-4xl bg-white shadow-lg rounded-lg p-8 mt-6">
            
            <h1 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-3">
                Edit Heldup Reasons - Trip ID: <span class="text-indigo-600"><?php echo $target_trip_id; ?></span>
            </h1>

            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg text-sm grid grid-cols-2 gap-2">
                <p><strong>Vehicle No:</strong> <?php echo htmlspecialchars($trip_details['vehicle_no']); ?></p>
                <p><strong>Op Code:</strong> <?php echo htmlspecialchars($trip_details['op_code']); ?></p>
                <p><strong>Date:</strong> <?php echo htmlspecialchars($trip_details['date']); ?></p>
                <p><strong>Out/In Time:</strong> <?php echo htmlspecialchars($trip_details['out_time']) . ' / ' . htmlspecialchars($trip_details['in_time']?? "N/A"); ?></p>
                <p class="col-span-2"><strong>Status:</strong> <span class="font-bold text-<?php echo ($trip_details['done'] == 1 ? 'green' : 'red'); ?>-600"><?php echo ($trip_details['done'] == 1 ? 'DONE' : 'PENDING'); ?></span></p>
            </div>


            <h2 class="text-xl font-semibold text-gray-800 mb-4">Add New Employee Reason</h2>
            <form id="addReasonForm" class="form-group mb-8">
                <input type="hidden" name="action_reason" value="add_reason">
                <input type="hidden" name="trip_id_hidden" value="<?php echo $target_trip_id; ?>"> 
                
                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label for="emp_id" class="block text-sm font-medium text-gray-700">Employee ID</label>
                        <input type="text" id="emp_id" name="emp_id" required placeholder="GPxxxxxx" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 uppercase">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label for="reason_code" class="block text-sm font-medium text-gray-700">Reason</label>
                        <select id="reason_code" name="reason_code" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                            <option value="" disabled selected>Select Reason</option>
                            <?php 
                            $current_category = '';
                            foreach ($available_reasons as $reason):
                                // reason_category now holds gl_name
                                if ($reason['reason_category'] !== $current_category): 
                                    if ($current_category !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($reason['reason_category']) . '">';
                                    $current_category = $reason['reason_category'];
                                endif;
                            ?>
                                <option value="<?php echo htmlspecialchars($reason['reason_code']); ?>">
                                    <?php echo htmlspecialchars($reason['reason']); ?>
                                </option>
                            <?php endforeach; 
                            if ($current_category !== '') echo '</optgroup>';
                            ?>
                        </select>
                    </div>
                </div>
                
                <div class="flex justify-end mt-4">
                    <button type="submit" id="addReasonBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded transition">
                        <i class="fas fa-plus mr-1"></i> Add Reason
                    </button>
                </div>
            </form>


            <h2 class="text-xl font-semibold text-gray-800 mb-4">Existing Reasons (Total: <span id="total-reason-count"><?php echo count($existing_reasons); ?></span>)</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 border border-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category (GL Name)</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody id="reasonsTableBody" class="bg-white divide-y divide-gray-200">
                        <?php if (empty($existing_reasons)): ?>
                            <tr id="noReasonsRow"><td colspan="4" class="px-6 py-4 text-center text-gray-500">No reasons currently linked to this trip.</td></tr>
                        <?php else: ?>
                            <?php foreach ($existing_reasons as $reason): ?>
                                <tr id="reason-row-<?php echo $reason['id']; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($reason['emp_id']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($reason['reason_category']); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($reason['reason']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <button data-record-id="<?php echo $reason['id']; ?>" data-trip-context="<?php echo $target_trip_id; ?>" class="delete-reason-btn text-red-600 hover:text-red-900 transition duration-150">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="flex justify-start mt-8">
                <a href="javascript:history.back()" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded transition">
                    Back
                </a>
            </div>

        </div>
    </div>

    <script>
        const TRIP_ID = <?php echo $target_trip_id; ?>;
        const addReasonForm = document.getElementById('addReasonForm');
        const reasonsTableBody = document.getElementById('reasonsTableBody');
        const addReasonBtn = document.getElementById('addReasonBtn');
        const noReasonsRow = document.getElementById('noReasonsRow');
        const totalReasonCount = document.getElementById('total-reason-count');
        
        // --- Utility Functions ---
        function showToast(message, type = 'success', duration = 3000) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.classList.add('toast', type, 'show');
            const iconHtml = type === 'success' ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-triangle mr-2"></i>';
            toast.innerHTML = iconHtml + `<span>${message}</span>`;
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, duration);
        }

        // --- ADD Reason Logic (Remains the same as it relies on PHP rendering) ---
        addReasonForm.addEventListener('submit', async function(event) {
            event.preventDefault();
            
            if (!addReasonForm.checkValidity()) return;

            addReasonBtn.disabled = true;
            addReasonBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Adding...';
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch('day_heldup_edit_reasons.php', {
                    method: 'POST',
                    body: new URLSearchParams(formData),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                
                const responseText = await response.text();
                let data;
                
                try {
                    data = JSON.parse(responseText);
                } catch (e) {
                    // Log raw output to find source of JSON error
                    console.error('JSON Parse Error: Raw Response:', responseText);
                    showToast('Server returned an invalid JSON response.', 'error', 10000);
                    return;
                }

                if (data.success) {
                    showToast(data.message, 'success');
                    // Reload the page to display the new entry
                    setTimeout(() => window.location.reload(), 500); 
                } else {
                    showToast(data.message, 'error');
                }

            } catch (error) {
                console.error('Add Reason Error:', error);
                showToast('Network error during addition.', 'error');
            } finally {
                addReasonBtn.disabled = false;
                addReasonBtn.innerHTML = '<i class="fas fa-plus mr-1"></i> Add Reason';
            }
        });

        // --- DELETE Reason Logic (Remains the same as it relies on PHP rendering) ---
        document.addEventListener('click', async function(event) {
            if (event.target.classList.contains('delete-reason-btn') || event.target.closest('.delete-reason-btn')) {
                const button = event.target.closest('.delete-reason-btn');
                const recordId = button.getAttribute('data-record-id');
                const tripContextId = button.getAttribute('data-trip-context');

                if (!confirm(`Are you sure you want to delete this reason record ID ${recordId}?`)) return;

                button.disabled = true;
                button.innerHTML = 'Deleting...';
                
                const formData = new FormData();
                formData.append('action_reason', 'delete_reason');
                formData.append('record_id', recordId);
                // Use the primary PHP variable name for the context passed to the handler
                formData.append('trip_id_hidden', tripContextId); 

                try {
                    const response = await fetch('day_heldup_edit_reasons.php', {
                        method: 'POST',
                        body: new URLSearchParams(formData),
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                    });
                    
                    const responseText = await response.text();
                    let data;
                    
                    try {
                        data = JSON.parse(responseText);
                    } catch (e) {
                        console.error('JSON Parse Error: Raw Response:', responseText);
                        showToast('Server returned an invalid JSON response.', 'error', 10000);
                        return;
                    }

                    if (data.success) {
                        showToast(data.message, 'success');
                        // Reload to update the total count and the table
                        setTimeout(() => window.location.reload(), 500); 
                    } else {
                        showToast(data.message, 'error');
                    }

                } catch (error) {
                    console.error('Delete Reason Error:', error);
                    showToast('Network error during deletion.', 'error');
                } finally {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-trash-alt"></i> Delete';
                }
            }
        });
    </script>
</body>
</html>