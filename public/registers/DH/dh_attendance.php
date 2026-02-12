<?php
// dh_attendance.php - Displays Day Heldup Attendance Records (Filtered by Separate Month/Year)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- User Context ---
$user_role = $_SESSION['user_role'] ?? 'guest';
$can_act = in_array($user_role, ['super admin', 'admin', 'developer', 'manager']);
$logged_in_user_id = (string)($_SESSION['user_id'] ?? ''); 
// --------------------

// Set default filter values
$current_year = date('Y');
$current_month = date('m');

$filterYear = $current_year;
$filterMonthNum = $current_month; // Numeric month (01-12)

// --- Handle Filter via GET (PRG Pattern) ---
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['year']) && is_numeric($_GET['year'])) {
        $filterYear = $_GET['year'];
    }
    if (isset($_GET['month_num']) && is_numeric($_GET['month_num'])) {
        // Ensure month number is two digits (e.g., 5 becomes 05)
        $filterMonthNum = str_pad($_GET['month_num'], 2, '0', STR_PAD_LEFT);
    }
}

// Combine for SQL filtering
$filterYearMonth = "{$filterYear}-{$filterMonthNum}";


// ------------------------------------------------------------------
// 1. Fetch GLOBAL PENDING COUNT (AC Status = NULL, NO DATE FILTER)
// ------------------------------------------------------------------
$pending_count = 0;
$count_sql = "SELECT COUNT(*) AS pending_count FROM dh_attendance WHERE ac IS NULL";
$count_stmt = $conn->prepare($count_sql);

if ($count_stmt) {
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_row = $count_result->fetch_assoc();
    $pending_count = (int)($count_row['pending_count'] ?? 0);
    $count_stmt->close();
}
// ------------------------------------------------------------------


// 2. Fetch DH Attendance Records (Main Query - FILTERED BY MONTH/YEAR)
$sql = "
    SELECT 
        dha.op_code, 
        dha.vehicle_no, 
        dha.date, 
        dha.time,
        dha.user_id,
        dha.ac_user_id, 
        dha.ac, 
        
        CASE
            WHEN dha.user_id IS NOT NULL 
            THEN user_employee.calling_name 
            ELSE NULL 
        END AS recorded_by_user_display 
    FROM 
        dh_attendance dha
    
    LEFT JOIN 
        admin a ON dha.user_id = a.user_id
    LEFT JOIN
        employee AS user_employee ON a.emp_id = user_employee.emp_id 

    WHERE 
        DATE_FORMAT(dha.date, '%Y-%m') = ? /* FILTER BY COMBINED YYYY-MM */
    ORDER BY 
        dha.date DESC, dha.time DESC /* Most recent date/time first */
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $filterYearMonth); // Binding the YYYY-MM filter string
$stmt->execute();
$result = $stmt->get_result();

$attendance_records = [];
while ($row = $result->fetch_assoc()) {
    $attendance_records[] = $row;
}

$stmt->close();
$conn->close();

