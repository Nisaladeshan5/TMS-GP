<?php
// own_vehicle_attendance.php

// 1. Session Start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 2. Login Check
// if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
//     header("Location: ../../../includes/login.php");
//     exit();
// }

$is_logged_in = true;
$user_role = $_SESSION['user_role'] ?? ''; 
$current_user_id = $_SESSION['user_id'] ?? 0;

// 3. Include DB ONLY
include('../../../includes/db.php');

// ------------------------------------------------------------------
// --- DELETE HANDLER ---
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    
    $d_emp_id = $_POST['emp_id'];
    $d_vehicle_no = $_POST['vehicle_no'];
    $d_date = $_POST['date'];
    $d_time = $_POST['time']; 

    // Security & Logic Checks (As per previous code)
    $checkSql = "SELECT user_id FROM own_vehicle_attendance WHERE emp_id = ? AND vehicle_no = ? AND date = ? AND time = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ssss", $d_emp_id, $d_vehicle_no, $d_date, $d_time);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();
    
    if ($checkRes->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Record not found.']);
        exit;
    }
    $recordData = $checkRes->fetch_assoc();
    
    if ($recordData['user_id'] != $current_user_id) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied.']);
        exit;
    }

    $paySql = "SELECT year, month FROM own_vehicle_payments ORDER BY year DESC, month DESC LIMIT 1";
    $payRes = $conn->query($paySql);
    if ($payRes && $payRes->num_rows > 0) {
        $payRow = $payRes->fetch_assoc();
        $locked_limit = date("Y-m-t", strtotime($payRow['year'] . "-" . $payRow['month'] . "-01"));
        if ($d_date <= $locked_limit) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete. Finalized in payments.']);
            exit;
        }
    }

    $delSql = "DELETE FROM own_vehicle_attendance WHERE emp_id = ? AND vehicle_no = ? AND date = ? AND time = ?";
    $delStmt = $conn->prepare($delSql);
    $delStmt->bind_param("ssss", $d_emp_id, $d_vehicle_no, $d_date, $d_time);
    
    if ($delStmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Record deleted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error.']);
    }
    exit;
}
// ------------------------------------------------------------------

// 4. Include Headers
include('../../../includes/header.php');
include('../../../includes/navbar.php');

