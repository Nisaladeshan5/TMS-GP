<?php
// day_heldup_register.php (Focusing on Day Heldup TRIP Records)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

// --- CRITICAL CHANGE: REMOVING THE SESSION LOCK ---
// This page is accessible to all, but actions are restricted based on login status.
// --------------------------------------------------

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- User Context (CORRECTED for Logged-out access) ---
$user_role = $_SESSION['user_role'] ?? 'guest';
$can_act = in_array($user_role, ['super admin', 'admin', 'developer', 'manager']); 

// FIX: If logged out, ID is 0. If logged in, get the actual ID.
$current_session_user_id = $is_logged_in ? (int)($_SESSION['user_id'] ?? 0) : 0; 
// --------------------

// Set the filter date to today's date by default
$filterDate = date('Y-m-d');

// --- Handle Filter via GET (PRG Pattern) ---
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['date'])) {
    $filterDate = $_GET['date'];
}


// --- AJAX Handler for Fetching Reasons ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_reasons']) && isset($_POST['trip_id'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $trip_id = (int)$_POST['trip_id'];
    
    // 1. Fetch Trip Details
    $trip_details_sql = "SELECT distance FROM day_heldup_register WHERE trip_id = ? LIMIT 1";
    $trip_stmt = $conn->prepare($trip_details_sql);
    $trip_stmt->bind_param('i', $trip_id);
    $trip_stmt->execute();
    $trip_details = $trip_stmt->get_result()->fetch_assoc();
    $trip_stmt->close();

    // 2. Fetch Employee Reasons Breakdown (Modified to include Employee Name)
    $reasons_sql = "
        SELECT 
            dher.emp_id,
            e.calling_name,
            e.department,
            r.reason
        FROM dh_emp_reason dher
        JOIN reason r ON dher.reason_code = r.reason_code
        LEFT JOIN employee e ON dher.emp_id = e.emp_id
        WHERE dher.trip_id = ?
        ORDER BY dher.emp_id ASC
    ";

    $stmt = $conn->prepare($reasons_sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'DB Prepare Failed for reasons.']);
        exit;
    }
    $stmt->bind_param('i', $trip_id);
    $stmt->execute();
    $reasons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // 3. Combine and return data
    if (!empty($trip_details)) {
        echo json_encode([
            'success' => true, 
            'reasons' => $reasons,
            'distance' => number_format($trip_details['distance'] ?? 0, 2),
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Trip details not found.']);
    }
    
    if (isset($conn)) $conn->close();
    exit();
}
// --- END AJAX Handler ---


// 1. Fetch relevant Day Heldup Trip records
$sql = "
    SELECT 
        dhr.trip_id,
        dhr.op_code, 
        dhr.vehicle_no, 
        dhr.date, 
        dhr.out_time,
        dhr.in_time,
        dhr.distance,
        dhr.done AS heldup_done_status,
        dhr.user_id, /* Get user_id for client-side comparison */
        COUNT(dher.emp_id) AS employee_count,
        GROUP_CONCAT(DISTINCT r.reason SEPARATOR ' / ') AS reasons_summary,
        
        CASE
            WHEN dhr.user_id IS NOT NULL 
            THEN user_employee.calling_name 
            ELSE NULL 
        END AS done_by_user_display 
    FROM 
        day_heldup_register dhr
    LEFT JOIN 
        dh_emp_reason dher ON dhr.trip_id = dher.trip_id
    LEFT JOIN 
        reason r ON dher.reason_code = r.reason_code
    
    LEFT JOIN 
        admin a ON dhr.user_id = a.user_id
    LEFT JOIN
        employee AS user_employee ON a.emp_id = user_employee.emp_id 

    WHERE 
        DATE(dhr.date) = ?
    GROUP BY 
        dhr.trip_id, dhr.op_code, dhr.vehicle_no, dhr.date, dhr.done, dhr.out_time, dhr.in_time, dhr.distance, dhr.user_id, user_employee.calling_name
    ORDER BY 
        dhr.trip_id ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $filterDate);
$stmt->execute();
$result = $stmt->get_result();

$heldup_records = [];
while ($row = $result->fetch_assoc()) {
    // Ensure trip_user_id is correctly cast as integer for secure comparison in the loop below
    $row['user_id'] = (int)($row['user_id'] ?? 0); 
    $heldup_records[] = $row;
}

$stmt->close();
$conn->close();

