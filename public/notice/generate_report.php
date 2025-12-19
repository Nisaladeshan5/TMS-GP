<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

ob_start();
include('../../includes/db.php');
require '../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$CRITICAL_DAYS = 30;

$html = '
<!DOCTYPE html>
<html>
<head>
    <title>Critical Expiration Report</title>
    <style>
        @page {
            margin: 90px 40px 60px 40px;
        }

        body {
            font-family: "Helvetica", Arial, sans-serif;
            font-size: 10pt;
            color: #222;
            margin: 0;
            padding: 0;
        }

        header {
            position: fixed;
            top: -70px;
            left: 0;
            right: 0;
            text-align: center;
            border-bottom: 1px solid #aaa;
            padding-bottom: 8px;
        }

        footer {
            position: fixed;
            bottom: -40px;
            left: 0;
            right: 0;
            text-align: right;
            font-size: 8pt;
            color: #666;
            border-top: 1px solid #aaa;
            padding-top: 5px;
        }

        h1 {
            font-size: 14pt;
            font-weight: bold;
            margin: 0;
            color: #000;
        }

        .date {
            font-size: 9pt;
            color: #555;
            margin-top: 5px;
        }

        h2 {
            font-size: 12pt;
            font-weight: bold;
            color: #000;
            margin-top: 25px;
            margin-bottom: 10px;
            border-bottom: 1px solid #999;
            padding-bottom: 3px;
        }

        h3 {
            font-size: 10.5pt;
            font-weight: 600;
            color: #333;
            margin-top: 20px;
            margin-bottom: 8px;
        }

        p.note {
            font-size: 9pt;
            color: #555;
            margin-top: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            margin-bottom: 25px;
            page-break-inside: auto;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 6px 8px;
            font-size: 9pt;
            text-align: left;
            word-wrap: break-word;
        }

        th {
            background: #f2f2f2;
            font-weight: 600;
        }

        tr:nth-child(even) {
            background: #fafafa;
        }

        .expired {
            color: #a00;
            font-weight: bold;
        }

        .critical {
            color: #b58900;
            font-weight: bold;
        }

        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>

<header>
    <h1>Critical Expiration Report</h1>
    <div class="date">Generated: ' . date("Y-m-d H:i:s") . '</div>
</header>

<footer>
    Page <span class="pageNumber"></span> of <span class="totalPages"></span>
</footer>

<main>
<p class="note">This report lists all drivers and vehicles with documents that are expired or due within the next <strong>' . $CRITICAL_DAYS . '</strong> days.</p>
';

# -----------------------------------
# DRIVER LICENSE EXPIRATIONS
# -----------------------------------
$html .= '<h2>Driver License Expirations</h2>';

$sql_drivers = "SELECT calling_name, phone_no, license_expiry_date,
                DATEDIFF(license_expiry_date, CURDATE()) AS days_left
                FROM driver 
                WHERE DATEDIFF(license_expiry_date, CURDATE()) <= $CRITICAL_DAYS AND is_active = 1
                ORDER BY license_expiry_date ASC";
$result_drivers = mysqli_query($conn, $sql_drivers);

if (mysqli_num_rows($result_drivers) > 0) {
    $html .= '<table>
        <thead>
            <tr>
                <th>Driver Name</th>
                <th>Phone Number</th>
                <th>License Expiry Date</th>
                <th>Days Left / Passed</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>';
    while ($row = mysqli_fetch_assoc($result_drivers)) {
        $days_left = $row['days_left'];
        $status_class = ($days_left <= 0) ? 'expired' : 'critical';
        $status_text = ($days_left <= 0)
            ? 'Expired (' . abs($days_left) . ' days ago)'
            : 'Expiring in ' . $days_left . ' days';
        $html .= '<tr>
            <td>' . htmlspecialchars($row["calling_name"]) . '</td>
            <td>' . htmlspecialchars($row["phone_no"]) . '</td>
            <td>' . htmlspecialchars($row["license_expiry_date"]) . '</td>
            <td>' . $days_left . '</td>
            <td class="' . $status_class . '">' . $status_text . '</td>
        </tr>';
    }
    $html .= '</tbody></table>';
} else {
    $html .= '<p style="color: #007700; font-weight: bold;">No driver licenses expiring within ' . $CRITICAL_DAYS . ' days.</p>';
}

# -----------------------------------
# VEHICLE DOCUMENT EXPIRATIONS
# -----------------------------------
$html .= '<div class="page-break"></div>';
$html .= '<h2>Vehicle Document Expirations</h2>';

