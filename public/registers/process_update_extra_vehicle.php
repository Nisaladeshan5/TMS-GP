<?php
// process_update_extra_vehicle.php
ob_start(); // Any accidental output buffer logic
include('../../includes/db.php');
if (session_status() == PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');

try {
    $trip_id = (int)$_POST['trip_id'];
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $route = !empty($_POST['route_code']) ? $_POST['route_code'] : NULL;
    $op_code = !empty($_POST['op_code']) ? $_POST['op_code'] : NULL;
    $vehicle_no = $_POST['vehicle_no'];
    $supplier_code = $_POST['supplier_code'];
    $distance = (float)$_POST['distance'];
    $ac_status = (int)$_POST['ac_status'];

    $conn->begin_transaction();

    // 1. Update main record
    $sql = "UPDATE extra_vehicle_register SET route=?, op_code=?, vehicle_no=?, supplier_code=?, distance=?, ac_status=? WHERE id=? AND user_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssdiii", $route, $op_code, $vehicle_no, $supplier_code, $distance, $ac_status, $trip_id, $user_id);
    $stmt->execute();

    // 2. Clear old passengers
    $stmt_del = $conn->prepare("DELETE FROM ev_trip_employee_reasons WHERE trip_id = ?");
    $stmt_del->bind_param("i", $trip_id);
    $stmt_del->execute();

    // 3. Insert new passengers
    if (isset($_POST['reason_group'])) {
        foreach ($_POST['reason_group'] as $idx => $reason_code) {
            if (!empty($reason_code) && isset($_POST['emp_id_group'][$idx])) {
                foreach ($_POST['emp_id_group'][$idx] as $emp_id) {
                    if (!empty($emp_id)) {
                        $stmt_ins = $conn->prepare("INSERT INTO ev_trip_employee_reasons (trip_id, emp_id, reason_code) VALUES (?, ?, ?)");
                        $stmt_ins->bind_param("iss", $trip_id, $emp_id, $reason_code);
                        $stmt_ins->execute();
                    }
                }
            }
        }
    }

    $conn->commit();
    ob_end_clean();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($conn->connect_errno === 0) { $conn->rollback(); }
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;