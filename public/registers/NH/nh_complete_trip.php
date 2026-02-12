<?php
// nh_complete_trip.php
include('../../../includes/db.php');
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { 
    header("Location: ../../../includes/login.php"); exit(); 
}

$trip_id = (int)($_GET['id'] ?? 0);
$current_user_id = (int)$_SESSION['user_id'];

// Trip details fetch කිරීම
$stmt = $conn->prepare("SELECT * FROM nh_register WHERE id = ?");
$stmt->bind_param("i", $trip_id);
$stmt->execute();
$trip = $stmt->get_result()->fetch_assoc();
if (!$trip) die("Trip not found!");

// Dropdown එක සඳහා OP Codes ලබා ගැනීම
$op_codes_list = [];
$result_op = $conn->query("SELECT op_code FROM op_services WHERE op_code LIKE 'NH%' OR op_code LIKE 'EV%' AND is_active = 1 ORDER BY op_code ASC");
if($result_op) {
    while ($row = $result_op->fetch_assoc()) $op_codes_list[] = $row['op_code'];
}

// Form Submission ලොජික් එක
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op_code = $_POST['op_code'];
    $distance = (float)$_POST['distance'];
    $emp_ids = $_POST['emp_ids'] ?? []; 

    $conn->begin_transaction();
    try {
        $sql = "UPDATE nh_register SET op_code=?, distance=?, done=1, user_id=? WHERE id=?";
        $stmt_up = $conn->prepare($sql);
        $stmt_up->bind_param("sdii", $op_code, $distance, $current_user_id, $trip_id);
        $stmt_up->execute();

        $conn->query("DELETE FROM nh_trip_departments WHERE trip_id = $trip_id");
        $stmt_emp = $conn->prepare("INSERT INTO nh_trip_departments (trip_id, emp_id) VALUES (?, ?)");
        foreach ($emp_ids as $eid) {
            $stmt_emp->bind_param("is", $trip_id, $eid);
            $stmt_emp->execute();
        }

        $conn->commit();
        echo "<script>window.location.href='night_heldup_register.php?status=success&message=" . urlencode("Trip Record Completed Successfully") . "';</script>";
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Error: " . $e->getMessage() . "');</script>";
    }
}

// දැනට ඉන්න සේවකයන්ව ලබා ගැනීම (Department එකත් සමඟ)
$existing_emps = [];
$res_e = $conn->query("SELECT d.emp_id, e.calling_name, e.department FROM nh_trip_departments d LEFT JOIN employee e ON d.emp_id = e.emp_id WHERE d.trip_id = $trip_id");
while($row = $res_e->fetch_assoc()) { $existing_emps[] = $row; }