# --- Vehicle License ---
$html .= '<h3>Vehicle License Expiry</h3>';
$sql_vehicle_license = "SELECT v.vehicle_no, v.license_expiry_date,
                        DATEDIFF(v.license_expiry_date, CURDATE()) AS days_left,
                        s.supplier AS supplier_name, s.s_phone_no AS supplier_phone
                        FROM vehicle v
                        JOIN supplier s ON v.supplier_code = s.supplier_code
                        WHERE DATEDIFF(v.license_expiry_date, CURDATE()) <= $CRITICAL_DAYS AND v.is_active = 1
                        ORDER BY v.license_expiry_date ASC";
$result_vehicle_license = mysqli_query($conn, $sql_vehicle_license);

if (mysqli_num_rows($result_vehicle_license) > 0) {
    $html .= '<table>
        <thead>
            <tr>
                <th>Vehicle No</th>
                <th>Supplier Name</th>
                <th>Supplier Phone</th>
                <th>License Expiry</th>
                <th>Status</th>
            </tr>
        </thead><tbody>';
    while ($row = mysqli_fetch_assoc($result_vehicle_license)) {
        $days_left = $row['days_left'];
        $status_class = ($days_left <= 0) ? 'expired' : 'critical';
        $status_text = ($days_left <= 0)
            ? 'Expired (' . abs($days_left) . ' days ago)'
            : 'Expiring in ' . $days_left . ' days';
        $html .= '<tr>
            <td>' . htmlspecialchars($row["vehicle_no"]) . '</td>
            <td>' . htmlspecialchars($row["supplier_name"]) . '</td>
            <td>' . htmlspecialchars($row["supplier_phone"]) . '</td>
            <td>' . htmlspecialchars($row["license_expiry_date"]) . '</td>
            <td class="' . $status_class . '">' . $status_text . '</td>
        </tr>';
    }
    $html .= '</tbody></table>';
} else {
    $html .= '<p style="color: #007700; font-weight: bold;">No vehicle licenses expiring within ' . $CRITICAL_DAYS . ' days.</p>';
}

# --- Vehicle Insurance ---
$html .= '<h3>Vehicle Insurance Expiry</h3>';
$sql_vehicle_insurance = "SELECT v.vehicle_no, v.insurance_expiry_date,
                        DATEDIFF(v.insurance_expiry_date, CURDATE()) AS days_left,
                        s.supplier AS supplier_name, s.s_phone_no AS supplier_phone
                        FROM vehicle v
                        JOIN supplier s ON v.supplier_code = s.supplier_code
                        WHERE DATEDIFF(v.insurance_expiry_date, CURDATE()) <= $CRITICAL_DAYS AND v.is_active = 1
                        ORDER BY v.insurance_expiry_date ASC";
$result_vehicle_insurance = mysqli_query($conn, $sql_vehicle_insurance);

if (mysqli_num_rows($result_vehicle_insurance) > 0) {
    $html .= '<table>
        <thead>
            <tr>
                <th>Vehicle No</th>
                <th>Supplier Name</th>
                <th>Supplier Phone</th>
                <th>Insurance Expiry</th>
                <th>Status</th>
            </tr>
        </thead><tbody>';
    while ($row = mysqli_fetch_assoc($result_vehicle_insurance)) {
        $days_left = $row['days_left'];
        $status_class = ($days_left <= 0) ? 'expired' : 'critical';
        $status_text = ($days_left <= 0)
            ? 'Expired (' . abs($days_left) . ' days ago)'
            : 'Expiring in ' . $days_left . ' days';
        $html .= '<tr>
            <td>' . htmlspecialchars($row["vehicle_no"]) . '</td>
            <td>' . htmlspecialchars($row["supplier_name"]) . '</td>
            <td>' . htmlspecialchars($row["supplier_phone"]) . '</td>
            <td>' . htmlspecialchars($row["insurance_expiry_date"]) . '</td>
            <td class="' . $status_class . '">' . $status_text . '</td>
        </tr>';
    }
    $html .= '</tbody></table>';
} else {
    $html .= '<p style="color: #007700; font-weight: bold;">No vehicle insurance expiring within ' . $CRITICAL_DAYS . ' days.</p>';
}

$html .= '</main></body></html>';

mysqli_close($conn);

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

ob_end_clean();
$filename = 'Critical_Expiration_Report_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ["Attachment" => true]);
?>
