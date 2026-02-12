<?php
// day_heldup_edit_reasons.php - Styled exactly like day_heldup_add.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- 1. AJAX HANDLER (Update, Add, Delete) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_length() > 0) ob_clean(); 
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Invalid Request.'];

    try {
        $target_trip_id = (int)($_POST['trip_id'] ?? 0);
        if ($target_trip_id === 0) throw new Exception("Trip ID missing.");

        if (isset($_POST['action']) && $_POST['action'] === 'update_general') {
            $vehicle_no = strtoupper(trim($_POST['vehicle_no']));
            $op_code = trim($_POST['op_code']);
            $date = $_POST['date'];
            $out_time = $_POST['out_time'];
            $in_time = $_POST['in_time'];
            $distance = (float)$_POST['distance'];

            $update_sql = "UPDATE day_heldup_register SET vehicle_no=?, op_code=?, date=?, out_time=?, in_time=?, distance=? WHERE trip_id=?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("sssssdi", $vehicle_no, $op_code, $date, $out_time, $in_time, $distance, $target_trip_id);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Trip updated successfully!'];
            } else {
                throw new Exception("Update Failed: " . $conn->error);
            }
            $stmt->close();
        }

        if (isset($_POST['action_reason']) && $_POST['action_reason'] === 'add_reason') {
            $emp_id = strtoupper(trim($_POST['emp_id']));
            $reason_code = trim($_POST['reason_code']);
            $insert_sql = "INSERT INTO dh_emp_reason (trip_id, emp_id, reason_code) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param("iss", $target_trip_id, $emp_id, $reason_code);
            if ($stmt->execute()) $response = ['success' => true, 'message' => 'Reason added.'];
            $stmt->close();
        }

        if (isset($_POST['action_reason']) && $_POST['action_reason'] === 'delete_reason') {
            $record_id = (int)$_POST['record_id'];
            $stmt = $conn->prepare("DELETE FROM dh_emp_reason WHERE id = ? AND trip_id = ?");
            $stmt->bind_param("ii", $record_id, $target_trip_id);
            if ($stmt->execute()) $response = ['success' => true, 'message' => 'Reason deleted.'];
            $stmt->close();
        }

    } catch (Exception $e) { $response['message'] = $e->getMessage(); }
    echo json_encode($response);
    exit();
}

// --- 2. FETCH DATA ---
$target_trip_id = (int)($_GET['trip_id'] ?? 0);
if ($target_trip_id === 0) die("Error: Trip ID missing.");

$trip_sql = "SELECT * FROM day_heldup_register WHERE trip_id = ? LIMIT 1";
$stmt = $conn->prepare($trip_sql);
$stmt->bind_param("i", $target_trip_id);
$stmt->execute();
$trip = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$trip) die("Error: Record not found.");

$op_codes = $conn->query("SELECT op_code FROM op_services WHERE op_code LIKE 'DH-%' ORDER BY op_code ASC")->fetch_all(MYSQLI_ASSOC);
$existing_reasons_sql = "SELECT dher.*, r.reason, g.gl_name, e.calling_name, e.department FROM dh_emp_reason dher JOIN reason r ON dher.reason_code = r.reason_code JOIN gl g ON r.gl_code = g.gl_code LEFT JOIN employee e ON dher.emp_id = e.emp_id WHERE dher.trip_id = $target_trip_id ORDER BY dher.id ASC";
$existing_reasons = $conn->query($existing_reasons_sql)->fetch_all(MYSQLI_ASSOC);
$all_reasons = $conn->query("SELECT r.*, g.gl_name FROM reason r JOIN gl g ON r.gl_code = g.gl_code ORDER BY g.gl_name, r.reason")->fetch_all(MYSQLI_ASSOC);

