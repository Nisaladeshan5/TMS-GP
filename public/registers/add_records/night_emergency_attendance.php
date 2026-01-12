<?php
// Ensure session is started if not done in header.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// --- IMPORTANT: Get User ID from Session ---
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Initialize filter variables
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Security Check Flag
$is_user_logged_in = ($user_id !== null && $user_id > 0);
$user_id = $is_user_logged_in ? $user_id : 0;

// Base SQL query
$sql = "
    SELECT
        nea.vehicle_no,
        nea.date,
        nea.report_time,
        nea.driver_NIC,
        nea.vehicle_status,
        nea.driver_status,
        nea.op_code,
        s.supplier,
        os.op_code
    FROM
        night_emergency_attendance AS nea
    JOIN
        op_services AS os ON nea.op_code = os.op_code 
    JOIN
        vehicle AS v ON os.vehicle_no = v.vehicle_no 
    JOIN
        supplier AS s ON v.supplier_code = s.supplier_code
";
$conditions = [];
$params = [];
$types = "";

// Add month and year filters
if (!empty($filter_month) && !empty($filter_year)) {
    $conditions[] = "MONTH(nea.date) = ? AND YEAR(nea.date) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= "ii";
}

if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY nea.date DESC, nea.report_time DESC";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Date Setup for Dropdowns
$months = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June',
    '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];
$current_year_sys = date('Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Night Emergency Attendance Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .red-cell {
            background-color: #fee2e2; /* red-100 */
            color: #991b1b; /* red-800 */
            font-weight: bold;
        }
        /* Style for the modal */
        #deletionModal {
            transition: opacity 0.3s ease-in-out;
        }
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
                <a href="../night_emergency.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Night Emergency Register
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    Attendance
                </span>
            </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <form method="GET" action="" class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            
            <select name="month" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <?php foreach ($months as $num => $name): 
                    $selected = ($num == $filter_month) ? 'selected' : '';
                ?>
                    <option value="<?php echo $num; ?>" <?php echo $selected; ?> class="text-gray-900 bg-white">
                        <?php echo $name; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <span class="text-gray-400 mx-1">|</span>

            <select name="year" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-1 pr-2 appearance-none hover:text-yellow-200 transition">
                <?php 
                for ($y = $current_year_sys; $y >= 2020; $y--): 
                    $selected = ($y == $filter_year) ? 'selected' : '';
                ?>
                    <option value="<?php echo $y; ?>" <?php echo $selected; ?> class="text-gray-900 bg-white">
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>

        </form>

        <span class="text-gray-600">|</span>

        <a href="../night_emergency.php" class="text-gray-300 hover:text-white transition">Register</a>
        
        <?php if ($is_user_logged_in): ?>
            <a href="night_emergency_attendance_log.php" class="text-gray-300 hover:text-red-300 transition">Deletion Log</a>
        <?php endif; ?>

        <a href="add_night_emergency_attendance.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Attendance
        </a>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200">
        <table class="w-full table-auto">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-3 text-left">Supplier</th>
                    <th class="px-4 py-3 text-left">Vehicle No</th>
                    <th class="px-4 py-3 text-left">Driver License ID</th>
                    <th class="px-4 py-3 text-left">Date</th>
                    <th class="px-4 py-3 text-left">Report Time</th>
                    <th class="px-4 py-3 text-left">OP Code</th>
                    
                    <?php if ($is_user_logged_in): ?>
                    <th class="px-4 py-3 text-center">Action</th> 
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="text-sm text-gray-700">
                <?php
                // Determine colspan dynamically
                $column_count = $is_user_logged_in ? 7 : 6; 
                
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        // Determine the CSS class for red cells
                        $vehicle_cell_class = ($row['vehicle_status'] == 0) ? 'red-cell' : '';
                        $driver_cell_class = ($row['driver_status'] == 0) ? 'red-cell' : '';
                        
                        $op_code = htmlspecialchars($row['op_code']);
                        $date = htmlspecialchars($row['date']);
                        $user_id_js = $user_id; 

                        echo "<tr class='hover:bg-indigo-50 border-b border-gray-100 transition duration-150'>";
                        echo "<td class='px-4 py-3'>" . htmlspecialchars($row['supplier']) . "</td>";
                        echo "<td class='px-4 py-3 font-bold uppercase {$vehicle_cell_class}'>" . htmlspecialchars($row['vehicle_no']) . "</td>";
                        echo "<td class='px-4 py-3 {$driver_cell_class}'>" . htmlspecialchars($row['driver_NIC']) . "</td>";
                        echo "<td class='px-4 py-3'>" . htmlspecialchars($row['date']) . "</td>";
                        echo "<td class='px-4 py-3'>" . htmlspecialchars($row['report_time']) . "</td>";
                        echo "<td class='px-4 py-3 font-mono text-indigo-600 font-medium'>" . htmlspecialchars($row['op_code']) . "</td>";
                        
                        if ($is_user_logged_in): 
                            echo "<td class='px-4 py-3 text-center'>";
                            echo "<button 
                                    type='button' 
                                    onclick='confirmDeletion(\"{$op_code}\", \"{$date}\", {$user_id_js})' 
                                    class='bg-red-500 text-white px-2 py-1.5 rounded text-xs hover:bg-red-600 transition shadow-sm'
                                    title='Delete Record'
                                  ><i class='fas fa-trash-alt'></i></button>";
                            echo "</td>"; 
                        endif;
                        
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='{$column_count}' class='px-6 py-4 text-center text-gray-500'>
                            No attendance records found for the selected period.
                          </td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($is_user_logged_in): ?>