// Includes moved here to ensure $conn is closed before they run
// NOTE: These includes must handle a user being logged out.
// For the navbar, you may need a separate check inside navbar.php.
include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Day Heldup Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<style>
    /* CSS for toast */
    #toast-container {
        position: fixed; top: 1rem; right: 1rem; z-index: 4000; display: flex; flex-direction: column; align-items: flex-end;
    }
    .toast {
        display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; max-width: 400px; 
    }
    .toast.show { transform: translateY(0); opacity: 1; }
    .toast.success { background-color: #4CAF50; }
    .toast.error { background-color: #F44336; }
    
    /* Custom CSS for status highlight */
    .heldup-pending { background-color: #fca5a5; /* Red-400 */ }
    .heldup-done { background-color: #d1fae5; /* Green-100 */ }

    /* Modal Styling */
    .reason-modal-overlay, .complete-modal-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
        background-color: rgba(0, 0, 0, 0.6); 
        display: none; justify-content: center; align-items: center; z-index: 3000;
    }
    .reason-modal-content, .complete-modal-content {
        background-color: white; padding: 2rem; border-radius: 0.5rem; width: 90%; max-width: 800px; box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
    }
</style>
<body class="bg-gray-100 ">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="dh_attendance.php" class="hover:text-yellow-600">Attendance</a>
        <?php if ($can_act): ?>
            <a href="day_heldup_add.php" class="hover:text-yellow-600">Add Trip</a>
        <?php endif; ?>
        <?php if (!$can_act): // Allow unprivileged users to see the add trip link if not an admin ?>
            <a href="day_heldup_add_trip.php" class="hover:text-yellow-600">Add Trip</a>
        <?php endif; ?>
          

    </div>
</div>

<div class="w-[85%] ml-[15%] " style="display: flex; flex-direction: column; align-items: center;">
    <p class="text-[32px] font-bold text-gray-800 mt-2">Day Heldup Trip Register</p>

    <form method="GET" class="mb-6 flex justify-center">
        <div class="flex items-center">
            <label for="date" class="text-lg font-medium mr-2">Filter by Date:</label>
            <input type="date" id="date" name="date" class="border border-gray-300 p-2 rounded-md"
                    value="<?php echo htmlspecialchars($filterDate); ?>" required>
            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md ml-2 hover:bg-blue-600">Filter</button>
        </div>
    </form>

    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6">
        <table class="w-full table-auto p-2">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-2 text-left">Trip ID</th>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Op Code</th>
                    <th class="px-4 py-2 text-left">Out Time</th>
                    <th class="px-4 py-2 text-left">In Time</th>
                    <th class="px-4 py-2 text-right">Distance (km)</th>
                    <th class="px-4 py-2 text-left">Employees (Count)</th>
                    <th class="px-4 py-2 text-left">Done By</th>
                    <th class="px-4 py-2 text-center">Reasons</th>
                    <th class="px-4 py-2 text-center" style="min-width: 150px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($heldup_records)) {
                    // Colspan is now fixed at 10 (since the Action column is always visible)
                    echo "<tr><td colspan='10' class='border px-4 py-2 text-center text-gray-500'>No Day Heldup Trip records available for " . htmlspecialchars($filterDate) . ".</td></tr>";
                } else {
                    foreach ($heldup_records as $entry) {
                        
                        $is_done = $entry['heldup_done_status'] == 1;
                        $employee_count = (int)$entry['employee_count'];
                        $trip_id = $entry['trip_id'];
                        $trip_user_id = $entry['user_id']; // Integer ID of the user who completed the trip
                        $trip_user_id_is_null = $entry['user_id'] === 0 && $entry['done_by_user_display'] === null; 

                        $status_text = $is_done ? 'DONE' : 'PENDING';
                        $row_class = $is_done ? 'heldup-done' : 'heldup-pending';
                        
                        $out_time = htmlspecialchars($entry['out_time'] ?? '---');
                        $in_time = htmlspecialchars($entry['in_time'] ?? '---');
                        $distance = number_format($entry['distance'] ?? 0, 2);
                        
                        // FIX: Ensure $done_by_display is always defined
                        $done_by_display = htmlspecialchars($entry['done_by_user_display'] ?? '---');
                        
                        // Check ownership/permissions
                        // $is_owner is TRUE if the current logged-in user (ID) matches the user_id saved on the trip record.
                        $is_owner = ($current_session_user_id !== 0 && $current_session_user_id === $trip_user_id);
                        $is_super_admin = $user_role === 'super admin' || $user_role === 'developer';
                        
                        $can_edit_done_distance = $is_owner; 
                        
                        // Render Row
                        echo "<tr class='{$row_class}'>
                            <td class='border px-4 py-2'>{$trip_id}</td>
                            <td class='border px-4 py-2'>{$entry['vehicle_no']}</td>
                            <td class='border px-4 py-2'>{$entry['op_code']}</td>
                            <td class='border px-4 py-2'>{$out_time}</td>
                            <td class='border px-4 py-2'>{$in_time}</td>
                            <td class='border px-4 py-2 text-right'>{$distance}</td>
                            <td class='border px-4 py-2'>{$employee_count}</td>
                            <td class='border px-4 py-2 text-sm'>{$done_by_display}</td>
                            
                            <td class='border px-4 py-2 text-center'>
                                <button data-trip-id='{$trip_id}' 
                                        class='view-reasons-btn bg-indigo-500 hover:bg-indigo-700 text-white font-bold py-1 px-2 rounded text-xs'>
                                    <i class='fas fa-eye'></i>
                                </button>
                            </td>";

                        // ACTION CELL (Always visible now since session lock is removed)
                        echo "<td class='border px-4 py-2 text-center space-x-1'>";
                        
                        if ($is_done) {
                            // DONE Trip Logic (ONLY owner can edit distance, and ONLY if logged in - enforced by $is_owner)
                            
                            if ($can_edit_done_distance) {
                                // Action 1: Completed BY THIS USER - Allow distance edit
                                echo "<button data-trip-id='{$trip_id}' 
                                            data-distance='{$distance}'
                                            class='edit-distance-btn bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-2 rounded text-xs'>
                                            <i class='fas fa-pencil-alt'></i>
                                        </button>";
                            } else {
                                // Completed by OTHERS - No action allowed
                                echo "---"; 
                            }
                        } else {
                            // PENDING ACTIONS (done = 0)
                            $in_time_is_null = empty($entry['in_time']) || $entry['in_time'] === '---';
                            
                            // 1. Set In Time Button (If In Time is NULL) - **LOGGED OUT ONLY**
                            if ($in_time_is_null) {
                                // NEW RULE IMPLEMENTED HERE: Show Set In Time ONLY if trip is UNCLAIMED AND user is NOT logged in
                                if ($trip_user_id === 0 && !$is_logged_in) { 
                                    echo "<button data-trip-id='{$trip_id}' 
                                                class='set-in-time-btn bg-yellow-800 hover:bg-yellow-900 text-white font-bold py-1 px-2 rounded text-xs mr-1'>
                                                <i class='fas fa-clock'></i>
                                            </button>";
                                } 
                            } 
                            
                            // --- LOGGED IN USER ACTIONS (ALL OTHER ACTIONS) ---
                            if ($is_logged_in) {

                                // Delete Button logic setup (Moved inside the logged-in check)
                                $show_delete = false;
                                $delete_security_type = '';

                                if ($trip_user_id === 0) { // Unclaimed trip
                                    $show_delete = true;
                                    $delete_security_type = 'PIN_REQUIRED'; // Requires PIN for anyone with access
                                } elseif ($is_owner) { // Claimed by current user
                                    $show_delete = true;
                                    $delete_security_type = 'OWNER';
                                }

                                // 2. Complete Trip Button (If In Time is SET)
                                
                                    // Can complete if owner OR if unclaimed (user_id === 0)
                                $can_complete = ($trip_user_id >= 0); 
                                    
                                if ($can_complete) {
                                    echo "<button data-trip-id='{$trip_id}' 
                                                data-op-code='{$entry['op_code']}'
                                                class='complete-trip-btn bg-green-600 hover:bg-green-700 text-white font-bold py-1 px-2 rounded text-xs'>
                                                <i class='fas fa-check'></i>
                                            </button>";
                                }
                                
                                // 3. Edit Reasons Link (Available if trip is pending, usually only to Admins/Managers)
                                if ($can_act) { 
                                    echo "<a href='day_heldup_process.php?trip_id={$trip_id}&action=edit_reasons' 
                                            class='bg-yellow-600 hover:bg-yellow-700 text-white font-bold py-1 px-2 rounded text-xs'>
                                            <i class='fas fa-edit'></i>
                                        </a>";
                                }


                                // 4. Delete Button
                                if ($show_delete) {
                                   echo "<button data-trip-id='{$trip_id}' 
                                                data-security-type='{$delete_security_type}'
                                                class='delete-trip-btn bg-red-600 hover:bg-red-700 text-white font-bold py-1 px-2 rounded text-xs mr-1'>
                                                <i class='fas fa-trash-alt'></i>
                                            </button>";
                                }
                            } // End if ($is_logged_in)
                        }
                        echo "</td>"; // Close Action Cell
                        echo "</tr>"; // Close Row
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<div id="toast-container"></div>

<div id="reasonsModal" class="reason-modal-overlay">
    <div class="reason-modal-content">
        <h2 class="text-xl font-bold mb-4 text-gray-800 border-b pb-2">Reasons Breakdown for Trip ID: <span id="modalTripId" class="text-indigo-600"></span></h2>
        <div id="reasonsContent" class="overflow-y-auto max-h-80">
            <p class="text-gray-500">Loading reasons...</p>
        </div>
        <div class="flex justify-end mt-4">
            <button onclick="document.getElementById('reasonsModal').style.display='none'" 
                    class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded transition">
                Close
            </button>
        </div>
    </div>
</div>
<div id="completeTripModal" class="complete-modal-overlay">
    <div class="complete-modal-content">
        <h2 class="text-xl font-bold mb-4 text-gray-800"><span id="modalTitleAction">Finalize</span> Trip Completion</h2>
        <p class="mb-4 text-sm text-gray-600">
            Trip ID: <span id="completeModalTripId" class="font-semibold text-indigo-600"></span>.
        </p>
        
        <form id="completeTripForm" data-action-type="complete">
            <input type="hidden" id="completeTripHiddenId" name="trip_id">

            <div class="mb-6">
                <label for="distanceInput" class="block text-sm font-medium text-gray-700 mb-1">
                    Total Distance Traveled (km):
                </label>
                <input type="number" step="0.01" min="0" id="distanceInput" name="distance" required 
                        class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-indigo-500 focus:border-indigo-500"
                        placeholder="Enter final odometer reading / distance">
            </div>

            <div class="flex justify-end space-x-3">
                <button type="button" onclick="document.getElementById('completeTripModal').style.display='none'"
                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">
                    Cancel
                </button>
                <button type="submit" id="confirmCompleteBtn" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded transition">
                    Confirm & Save Distance
                </button>
            </div>
        </form>
    </div>
</div>

<div id="deleteTripModal" class="complete-modal-overlay">
    <div class="complete-modal-content">
        <h2 class="text-xl font-bold mb-4 text-gray-800 text-red-600">Confirm Deletion</h2>
        <p class="mb-4 text-sm text-gray-700">
            You are about to delete Trip ID: <span id="deleteModalTripId" class="font-semibold text-red-600"></span>.
            This action requires a security PIN.
        </p>
        
        <form id="deleteTripForm">
            <input type="hidden" id="deleteTripHiddenId" name="trip_id">
            <input type="hidden" name="action" value="delete">

            <div class="mb-6">
                <label for="deletePinInput" class="block text-sm font-medium text-gray-700 mb-1">
                    Security PIN :
                </label>
                <input type="text" id="deletePinInput" name="pin" required 
                        class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500"
                        placeholder="Enter PIN">
            </div>

            <div class="flex justify-end space-x-3">
                <button type="button" onclick="document.getElementById('deleteTripModal').style.display='none'"
                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">
                    Cancel
                </button>
                <button type="submit" id="confirmDeleteBtn" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded transition">
                    Confirm Deletion
                </button>
            </div>
        </form>
    </div>


<script>
    // Global variables for modals/forms
    const reasonsModal = document.getElementById('reasonsModal');
    const completeTripModal = document.getElementById('completeTripModal');
    const completeTripForm = document.getElementById('completeTripForm');
    const completeTripHiddenId = document.getElementById('completeTripHiddenId');
    const completeModalTripId = document.getElementById('completeModalTripId');
    const confirmCompleteBtn = document.getElementById('confirmCompleteBtn');
    const modalTitleAction = document.getElementById('modalTitleAction');
    
    // New delete modal elements
    const deleteTripModal = document.getElementById('deleteTripModal');
    const deleteTripForm = document.getElementById('deleteTripForm');
    const deleteTripHiddenId = document.getElementById('deleteTripHiddenId');
    const deleteModalTripId = document.getElementById('deleteModalTripId');
    const deletePinInput = document.getElementById('deletePinInput');
    const pinInputGroup = deletePinInput.closest('div'); 
    
    // Pass the PHP session user ID to JavaScript
    const CURRENT_SESSION_USER_ID = <?php echo json_encode($current_session_user_id); ?>;


    // --- Utility Functions ---
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<span>${message}</span>`;
        
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 5000); 
    }

    // --- Reason Fetching Logic (Modal 1) ---
    document.querySelectorAll('.view-reasons-btn').forEach(button => {
        button.addEventListener('click', function() {
            const tripId = this.getAttribute('data-trip-id');
            const reasonsContent = document.getElementById('reasonsContent');
            
            document.getElementById('modalTripId').textContent = tripId;
            reasonsModal.style.display = 'flex';
            
            fetchReasons(tripId, reasonsContent);
        });
    });

    async function fetchReasons(tripId, contentElement) {
        contentElement.innerHTML = '<p class="text-blue-500 text-center"><i class="fas fa-spinner fa-spin mr-2"></i>Loading details...</p>';
        
        const formData = new FormData();
        formData.append('fetch_reasons', '1');
        formData.append('trip_id', tripId);

        try {
            const response = await fetch('day_heldup_register.php', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) throw new Error('Network error.');
            const data = await response.json();

            if (data.success && data.reasons.length > 0) {
                let html = `<table class="min-w-full divide-y divide-gray-200 mt-2">
                                <thead><tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Emp ID</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Employee Name</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Department</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                                </tr></thead>
                                <tbody class="bg-white divide-y divide-gray-200">`;

                data.reasons.forEach(item => {
                    const empName = item.calling_name || 'N/A';
                    const dept = item.department || 'N/A';
                    
                    html += `<tr>
                                <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900">${item.emp_id}</td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">${empName}</td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">${dept}</td>
                                <td class="px-4 py-2 text-sm text-gray-500">${item.reason}</td>
                            </tr>`;
                });

                html += `</tbody></table>`;
                contentElement.innerHTML = html;
            } else {
                contentElement.innerHTML = `<p class="px-4 py-2 text-center text-gray-500 font-semibold">No specific employee reasons recorded.</p>`;
            }

        } catch (error) {
            console.error('Fetch Error:', error);
            contentElement.innerHTML = `<p class="px-4 py-2 text-center text-red-500">Could not retrieve details. Server error.</p>`;
            showToast('Error retrieving details.', 'error');
        }
    }


    // --- Complete/Edit Distance Logic ---
    
    document.addEventListener('DOMContentLoaded', () => {

        // 1. Open COMPLETE Modal Handler (PENDING trips)
        document.querySelectorAll('.complete-trip-btn').forEach(button => {
            button.addEventListener('click', function() {
                const tripId = this.getAttribute('data-trip-id');
                
                modalTitleAction.textContent = 'Finalize';
                document.getElementById('confirmCompleteBtn').textContent = 'Confirm & Save Distance';
                completeTripForm.setAttribute('data-action-type', 'complete');
                document.getElementById('distanceInput').value = '';

                completeModalTripId.textContent = tripId;
                completeTripHiddenId.value = tripId;
                completeTripModal.style.display = 'flex';
            });
        });
        
        // --- Open EDIT DISTANCE Handler (DONE trips) ---
        document.querySelectorAll('.edit-distance-btn').forEach(button => {
            button.addEventListener('click', function() {
                const tripId = this.getAttribute('data-trip-id');
                const currentDistance = button.getAttribute('data-distance');
                
                modalTitleAction.textContent = 'Edit';
                document.getElementById('confirmCompleteBtn').textContent = 'Update Distance';
                completeTripForm.setAttribute('data-action-type', 'edit_distance');
                document.getElementById('distanceInput').value = currentDistance;
                
                completeModalTripId.textContent = tripId;
                completeTripHiddenId.value = tripId;
                completeTripModal.style.display = 'flex';
            });
        });


        // 2. Combined Form Submission Handler (Complete / Edit Distance)
        completeTripForm.addEventListener('submit', async function(event) {
            event.preventDefault();
            
            const actionType = this.getAttribute('data-action-type');
            
            document.getElementById('confirmCompleteBtn').disabled = true;
            document.getElementById('confirmCompleteBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const distance = document.getElementById('distanceInput').value.trim();
            const tripId = document.getElementById('completeTripHiddenId').value;

            const formData = new FormData();
            formData.append('trip_id', tripId);
            formData.append('distance', distance);
            formData.append('action', actionType);
            formData.append('session_user_id', CURRENT_SESSION_USER_ID); // Pass user ID for logging/auditing
            
            try {
                const response = await fetch('day_heldup_process.php', {
                    method: 'POST',
                    body: new URLSearchParams(formData),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });

                const data = await response.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    completeTripModal.style.display = 'none';
                    
                    setTimeout(() => window.location.reload(), 1000);

                } else {
                    showToast(data.message, 'error');
                }

            } catch (error) {
                console.error('AJAX Complete/Edit Error:', error);
                showToast('An unexpected error occurred during trip completion.', 'error');
            } finally {
                document.getElementById('confirmCompleteBtn').disabled = false;
                document.getElementById('confirmCompleteBtn').innerHTML = actionType === 'complete' ? 'Confirm & Save Distance' : 'Update Distance';
            }
        });


        // --- DELETE Logic ---

        // 3. Open DELETE Modal Handler
        document.querySelectorAll('.delete-trip-btn').forEach(button => {

            // Open Delete Modal
            button.addEventListener('click', function() {

                const tripId = this.getAttribute('data-trip-id');
                const securityType = this.getAttribute('data-security-type');

                deleteTripHiddenId.value = tripId;
                deleteModalTripId.textContent = tripId;

                // Show/Hide PIN input based on security type
                if (securityType === 'PIN_REQUIRED') {
                    pinInputGroup.style.display = 'block';
                    deletePinInput.required = true;
                    deletePinInput.placeholder = 'Enter PIN';
                    deletePinInput.value = '';
                } else {
                    pinInputGroup.style.display = 'none';
                    deletePinInput.required = false;
                    deletePinInput.value = 'OWNER_CONFIRMED';
                }

                deleteTripForm.setAttribute('data-security-type', securityType);
                deleteTripModal.style.display = 'flex';
            });
        });


        // 4. Submit Delete Request
        deleteTripForm.addEventListener('submit', async function(event) {
            event.preventDefault();

            const deleteButton = this.querySelector('#confirmDeleteBtn');
            const securityType = this.getAttribute('data-security-type');

            // Prepare formData
            const formData = new FormData(this);

            deleteButton.disabled = true;
            deleteButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

            // Add server-required parameters
            formData.set('session_user_id', CURRENT_SESSION_USER_ID);
            formData.set('security_type', securityType);

            try {
                // Send request
                const response = await fetch('day_heldup_process.php', {
                    method: 'POST',
                    body: new URLSearchParams(formData),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });

                const data = await response.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    deleteTripModal.style.display = 'none';

                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }

            } catch (error) {
                console.error('AJAX Delete Error:', error);
                showToast('An unexpected error occurred during deletion.', 'error');

            } finally {
                deleteButton.disabled = false;
                deleteButton.innerHTML = 'Confirm Deletion';
            }
        });
        
        
        // 5. Set In Time Handler
        document.querySelectorAll('.set-in-time-btn').forEach(button => {
            button.addEventListener('click', async function() {
                const tripId = this.getAttribute('data-trip-id');
                const originalHtml = this.innerHTML;
                
                // IMPORTANT: Since the button is only shown to logged-out users, CURRENT_SESSION_USER_ID will be 0.
                // The backend (day_heldup_process.php) will need to handle this by setting the trip user_id to NULL or a guest ID (0).
                
                if (!confirm(`Confirm ending Trip ID ${tripId}? This will record the current time as In Time.`)) {
                    return;
                }

                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

                const formData = new FormData();
                formData.append('trip_id', tripId);
                formData.append('action', 'set_in_time'); 
                // Pass user ID (which will be 0) - The backend process must interpret a 0 as an un-claimed trip update.
                formData.append('session_user_id', CURRENT_SESSION_USER_ID); 
                
                try {
                    const response = await fetch('day_heldup_process.php', {
                        method: 'POST',
                        body: new URLSearchParams(formData),
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                    });

                    const data = await response.json();

                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(() => window.location.reload(), 1000); 

                    } else {
                        showToast(data.message, 'error');
                    }

                } catch (error) {
                    console.error('AJAX Set In Time Error:', error);
                    showToast('Network error during In Time update.', 'error');
                } finally {
                    this.disabled = false;
                    this.innerHTML = originalHtml;
                }
            });
        });
    });
</script>
</body>
</html>