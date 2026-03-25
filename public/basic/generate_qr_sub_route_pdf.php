<?php
// generate_qr_sub_route_pdf.php
require '../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;

// 1. Get selected codes
$selected_codes_str = $_POST['selected_sub_route_codes'] ?? '';
$selected_codes = array_map('trim', explode(',', $selected_codes_str));
$selected_codes = array_filter($selected_codes);

if (empty($selected_codes)) {
    die("Error: No sub-routes selected.");
}

include('../../includes/db.php'); 

// 2. Query only from sub_route table
$placeholders = implode(',', array_fill(0, count($selected_codes), '?'));
$sql = "SELECT sub_route_code, sub_route FROM sub_route WHERE sub_route_code IN ($placeholders) AND is_active = 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('s', count($selected_codes)), ...$selected_codes);
$stmt->execute();
$result = $stmt->get_result();

$sub_route_data = [];
while($row = $result->fetch_assoc()) {
    $sub_route_data[] = $row;
}
$stmt->close();
$conn->close();

// 3. Dompdf Setup
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->setPaper('A4', 'portrait');

// Logo setup
$logo_path = __DIR__ . '/../assets/logo.png';
$base64_logo = file_exists($logo_path) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path)) : '';

$html = '<html><head><style>
    body { font-family: sans-serif; margin: 0; }
    @page { margin: 10mm; }
    .label-container { width: 80mm; height: 50mm; border: 1px solid black; margin: 0 auto 5mm auto; padding: 1px; page-break-inside: avoid; }
    .logo-container { width: 80mm; height: 50mm; border: 1px solid black; margin: 0 auto 5mm auto; page-break-inside: avoid; }
    .rotated { height: 100%; width: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; transform: rotate(180deg); text-align: center; }
    .qr-img { width: 38mm; height: 38mm; }
</style></head><body>';

foreach ($sub_route_data as $row) {
    $qr_img = (new QRCode())->render($row['sub_route_code']);
    $sub_name = htmlspecialchars(strtoupper($row['sub_route']));
    $sub_code = htmlspecialchars(strtoupper($row['sub_route_code']));
    
    // --- මෙතන Purpose එක නැති නිසා "FACTORY" කියලා කෙලින්ම දැම්මා ---
    $purpose = "FACTORY"; 

    $html .= '
    <div class="label-container">
        <div style="float: left; width: 50%; text-align: center; margin-top: 2mm;">
            <div style="font-weight: bold; font-size: 9pt;">' . $purpose . '<br>TRANSPORT</div>
            <img src="' . $qr_img . '" class="qr-img">
        </div>
        <div style="float: right; width: 50%; padding-left: 2mm; margin-top: 4mm;">
            <p style="font-size: 9pt; font-weight: 900; margin: 0;">' . $sub_name . '</p>
            <p style="font-size: 7pt; margin: 1mm 0;">CODE: ' . $sub_code . '</p>
            <hr style="border-top: 1px solid black; margin: 10mm 0 2mm 0;">
            <div style="font-size: 5pt; line-height: 1.2;">
                <strong>GP GARMENTS (PVT) LTD</strong><br>
                SEETHAWAKA EPZ, AWISSAWELLA.<br>
                TEL: 036 2232 900
            </div>
        </div>
    </div>
    <div class="logo-container">
        <div class="rotated">
            <span style="font-size: 15pt; font-weight: 900; margin-bottom: 2mm; display: block;">' . $sub_name . '</span>
            <img src="' . $base64_logo . '" style="max-width: 45mm; max-height: 40mm;">
        </div>
    </div>';
}

$html .= '</body></html>';
$dompdf->loadHtml($html);
$dompdf->render();
$dompdf->stream("sub_route_qr.pdf", ["Attachment" => 1]);