<?php
// edit_extra_vehicle.php
if (session_status() == PHP_SESSION_NONE) { session_start(); }

include('../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$current_user_id = $is_logged_in ? (int)$_SESSION['user_id'] : 0;

$trip_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$filterDate = $_GET['date'] ?? date('Y-m-d');

// 1. Fetch Main Trip Data
$stmt = $conn->prepare("SELECT * FROM extra_vehicle_register WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $trip_id, $current_user_id);
$stmt->execute();
$trip = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$trip) {
    echo "<script>alert('Unauthorized access or record not found!'); window.location.href='extra_vehicle.php?date=$filterDate';</script>";
    exit;
}

// 2. Fetch Existing Passengers
$existing_passengers = [];
$sql_pass = "SELECT emp_id, reason_code FROM ev_trip_employee_reasons WHERE trip_id = ?";
$stmt_p = $conn->prepare($sql_pass);
$stmt_p->bind_param("i", $trip_id);
$stmt_p->execute();
$res_p = $stmt_p->get_result();
while($row = $res_p->fetch_assoc()){
    $existing_passengers[$row['reason_code']][] = $row['emp_id'];
}
$stmt_p->close();

// 3. Fetch Dropdown Lists
$suppliers = $conn->query("SELECT supplier_code, supplier FROM supplier ORDER BY supplier ASC")->fetch_all(MYSQLI_ASSOC);
$routes = $conn->query("SELECT route_code, route FROM route WHERE is_active = 1 ORDER BY route_code ASC")->fetch_all(MYSQLI_ASSOC);
$op_codes = $conn->query("SELECT op_code FROM op_services WHERE is_active = 1 GROUP BY op_code ORDER BY op_code ASC")->fetch_all(MYSQLI_ASSOC);
$reasons = $conn->query("SELECT reason_code, reason FROM reason ORDER BY reason ASC")->fetch_all(MYSQLI_ASSOC);

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Extra Vehicle Trip</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .toast { position: fixed; top: 1rem; right: 1rem; z-index: 2000; padding: 1rem; border-radius: 0.5rem; color: white; display: none; }
        .success { background-color: #4CAF50; }
        .error { background-color: #F44336; }
        .readonly-field { background-color: #f3f4f6; cursor: not-allowed; }
    </style>
</head>
<body class="bg-gray-100 font-sans">

<div id="toast" class="toast"></div>

<div class="w-[85%] ml-[15%] p-8">
    <div class="max-w-4xl mx-auto bg-white shadow-lg rounded-lg p-8">
        <h1 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-4">Edit Extra Vehicle Trip</h1>

        <form id="editTripForm" class="space-y-6">
            <input type="hidden" name="trip_id" value="<?= $trip_id ?>">

            <div class="bg-blue-50 p-6 rounded-lg border border-blue-200 grid md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Route Name:</label>
                    <select id="route_code" name="route_code" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                        <option value="">-- Select Route --</option>
                        <?php foreach ($routes as $r): ?>
                            <option value="<?= $r['route_code'] ?>" <?= $trip['route'] == $r['route_code'] ? 'selected' : '' ?>><?= $r['route'] ?> (<?= $r['route_code'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Operation Code:</label>
                    <select id="op_code" name="op_code" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                        <option value="">-- Select Op Code --</option>
                        <?php foreach ($op_codes as $o): ?>
                            <option value="<?= $o['op_code'] ?>" <?= $trip['op_code'] == $o['op_code'] ? 'selected' : '' ?>><?= $o['op_code'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Vehicle No: <span id="vehicle_status" class="text-xs italic ml-2"></span></label>
                    <input type="text" id="vehicle_no" name="vehicle_no" value="<?= $trip['vehicle_no'] ?>" required class="mt-1 block w-full rounded-md border-gray-300 p-2 border">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Supplier:</label>
                    <select id="supplier_code" name="supplier_code" required class="mt-1 block w-full rounded-md border-gray-300 p-2 border">
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['supplier_code'] ?>" <?= $trip['supplier_code'] == $s['supplier_code'] ? 'selected' : '' ?>><?= $s['supplier'] ?> (<?= $s['supplier_code'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Distance (Km):</label>
                    <input type="number" step="0.01" name="distance" value="<?= $trip['distance'] ?>" class="mt-1 block w-full rounded-md border-gray-300 p-2 border">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">A/C Status:</label>
                    <div class="mt-2 flex space-x-4">
                        <label><input type="radio" name="ac_status" value="0" <?= $trip['ac_status'] == 0 ? 'checked' : '' ?>> Non A/C</label>
                        <label><input type="radio" name="ac_status" value="1" <?= $trip['ac_status'] == 1 ? 'checked' : '' ?>> A/C</label>
                    </div>
                </div>
            </div>

            <div class="bg-gray-50 p-6 rounded-lg border border-gray-200">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-800">Employee Details</h3>
                    <button type="button" id="add-reason-group-btn" class="bg-indigo-600 text-white px-3 py-1.5 rounded-md text-xs">+ Add Group</button>
                </div>

                <div id="reason-group-container" class="space-y-4">
                    <?php 
                    $group_idx = 0;
                    if(empty($existing_passengers)): 
                    ?>
                        <div class="reason-group bg-white p-4 rounded-md border shadow-sm">
                            <select name="reason_group[]" class="reason-select w-full mb-2 p-2 border rounded text-xs">
                                <option value="">Select Reason</option>
                                <?php foreach ($reasons as $res) echo "<option value='{$res['reason_code']}'>{$res['reason']}</option>"; ?>
                            </select>
                            <div class="employee-list-container space-y-2">
                                <div class="employee-input flex gap-2">
                                    <input type="text" name="emp_id_group[0][]" placeholder="Emp ID" class="emp-id-input flex-grow p-2 border rounded text-xs">
                                    <button type="button" class="remove-employee-btn text-red-500">&times;</button>
                                </div>
                            </div>
                            <button type="button" class="add-employee-btn-group text-xs text-blue-600 mt-2">+ Add Employee</button>
                        </div>
                    <?php else: 
                        foreach($existing_passengers as $r_code => $emp_ids): ?>
                        <div class="reason-group bg-white p-4 rounded-md border shadow-sm">
                            <div class="flex justify-between mb-2">
                                <span class="font-bold text-xs text-gray-500">Group <?= $group_idx + 1 ?></span>
                                <button type="button" class="remove-group-btn text-red-500 text-xs">Remove Group</button>
                            </div>
                            <select name="reason_group[]" class="reason-select w-full mb-3 p-2 border rounded text-xs font-bold">
                                <?php foreach ($reasons as $res): ?>
                                    <option value="<?= $res['reason_code'] ?>" <?= $r_code == $res['reason_code'] ? 'selected' : '' ?>><?= $res['reason'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="employee-list-container space-y-2">
                                <?php foreach($emp_ids as $eid): ?>
                                <div class="employee-input flex gap-2">
                                    <input type="text" name="emp_id_group[<?= $group_idx ?>][]" value="<?= $eid ?>" class="emp-id-input flex-grow p-2 border rounded text-xs bg-green-50">
                                    <button type="button" class="remove-employee-btn text-red-500">&times;</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="add-employee-btn-group text-xs text-blue-600 mt-2">+ Add Employee</button>
                        </div>
                    <?php $group_idx++; endforeach; endif; ?>
                </div>
            </div>

            <div class="flex justify-between pt-6 border-t">
                <a href="extra_vehicle.php?date=<?= $filterDate ?>" class="bg-gray-500 text-white px-6 py-2 rounded shadow">Back</a>
                <button type="submit" id="submitBtn" class="bg-blue-600 text-white px-8 py-2 rounded shadow font-bold hover:bg-blue-700">Update Record</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    $(document).ready(function() {
        const routeSelect = $('#route_code');
        const opSelect = $('#op_code');
        const vehicleInput = $('#vehicle_no');
        const supplierSelect = $('#supplier_code');
        const vehicleStatus = $('#vehicle_status');

        // --- 1. Vehicle/Supplier Auto-Lookup ---
        function lookupDetails(codeType, tripCode) {
            if (!tripCode) return;
            
            // Path eka hariyata check karanna:
            const fetchUrl = 'add_records/extra_vehicle/fetch_details.php'; 

            $.ajax({
                url: fetchUrl,
                method: 'GET',
                data: { code_type: codeType, trip_code: tripCode },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#vehicle_no').val(response.vehicle_no || '');
                        $('#supplier_code').val(response.supplier_code);
                        
                        if(response.ac_status !== undefined) {
                            $(`input[name="ac_status"][value="${response.ac_status}"]`).prop('checked', true);
                        }
                        $('#vehicle_status').text('Auto-filled').css('color', 'green');
                    } else {
                        $('#vehicle_status').text('Not found').css('color', 'red');
                    }
                },
                error: function() {
                    console.error("Path error or Server error");
                }
            });
        }

        // --- 2. Dropdown Change Handlers ---
        routeSelect.on('change', function() {
            const val = $(this).val();
            if (val) {
                opSelect.val('').prop('disabled', true).addClass('bg-gray-100');
                lookupDetails('Route', val);
            } else {
                opSelect.prop('disabled', false).removeClass('bg-gray-100');
            }
        });

        opSelect.on('change', function() {
            const val = $(this).val();
            if (val) {
                routeSelect.val('').prop('disabled', true).addClass('bg-gray-100');
                lookupDetails('Operation', val);
            } else {
                routeSelect.prop('disabled', false).removeClass('bg-gray-100');
            }
        });

        // Initialize state
        if(routeSelect.val()) opSelect.prop('disabled', true).addClass('bg-gray-100');
        if(opSelect.val()) routeSelect.prop('disabled', true).addClass('bg-gray-100');

        // --- 3. Dynamic Employee Groups ---
        $('#add-reason-group-btn').click(function () {
            let idx = $('.reason-group').length;
            let $newGroup = $('.reason-group').first().clone();
            $newGroup.find('input').val('').removeClass('bg-green-50');
            $newGroup.find('select').val('');
            $newGroup.find('span').text('Group ' + (idx + 1));
            $newGroup.find('.employee-list-container').html(`
                <div class="employee-input flex gap-2">
                    <input type="text" name="emp_id_group[${idx}][]" placeholder="Emp ID" class="emp-id-input flex-grow p-2 border rounded text-xs">
                    <button type="button" class="remove-employee-btn text-red-500">&times;</button>
                </div>
            `);
            $('#reason-group-container').append($newGroup);
        });

        $(document).on('click', '.remove-group-btn', function() {
            if($('.reason-group').length > 1) {
                $(this).closest('.reason-group').remove();
                // Re-index groups
                $('.reason-group').each(function(i) {
                    $(this).find('span').text('Group ' + (i + 1));
                    $(this).find('.emp-id-input').attr('name', `emp_id_group[${i}][]`);
                });
            }
        });

        $(document).on('click', '.add-employee-btn-group', function() {
            let groupIdx = $(this).closest('.reason-group').index();
            let html = `
                <div class="employee-input flex gap-2">
                    <input type="text" name="emp_id_group[${groupIdx}][]" placeholder="Emp ID" class="emp-id-input flex-grow p-2 border rounded text-xs">
                    <button type="button" class="remove-employee-btn text-red-500">&times;</button>
                </div>`;
            $(this).closest('.reason-group').find('.employee-list-container').append(html);
        });

        $(document).on('click', '.remove-employee-btn', function() {
            if($(this).closest('.employee-list-container').find('.employee-input').length > 1) {
                $(this).closest('.employee-input').remove();
            }
        });

        // --- 4. Submit via AJAX ---
        $('#editTripForm').on('submit', function(e) {
            e.preventDefault();
            let btn = $('#submitBtn');
            btn.prop('disabled', true).text('Updating...');

            $.ajax({
                url: 'process_update_extra_vehicle.php',
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(res) {
                    btn.prop('disabled', false).text('Update Record');
                    if(res.success) {
                        showToast("Record Updated Successfully!", "success");
                        setTimeout(() => window.location.href = 'extra_vehicle.php?date=<?= $filterDate ?>', 1000);
                    } else {
                        showToast(res.message, "error");
                    }
                }
            });
        });

        function showToast(msg, type) {
            $('#toast').text(msg).removeClass('success error').addClass(type).fadeIn();
            setTimeout(() => $('#toast').fadeOut(), 3000);
        }
    });
</script>
</body>
</html>