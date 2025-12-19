<?php
// Ensure session is started if not done in header.php (Crucial for $_SESSION['user_id'])
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// --- IMPORTANT: Get User ID from Session ---
// Assuming 'user_id' is stored in the session when the user logs in.
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Initialize filter variables
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Security Check Flag: Used to control the visibility of the "Action" column and buttons
$is_user_logged_in = ($user_id !== null && $user_id > 0);
// If user_id is null/0, set it to 0 for JavaScript safety, but $is_user_logged_in handles logic
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
        nea.op_code, -- Added nea.op_code for direct use in the delete link
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

// Add month and year filters if they are set
if (!empty($filter_month) && !empty($filter_year)) {
    $conditions[] = "MONTH(nea.date) = ? AND YEAR(nea.date) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= "ii";
}

// Append conditions to the query
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
// Final ORDER BY clause
$sql .= " ORDER BY nea.date DESC, nea.report_time DESC";

// Prepare and execute the statement
$stmt = $conn->prepare($sql);
if ($types) {
    // The use of '...' requires PHP 5.6+
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
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
            background-color: #fca5a5; /* A light red color from Tailwind CSS */
        }
        /* Style for the modal so it appears centered and covers the screen */
        #deletionModal {
            transition: opacity 0.3s ease-in-out;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="../night_emergency.php" class="hover:text-yellow-600">Back to Trips</a>
        <?php if ($is_user_logged_in): ?>
        <a href="night_emergency_attendance_log.php" class="hover:text-yellow-600">Deletion Log</a>
        <?php endif; ?>
        <a href="add_night_emergency_attendance.php" class="hover:text-yellow-600">Add Attendance</a> 
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[36px] font-bold text-gray-800 mt-2">Night Emergency Attendance Register</p>

    <form method="GET" action="" class="mb-6 flex justify-center mt-1">
        <div class="flex items-center">
            <label for="month" class="text-lg font-medium mr-2">Filter by:</label>
            
            <select id="month" name="month" class="border border-gray-300 p-2 rounded-md">
                <?php
                $months = [
                    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June',
                    '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                ];
                foreach ($months as $num => $name) {
                    $selected = ($num == $filter_month) ? 'selected' : '';
                    echo "<option value='{$num}' {$selected}>{$name}</option>";
                }
                ?>
            </select>
            
            <select id="year" name="year" class="border border-gray-300 p-2 rounded-md ml-2">
                <?php
                $current_year = date('Y');
                for ($y = $current_year; $y >= 2020; $y--) {
                    $selected = ($y == $filter_year) ? 'selected' : '';
                    echo "<option value='{$y}' {$selected}>{$y}</option>";
                }
                ?>
            </select>

            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md ml-2 hover:bg-blue-600">Filter</button>
        </div>
    </form>
    
    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6 w-full">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Supplier</th>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Driver License ID</th>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Report Time</th>
                    <th class="px-4 py-2 text-left">OP Code</th>
                    
                    <?php if ($is_user_logged_in): // Only show Action header if logged in ?>
                    <th class="px-4 py-2 text-center">Action</th> 
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                // Determine colspan dynamically: 6 columns + 1 for Action if logged in
                $column_count = $is_user_logged_in ? 7 : 6; 
                
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        // Determine the CSS class for the vehicle_no cell
                        $vehicle_cell_class = ($row['vehicle_status'] == 0) ? 'red-cell' : '';
                        
                        // Determine the CSS class for the driver_NIC cell
                        $driver_cell_class = ($row['driver_status'] == 0) ? 'red-cell' : '';
                        
                        // Data for JavaScript deletion
                        $op_code = htmlspecialchars($row['op_code']);
                        $date = htmlspecialchars($row['date']);
                        // Note: $user_id is already set above to the logged-in ID or 0
                        $user_id_js = $user_id; 

                        echo "<tr class='hover:bg-gray-100'>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['supplier']) . "</td>";
                        echo "<td class='border px-4 py-2 {$vehicle_cell_class}'>" . htmlspecialchars($row['vehicle_no']) . "</td>";
                        echo "<td class='border px-4 py-2 {$driver_cell_class}'>" . htmlspecialchars($row['driver_NIC']) . "</td>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['date']) . "</td>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['report_time']) . "</td>";
                        echo "<td class='border px-4 py-2'>" . htmlspecialchars($row['op_code']) . "</td>";
                        
                        // Only show Action button cell if logged in
                        if ($is_user_logged_in): 
                            echo "<td class='border px-4 py-2 text-center'>";
                            // Updated button to call the new confirmDeletion function
                            echo "<button 
                                    type='button' 
                                    onclick='confirmDeletion(\"{$op_code}\", \"{$date}\", {$user_id_js})' 
                                    class='bg-red-500 text-white px-2 py-1 rounded text-sm hover:bg-red-600'
                                  ><i class='fas fa-trash-alt'></i></button>";
                            echo "</td>"; 
                        endif;
                        
                        echo "</tr>";
                    }
                } else {
                    // Use dynamic column count for "No records found"
                    echo "<tr><td colspan='{$column_count}' class='border px-4 py-2 text-center'>No records found.</td></tr>";
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
            <textarea id="modal_remark_input" rows="3" class="w-full border border-gray-300 p-2 rounded-md focus:ring-red-500 focus:border-red-500" placeholder="A required reason for auditing purposes..."></textarea>
        </div>
        
        <div class="flex justify-between space-x-3">
            <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                Cancel
            </button>
            <button type="button" onclick="submitDeletion()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                <i class="fas fa-trash-alt mr-2"></i> Confirm Delete
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Global variables to hold the data while the modal is open
let currentOpCode = null;
let currentAttendanceDate = null;
let currentUserId = null;

// Function to show the modal and populate data
function confirmDeletion(op_code, attendance_date, user_id) {
    // Basic client-side check
    if (user_id === 0) {
        alert("Authentication error. Please log in to perform this action.");
        return;
    }

    // Store data globally
    currentOpCode = op_code;
    currentAttendanceDate = attendance_date;
    currentUserId = user_id;

    // Populate modal fields
    document.getElementById('modal_op_code_display').textContent = op_code;
    document.getElementById('modal_date_display').textContent = attendance_date;
    
    // Clear previous remark input
    document.getElementById('modal_remark_input').value = '';

    // Show the modal
    document.getElementById('deletionModal').classList.remove('hidden');
    document.getElementById('deletionModal').classList.add('flex');
}

// Function to hide the modal
function closeModal() {
    document.getElementById('deletionModal').classList.add('hidden');
    document.getElementById('deletionModal').classList.remove('flex');
}

// Function to validate remark and submit the hidden form
function submitDeletion() {
    const remark = document.getElementById('modal_remark_input').value.trim();

    if (remark === "") {
        alert("A reason/remark is required for deletion.");
        document.getElementById('modal_remark_input').focus();
        return;
    }
    
    // 1. Populate Hidden Form Fields
    document.getElementById('delete_op_code').value = currentOpCode;
    document.getElementById('delete_attendance_date').value = currentAttendanceDate;
    document.getElementById('delete_user_id').value = currentUserId;
    document.getElementById('delete_remark').value = remark;

    // 2. Submit the Form
    document.getElementById('deleteForm').submit();
    
    // Close the modal immediately after submission (optional, as the page will redirect)
    closeModal();
}
</script>

</body>
</html>