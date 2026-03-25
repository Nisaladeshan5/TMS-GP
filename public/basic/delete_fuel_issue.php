<?php
session_start();
require_once '../../includes/session_check.php';
include('../../includes/db.php');

// Log wela inna user ID eka
$logged_in_user_id = $_SESSION['user_id'] ?? null;

if (isset($_GET['id']) && isset($_GET['type']) && $logged_in_user_id) {
    $id = intval($_GET['id']);
    $type = $_GET['type']; // 'routes' or 'employees'
    $table = ($type === 'routes') ? 'fuel_issues' : 'employee_fuel_issues';

    // 1. Mulinma balanawa mē record eka dapu kenaama da delete karanna hadanne kiyala
    $check_sql = "SELECT user_id FROM $table WHERE id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result->fetch_assoc();

    if ($record && $record['user_id'] == $logged_in_user_id) {
        // 2. Aithi kenaama nam delete karanawa
        $delete_sql = "DELETE FROM $table WHERE id = ?";
        $del_stmt = $conn->prepare($delete_sql);
        $del_stmt->bind_param("i", $id);

        if ($del_stmt->execute()) {
            $_SESSION['toast'] = ['message' => 'Record deleted successfully.', 'type' => 'success'];
        } else {
            $_SESSION['toast'] = ['message' => 'Failed to delete the record.', 'type' => 'error'];
        }
        $del_stmt->close();
    } else {
        // 3. User ID eka match wenne nethnam (Permission nethnam)
        $_SESSION['toast'] = ['message' => 'Unauthorized action or record not found.', 'type' => 'error'];
    }
    $stmt->close();
} else {
    $_SESSION['toast'] = ['message' => 'Invalid request.', 'type' => 'error'];
}

// History page ekatama reverse yanawa
header("Location: fuel_issue_history.php?view_filter=" . ($type ?? 'routes'));
exit();