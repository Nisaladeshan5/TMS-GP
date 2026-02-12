<?php
// Note: This file MUST NOT have any whitespace/characters before the opening <?php tag.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- Database Functions ---
function fetch_details_by_vehicle_no($conn, $vehicle_no) {
    $sql = "SELECT ov.emp_id, ov.vehicle_no, ov.is_active, e.calling_name 
            FROM own_vehicle AS ov
            LEFT JOIN employee AS e ON ov.emp_id = e.emp_id 
            WHERE ov.vehicle_no = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $vehicle_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row && !empty($row['emp_id'])) {
        return [
            'emp_id' => $row['emp_id'],
            'calling_name' => $row['calling_name'] ?? 'N/A', 
            'vehicle_no' => $row['vehicle_no'],
            'is_active' => (int)$row['is_active']
        ];
    }
    return null;
}

function get_daily_attendance_record($conn, $emp_id, $date) {
    $sql = "SELECT time, out_time FROM own_vehicle_attendance WHERE emp_id = ? AND date = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $emp_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc(); 
    $stmt->close();
    return $row;
}

// --- AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $vehicle_no = strtoupper(trim($_POST['vehicle_no']));
    $today_date = date('Y-m-d');
    $current_time = date('H:i:s');
    
    $details = fetch_details_by_vehicle_no($conn, $vehicle_no);

    if (!$details) {
        echo json_encode(['success' => false, 'message' => "Vehicle <b>{$vehicle_no}</b> not found."]);
        exit();
    }
    
    if ($details['is_active'] !== 1) {
        echo json_encode(['success' => false, 'message' => "ACCESS DENIED!<br>Vehicle is <b>INACTIVE</b>.<br>Contact Admin."]);
        exit();
    }

    $emp_id = $details['emp_id'];
    $calling_name = $details['calling_name'];

    $conn->begin_transaction();
    try {
        $existing_record = get_daily_attendance_record($conn, $emp_id, $today_date);
        if (!$existing_record) {
            $stmt = $conn->prepare("INSERT INTO own_vehicle_attendance (emp_id, date, time, vehicle_no) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $emp_id, $today_date, $current_time, $vehicle_no);
            $stmt->execute();
            $message = "Check-IN Recorded:<br><b class='text-emerald-600'>{$calling_name}</b><br>at {$current_time}";
        } else {
            $stmt = $conn->prepare("UPDATE own_vehicle_attendance SET out_time = ? WHERE emp_id = ? AND date = ?");
            $stmt->bind_param('sss', $current_time, $emp_id, $today_date);
            $stmt->execute();
            $message = "Check-OUT Recorded:<br><b class='text-amber-600'>{$vehicle_no}</b><br>at {$current_time}";
        }
        $conn->commit();
        echo json_encode(['success' => true, 'message' => $message]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => "DB Error"]);
    }
    exit(); 
}

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Own Vehicle Attendance</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        /* Parana Modal Style Ekama Fix Kala */
        .modal { opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s ease; }
        .modal.active { opacity: 1; visibility: visible; }
        .modal-content { transform: scale(0.95); transition: transform 0.3s ease; }
        .modal.active .modal-content { transform: scale(1); }
        .pattern-dots { background-image: radial-gradient(#ffffff22 1px, transparent 1px); background-size: 20px 20px; }
    </style>
</head>
<body class="bg-slate-950 text-white">

<div class="fixed top-0 left-[15%] w-[85%] bg-slate-900/90 backdrop-blur border-b border-slate-800 h-16 flex justify-between items-center px-6 z-40 shadow-lg">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Own Vehicle Management
        </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="../../registers/non_paid/own_vehicle_attendance.php" class="text-slate-400 hover:text-indigo-400 transition flex items-center gap-2">
            <i class="fas fa-list-alt"></i> View Register
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex flex-col justify-center items-center">
    <div class="w-full max-w-lg bg-slate-900 rounded-2xl shadow-2xl border border-slate-800 overflow-hidden">
        
        <div class="bg-indigo-700 p-6 text-center relative overflow-hidden">
            <div class="absolute inset-0 bg-black opacity-20 pattern-dots"></div>
            <div class="relative z-10">
                <div class="w-16 h-16 bg-slate-900 rounded-full flex items-center justify-center mx-auto mb-3 shadow-lg border border-slate-700 text-indigo-400">
                    <i class="fas fa-qrcode text-3xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-white tracking-tight">Scan Vehicle Code</h2>
                <p class="text-indigo-200 text-sm mt-1">Place cursor in box & scan barcode</p>
            </div>
        </div>

        <div class="p-8">
            <div class="relative mb-6">
                <label for="vehicleNoInput" class="block text-sm font-bold text-indigo-400 mb-2 uppercase tracking-wide">Vehicle Number</label>
                <div class="relative group">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-car text-slate-500 group-focus-within:text-indigo-400 transition-colors"></i>
                    </div>
                    <input type="text" id="vehicleNoInput" 
                           class="block w-full pl-12 pr-4 py-4 bg-slate-950 border-2 border-slate-700 rounded-xl text-white text-xl font-mono focus:ring-0 focus:border-indigo-500 transition-colors uppercase placeholder-slate-600 text-center shadow-inner" 
                           placeholder="SCAN HERE..." autofocus oninput="this.value=this.value.toUpperCase();">
                    
                    <div class="absolute top-0 right-4 h-full flex items-center">
                        <span class="relative flex h-3 w-3">
                          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-500 opacity-75"></span>
                          <span class="relative inline-flex rounded-full h-3 w-3 bg-indigo-400"></span>
                        </span>
                    </div>
                </div>
            </div>

            <div id="statusMessage" class="rounded-lg p-4 text-center text-sm font-medium bg-slate-800 text-slate-400 border border-slate-700 flex items-center justify-center gap-2 min-h-[60px]">
                <i class="fas fa-info-circle"></i> Ready to scan...
            </div>
        </div>
    </div>
</div>

<div id="confirmation-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900 bg-opacity-60 backdrop-blur-sm px-4">
    <div class="modal-content bg-white rounded-lg shadow-2xl w-full max-w-md p-6 relative">
        <button onclick="closeCustomModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
            <i class="fas fa-times text-lg"></i>
        </button>

        <div class="flex flex-col items-center text-center">
            <div id="modal-icon-container" class="w-16 h-16 rounded-full flex items-center justify-center mb-4 text-3xl shadow-inner">
                <i id="modal-icon" class="fas"></i>
            </div>
            
            <h3 id="modal-title" class="text-xl font-bold text-gray-800 mb-2"></h3>
            
            <p id="modal-message" class="text-gray-600 mb-6 px-4 text-sm"></p>
            
            <div class="flex gap-3 w-full justify-center">
                <button onclick="closeCustomModal()" id="modal-btn" class="px-8 py-2 rounded-lg text-white font-medium shadow-md transition hover:shadow-lg w-1/2 uppercase tracking-wide text-xs">
                    OK (Enter)
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const vehicleNoInput = document.getElementById('vehicleNoInput');
    const modal = document.getElementById('confirmation-modal');
    const statusMessage = document.getElementById('statusMessage');
    let scanTimeout;

    function openCustomModal(success, title, message) {
        const iconContainer = document.getElementById('modal-icon-container');
        const icon = document.getElementById('modal-icon');
        const btn = document.getElementById('modal-btn');
        
        if (success) {
            iconContainer.className = "w-16 h-16 rounded-full flex items-center justify-center mb-4 text-3xl shadow-inner bg-green-100 text-green-500";
            icon.className = "fas fa-check-circle";
            btn.className = "px-8 py-2 rounded-lg text-white font-medium shadow-md transition hover:shadow-lg w-1/2 uppercase tracking-wide text-xs bg-green-600 hover:bg-green-700";
        } else {
            iconContainer.className = "w-16 h-16 rounded-full flex items-center justify-center mb-4 text-3xl shadow-inner bg-red-100 text-red-500";
            icon.className = "fas fa-exclamation-triangle";
            btn.className = "px-8 py-2 rounded-lg text-white font-medium shadow-md transition hover:shadow-lg w-1/2 uppercase tracking-wide text-xs bg-red-600 hover:bg-red-700";
        }
        
        document.getElementById('modal-title').innerText = title;
        document.getElementById('modal-message').innerHTML = message;
        modal.classList.add('active');
    }

    function closeCustomModal() {
        modal.classList.remove('active');
        vehicleNoInput.value = '';
        setTimeout(() => vehicleNoInput.focus(), 150);
    }

    function submitAttendance(vehicleNo) {
        statusMessage.innerHTML = '<i class="fas fa-spinner fa-spin text-indigo-400"></i> Processing...';
        statusMessage.className = 'rounded-lg p-4 text-center text-sm font-medium bg-indigo-900/20 text-indigo-300 border border-indigo-900/50 flex items-center justify-center gap-2';

        const formData = new FormData();
        formData.append('add_record', '1');
        formData.append('vehicle_no', vehicleNo);

        fetch('own_vehicle_attendance_qr.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                openCustomModal(true, "CONFIRMED", data.message);
                statusMessage.className = 'rounded-lg p-4 text-center text-sm font-medium bg-emerald-900/20 text-emerald-400 border border-emerald-900/50 flex items-center justify-center gap-2';
                statusMessage.innerHTML = `<i class="fas fa-check-circle"></i> Last Success: ${vehicleNo}`;
            } else {
                openCustomModal(false, "ERROR", data.message);
                statusMessage.className = 'rounded-lg p-4 text-center text-sm font-medium bg-red-900/20 text-red-400 border border-red-900/50 flex items-center justify-center gap-2';
                statusMessage.innerHTML = `<i class="fas fa-times-circle"></i> Scan Error: ${vehicleNo}`;
            }
        })
        .catch(() => openCustomModal(false, "Connection Error", "Network error occurred."));
    }

    vehicleNoInput.addEventListener('input', () => {
        clearTimeout(scanTimeout);
        scanTimeout = setTimeout(() => {
            const val = vehicleNoInput.value.trim();
            if(val.length > 3) submitAttendance(val);
        }, 400);
    });

    window.addEventListener('keydown', (e) => {
        if(e.key === 'Enter' && modal.classList.contains('active')) closeCustomModal();
    });

    document.addEventListener('click', () => {
        if(!modal.classList.contains('active')) vehicleNoInput.focus();
    });
</script>

</body>
</html>