<?php
require_once '../../../includes/session_check.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$logged_in_user_id = $is_logged_in && isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null; 

if (!$is_logged_in) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
// Set timezone for consistency
date_default_timezone_set('Asia/Colombo');

// Error reporting settings (Set to 0 in production)
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// --- New Database Function: Get Pending Count ---
function get_pending_trip_count($conn) { 
    $sql = "SELECT COUNT(id) FROM own_vehicle_extra WHERE done = 0"; 
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("Pending Count Prepare Failed: " . $conn->error);
        return 0;
    }
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int)$count;
}
// --- End New Database Function ---


// --- AJAX Handler for Completing Trip (UNCHANGED) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_trip']) && isset($_POST['id'])) {
    if (ob_get_length() > 0) {
        ob_clean();
    }
    header('Content-Type: application/json');

    $record_id = (int)$_POST['id'];
    $distance = isset($_POST['distance']) ? (float)$_POST['distance'] : 0.00; 
    
    $user_id_for_record = $logged_in_user_id;

    if ($distance < 0 || !is_numeric($distance)) {
        echo json_encode(['success' => false, 'message' => 'Distance must be a valid positive number.']);
        if (isset($conn)) $conn->close();
        exit();
    }
    if (empty($user_id_for_record)) {
         echo json_encode(['success' => false, 'message' => 'Error: User ID not found in session for recording.']);
         if (isset($conn)) $conn->close();
         exit();
    }

    $conn->begin_transaction();

    try {
        $update_sql = "UPDATE own_vehicle_extra SET distance = ?, done = 1, user_id = ? WHERE id = ?";
        
        $update_stmt = $conn->prepare($update_sql);
        if ($update_stmt === false) {
             throw new Exception("Update Prepare Failed: " . $conn->error);
        }
        
        $update_stmt->bind_param('dsi', $distance, $user_id_for_record, $record_id); 

        if (!$update_stmt->execute()) {
            throw new Exception("Update Execute Failed: " . $update_stmt->error);
        }
        $update_stmt->close();

        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => "Trip completed! Recorded {$distance} km.",
            'id' => $record_id,
            'done_by_user_id' => $user_id_for_record 
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Extra Trip Completion Failed: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database Error: Failed to complete trip.']);
    }

    if (isset($conn)) $conn->close();
    exit(); 
}
// --- End AJAX Handler ---


// Initialize filter variables for Month and Year
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y'); 

// Fetch the total pending count
$pending_count = get_pending_trip_count($conn);

// Base SQL query (UNCHANGED)
$sql = "
    SELECT
        ove.id,
        ove.emp_id,
        e.calling_name,
        ove.vehicle_no,
        ove.date,
        ove.out_time,
        ove.in_time,
        ove.distance,
        ove.done,
        ove.user_id,
        CASE
            WHEN ove.done = 1 AND ove.user_id IS NOT NULL 
            THEN user_employee.calling_name 
            ELSE NULL 
        END AS done_by_user_display 
    FROM
        own_vehicle_extra AS ove
    JOIN
        employee AS e ON ove.emp_id = e.emp_id
    LEFT JOIN 
        admin AS a ON ove.user_id = a.user_id
    LEFT JOIN
        employee AS user_employee ON a.emp_id = user_employee.emp_id 
";
$conditions = [];
$params = [];
$types = "";

// Add Month and Year filters
if (!empty($filter_month) && !empty($filter_year)) {
    $conditions[] = "MONTH(ove.date) = ? AND YEAR(ove.date) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= "ii";
}

// Append conditions to the query
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
// Final ORDER BY clause: Order by Date, then Out Time
$sql .= " ORDER BY ove.date DESC, ove.out_time ASC";

// Prepare and execute the statement
$stmt = $conn->prepare($sql);

if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Required for the filter form display
$months = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June',
    '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];
