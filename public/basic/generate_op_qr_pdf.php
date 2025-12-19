<?php
// generate_op_qr_pdf.php
// Creates 80mm x 50mm QR labels for Operational Service Rates, 
// using op_code as the QR data and displaying the descriptive service name (NE, DH, NH, EV) and Supplier Name.

require '../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\Data\QRCodeDataException;

// 1. Safely retrieve Service Rate Codes (op_code|vehicle_no)
$selected_rate_codes_str = $_POST['selected_service_rates'] ?? '';
$selected_rates = array_map('trim', explode(',', $selected_rate_codes_str));
$selected_rates = array_filter($selected_rates);

if (empty($selected_rates)) {
    die("Error: No service rates selected for PDF generation.");
}

// 2. Prepare for Database Retrieval using the dynamic OR structure
include('../../includes/db.php'); 

$where_clauses = [];
$bind_values = [];
$bind_types = '';

foreach ($selected_rates as $rate_pair) {
    // Ensure the pair format is valid before splitting
    if (strpos($rate_pair, '|') !== false) {
        list($op_code, $vehicle_no) = explode('|', $rate_pair);
        
        // Add one clause per selected composite key
        $where_clauses[] = "(os.op_code = ? AND os.vehicle_no = ?)";
        
        // Add the corresponding values for the placeholders
        $bind_values[] = $op_code;
        $bind_values[] = $vehicle_no;
        $bind_types .= 'ss'; // 's' for op_code (string) and 's' for vehicle_no (string)
    }
}

if (empty($where_clauses)) {
    die("Error: Invalid rate data provided.");
}

// Combine all clauses with OR
$where_condition = implode(' OR ', $where_clauses);

// --- SQL Query to fetch Service Rate and Supplier Details ---
$sql = "SELECT
            os.op_code,
            os.vehicle_no,
            s.supplier,
            os.is_active
        FROM
            op_services AS os
        LEFT JOIN
            vehicle AS v ON os.vehicle_no = v.vehicle_no
        LEFT JOIN
            supplier AS s ON v.supplier_code = s.supplier_code
        WHERE 
            os.is_active = 1 AND ({$where_condition})
        ORDER BY os.op_code, os.vehicle_no";


$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

// Bind the dynamic parameters
if (!empty($bind_values)) {
    // ...$bind_values unpacks the array into arguments for bind_param
    $stmt->bind_param($bind_types, ...$bind_values);
}

$stmt->execute();
$result = $stmt->get_result();

$service_data = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $service_data[] = $row;
    }
}
$stmt->close();
$conn->close();

if (empty($service_data)) {
    die("Error: No active data found for the selected rates.");
}


// --- HELPER FUNCTION: Maps op_code prefix to a descriptive name ---
function getServiceDescription(string $op_code): string {
    $prefix = substr($op_code, 0, 2);
    switch (strtoupper($prefix)) {
        case 'NE':
            return 'NIGHT EMERGENCY';
        case 'DH':
            return 'DAY HELD UP';
        case 'NH':
            return 'NIGHT HELD UP';
        case 'EV':
            return 'EXTRA VEHICLE';
        default:
            return '';
    }
}


// 3. Setup Dompdf and A4 Paper Size (Same as original)
$options = new Options();
$options->set('defaultFont', 'sans-serif'); 
$options->set('isPhpEnabled', true); 
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->setPaper('A4', 'portrait'); 

// LOGO Handling (Same as original)
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

// HTML Structure and CSS (Same as original)
$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Service QR Labels</title>
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
        
        .rotated-content-wrapper {
            height: 100%; 
            width: 100%; 
            display: flex; 
            flex-direction: column; 
            justify-content: center; 
            align-items: center; 
            box-sizing: border-box;
            padding: 1mm; 
            transform: rotate(180deg); 
        }

        .service-name-display { 
            font-size: 10pt; 
            font-weight: 900; 
            line-height: 1.1; 
            margin: 0 0 0 0; 
            color: #000; 
            word-wrap: break-word;
            text-align: center;
            display: block; 
        }

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

// 4. Generate QR Labels
foreach ($service_data as $index => $rate) {
    $html .= '<div class="label-row">';
    
    // The QR data is the op_code
    $qr_data = $rate['op_code']; 
    
    $qrcode_img = '';
    try {
        $qrcode_img = (new QRCode())->render($qr_data);
    } catch (QRCodeDataException $e) {
        $qrcode_img = 'data:image/png;base64,iVBORw0KGgoAAAAABJRU5ErkJggg==';
    }
    
    $op_code = htmlspecialchars(strtoupper($rate['op_code'] ?? 'N/A'));
    $supplier_name = htmlspecialchars(strtoupper($rate['supplier'] ?? 'SUPPLIER N/A'));
    $vehicle_no = htmlspecialchars(strtoupper($rate['vehicle_no'] ?? 'V/N N/A')); 
    
    // Get the dynamic service description
    $service_description = getServiceDescription($op_code); 
    
    // --- QR Label Container (Uses dynamic service_description) ---
    $html .= '
<div class="label-container">
    <div style="height: 100%; width: 100%; box-sizing: border-box;">
        <div style="float: left; width: 50%; text-align: center; height: 100%; box-sizing: border-box; padding-right: 1.5mm;">
            <div style="line-height: 1.1; margin: 5mm auto 0 auto; font-weight: bold; font-size: 10pt; color: #000; text-align: center;">
                ' . $service_description . '<br>
            </div>
            <img src="' . $qrcode_img . '" alt="QR Code" style="width: 38mm; height: 38mm; display: block; margin: 0 auto;">
        </div>
        <div style="float: right; width: 50%; padding-left: 1.5mm; box-sizing: border-box; margin-top: 5mm;">
            <p style="font-size: 7pt; font-weight: 900; line-height: 1.1; margin: 0 0 1mm 0; word-wrap: break-word; color: #000;">SUPPLIER: ' . $supplier_name . '</p>
            
            
            <span style="font-weight: 900; font-size: 10pt; display: block; text-align: center; margin: 3mm 0 1.5mm 0;">'.  $op_code .'</span>
            
            <hr style="border: none; border-top: 1px solid #000; margin: 5mm 0 1.5mm 0;">
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

    // --- Logo Container (Rotated, displays both Rate Code and Supplier Name) ---
    $html .= '<div class="item-container">
        <div class="logo-container">
            <table class="logo-table">
                <tr>
                    <td>
                        <div class="rotated-content-wrapper">
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

$dompdf->stream("service_qr_labels_" . date('Ymd_His') . ".pdf", array("Attachment" => 1));
?>