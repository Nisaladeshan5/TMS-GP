<?php
// dh_attendance.php - Displays Day Heldup Attendance Records (Filtered by Separate Month/Year)

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
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
</style>
<body class="bg-gray-100 ">
<div id="toast-container"></div>

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="day_heldup_register.php" class="hover:text-yellow-600">Trip Register</a>
        <?php if ($can_act): ?>
            <a href="day_heldup_add_attendance_manual.php" class="hover:text-yellow-600">Add Attendance </a>
        <?php endif; ?>
        <?php if (!$can_act): ?>
            <a href="day_heldup_add_attendance.php" class="hover:text-yellow-600">Add Attendance</a>
        <?php endif; ?>
    </div>
</div>

<div class="w-[85%] ml-[15%]" style="display: flex; flex-direction: column; align-items: center;">
    <p class="text-[32px] font-bold text-gray-800 mt-2">Day Heldup Attendance Register</p>

    <form method="GET" class="mb-6 flex justify-center items-center">
        <div class="flex items-center space-x-2">
            <label class="text-lg font-medium">Filter by:</label>
            
            <div>
                <select name="month_num" class="border border-gray-300 p-2 rounded-md">
                    <?php 
                    foreach ($monthNames as $num => $name): 
                        $selected = ($filterMonthNum == $num) ? 'selected' : '';
                    ?>
                        <option value="<?php echo $num; ?>" <?php echo $selected; ?>>
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <select name="year" class="border border-gray-300 p-2 rounded-md">
                    <?php 
                    $startYear = date('Y') - 3;
                    $endYear = date('Y') + 1;
                    for ($year = $startYear; $year <= $endYear; $year++):
                        $selected = ($filterYear == $year) ? 'selected' : '';
                    ?>
                        <option value="<?php echo $year; ?>" <?php echo $selected; ?>>
                            <?php echo $year; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md hover:bg-blue-600">Filter</button>
        </div>
        
        <?php if ($pending_count > 0): ?>
            <div class="count-badge">
                <div class="px-3 py-1 bg-red-600 text-white rounded-full shadow-lg text-sm font-bold ml-4">
                    AC PENDING : <?php echo $pending_count; ?>
                </div>
            </div>
        <?php endif; ?>
    </form>

    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6">
        <table class="w-full table-auto p-2">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Time</th>
                    <th class="px-4 py-2 text-left">Op Code</th>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Recorded By</th>
                    <th class="px-4 py-2 text-center">AC Status</th>
                    <th class="px-4 py-2 text-center" style="min-width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($attendance_records)) {
                    echo "<tr><td colspan='7' class='border px-4 py-2 text-center text-gray-500'>No Attendance records available for " . htmlspecialchars($filterYearMonth) . ".</td></tr>";
                } else {
                    foreach ($attendance_records as $entry) {
                        
                        $record_op_code = htmlspecialchars($entry['op_code']);
                        $record_date = htmlspecialchars($entry['date']);
                        $record_user_id = $entry['user_id'];
                        $record_ac_user_id = $entry['ac_user_id']; 
                        $ac_status_db = $entry['ac']; 

                        // ⬇️ NEW CODE: Highlight row if AC Status is NULL (Pending)
                        $row_class = 'hover:bg-gray-50';
                        if ($ac_status_db === null) {
                            $row_class = 'bg-red-100 hover:bg-red-200'; 
                        }
                        // ⬆️ END NEW CODE
                        
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

                        
                        // AC Status Display Logic
                        if ($ac_status_db === 1) {
                            $ac_display = '<span class="text-green-600 font-bold">AC</span>';
                        } elseif ($ac_status_db === 2) {
                            $ac_display = '<span class="text-red-600 font-bold">NON-AC</span>';
                        } else {
                            // NULL or 0
                            $ac_display = '<span class="text-gray-500">---</span>';
                        }
                        
                        // Display user name, defaulting to '---' if NULL
                        $recorded_by = htmlspecialchars($entry['recorded_by_user_display'] ?? '---');

                        // ⬇️ MODIFIED LINE: Apply the calculated $row_class
                        echo "<tr class='{$row_class}'>
                            <td class='border px-4 py-2'>{$record_date}</td>
                            <td class='border px-4 py-2'>{$entry['time']}</td>
                            <td class='border px-4 py-2'>{$record_op_code}</td>
                            <td class='border px-4 py-2'>{$entry['vehicle_no']}</td>
                            <td class='border px-4 py-2 text-sm font-semibold'>{$recorded_by}</td>
                            
                            <td class='border px-4 py-2 text-center'>";
                            if ($can_toggle_ac) {
                                // AC Status clickable area (triggers modal)
                                echo "<button data-op-code='{$record_op_code}' 
                                        data-date='{$record_date}'
                                        data-current-ac='{$ac_status_db}'
                                        class='ac-status-btn text-blue-600 hover:text-blue-800 font-bold py-1 px-2 rounded text-xs'>
                                        {$ac_display} <i class='fas fa-pencil-alt ml-1'></i>
                                    </button>";
                            } else {
                                echo $ac_display; // Display status, but not clickable
                            }
                            echo "</td>
                            
                            <td class='border px-4 py-2 text-center'>";
                            
                            if ($is_record_owner) {
                                // Delete Button (Strictly only for record owner)
                                echo "<button data-op-code='{$record_op_code}' 
                                        data-date='{$record_date}'
                                        class='delete-attendance-btn bg-red-600 hover:bg-red-700 text-white font-bold py-1 px-2 rounded text-xs'>
                                        <i class='fas fa-trash-alt'></i>
                                    </button>";
                            } else {
                                echo "---";
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
        <h2 class="text-xl font-bold mb-4">Set AC Status for <span id="modalOpCode" class="text-indigo-600"></span></h2>
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
                <button type="button" onclick="document.getElementById('acStatusModal').style.display='none'" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded">Cancel</button>
                <button type="submit" id="acStatusSubmitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded">Submit</button>
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