$current_year_sys = date('Y');

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Own Vehicle Extra Travel Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Toast Notification Styling */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        
        /* Modal Styling */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); display: none; justify-content: center; align-items: center; z-index: 3000; }
        .modal-content { background-color: white; padding: 2rem; border-radius: 0.5rem; width: 90%; max-width: 400px; box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2); }
        
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
            <a href="own_vehicle_attendance.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
                Own Vehicle Register
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Extra
            </span>
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <?php if ($pending_count > 0): ?>
            <div class="bg-red-600 text-white px-3 py-1 rounded-full shadow-lg text-xs font-bold animate-pulse flex items-center gap-1">
                <i class="fas fa-exclamation-circle"></i> PENDING: <?php echo $pending_count; ?>
            </div>
        <?php endif; ?>

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

        <a href="own_vehicle_attendance.php" class="text-gray-300 hover:text-white transition">Attendance</a>
        
        <?php if ($is_logged_in): ?>
            <a href="add_own_vehicle_extra.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
                Add Extra
            </a>
        <?php endif; ?>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full mx-auto">
        <table class="w-full table-auto">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-6 py-3 text-left">Employee ID</th>
                    <th class="px-6 py-3 text-left">Employee Name</th>
                    <th class="px-6 py-3 text-left">Vehicle No</th>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3 text-left">Out Time</th>
                    <th class="px-6 py-3 text-left">In Time</th>
                    <th class="px-6 py-3 text-left">Distance (km)</th>
                    <th class="px-6 py-3 text-left">Done By</th> 
                    <th class="px-6 py-3 text-center">Action</th> 
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        
                        $record_id = htmlspecialchars($row['id']); 

                        // FIX for NULL/Deprecated values
                        $out_time_display = htmlspecialchars($row['out_time'] ?? '---');
                        $in_time_display = htmlspecialchars($row['in_time'] ?? '---');
                        
                        $distance_display = $row['distance'] !== NULL 
                                            ? number_format($row['distance'], 2) 
                                            : '---';

                        // Determine display value for "Done By" column
                        $done_by_display = '---';
                        $row_bg = 'hover:bg-red-50'; // Default pending highlight

                        if ($row['done'] == 1) {
                            $done_by_name = htmlspecialchars($row['done_by_user_display'] ?? 'N/A');
                            $done_by_display = "<span class='text-green-600 font-semibold'>{$done_by_name}</span>";
                            $row_bg = 'hover:bg-green-50'; // Completed highlight
                        } elseif ($row['done'] == 0) {
                            $done_by_display = '<span class="text-red-600 font-semibold">Pending</span>';
                        }
                        
                        echo "<tr class='{$row_bg} border-b border-gray-100 transition duration-150' id='row-{$record_id}'>";
                        echo "<td class='px-6 py-3 font-mono text-blue-600 font-medium'>" . htmlspecialchars($row['emp_id']) . "</td>";
                        echo "<td class='px-6 py-3 font-medium text-gray-800'>" . htmlspecialchars($row['calling_name']) . "</td>";
                        echo "<td class='px-6 py-3 font-bold uppercase'>" . htmlspecialchars($row['vehicle_no']) . "</td>";
                        echo "<td class='px-6 py-3'>" . htmlspecialchars($row['date']) . "</td>";
                        echo "<td class='px-6 py-3 out-time-cell-{$record_id}'>" . $out_time_display . "</td>";
                        echo "<td class='px-6 py-3 in-time-cell-{$record_id}'>" . $in_time_display . "</td>";
                        echo "<td class='px-6 py-3 text-left font-mono distance-cell-{$record_id}'>" . $distance_display . "</td>";
                        
                        // Display "Done By" column
                        echo "<td class='px-6 py-3 done-by-cell-{$record_id}'>{$done_by_display}</td>";
                        
                        // Action Button Column
                        echo "<td class='px-6 py-3 action-cell-{$record_id} text-center'>";
                        if ($row['done'] == 0) {
                            echo "<button data-id='{$record_id}' 
                                          class='complete-trip-btn bg-green-500 hover:bg-green-600 text-white font-bold py-1.5 px-3 rounded text-xs shadow-sm transition transform hover:scale-105'>
                                    Complete
                                  </button>";
                        } else {
                            echo "<span class='text-gray-400 text-xs italic'><i class='fas fa-check'></i> Done</span>";
                        }
                        echo "</td>";
                        
                        echo "</tr>";
                    }
                } else {
                    $month_name = $months[$filter_month] ?? 'Selected Month';
                    echo "<tr><td colspan='9' class='px-6 py-4 text-center text-gray-500'>
                            No extra travel records found in {$month_name} {$filter_year}
                          </td></tr>";
                }
                $stmt->close();
                if (isset($conn)) $conn->close();
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

