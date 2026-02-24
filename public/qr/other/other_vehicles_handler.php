<?php
// කිසිදු ආකාරයක Space එකක් PHP tag එකට කලින් තියන්න එපා
error_reporting(E_ALL);
ini_set('display_errors', 0); // Errors JSON එකට මිශ්‍ර වීම වැළැක්වීමට

ob_start();
// Path එක නිවැරදි දැයි පරීක්ෂා කරන්න (අවශ්‍ය නම් ../../ ලෙස වෙනස් කරන්න)
include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op_code = $_POST['op_code'] ?? '';
    $vehicle_no = $_POST['vehicle_no'] ?? '';
    $supplier_code = $_POST['supplier_code'] ?? '';
    $from_loc = $_POST['from_loc'] ?? '-';
    $to_loc = $_POST['to_loc'] ?? '-';
    
    $date = date('Y-m-d');
    $time = date('H:i:s');

    if (empty($op_code) || empty($vehicle_no)) {
        echo json_encode(['status' => 'error', 'message' => 'Required data missing!']);
        exit;
    }

    try {
        // Double scan check
        $check = $conn->prepare("SELECT id FROM extra_vehicle_register WHERE op_code = ? AND date = ?");
        $check->bind_param("ss", $op_code, $date);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Already registered for today!']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO extra_vehicle_register (op_code, vehicle_no, supplier_code, date, time, from_location, to_location, distance, done, user_id, ac_status) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0)");
        $stmt->bind_param("sssssss", $op_code, $vehicle_no, $supplier_code, $date, $time, $from_loc, $to_loc);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Record Saved Successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $stmt->error]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Server Exception: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method']);
}