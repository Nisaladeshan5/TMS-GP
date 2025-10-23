<?php
// generate_qr_pdf.php

require '../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\Data\QRCodeDataException;

// 1. ආරක්ෂිතව User IDs ලබා ගැනීම
$selected_emp_ids_str = $_POST['selected_emp_ids'] ?? ''; 
$selected_emp_ids = array_map('trim', explode(',', $selected_emp_ids_str));
$selected_emp_ids = array_filter($selected_emp_ids);

if (empty($selected_emp_ids)) {
    die("Error: No users selected for PDF generation. Please ensure 'selected_emp_ids' data is being passed correctly.");
}

// 2. Database එකෙන් අවශ්‍ය Data ලබා ගැනීම
include('../../includes/db.php'); 

$placeholders = implode(',', array_fill(0, count($selected_emp_ids), '?'));
$id_types = str_repeat('s', count($selected_emp_ids));

$sql = "SELECT emp_id, route_code, purpose, qr_token, calling_name 
        FROM user 
        WHERE emp_id IN ($placeholders)";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param($id_types, ...$selected_emp_ids); 
$stmt->execute();
$result = $stmt->get_result();

$user_data = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $user_data[] = $row;
    }
}
$stmt->close();
$conn->close();

if (empty($user_data)) {
    die("Error: No data found for the selected employees. Check IDs and database connection.");
}

// 3. Dompdf සහ A4 Size එක සකස් කිරීම
$options = new Options();
$options->set('defaultFont', 'sans-serif'); 
$options->set('isPhpEnabled', true); 

$dompdf = new Dompdf($options);
$dompdf->setPaper('A4'); 

// ----------------------------------------------------------------------
// LOGO Path & Base64
$logo_path = __DIR__ . '/../assets/logo.png'; 
$base64_logo_data = '';
$fallback_logo = 'data:image/png;base64,iVBORw0KGgoAAAAABJRU5ErkJggg==';

if (file_exists($logo_path)) {
    $logo_data = file_get_contents($logo_path);
    $mime_type = 'image/png';
    
    if ($logo_data !== false) {
        $base64_encoded_data = base64_encode($logo_data);
        $base64_logo_data = 'data:' . $mime_type . ';base64,' . $base64_encoded_data; 
    } else {
        $base64_logo_data = $fallback_logo; 
    }
} else {
    $base64_logo_data = $fallback_logo; 
}
// ----------------------------------------------------------------------

$base_url = "https://bitzit.com.lk/tmsgp/mobile_login.php?token=";

$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QR Labels</title>
    <style>
        body {
            font-family: sans-serif;
            margin: 0;
            padding: 0;
            color: #000;
            text-align: left; 
        }
        @page { margin: 10mm; }

        .label-container {
            width: 80mm;
            height: 50mm;
            display: block; 
            margin: 0 auto 5mm auto; 
            box-sizing: border-box;
            border: 1px solid black; 
            page-break-inside: avoid; 
        }

        .logo-container {
            width: 80mm;
            height: 50mm;
            margin: 0 auto 5mm auto;
            box-sizing: border-box;
            border: 1px solid black; 
            page-break-inside: avoid; 
        }

        .logo-table {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
            text-align: center;
        }
        
        .logo-table td {
            vertical-align: middle;
            padding: 0;
        }

        /* 🔥 Logo rotate 180deg */
        .logo-container img {
            max-width: 40mm; 
            max-height: 40mm; 
            transform: rotate(180deg);
        }

        .label-container table {
             page-break-inside: avoid;
        }

        .label-container:nth-child(8n+9),
        .logo-container:nth-child(8n+9) { 
            page-break-before: always;
        }
    </style>
</head>
<body>';

foreach ($user_data as $user) {
    $qr_data = $base_url . $user['qr_token'];
    
    $qrcode_img = '';
    try {
        $qrcode_img = (new QRCode())->render($qr_data);
    } catch (QRCodeDataException $e) {
        $qrcode_img = 'data:image/png;base64,iVBORw0KGgoAAAAABJRU5ErkJggg==';
    }
    
    $emp_id = htmlspecialchars($user['emp_id'] ?? 'N/A');
    $purpose = htmlspecialchars(strtoupper($user['purpose'] ?? 'STAFF'));
    $calling_name = htmlspecialchars(strtoupper($user['calling_name'] ?? 'N/A'));
    $route_code = htmlspecialchars(strtoupper($user['route_code'] ?? 'N/A'));
    
    $html .= '
<div class="label-container">
    <table style="width: 100%; border-collapse: collapse; height: 100%; margin: 0; padding: 0;">
        <tr>
            <td style="width: 35%; text-align: center; vertical-align: top; padding-right: 1.5mm; padding-top: 2mm; padding-bottom: 2mm;">
                <p style="font-size: 10pt; font-weight: bold; text-align: center; border-bottom: 1px solid #333; margin: 2mm 0 4mm 0; padding-bottom: 0.5mm;">
                    ' . $purpose . ' <span style="font-size: 10pt; font-weight: 900;">TRANSPORT</span>
                </p>
                <img src="' . $qrcode_img . '" style="width: 100%; height: auto; max-width: 25mm; display: block; margin: 0 auto;">
            </td>
            <td style="width: 65%; vertical-align: top; padding-left: 1mm; padding-top: 4mm;"> 
                <p style="font-size: 14pt; font-weight: 900; line-height: 1.1; margin: 0 0 5mm 0;">' . $calling_name . '</p> 
                <p style="font-size: 8pt; font-weight: 500; margin: 0 0 1.5mm 0; line-height: 1.1;">FACTORY NO : ' . $emp_id . '</p>
                <p style="font-size: 8pt; font-weight: 500; margin: 0 0 6mm 0; line-height: 1.1;">ROUTE CODE: ' . $route_code . '</p> 
                <p style="font-size: 6pt; line-height: 1.2; margin: 0 0 0.5mm 0; padding-top: 0.5mm; border-top: 1px solid #000;">IF FOUND PLEASE RETURN TO</p>
                <p style="font-weight: bold; font-size: 6.5pt; line-height: 1.2; margin: 0 0 0.5mm 0;">GP GARMENTS (PVT) LTD</p>
                <p style="font-size: 6pt; line-height: 1.2; margin: 0 0 0.5mm 0;">SEETHAWAKA EXPORT PROCESSING ZONE</p>
                <p style="font-size: 6pt; line-height: 1.2; margin: 0 0 0.5mm 0;">AWISSAWELLA, SRI LANKA.</p>
                <p style="font-size: 6pt; line-height: 1.2; margin: 0 0 0.5mm 0;">TEL: 036 2232 900 / 0365420000</p>
                <p style="font-size: 6pt; line-height: 1.2; margin: 0;">FAX: 036 2231685</p>
            </td>
        </tr>
    </table>
</div>';

    $html .= '<div class="logo-container">
        <table class="logo-table">
            <tr>
                <td>
                    <img src="' . $base64_logo_data . '" alt="Company Logo">
                </td>
            </tr>
        </table>
    </div>';
}

$html .= '</body></html>'; 

$dompdf->loadHtml($html);
$dompdf->render();
$dompdf->stream("qr_labels_" . date('Ymd_His') . ".pdf", array("Attachment" => 1));

?>