<form id="deleteForm" method="POST" action="handle_delete_attendance.php" style="display: none;">
    <input type="hidden" name="op_code" id="delete_op_code">
    <input type="hidden" name="attendance_date" id="delete_attendance_date">
    <input type="hidden" name="user_id" id="delete_user_id">
    <input type="hidden" name="remark" id="delete_remark">
</form>

<div id="deletionModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg p-6">
        <h3 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
            <i class="fas fa-exclamation-triangle text-red-500 mr-3"></i> Confirm Deletion
        </h3>
        <p class="text-gray-600 mb-4">
            You are about to delete the attendance entry for:
        </p>
        <div class="mb-4 p-3 bg-red-50 rounded-md border border-red-200 text-sm">
            <strong class="text-gray-800">OP Code:</strong> <span id="modal_op_code_display" class="font-mono text-red-700"></span><br>
            <strong class="text-gray-800">Date:</strong> <span id="modal_date_display" class="font-mono text-red-700"></span>
        </div>
        <div class="mb-6">
            <label for="modal_remark_input" class="block text-sm font-medium text-gray-700 mb-1">
                Reason for deletion:
            </label>
            <textarea id="modal_remark_input" rows="3" class="w-full border border-gray-300 p-2 rounded-md focus:ring-red-500 focus:border-red-500 outline-none" placeholder="A required reason for auditing purposes..."></textarea>
        </div>
        
        <div class="flex justify-between space-x-3">
            <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 font-medium transition">
                Cancel
            </button>
            <button type="button" onclick="submitDeletion()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium transition shadow-sm">
                <i class="fas fa-trash-alt mr-2"></i> Confirm Delete
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Global variables
let currentOpCode = null;
let currentAttendanceDate = null;
let currentUserId = null;

function confirmDeletion(op_code, attendance_date, user_id) {
    if (user_id === 0) {
        alert("Authentication error. Please log in to perform this action.");
        return;
    }

    currentOpCode = op_code;
    currentAttendanceDate = attendance_date;
    currentUserId = user_id;

    document.getElementById('modal_op_code_display').textContent = op_code;
    document.getElementById('modal_date_display').textContent = attendance_date;
    document.getElementById('modal_remark_input').value = '';

    document.getElementById('deletionModal').classList.remove('hidden');
    document.getElementById('deletionModal').classList.add('flex');
}

function closeModal() {
    document.getElementById('deletionModal').classList.add('hidden');
    document.getElementById('deletionModal').classList.remove('flex');
}

function submitDeletion() {
    const remark = document.getElementById('modal_remark_input').value.trim();

    if (remark === "") {
        alert("A reason/remark is required for deletion.");
        document.getElementById('modal_remark_input').focus();
        return;
    }
    
    document.getElementById('delete_op_code').value = currentOpCode;
    document.getElementById('delete_attendance_date').value = currentAttendanceDate;
    document.getElementById('delete_user_id').value = currentUserId;
    document.getElementById('delete_remark').value = remark;

    document.getElementById('deleteForm').submit();
    closeModal();
}
</script>

</body>
</html>