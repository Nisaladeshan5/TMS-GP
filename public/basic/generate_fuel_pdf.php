<?php
// generate_fuel_pdf.php

require_once '../../includes/session_check.php';
require_once '../../includes/db.php';
require_once '../../tcpdf/tcpdf.php'; 

// --- 1. Fetch Data ---
$filter_rate_id = isset($_GET['rate_id']) ? $_GET['rate_id'] : 'all';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$sql = "SELECT rate_id, type, rate, date FROM fuel_rate WHERE 1=1";
$filter_text = "All Fuel Types";

if ($filter_rate_id != 'all') {
    $sql .= " AND rate_id = " . intval($filter_rate_id);
    $type_query = $conn->query("SELECT type FROM fuel_rate WHERE rate_id = " . intval($filter_rate_id) . " LIMIT 1");
    if($r = $type_query->fetch_assoc()) { $filter_text = $r['type']; }
}

if (!empty($from_date)) {
    $sql .= " AND date >= '" . $conn->real_escape_string($from_date) . "'";
}
if (!empty($to_date)) {
    $sql .= " AND date <= '" . $conn->real_escape_string($to_date) . "'";
}

$sql .= " ORDER BY date DESC, type ASC";
$result = $conn->query($sql);

// --- 2. Initialize PDF ---
// 'P' = Portrait, 'mm' = millimeters, 'A4'
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

// Metadata
$pdf->SetCreator('TMS System');
$pdf->SetAuthor('Admin');
$pdf->SetTitle('Fuel Rate Report');

// Disable default Header/Footer for custom design
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set Margins (Left, Top, Right)
$pdf->SetMargins(15, 20, 15);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->SetFont('helvetica', '', 10);

$pdf->AddPage();

// --- 3. Custom Report Styling ---
// We use simple inline CSS for TCPDF compatibility
$html = '
<style>
    .title { font-size: 18pt; font-weight: bold; color: #2c3e50; }
    .subtitle { font-size: 10pt; color: #7f8c8d; }
    .meta-table { border-bottom: 2px solid #2c3e50; padding-bottom: 10px; }
    .data-table { width: 100%; border-collapse: collapse; }
    .data-th { background-color: #2c3e50; color: #ffffff; font-weight: bold; text-align: center; }
    .data-td { border-bottom: 1px solid #dfe6e9; color: #333; }
    .row-even { background-color: #ffffff; }
    .row-odd { background-color: #f8f9fa; }
    .total-count { font-weight: bold; font-size: 10pt; color: #333; }
</style>

<table cellpadding="0" cellspacing="0" class="meta-table" style="width: 100%;">
    <tr>
        <td width="60%">
            <div class="title">FUEL RATE HISTORY</div>
            <div class="subtitle">Transport Management System Report</div>
        </td>
        <td width="40%" align="right" style="font-size: 9pt; color: #555;">
            <strong>Generated:</strong> ' . date('Y-m-d H:i') . '<br>
            <strong>User:</strong> Admin<br>
        </td>
    </tr>
</table>
<br><br>

<table cellpadding="5" cellspacing="0" style="width: 100%; background-color: #ecf0f1; border-radius: 4px;">
    <tr>
        <td width="50%"><strong>Filter:</strong> ' . $filter_text . '</td>
        <td width="50%" align="right"><strong>Range:</strong> ' . ($from_date ? $from_date : 'Start') . ' to ' . ($to_date ? $to_date : 'Now') . '</td>
    </tr>
</table>
<br><br>

<table cellpadding="8" cellspacing="0" class="data-table">
    <thead>
        <tr>
            <th class="data-th" width="30%">Effective Date</th>
            <th class="data-th" width="40%">Fuel Type</th>
            <th class="data-th" width="30%" align="right">Rate (LKR)</th>
        </tr>
    </thead>
    <tbody>';

if ($result->num_rows > 0) {
    $i = 0; // Counter for zebra striping
    while ($row = $result->fetch_assoc()) {
        $bg_class = ($i % 2 == 0) ? 'row-even' : 'row-odd';
        
        $html .= '
        <tr class="' . $bg_class . '">
            <td class="data-td">' . date('Y-m-d', strtotime($row['date'])) . '</td>
            <td class="data-td">' . htmlspecialchars($row['type']) . '</td>
            <td class="data-td" align="right">' . number_format($row['rate'], 2) . '</td>
        </tr>';
        $i++;
    }
} else {
    $html .= '<tr><td colspan="3" align="center" style="padding: 20px; color: #7f8c8d;">No records found for this period.</td></tr>';
}

$html .= '</tbody></table>';

// --- 4. Footer Summary ---
$html .= '<br><br>
<div align="right" class="total-count">
    Total Records Found: ' . $result->num_rows . '
</div>';

// --- 5. Output ---
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('Fuel_History_' . date('Ymd') . '.pdf', 'D');
?>