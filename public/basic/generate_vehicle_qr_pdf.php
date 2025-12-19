<?php
// generate_vehicle_qr_pdf.php
// Creates 80mm x 50mm QR labels for Vehicles arranged on an A4 page 
// The input is a list of Employee IDs received via GET method.

require '../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\Data\QRCodeDataException;

// 1. Safely retrieve Employee IDs from the GET request
$selected_emp_ids_str = $_GET['emp_ids'] ?? ''; // 🔑 Changed from $_POST to $_GET
$selected_emp_ids = array_map('trim', explode(',', $selected_emp_ids_str));
$selected_emp_ids = array_filter($selected_emp_ids);

if (empty($selected_emp_ids)) {
    die("Error: No Employee IDs selected for PDF generation.");
}

// 2. Retrieve Vehicle Numbers using Employee IDs from op_services (Step 1 Lookup)
include('../../includes/db.php'); 

$placeholders = implode(',', array_fill(0, count($selected_emp_ids), '?'));
$code_types = str_repeat('s', count($selected_emp_ids));

// Query to get the vehicle_no(s) associated with the employee IDs
$sql_vehicle_nos = "SELECT DISTINCT vehicle_no FROM own_vehicle WHERE emp_id IN ($placeholders)";

$stmt = $conn->prepare($sql_vehicle_nos);

if ($stmt === false) {
    die("Error preparing vehicle number statement: " . $conn->error);
}

$stmt->bind_param($code_types, ...$selected_emp_ids);
$stmt->execute();
$result_vehicle_nos = $stmt->get_result();

$target_vehicle_nos = [];
while($row = $result_vehicle_nos->fetch_assoc()) {
    if (!empty($row['vehicle_no'])) {
        $target_vehicle_nos[] = $row['vehicle_no'];
    }
}
$stmt->close();

if (empty($target_vehicle_nos)) {
    $conn->close();
    // Use the first few IDs for context in the error message
    $display_ids = implode(', ', array_slice($selected_emp_ids, 0, 3));
    if (count($selected_emp_ids) > 3) {
        $display_ids .= '...';
    }
    die("Error: No vehicle numbers found in the 'op_services' table for the selected Employee IDs: " . htmlspecialchars($display_ids) . ".");
}


// 3. Retrieve detailed Vehicle Data using the found Vehicle Numbers (Step 2 Lookup)
$vehicle_placeholders = implode(',', array_fill(0, count($target_vehicle_nos), '?'));
$vehicle_code_types = str_repeat('s', count($target_vehicle_nos));

$sql_vehicle_data = "SELECT 
            ov.vehicle_no, 
            ov.emp_id, 
            e.calling_name, 
            ov.type,
            ov.fuel_efficiency
        FROM 
            own_vehicle ov
        JOIN 
            employee e ON ov.emp_id = e.emp_id
        WHERE 
            ov.vehicle_no IN ($vehicle_placeholders)";

$stmt_data = $conn->prepare($sql_vehicle_data);

if ($stmt_data === false) {
    $conn->close();
    die("Error preparing detailed data statement: " . $conn->error);
}

$stmt_data->bind_param($vehicle_code_types, ...$target_vehicle_nos);
$stmt_data->execute();
$result_vehicle_data = $stmt_data->get_result();

$vehicle_data = [];
if ($result_vehicle_data->num_rows > 0) {
    while($row = $result_vehicle_data->fetch_assoc()) {
        $vehicle_data[] = $row;
    }
}
$stmt_data->close();
$conn->close();

if (empty($vehicle_data)) {
    die("Error: No detailed data found for the vehicle numbers retrieved.");
}

// 4. Setup Dompdf and A4 Paper Size
$options = new Options();
$options->set('defaultFont', 'sans-serif'); 
$options->set('isPhpEnabled', true); 
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->setPaper('A4', 'portrait'); 

// LOGO Handling (No change)
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

