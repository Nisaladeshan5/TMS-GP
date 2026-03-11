<?php
require_once '../../includes/session_check.php';
include('../../includes/db.php');

// Login Check
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    exit("Unauthorized access");
}

// File name එක සකස් කිරීම
$filename = "User_Report_" . date('Y-m-d_H-i') . ".xls";

// Excel Header set කිරීම
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Data ලබා ගැනීම (Route එක අනුව sort කරලා තියෙන්නේ)
$sql = "SELECT 
            u.emp_id, 
            u.calling_name, 
            r.route, 
            e.line 
        FROM user u
        LEFT JOIN employee e ON u.emp_id = e.emp_id
        LEFT JOIN route r ON u.route_code = r.route_code
        ORDER BY r.route ASC, u.emp_id ASC";

$result = $conn->query($sql);

?>

<style>
    table {
        border-collapse: collapse; /* Border එක ලස්සනට එකට සෙට් වෙන්න */
    }
    th {
        background-color: #4F81BD; /* Header එකේ නිල් පාට */
        color: #FFFFFF;
        font-weight: bold;
        height: 35px;
        vertical-align: middle;
        text-align: left;
        border: 1px solid #000000; /* Header එකට කළු පාට border එකක් */
    }
    td {
        height: 25px;
        vertical-align: middle;
        border: 1px solid #000000; /* Data තියෙන සෙල් වලට විතරක් කළු පාට border එකක් */
    }
</style>

<table>
    <thead>
        <tr>
            <th width="120">Emp ID</th>
            <th width="250">Calling Name</th>
            <th width="250">Route</th>
            <th width="120">Line</th>
        </tr>
    </thead>
    <tbody>
        <?php
        if ($result && $result->num_rows > 0) {
            // Row color කරන එක (count, even class) සම්පූර්ණයෙන්ම අයින් කළා
            while($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['emp_id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['calling_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['route'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($row['line'] ?? 'N/A') . "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='4' style='text-align:center;'>No users found</td></tr>";
        }
        ?>
    </tbody>
</table>