include('../../../includes/header.php');
include('../../../includes/navbar.php'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Complete Night Trip</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100 font-sans">

<div class="w-[85%] ml-[15%] flex justify-center p-3 mt-6">
    <div class="container max-w-2xl bg-white shadow-lg rounded-lg p-8 mt-2 border border-gray-100">
        
        <h1 class="text-3xl font-extrabold text-gray-900 mb-2 border-b-2 border-indigo-500 pb-2">
            Complete Night Trip #<?= $trip_id ?>
        </h1>
        <p class="text-sm text-gray-600 mb-6 font-bold uppercase tracking-tighter">
            Vehicle: <span class="text-indigo-600"><?= $trip['vehicle_no'] ?></span> | Date: <?= $trip['date'] ?>
        </p>

        <form method="POST" action="" id="completeForm" class="space-y-6">
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 uppercase tracking-wide">Op Code <span class="text-red-500">*</span></label>
                    <select name="op_code" required 
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-3 border focus:ring-indigo-500 focus:border-indigo-500 font-bold text-gray-800 bg-white">
                        <option value="" disabled selected>Select Code</option>
                        <?php foreach ($op_codes_list as $code): ?>
                            <option value="<?= $code ?>" <?= ($trip['op_code'] == $code) ? 'selected' : '' ?>><?= $code ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 uppercase tracking-wide">Distance (Km) <span class="text-red-500">*</span></label>
                    <input type="number" step="0.01" name="distance" value="<?= $trip['distance'] ?>" required placeholder="0.00" 
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-3 border focus:ring-indigo-500 focus:border-indigo-500 font-bold text-indigo-600">
                </div>
            </div>

            <div class="bg-indigo-50 p-3 rounded-md border border-indigo-100 mt-4 shadow-inner">
                <label class="block text-xs font-bold text-gray-500 uppercase mb-2 tracking-widest">Add Employees to Trip</label>
                <div class="flex gap-2 mb-4">
                    <input type="text" id="empSearch" placeholder="Enter ID (7135, ST8, D187...)" 
                           class="flex-1 rounded-md border-gray-300 shadow-sm p-3 text-gray-800 font-bold focus:ring-indigo-500 focus:border-indigo-500 border uppercase">
                    <button type="button" onclick="addEmp()" class="bg-gray-800 hover:bg-black text-white font-bold py-2 px-6 rounded-md shadow-md transition active:scale-95">
                        Add
                    </button>
                </div>
                
                <div id="empList" class="space-y-2 max-h-64 overflow-y-auto">
                    <?php if(empty($existing_emps)): ?>
                        <p id="emptyMsg" class="text-center text-indigo-300 italic text-xs py-2">No employees added yet.</p>
                    <?php endif; ?>
                    
                    <?php foreach($existing_emps as $e): ?>
                        <div class="flex justify-between items-center bg-white p-3 rounded-md border border-indigo-100 shadow-sm" id="row_<?= $e['emp_id'] ?>">
                            <div class="flex flex-col">
                                <span class="text-sm font-bold text-gray-700 italic"><b><?= $e['emp_id'] ?></b> - <?= $e['calling_name'] ?></span>
                                <span class="text-[10px] text-indigo-500 font-bold uppercase tracking-wider"><?= $e['department'] ?: 'No Dept' ?></span>
                            </div>
                            <input type="hidden" name="emp_ids[]" value="<?= $e['emp_id'] ?>">
                            <button type="button" onclick="removeEmp('<?= $e['emp_id'] ?>')" class="text-red-500 hover:text-red-700 transition">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="flex justify-between gap-3 pt-2">
                <a href="night_heldup_register.php" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-8 rounded-md shadow-md transition duration-300">
                    Cancel
                </a>
                <button type="submit" id="submitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-10 rounded-md shadow-md transition duration-300 flex items-center">
                    Submit Record
                </button>
            </div>

        </form>
    </div>
</div>

<script>
function formatEmpID(val) {
    val = val.trim().toUpperCase();
    if (!val) return "";
    let letters = val.match(/[A-Z]+/g) ? val.match(/[A-Z]+/g).join('') : "";
    let numbers = val.match(/\d+/g) ? val.match(/\d+/g).join('') : "";
    if (letters === "D") { letters = "GPD"; } 
    else if (letters === "") { letters = "GP"; }
    let currentLen = letters.length + numbers.length;
    let zeros = "";
    if (currentLen < 8) { zeros = "0".repeat(8 - currentLen); }
    return letters + zeros + numbers;
}

function addEmp() {
    const inputField = document.getElementById('empSearch');
    const rawId = inputField.value.trim();
    const id = formatEmpID(rawId);

    if(!id) return;
    if(document.getElementById('row_'+id)) { alert("Already added!"); return; }

    fetch('nh_validate_emp.php?id=' + id)
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            const emptyMsg = document.getElementById('emptyMsg');
            if(emptyMsg) emptyMsg.remove();

            const list = document.getElementById('empList');
            const html = `
                <div class="flex justify-between items-center bg-white p-3 rounded-md border border-indigo-100 shadow-sm" id="row_${id}">
                    <div class="flex flex-col">
                        <span class="text-sm font-bold text-gray-700 italic"><b>${id}</b> - ${data.name}</span>
                        <span class="text-[10px] text-indigo-500 font-bold uppercase tracking-wider">${data.dept || 'No Dept'}</span>
                    </div>
                    <input type="hidden" name="emp_ids[]" value="${id}">
                    <button type="button" onclick="removeEmp('${id}')" class="text-red-500 hover:text-red-700 transition">
                        <i class="fas fa-trash"></i></button>
                </div>`;
            list.insertAdjacentHTML('afterbegin', html);
            inputField.value = '';
            inputField.focus();
        } else {
            alert("Employee ID " + id + " not found!");
        }
    });
}

function removeEmp(id) {
    document.getElementById('row_' + id).remove();
}

document.getElementById('empSearch').addEventListener('keypress', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); addEmp(); }
});

const submitBtn = document.getElementById('submitBtn');
document.getElementById('completeForm').addEventListener('submit', function() {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Saving...';
});
</script>

</body>
</html>