<div id="completeTripModal" class="modal-overlay">
    <div class="modal-content">
        <h2 class="text-xl font-bold mb-4 text-gray-800">Complete Extra Trip</h2>
        <p class="mb-4 text-sm text-gray-600">Enter the total distance traveled for the pending trip record ID: <span id="modalRecordId" class="font-semibold text-indigo-600"></span></p>
        
        <form id="distanceForm">
            <div class="mb-6">
                <label for="distanceInput" class="block text-sm font-medium text-gray-700 mb-1">Distance Traveled (km):</label>
                <input type="number" step="0.01" min="0" id="distanceInput" required 
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="0.00">
            </div>

            <div class="flex justify-end space-x-3">
                <button type="button" id="cancelModalBtn" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">
                    Cancel
                </button>
                <button type="submit" id="confirmModalBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded transition shadow">
                    Confirm & Complete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Global variables for modal elements
const modalOverlay = document.getElementById('completeTripModal');
const modalRecordIdDisplay = document.getElementById('modalRecordId');
const distanceForm = document.getElementById('distanceForm');
const distanceInput = document.getElementById('distanceInput');
const cancelModalBtn = document.getElementById('cancelModalBtn');

let currentRecordId = null; // Stores the ID of the record being processed
const loggedInUserId = '<?php echo $logged_in_user_id; ?>'; // Passed from PHP session

// --- Toast Notification Functions ---
function showToast(message, type) {
    const toastContainer = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.classList.add('toast', type, 'show');
    const iconHtml = type === 'success' ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-triangle mr-2"></i>';
    
    toast.innerHTML = iconHtml + `<span>${message}</span>`;

    toastContainer.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.remove('show');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }, 5000); 
}

// --- Modal Control Functions ---
function showModal(id) {
    currentRecordId = id;
    modalRecordIdDisplay.textContent = id;
    distanceInput.value = ''; // Clear previous input
    distanceInput.focus();
    modalOverlay.style.display = 'flex';
}

function hideModal() {
    modalOverlay.style.display = 'none';
    currentRecordId = null;
}

// --- Main Logic ---

document.addEventListener('DOMContentLoaded', () => {
    
    if (!loggedInUserId || loggedInUserId === '') {
        console.error("User ID not found in session. Completion actions disabled.");
        showToast("Error: User session is incomplete. Please log in again.", 'error');
    }
    
    // Event listener for opening the modal via the "Complete Trip" buttons
    document.querySelectorAll('.complete-trip-btn').forEach(button => {
        button.addEventListener('click', function() {
            const recordId = this.getAttribute('data-id');
            showModal(recordId);
        });
    });

    // Event listener for canceling the modal
    cancelModalBtn.addEventListener('click', hideModal);

    // Event listener for submitting the distance form inside the modal
    distanceForm.addEventListener('submit', function(event) {
        event.preventDefault();
        
        const distance = parseFloat(distanceInput.value.trim());

        if (isNaN(distance) || distance < 0) {
            showToast("Please enter a valid positive distance.", 'error');
            distanceInput.focus();
            return;
        }

        if (currentRecordId) {
            sendUpdate(currentRecordId, distance);
        }
    });


    async function sendUpdate(id, distance) {
        const formData = new FormData();
        formData.append('complete_trip', '1');
        formData.append('id', id);
        formData.append('distance', distance);

        try {
            const response = await fetch('own_vehicle_extra_register.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.statusText);
            }

            const result = await response.json();

            if (result.success) {
                showToast(result.message, 'success');
                hideModal(); 

                // --- CORE FIX: Ensure reload after success message ---
                setTimeout(() => {
                    window.location.reload(); 
                }, 1000); 
                
            } else {
                showToast(result.message, 'error');
            }

        } catch (error) {
            console.error('AJAX Error:', error);
            showToast('Server Error.', 'error'); 
        }
    }
});
</script>
</body>
</html>