include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Trip - <?= $target_trip_id; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 5000; }
        .toast { display: flex; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
    </style>
</head>
<body class="bg-gray-100 font-sans h-screen overflow-hidden">
    <div id="toast-container"></div>

    <div class="w-[85%] ml-[15%] flex justify-center p-3 h-full">
        <div class="container max-w-2xl bg-white shadow-lg rounded-lg p-8 mt-2 flex flex-col h-full max-h-[95vh]">
            
            <div class="shrink-0 mb-4">
                <h1 class="text-3xl font-extrabold text-gray-900 mb-2 border-b pb-2">
                    Edit Day Heldup Trip
                </h1>
                <p class="text-sm text-gray-600">
                    ID Context: <strong><?= $target_trip_id ?></strong>. Modify trip details and reasons below.
                </p>
            </div>

            <div class="flex-grow overflow-y-auto pr-2 space-y-6">
                
                <form id="generalDetailsForm" class="space-y-6">
                    <input type="hidden" name="action" value="update_general">
                    <input type="hidden" name="trip_id" value="<?= $target_trip_id; ?>">

                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Op Code<span class="text-red-500">*</span></label>
                            <select id="op_code" name="op_code" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                                <?php foreach($op_codes as $oc): ?>
                                    <option value="<?= $oc['op_code'] ?>" <?= $trip['op_code']==$oc['op_code']?'selected':'' ?>><?= $oc['op_code'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Vehicle No<span class="text-red-500">*</span></label>
                            <input type="text" id="vehicle_no" name="vehicle_no" value="<?= $trip['vehicle_no'] ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 uppercase border">
                        </div>
                    </div>

                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date<span class="text-red-500">*</span></label>
                            <input type="date" name="date" value="<?= $trip['date'] ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Out Time<span class="text-red-500">*</span></label>
                            <input type="time" name="out_time" value="<?= $trip['out_time'] ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">In Time<span class="text-red-500">*</span></label>
                            <input type="time" name="in_time" value="<?= $trip['in_time'] ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                        </div>
                    </div>

                    <div class="flex items-end gap-3">
                        <div class="flex-grow">
                            <label class="block text-sm font-medium text-gray-700">Distance (km)</label>
                            <input type="number" step="0.01" name="distance" value="<?= $trip['distance'] ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                        </div>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-md shadow transition text-sm">
                            Update Basic Info
                        </button>
                    </div>
                </form>

                <div class="space-y-4 border border-indigo-200 p-4 rounded-md bg-indigo-50">
                    <h3 class="text-md font-semibold text-indigo-700">Employee and Reason Details</h3>
                    
                    <form id="addReasonForm" class="grid md:grid-cols-3 gap-3">
                        <input type="hidden" name="action_reason" value="add_reason">
                        <input type="hidden" name="trip_id" value="<?= $target_trip_id; ?>">
                        <input type="text" name="emp_id" required placeholder="Emp ID" class="w-full border rounded p-2 text-sm uppercase">
                        <select name="reason_code" required class="w-full border rounded p-2 text-sm">
                            <option value="">Select Reason</option>
                            <?php 
                            $cat = '';
                            foreach($all_reasons as $r) {
                                if($cat != $r['gl_name']) {
                                    if($cat != '') echo "</optgroup>";
                                    echo "<optgroup label='{$r['gl_name']}'>";
                                    $cat = $r['gl_name'];
                                }
                                echo "<option value='{$r['reason_code']}'>{$r['reason']}</option>";
                            }
                            ?>
                        </select>
                        <button type="submit" class="bg-indigo-400 text-white font-bold rounded p-2 text-sm hover:bg-indigo-600 transition">Add Reason</button>
                    </form>

                    <div class="bg-white rounded border overflow-hidden">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-gray-100 border-b">
                                <tr class="text-gray-500 uppercase font-bold">
                                    <th class="p-2">Employee</th>
                                    <th class="p-2">Reason</th>
                                    <th class="p-2 text-center">X</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if(empty($existing_reasons)): ?>
                                    <tr><td colspan="3" class="p-3 text-center text-gray-400 italic">No entries.</td></tr>
                                <?php endif; ?>
                                <?php foreach($existing_reasons as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-2">
                                        <div class="font-bold text-gray-800"><?= $row['emp_id']; ?></div>
                                        <div class="text-[9px] text-gray-400 uppercase"><?= ($row['calling_name'] ?? '---') . " | " . ($row['department'] ?? '---'); ?></div>
                                    </td>
                                    <td class="p-2 text-gray-600"><?= $row['reason']; ?></td>
                                    <td class="p-2 text-center">
                                        <button onclick="deleteReason(<?= $row['id']; ?>)" class="text-red-400 hover:text-red-600"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="shrink-0 flex justify-between pt-4 border-t mt-4">
                <a href="day_heldup_register.php?date=<?= $trip['date'] ?>" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-md shadow transition text-sm">
                    Back to Register
                </a>
            </div>
        </div>
    </div>

    <script>
        // JS Logic remain the same for functionality
        document.getElementById('op_code').onchange = async function() {
            const vInput = document.getElementById('vehicle_no');
            vInput.value = '...';
            const fd = new FormData();
            fd.append('op_code', this.value);
            try {
                const res = await fetch('day_heldup_fetch_details.php', { method: 'POST', body: new URLSearchParams(fd) });
                const data = await res.json();
                vInput.value = data.success ? data.vehicle_no : '';
            } catch (e) { vInput.value = ''; }
        };

        function showToast(msg, type = 'success') {
            const c = document.getElementById('toast-container');
            const t = document.createElement('div');
            t.className = `toast ${type === 'success' ? 'success' : 'error'} show`;
            t.innerHTML = `<span>${msg}</span>`;
            c.appendChild(t);
            setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 500); }, 3000);
        }

        document.getElementById('generalDetailsForm').onsubmit = async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const res = await fetch('day_heldup_edit_reasons.php', { method: 'POST', body: new URLSearchParams(fd) });
            const data = await res.json();
            showToast(data.message, data.success ? 'success' : 'error');
        };

        document.getElementById('addReasonForm').onsubmit = async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const res = await fetch('day_heldup_edit_reasons.php', { method: 'POST', body: new URLSearchParams(fd) });
            const data = await res.json();
            if(data.success) location.reload(); else showToast(data.message, 'error');
        };

        async function deleteReason(id) {
            if(!confirm('Delete this entry?')) return;
            const fd = new FormData();
            fd.append('action_reason', 'delete_reason');
            fd.append('record_id', id);
            fd.append('trip_id', '<?= $target_trip_id ?>');
            const res = await fetch('day_heldup_edit_reasons.php', { method: 'POST', body: new URLSearchParams(fd) });
            const data = await res.json();
            if(data.success) location.reload(); else showToast(data.message, 'error');
        }
    </script>
</body>
</html>