// 5. HTML Structure and Content Generation (No change)
$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vehicle QR Labels</title>
    <style>
        body { font-family: sans-serif; margin: 0; padding: 0; color: #000; text-align: left; }
        @page { margin: 10mm; }
        .label-container { width: 80mm; height: 50mm; display: block; margin: 0 auto 5mm auto; box-sizing: border-box; border: 1px solid black; page-break-inside: avoid; padding: 1px; }
        .logo-container { width: 80mm; height: 50mm; margin: 0 auto 5mm auto; box-sizing: border-box; border: 1px solid black; page-break-inside: avoid; padding: 0; }
        .logo-table { width: 100%; height: 100%; border-collapse: collapse; text-align: center; }
        .logo-table td { vertical-align: middle; padding: 0; height: 100%; } 
        .rotated-content-wrapper { height: 100%; width: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; box-sizing: border-box; padding: 1mm; transform: rotate(180deg); }
        .name-id-display { font-size: 15pt; font-weight: 900; line-height: 1.1; margin: 3mm 0 1mm 0; color: #000; word-wrap: break-word; text-align: center; display: block; }
        .logo-container img { max-width: 45mm; max-height: 45mm; margin: auto; display: block; transform: none; }
        .label-container table { page-break-inside: avoid; }
        .label-container:nth-child(4n+5), 
        .logo-container:nth-child(4n+5) { page-break-before: always; }
        .label-row { clear: both; } 
    </style>
</head>
<body>';

foreach ($vehicle_data as $index => $vehicle) {
    
    // QR data is the Vehicle No
    $qr_data = $vehicle['vehicle_no'];
    
    $qrcode_img = '';
    try {
        $qrcode_img = (new QRCode())->render($qr_data);
    } catch (QRCodeDataException $e) {
        $qrcode_img = 'data:image/png;base64,iVBORw0KGgoAAAAABJRU5ErkJggg==';
    }
    
    // Data variables
    $vehicle_no = htmlspecialchars(strtoupper($vehicle['vehicle_no'] ?? 'N/A'));
    $emp_id = htmlspecialchars(strtoupper($vehicle['emp_id'] ?? 'N/A'));
    $employee_name = htmlspecialchars(strtoupper($vehicle['calling_name'] ?? 'OWNER N/A'));
    $vehicle_type = htmlspecialchars(strtoupper($vehicle['type'] ?? 'VEHICLE'));

    // --- QR Label Container ---
    $html .= '
<div class="label-container">
    <div style="height: 100%; width: 100%; box-sizing: border-box;">
       
        <div style="float: right; width: 38%; padding-left: 2mm; box-sizing: border-box; margin-top: 6mm; border-left: 1px; color: #333">
            
            <p style="font-size: 8pt; font-weight: 900; padding-left: 1mm; line-height: 1.1; margin: 0 0 1mm 0; word-wrap: break-word; color: #000;">' . $employee_name . '</p>
            <p style="font-size: 6.5pt; padding-left: 1mm; font-weight: bold; margin: 0 0 1mm 0; line-height: 1.2; color: #333;">EMP ID: ' . $emp_id . '</p>
            <hr style="border: none; border-top: 1px solid #000; margin: 0 0 1.5mm 0;">
            
            <p style="font-size: 16pt; font-weight: 900; line-height: 1.1; margin-top: 10mm; word-wrap: break-word; color: #000; text-align: left;">' . $vehicle_no . '</p>

            
        </div>
        <div style="float: left; width: 62%; text-align: left; height: 100%; box-sizing: border-box; padding-right: 1mm;">
            
            <img src="' . $qrcode_img . '" alt="QR Code" style="width: 50mm; height: 50mm; display: block; margin: 0 0;">
        </div>
    </div>
</div>';

    // --- Logo Container (Back Label) ---
    $html .= '<div class="logo-container">
        <table class="logo-table">
            <tr>
                <td>
                    <div class="rotated-content-wrapper">
                        <img src="' . $base64_logo_data . '" alt="Company Logo">
                    </div>
                </td>
            </tr>
        </table>
    </div>';
}

$html .= '</body></html>'; 

$dompdf->loadHtml($html);
$dompdf->render();

$dompdf->stream("vehicle_qr_labels_" . date('Ymd_His') . ".pdf", array("Attachment" => 1));
?>