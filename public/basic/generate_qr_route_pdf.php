<?php
// generate_route_qr_pdf.php
// Creates 80mm x 50mm QR labels for Routes arranged on an A4 page 
// (Single Column, exactly 4 Components per page: 2 Labels + 2 Logos).

require '../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\Data\QRCodeDataException;

// 1. Safely retrieve Route Codes
$selected_route_codes_str = $_POST['selected_route_codes'] ?? '';
$selected_route_codes = array_map('trim', explode(',', $selected_route_codes_str));
$selected_route_codes = array_filter($selected_route_codes);

if (empty($selected_route_codes)) {
    die("Error: No routes selected for PDF generation.");
}

// 2. Retrieve required Route Data from the Database
include('../../includes/db.php'); 

$placeholders = implode(',', array_fill(0, count($selected_route_codes), '?'));
$code_types = str_repeat('s', count($selected_route_codes));

$sql = "SELECT route_code, route, purpose
        FROM route 
        WHERE route_code IN ($placeholders) AND is_active = 1";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param($code_types, ...$selected_route_codes);
$stmt->execute();
$result = $stmt->get_result();

$route_data = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $route_data[] = $row;
    }
}
$stmt->close();
$conn->close();

if (empty($route_data)) {
    die("Error: No data found or selected routes are inactive.");
}

// 3. Setup Dompdf and A4 Paper Size
$options = new Options();
$options->set('defaultFont', 'sans-serif'); 
$options->set('isPhpEnabled', true); 
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->setPaper('A4', 'portrait'); 

// LOGO
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

// HTML
$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QR Labels</title>
    <style>
        body { font-family: sans-serif; margin: 0; padding: 0; color: #000; text-align: left; }
        @page { margin: 10mm; }

        .label-container {
            width: 80mm;
            height: 50mm;
            display: block;
            margin: 0 auto 5mm auto;
            box-sizing: border-box;
            border: 1px solid black;
            page-break-inside: avoid;
            padding: 1px;
        }

        .logo-container {
            width: 80mm;
            height: 50mm;
            margin: 0 auto 5mm auto;
            box-sizing: border-box;
            border: 1px solid black;
            page-break-inside: avoid;
            padding: 0; 
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
            height: 100%;
        } 
        
        /* සම්පූර්ණ අන්තර්ගතය රඳවා ඇති ප්‍රධාන කන්ටේනරය */
        .rotated-content-wrapper {
            height: 100%; 
            width: 100%; 
            display: flex; 
            flex-direction: column; 
            justify-content: center; 
            align-items: center; 
            box-sizing: border-box;
            padding: 1mm; 
            /* Logo සහ Name එකවර 180° කරකවයි - මෙය සාමාන්‍යයෙන් "උඩට" හැරවීමට යොදා ගනී */
            transform: rotate(180deg); 
        }

        /* Route Name එකේ CSS - Logo එකට කිට්ටු කිරීමට margin-bottom: 1mm */
        .route-name-display { 
            font-size: 17pt; 
            font-weight: 900; 
            line-height: 1.1; 
            margin: 3mm 0 1mm 0; 
            color: #000; 
            word-wrap: break-word;
            text-align: center;
            display: block; 
        }
        
        /* Logo රූපයේ CSS */
        .logo-container img { 
            max-width: 45mm; 
            max-height: 45mm; 
            margin: auto; 
            display: block;
            transform: none; 
        }

        .label-container table { page-break-inside: avoid; }

        .label-container:nth-child(8n+9),
        .logo-container:nth-child(8n+9) { page-break-before: always; }
    </style>
</head>
<body>';

foreach ($route_data as $index => $route) {
    $html .= '<div class="label-row">';

    $label_margin_style = '';
    
    $qr_data = $route['route_code'];
    
    $qrcode_img = '';
    try {
        $qrcode_img = (new QRCode())->render($qr_data);
    } catch (QRCodeDataException $e) {
        $qrcode_img = 'data:image/png;base64,iVBORw0KGgoAAAAABJRU5ErkJggg==';
    }
    
    $route_code = htmlspecialchars(strtoupper($route['route_code'] ?? 'N/A'));
    $route_name = htmlspecialchars(strtoupper($route['route'] ?? 'N/A ROUTE'));
    $purpose = htmlspecialchars(strtoupper($route['purpose'] ?? 'STAFF')); 
    
    // --- QR Label Container ---
    $html .= '
<div class="label-container" style="' . $label_margin_style . '">
    <div style="height: 100%; width: 100%; box-sizing: border-box;">
        <div style="float: left; width: 50%; text-align: center; height: 100%; box-sizing: border-box; padding-right: 1.5mm;">
            <div style="line-height: 1.1; margin: 2mm auto 0 auto; font-weight: bold; font-size: 10pt; color: #000; text-align: center;">
                ' . $purpose . '<br><span style="font-weight: 900; font-size: 10pt;">TRANSPORT</span>
            </div>
            <img src="' . $qrcode_img . '" alt="QR Code" style="width: 38mm; height: 38mm; display: block; margin: 0 auto;">
        </div>
        <div style="float: right; width: 50%; padding-left: 1.5mm; box-sizing: border-box; margin-top: 3mm;">
            <p style="font-size: 8pt; font-weight: 900; line-height: 1.1; margin: 0 0 1mm 0; word-wrap: break-word; color: #000;">' . $route_name . '</p>
            <p style="font-size: 6.5pt; font-weight: bold; margin: 0 0 5mm 0; line-height: 1.2; color: #333;">ROUTE CODE: ' . $route_code . '</p>
            <hr style="border: none; border-top: 1px solid #000; margin: 12mm 0 1.5mm 0;">
            <div>
                <p style="font-size: 5pt; line-height: 1.2; margin: 3mm 0 0.5mm 0;">IF FOUND PLEASE RETURN TO</p>
                <p style="font-weight: bold; font-size: 5.5pt; line-height: 1.2; margin: 0 0 0.5mm 0; color: #000;">GP GARMENTS (PVT) LTD</p>
                <p style="font-size: 5pt; line-height: 1.2; margin: 0 0 0.4mm 0;">SEETHAWAKA EXPORT PROCESSING ZONE</p>
                <p style="font-size: 5pt; line-height: 1.2; margin: 0 0 0.4mm 0;">AWISSAWELLA, SRI LANKA.</p>
                <p style="font-size: 5pt; line-height: 1.2; margin: 0 0 0.4mm 0;">TEL: 036 2232 900 / 0365420000</p>
                <p style="font-size: 5pt; line-height: 1.2; margin: 0;">FAX: 036 2231685</p>
            </div>
        </div>
    </div>
</div>';

    // --- UPDATED Logo Container (දෙකම එකට කරකවයි) ---
    $html .= '<div class="item-container">
        <div class="logo-container">
            <table class="logo-table">
                <tr>
                    <td>
                        <div class="rotated-content-wrapper">
                            <span class="route-name-display">' . $route_name . '</span>
                            <img src="' . $base64_logo_data . '" alt="Company Logo">
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>';
}

$html .= '</body></html>'; 

$dompdf->loadHtml($html);
$dompdf->render();

$dompdf->stream("route_qr_labels_" . date('Ymd_His') . ".pdf", array("Attachment" => 1));
?>