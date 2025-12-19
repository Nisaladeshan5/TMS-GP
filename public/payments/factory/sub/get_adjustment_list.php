<?php
// get_adjustment_list.php (Updated for History Filtering)
require_once '../../../../includes/session_check.php';
include('../../../../includes/db.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $month = (int)($_GET['month'] ?? 0);
    $year = (int)($_GET['year'] ?? 0);
    $mode = $_GET['mode'] ?? 'current'; // 'current' or 'all'

    $sql = "
        SELECT a.created_at, a.sub_route_code, a.adjustment_days, a.reason, a.month, a.year, s.sub_route
        FROM sub_route_adjustments a
        LEFT JOIN sub_route s ON a.sub_route_code = s.sub_route_code
    ";

    // Filter Logic
    if ($mode === 'current' && $month != 0 && $year != 0) {
        $sql .= " WHERE a.month = $month AND a.year = $year";
    }

    // අලුත්ම ඒවා උඩට එන විදියට සහ උපරිම 500ක් පෙන්වන්න (Load එක වැඩි නොවෙන්න)
    $sql .= " ORDER BY a.created_at DESC LIMIT 500";

    $result = $conn->query($sql);

    $data = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }

    echo json_encode($data);
}
$conn->close();
?>