$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'); 
$prevDate = date('Y-m-d', strtotime($filter_date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($filter_date . ' +1 day'));

// Payment Lock Logic
$locked_date_limit = '0000-00-00'; 
$payCheckSql = "SELECT year, month FROM own_vehicle_payments ORDER BY year DESC, month DESC LIMIT 1";
$payCheckResult = $conn->query($payCheckSql);
if ($payCheckResult && $payCheckResult->num_rows > 0) {
    $lastPay = $payCheckResult->fetch_assoc();
    $locked_date_limit = date("Y-m-t", strtotime($lastPay['year'] . "-" . $lastPay['month'] . "-01"));
}

// Data Query
$sql = "
    SELECT
        ova.user_id,
        ova.emp_id,
        e.calling_name,
        ova.vehicle_no,
        ova.date,
        ova.time,
        ova.out_time,
        admin_e.calling_name AS entered_by
    FROM
        own_vehicle_attendance AS ova
    JOIN
        employee AS e ON ova.emp_id = e.emp_id
    LEFT JOIN 
        admin AS a ON ova.user_id = a.user_id
    LEFT JOIN 
        employee AS admin_e ON a.emp_id = admin_e.emp_id
";

$conditions = [];
$params = [];
$types = "";

if (!empty($filter_date)) {
    if (DateTime::createFromFormat('Y-m-d', $filter_date) !== false) {
        $conditions[] = "ova.date = ?";
        $params[] = $filter_date;
        $types .= "s";
    } else {
        $filter_date = date('Y-m-d'); 
        $conditions[] = "ova.date = ?";
        $params[] = $filter_date;
        $types .= "s";
    }
}
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY ova.time DESC";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Own Vehicle Attendance Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
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
<body class="bg-gray-100 overflow-hidden">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="own_vehicle_attendance.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
                Own Vehicle Register
            </a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Attendance
            </span>
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            <a href="?date=<?php echo $prevDate; ?>" class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150"><i class="fas fa-chevron-left"></i></a>
            <form method="GET" class="flex items-center mx-1">
                <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer px-2 appearance-none text-center h-8">
            </form>
            <a href="?date=<?php echo $nextDate; ?>" class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150"><i class="fas fa-chevron-right"></i></a>
        </div>
        <span class="text-gray-600">|</span>
        <?php if ($is_logged_in): ?>
            <a href="unmark_own_vehicle_attendance.php" class="text-gray-300 hover:text-white transition">Unmark Managers</a>
            <a href="own_vehicle_attendance_calendar.php" class="text-gray-300 hover:text-white transition">Calender</a>
            <a href="own_vehicle_extra_register.php" class="text-gray-300 hover:text-white transition">Extra Register</a>
            <a href="add_own_vehicle_attendance.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">Add Attendance</a>
        <?php endif; ?>
    </div>
</div>

<div class="w-[85%] ml-[15%] mt-2 px-2 flex flex-col">
    
    <div class="max-h-[calc(100vh-70px)] overflow-y-auto shadow-lg rounded-lg border border-gray-200 bg-white w-full mx-auto relative mb-4">
        
        <table class="w-full table-auto border-collapse">
            <thead class="text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Employee ID</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Employee Name</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Vehicle No</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Date</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">In Time</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Out Time</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-left shadow-sm">Entered By</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-6 py-3 text-center shadow-sm" style="width: 80px;">Action</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        
                        $out_time_display = !empty($row['out_time']) && $row['out_time'] != '00:00:00' 
                                            ? htmlspecialchars($row['out_time']) 
                                            : '<span class="text-gray-400 italic">-</span>';
                        $entered_by = !empty($row['entered_by']) 
                                      ? htmlspecialchars($row['entered_by']) 
                                      : '<span class="text-gray-400 italic">-</span>';

                        $row_dom_id = "row_" . $row['emp_id'] . "_" . str_replace([':', ' ', '-'], '', $row['time']);
                        $row_class = "hover:bg-indigo-50"; 
                        $is_owner = false;

                        if (!empty($row['user_id']) && $row['user_id'] != 0) {
                            $row_class = "bg-yellow-100 hover:bg-yellow-200"; 
                            if ($row['user_id'] == $current_user_id) {
                                $is_owner = true;
                            }
                        }

                        $is_locked = ($row['date'] <= $locked_date_limit);

                        echo "<tr class='$row_class border-b border-gray-100 transition duration-150' id='$row_dom_id'>";
                        echo "<td class='px-6 py-3 font-mono text-blue-600 font-medium'>" . htmlspecialchars($row['emp_id']) . "</td>";
                        echo "<td class='px-6 py-3 font-medium text-gray-800'>" . htmlspecialchars($row['calling_name']) . "</td>";
                        echo "<td class='px-6 py-3 font-bold uppercase'>" . htmlspecialchars($row['vehicle_no']) . "</td>";
                        echo "<td class='px-6 py-3'>" . htmlspecialchars($row['date']) . "</td>";
                        echo "<td class='px-6 py-3 font-bold text-green-600'>" . htmlspecialchars($row['time']) . "</td>";
                        echo "<td class='px-6 py-3 font-bold text-red-600'>" . $out_time_display . "</td>";
                        echo "<td class='px-6 py-3 text-gray-600 font-medium text-xs'>" . $entered_by . "</td>";
                        
                        echo "<td class='px-6 py-3 text-center'>";
                        if ($is_owner && !$is_locked) {
                            echo "<button onclick='deleteRecord(\"" . $row['emp_id'] . "\", \"" . $row['vehicle_no'] . "\", \"" . $row['date'] . "\", \"" . $row['time'] . "\", \"$row_dom_id\")' 
                                          class='text-red-500 hover:text-red-700 transition' title='Delete Record'><i class='fas fa-trash'></i></button>";
                        } elseif ($is_locked) {
                            echo "<span class='text-gray-400 cursor-not-allowed' title='Locked by Payment ($locked_date_limit)'><i class='fas fa-lock'></i></span>";
                        } else {
                            echo "<span class='text-gray-300'><i class='fas fa-ban'></i></span>";
                        }
                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='8' class='px-6 py-4 text-center text-gray-500'>
                            <div class='flex flex-col items-center justify-center'>
                                <p>No attendance records found for " . htmlspecialchars($filter_date) . ".</p>
                            </div>
                          </td></tr>";
                }
                $stmt->close();
                if (isset($conn)) $conn->close();
                ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    async function deleteRecord(emp_id, vehicle_no, date, time, row_dom_id) {
        if (!confirm('Are you sure you want to delete this record?')) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('emp_id', emp_id);
        formData.append('vehicle_no', vehicle_no);
        formData.append('date', date);
        formData.append('time', time);

        try {
            const response = await fetch('own_vehicle_attendance.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.status === 'success') {
                // 1. Row එක අයින් කරන්න
                const row = document.getElementById(row_dom_id);
                if (row) row.remove();

                // 2. Table එක හිස්දැයි බලන්න (Check if table is empty)
                const tbody = document.querySelector('tbody');
                
                // Rows ගණන 0 නම්, No Records පණිවිඩය පෙන්වන්න
                if (tbody && tbody.children.length === 0) {
                    // Date input එකේ value එක ගන්න (Message එක ලස්සන කරන්න)
                    const dateVal = document.querySelector('input[name="date"]').value;

                    tbody.innerHTML = `
                        <tr><td colspan='8' class='px-6 py-4 text-center text-gray-500'>
                            <div class='flex flex-col items-center justify-center'>
                                <p>No attendance records found for ${dateVal}.</p>
                            </div>
                        </td></tr>
                    `;
                }

                alert('Record Deleted!');
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Something went wrong. Check console.');
        }
    }
</script>

</body>
</html>