// Month names array for display
$monthNames = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DH Attendance Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<style>
    /* CSS for toast */
    #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
    .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s; }
    .toast.show { opacity: 1; }
    .toast.success { background-color: #4CAF50; }
    .toast.error { background-color: #F44336; }
    /* Modal Styling */
    #acStatusModal { position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.5); display: none; align-items: center; justify-content: center; z-index: 50; }
    
    /* Scrollbar */
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #f1f1f1; }
    ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: #555; }
</style>
<body class="bg-gray-100 ">
<div id="toast-container"></div>

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
                <a href="day_heldup_register.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Day Heldup
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Attendance
                </span>
            </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <?php if ($pending_count > 0): ?>
            <div class="bg-red-600 text-white px-2 py-1 rounded-md text-xs font-bold animate-pulse shadow-sm flex items-center gap-1" title="Pending AC Confirmations">
                <i class="fas fa-exclamation-circle"></i> AC Pending: <?php echo $pending_count; ?>
            </div>
        <?php endif; ?>

        <form method="GET" class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            
            <select name="month_num" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <?php foreach ($monthNames as $num => $name): 
                    $selected = ($filterMonthNum == $num) ? 'selected' : '';
                ?>
                    <option value="<?php echo $num; ?>" <?php echo $selected; ?> class="text-gray-900 bg-white">
                        <?php echo $name; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <span class="text-gray-400 mx-1">|</span>

            <select name="year" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-1 pr-2 appearance-none hover:text-yellow-200 transition">
                <?php 
                $startYear = date('Y') + 1;
                $endYear = date('Y') - 3;
                for ($year = $startYear; $year >= $endYear; $year--):
                    $selected = ($filterYear == $year) ? 'selected' : '';
                ?>
                    <option value="<?php echo $year; ?>" <?php echo $selected; ?> class="text-gray-900 bg-white">
                        <?php echo $year; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </form>

        <span class="text-gray-600">|</span>

        <a href="day_heldup_register.php" class="text-gray-300 hover:text-white transition">Trip Register</a>
        
        <?php if ($can_act): ?>
            <a href="day_heldup_add_attendance_manual.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Attendance
            </a>
        <?php endif; ?>
        
        <?php if (!$can_act): ?>
            <a href="day_heldup_add_attendance.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Attendance
            </a>
        <?php endif; ?>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200">
        <table class="w-full table-auto">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-3 text-left">Date</th>
                    <th class="px-4 py-3 text-left">Time</th>
                    <th class="px-4 py-3 text-left">Op Code</th>
                    <th class="px-4 py-3 text-left">Vehicle No</th>
                    <th class="px-4 py-3 text-left">Recorded By</th>
                    <th class="px-4 py-3 text-center">AC Status</th>
                    <th class="px-4 py-3 text-center" style="min-width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody class="text-sm">
                <?php
                if (empty($attendance_records)) {
                    echo "<tr><td colspan='7' class='px-6 py-4 text-center text-gray-500'>
                            No Attendance records available for " . htmlspecialchars($filterYearMonth) . ".
                          </td></tr>";
                } else {
                    foreach ($attendance_records as $entry) {
                        
                        $record_op_code = htmlspecialchars($entry['op_code']);
                        $record_date = htmlspecialchars($entry['date']);
                        $record_user_id = $entry['user_id'];
                        $record_ac_user_id = $entry['ac_user_id']; 
                        $ac_status_db = $entry['ac']; 

                        // Highlight row if AC Status is NULL (Pending)
                        $row_class = 'hover:bg-gray-50 border-b border-gray-100 transition duration-150';
                        if ($ac_status_db === null) {
                            $row_class = 'bg-red-50 hover:bg-red-100 border-b border-red-100'; 
                        }
                        
                        // Check if the current logged-in user is the record creator or the AC status setter
                        $is_record_owner = !empty($logged_in_user_id) && !empty($record_user_id) && ((string)$logged_in_user_id === (string)$record_user_id);
                        $is_ac_setter = !empty($logged_in_user_id) && !empty($record_ac_user_id) && ((string)$logged_in_user_id === (string)$record_ac_user_id);
                        
                        // Permission to toggle AC: Owner, AC Setter, or High Privilege (for unowned/unmarked records)
                        $is_high_privilege = in_array($user_role, ['super admin', 'admin', 'developer', 'manager']);
                        $is_unowned_record = empty($record_user_id);
                        
                        // AC Status is UNMARKED (NULL) AND Current user is an Admin/High Privilege
                        $can_initial_mark = empty($ac_status_db) && $is_high_privilege; 

                        // AC Status is MARKED AND Current user is the original setter OR Admin/High Privilege
                        $can_edit_marked = !empty($ac_status_db) && ($is_ac_setter || $is_high_privilege);
                        
                        $can_toggle_ac = $can_initial_mark || $can_edit_marked;

                        
                        // 1. Determine Display Logic (Visuals only)
                        if ($ac_status_db === 1) {
                            $ac_display = '<span class="px-2 py-0.5 rounded text-xs font-bold bg-green-200 text-green-800">AC</span>';
                        } elseif ($ac_status_db === 2) {
                            $ac_display = '<span class="px-2 py-0.5 rounded text-xs font-bold bg-red-200 text-red-800">NON-AC</span>';
                        } else {
                            // Default display for non-clickable state
                            $ac_display = '<span class="text-gray-400 italic">---</span>';
                        }
                        
                        // Display user name, defaulting to '---' if NULL
                        $recorded_by = htmlspecialchars($entry['recorded_by_user_display'] ?? '---');

                        echo "<tr class='{$row_class}'>
                            <td class='px-4 py-3 font-medium text-gray-700'>{$record_date}</td>
                            <td class='px-4 py-3'>{$entry['time']}</td>
                            <td class='px-4 py-3'>{$record_op_code}</td>
                            <td class='px-4 py-3'>{$entry['vehicle_no']}</td>
                            <td class='px-4 py-3 text-xs text-gray-600'>{$recorded_by}</td>
                            
                            <td class='px-4 py-3 text-center'>";
                            
                            if ($can_toggle_ac) {
                                // If status is empty, SHOW SELECT ICON
                                if (empty($ac_status_db)) {
                                    $ac_display = "<span class='bg-white border border-blue-500 text-blue-600 rounded px-2 py-1 text-xs font-bold flex items-center justify-center gap-2 shadow-sm'>
                                                        <i class='fa-solid fa-hand-pointer animate-pulse'></i> Select
                                                   </span>";
                                }

                                // AC Status clickable area (triggers modal)
                                echo "<button data-op-code='{$record_op_code}' 
                                                data-date='{$record_date}'
                                                data-current-ac='{$ac_status_db}'
                                                class='ac-status-btn hover:opacity-80 transition transform hover:scale-105' title='Edit AC Status'>
                                                {$ac_display} 
                                      </button>";
                            } else {
                                echo $ac_display; // Display status, but not clickable
                            }
                            echo "</td>
                            
                            <td class='px-4 py-3 text-center'>";
                            
                            if ($is_record_owner) {
                                // Delete Button (Strictly only for record owner)
                                echo "<button data-op-code='{$record_op_code}' 
                                                data-date='{$record_date}'
                                                class='delete-attendance-btn bg-red-500 hover:bg-red-600 text-white p-1.5 rounded-md text-xs shadow-sm transition' title='Delete Record'>
                                                <i class='fas fa-trash-alt'></i>
                                      </button>";
                            } else {
                                echo "<span class='text-gray-400 text-xs italic'>Locked</span>";
                            }

                            echo "</td>
                        </tr>";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="acStatusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white p-6 rounded-lg shadow-xl w-96">
        <h2 class="text-xl font-bold mb-4 text-gray-800">Set AC Status</h2>
        <p class="text-sm text-gray-500 mb-4">For <span id="modalOpCode" class="font-bold text-indigo-600"></span></p>
        
        <form id="acStatusForm">
            <input type="hidden" name="action" value="set_ac_status_final">
            <input type="hidden" name="op_code">
            <input type="hidden" name="record_date">
            <input type="hidden" name="user_id" value="<?php echo $logged_in_user_id; ?>"> 
            
           <div class="mb-6 space-y-4">
                <label class="block text-sm font-medium text-gray-700">Select Status:</label>
                
                <div class="flex flex-col space-y-3">
                    <label for="ac_status_1" class="flex items-center justify-between p-3 border border-gray-300 rounded-lg cursor-pointer transition duration-200 hover:shadow-md hover:border-green-500 has-[:checked]:border-2 has-[:checked]:border-green-600 has-[:checked]:bg-green-50">
                        <span class="flex items-center">
                            <span class="text-lg text-green-700 font-bold">AC</span>
                        </span>
                        <input type="radio" id="ac_status_1" name="ac_status" value="1" class="form-radio text-green-600 h-5 w-5">
                    </label>
                    
                    <label for="ac_status_2" class="flex items-center justify-between p-3 border border-gray-300 rounded-lg cursor-pointer transition duration-200 hover:shadow-md hover:border-red-500 has-[:checked]:border-2 has-[:checked]:border-red-600 has-[:checked]:bg-red-50">
                        <span class="flex items-center">
                            <span class="text-lg text-red-700 font-bold">NON-AC</span>
                        </span>
                        <input type="radio" id="ac_status_2" name="ac_status" value="2" class="form-radio text-red-600 h-5 w-5">
                    </label>
                </div>
            </div>

            <div class="flex justify-between space-x-3">
                <button type="button" onclick="document.getElementById('acStatusModal').style.display='none'" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">Cancel</button>
                <button type="submit" id="acStatusSubmitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded transition">Submit</button>
            </div>
        </form>
    </div>
</div>


<script>
    // --- Utility Functions ---
    function showToast(message, type = 'success', duration = 2000) {
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

    // --- AC Status Modal Logic ---
    document.body.addEventListener('click', function(event) {
        const acBtn = event.target.closest('.ac-status-btn');
        if (acBtn) {
            const opCode = acBtn.getAttribute('data-op-code');
            const recordDate = acBtn.getAttribute('data-date');
            const currentAc = acBtn.getAttribute('data-current-ac');
            
            document.getElementById('modalOpCode').textContent = `${opCode} on ${recordDate}`;
            document.querySelector('#acStatusForm input[name="op_code"]').value = opCode;
            document.querySelector('#acStatusForm input[name="record_date"]').value = recordDate;
            
            // Set radio button based on current status
            const acRadio = document.querySelector('#acStatusForm input[name="ac_status"][value="1"]');
            const nonAcRadio = document.querySelector('#acStatusForm input[name="ac_status"][value="2"]');

            if (currentAc === '1') {
                acRadio.checked = true;
            } else if (currentAc === '2') {
                nonAcRadio.checked = true;
            } else {
                // Default to AC if unknown/null
                acRadio.checked = false; 
                nonAcRadio.checked = false; // Reset both
            }

            document.getElementById('acStatusModal').style.display = 'flex';
        }
    });

    // --- AC Status Submission ---
    document.getElementById('acStatusForm').addEventListener('submit', async function(event) {
        event.preventDefault();
        
        const submitBtn = document.getElementById('acStatusSubmitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        const formData = new URLSearchParams(new FormData(this));

        try {
            const response = await fetch('dh_attendance_process.php', {
                method: 'POST',
                body: formData,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            });

            const data = await response.json();

            if (data.success) {
                showToast(data.message, 'success');
                document.getElementById('acStatusModal').style.display = 'none';
                setTimeout(() => window.location.reload(), 1000); 
            } else {
                showToast(data.message, 'error');
            }

        } catch (error) {
            console.error('AC Status Submission Error:', error);
            showToast('Network error during AC status update.', 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Submit';
        }
    });

    // --- Delete Attendance Handler ---
    document.body.addEventListener('click', async function(event) {
        const deleteBtn = event.target.closest('.delete-attendance-btn');
        if (deleteBtn) {
            const btn = deleteBtn;
            const opCode = btn.getAttribute('data-op-code');
            const recordDate = btn.getAttribute('data-date');
            
            if (!confirm(`Are you sure you want to delete Attendance Record for ${opCode} on ${recordDate}?`)) {
                return;
            }

            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

            const formData = new FormData();
            formData.append('op_code', opCode); 
            formData.append('record_date', recordDate);
            
            formData.append('action', 'delete_attendance_composite'); // Action used in dh_attendance_process.php
            formData.append('user_id', '<?php echo $logged_in_user_id; ?>'); // Send current user ID for final backend check

            try {
                const response = await fetch('dh_attendance_process.php', {
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
                console.error('AJAX Delete Error:', error);
                showToast('Network error during deletion.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    });
</script>
</body>
</html>