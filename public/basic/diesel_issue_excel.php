<?php
require_once '../../includes/session_check.php';

// Start the session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// Set headers to trigger Excel download
$filename = "Weekly_Diesel_Issue_Report_" . date('Y-m-d') . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Fetch data from both route and sub_route tables
$sql = "SELECT 
            combined_routes.*,
            v.type AS vehicle_type, 
            v.rate_id,
            c.c_type AS consumption_type, 
            c.distance AS km_per_liter,
            fr.type AS fuel_type
        FROM (
            SELECT 
                r.route_code, 
                r.route, 
                TRIM(r.vehicle_no) AS vehicle_no, 
                r.distance AS route_distance,
                'Main' AS route_category
            FROM route r
            WHERE r.is_active = 1

            UNION ALL

            SELECT 
                sr.sub_route_code AS route_code, 
                sr.sub_route AS route, 
                TRIM(sr.vehicle_no) AS vehicle_no, 
                sr.distance AS route_distance,
                'Sub' AS route_category
            FROM sub_route sr
            WHERE sr.is_active = 1
        ) combined_routes
        LEFT JOIN vehicle v ON combined_routes.vehicle_no = TRIM(v.vehicle_no)
        LEFT JOIN consumption c ON v.fuel_efficiency = c.c_id
        LEFT JOIN (
            SELECT rate_id, type 
            FROM fuel_rate 
            WHERE id IN (SELECT MAX(id) FROM fuel_rate GROUP BY rate_id)
        ) fr ON v.rate_id = fr.rate_id
        /* Mulinma Main routes okkoma, eeta passe Sub routes okkoma */
        ORDER BY combined_routes.route_category ASC, combined_routes.route_code ASC";

$result = $conn->query($sql);

?>
<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
    <meta charset="utf-8">
</head>
<body>
    <table border="1" cellpadding="5" cellspacing="0" style="font-family: Arial, sans-serif; border-collapse: collapse; width: 100%;">
        
        <tr>
            <th colspan="10" style="font-size: 18px; font-weight: bold; text-align: center; background-color: #1E3A8A; color: #ffffff; padding: 15px;">
                Weekly Route Diesel Issue Report - <?php echo date('Y-m-d'); ?>
            </th>
        </tr>

        <tr style="color: #ffffff; font-weight: bold; text-align: center;">
            <th style="background-color: #2563EB; width: 100px;">Code</th>
            <th style="background-color: #2563EB; width: 80px;">Type</th>
            <th style="background-color: #2563EB; width: 200px;">Route Name</th>
            <th style="background-color: #2563EB; width: 120px;">Vehicle No</th>
            <th style="background-color: #2563EB; width: 100px;">Vehicle Type</th>
            <th style="background-color: #2563EB; width: 150px;">Consumption</th>
            <th style="background-color: #2563EB; width: 100px;">Distance (km)</th>
            <th style="background-color: #2563EB; width: 120px;">Need/Week (L)</th>
            <th style="background-color: #2563EB; width: 100px;">QR Quota (L)</th>
            <th style="background-color: #2563EB; width: 120px;">Difference (L)</th>
        </tr>

        <?php
        if ($result && $result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $route_code = $row["route_code"] ?? '-';
                $category = $row["route_category"] ?? '-';
                $route_name = $row["route"] ?? '-';
                $vehicle_no = $row["vehicle_no"] ?? '-';
                $vehicle_type = strtolower(trim($row['vehicle_type'] ?? ''));
                $km_per_liter = (float)($row['km_per_liter'] ?? 0);
                $distance = (float)($row['route_distance'] ?? 0);

                // Calculations
                $need_for_week = ($km_per_liter > 0) ? ($distance / $km_per_liter) * 6 : 0;

                $qr_quota = 0;
                if ($vehicle_type === 'bus') {
                    $qr_quota = 60;
                } elseif ($vehicle_type === 'van') {
                    $qr_quota = 40;
                } elseif ($vehicle_type === 'wheel') {
                    $qr_quota = 15;
                }

                $difference = $qr_quota - $need_for_week;
                $diff_color = ($difference < 0) ? '#DC2626' : '#16A34A';
                $sign = ($difference > 0) ? '+' : '';

                echo "<tr style='text-align: center;'>";
                echo "<td style='font-weight: bold; color: #2563EB;'>" . htmlspecialchars($route_code) . "</td>";
                echo "<td style='font-size: 10px; color: #6B7280;'>" . htmlspecialchars($category) . "</td>";
                echo "<td style='text-align: left;'>" . htmlspecialchars($route_name) . "</td>";
                echo "<td>" . htmlspecialchars($vehicle_no) . "</td>";
                echo "<td style='text-transform: uppercase;'>" . ($vehicle_type ?: 'UNKNOWN') . "</td>";
                echo "<td>" . number_format($km_per_liter, 2) . " km/L</td>";
                echo "<td>" . number_format($distance, 1) . "</td>";
                echo "<td style='font-weight: bold; color: #D97706;'>" . number_format($need_for_week, 2) . "</td>";
                echo "<td style='font-weight: bold; color: #4F46E5;'>" . $qr_quota . "</td>";
                echo "<td style='font-weight: bold; color: " . $diff_color . ";'>" . $sign . number_format($difference, 2) . "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='10' style='text-align: center; color: #6B7280;'>No route data found.</td></tr>";
        }
        ?>
    </table>
</body>
</html>
<?php 
$conn->close();
?>