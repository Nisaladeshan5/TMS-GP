<?php
// generate_fuel_pdf.php

require_once '../../includes/session_check.php';
require_once '../../includes/db.php';
require_once '../../tcpdf/tcpdf.php';
date_default_timezone_set('Asia/Colombo');

/* ======================================================
    PROFESSIONAL PDF CLASS
====================================================== */
class MYPDF extends TCPDF {
    public function Header() {
        // ඉතා සිහින් පිටත රාමුව (Light Gray)
        $this->SetLineStyle(array('width' => 0.1, 'color' => array(150, 150, 150)));
        $this->Rect(10, 10, 190, 277);

        $this->SetY(15);
        $this->SetFont('helvetica', 'B', 14); // ප්‍රධාන මාතෘකාව මඳක් කුඩා කළා
        $this->SetTextColor(30, 58, 138); // Royal Blue
        $this->Cell(0, 10, 'TRANSPORT MANAGEMENT SYSTEM', 0, 1, 'C');
        
        $this->SetFont('helvetica', 'B', 8); // Subtitle එක 8pt වලට කුඩා කළා
        $this->SetTextColor(100, 116, 139); 
        $this->Cell(0, 5, 'OFFICIAL FUEL RATE MONITORING REPORT', 0, 1, 'C');
        
        // සරල තනි ඉරක්
        $this->SetLineStyle(array('width' => 0.3, 'color' => array(30, 58, 138)));
        $this->Line(15, 32, 195, 32);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(148, 163, 184);
        $current_date = date('Y-m-d H:i:s');
        $this->Cell(0, 10, "Report Generated: $current_date | Page " . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

/* ======================================================
    DATA FETCHING
====================================================== */
$curr_res = $conn->query("SELECT fr1.type, fr1.rate, fr1.date FROM fuel_rate fr1 
             INNER JOIN (SELECT type, MAX(date) as max_date FROM fuel_rate GROUP BY type) fr2 
             ON fr1.type = fr2.type AND fr1.date = fr2.max_date ORDER BY fr1.type ASC");

$rate_id   = $_GET['rate_id'] ?? 'all';
$from_date = $_GET['from_date'] ?? '';
$to_date   = $_GET['to_date'] ?? '';

$sql = "SELECT type, rate, date FROM fuel_rate WHERE 1=1";
$filter_name = "All Types";

if ($rate_id != 'all') {
    $rate_id = intval($rate_id);
    $sql .= " AND rate_id = $rate_id";
    $q = $conn->query("SELECT type FROM fuel_rate WHERE rate_id=$rate_id LIMIT 1");
    if ($r = $q->fetch_assoc()) $filter_name = $r['type'];
}
if ($from_date) $sql .= " AND date >= '".$conn->real_escape_string($from_date)."'";
if ($to_date)   $sql .= " AND date <= '".$conn->real_escape_string($to_date)."'";

$sql .= " ORDER BY date DESC";
$history_res = $conn->query($sql);

/* ======================================================
    PDF CONFIGURATION
====================================================== */
$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetMargins(15, 40, 15);
$pdf->SetAutoPageBreak(TRUE, 30);
$pdf->AddPage();

/* ======================================================
    STYLING & CONTENT (CLEAN LOOK)
====================================================== */

$html = '
<style>
    .section-title { font-size: 9pt; font-weight: bold; color: #1e3a8a; }
    .meta-text { font-size: 8pt; color: #475569; }
    .main-table { width: 100%; border-collapse: collapse; }
    .th { background-color: #f1f5f9; color: #1e3a8a; font-weight: bold; border: 0.1px solid #cbd5e1; text-align: center; font-size: 8pt; }
    .td { border: 0.1px solid #e2e8f0; font-size: 8pt; color: #334155; }
</style>';

// Meta Info
$html .= '
<table width="100%" class="meta-text">
    <tr>
        <td width="50%"><strong>SCOPE:</strong> ' . strtoupper($filter_name) . '</td>
        <td width="50%" align="right"><strong>REF:</strong> TMS/FUEL/' . date('Ymd') . '</td>
    </tr>
    <tr>
        <td width="50%"><strong>PERIOD:</strong> ' . ($from_date ?: 'START') . ' - ' . ($to_date ?: date('Y-m-d')) . '</td>
        <td width="50%" align="right"><strong>STATUS:</strong> OFFICIAL RECORDS</td>
    </tr>
</table>
<br><br>';

// Executive Summary
$html .= '<div class="section-title">01. CURRENT MARKET RATES (LATEST)</div><br>
<table class="main-table" cellpadding="5" border="1">
    <thead>
        <tr class="th">
            <th width="45%">Fuel Description</th>
            <th width="30%">Last Updated Date</th>
            <th width="25%" align="right">Rate (LKR)</th>
        </tr>
    </thead>
    <tbody>';
while ($c = $curr_res->fetch_assoc()) {
    $html .= '
    <tr>
        <td width="45%" class="td">' . htmlspecialchars($c['type']) . '</td>
        <td width="30%" class="td" align="center">' . date('Y-m-d', strtotime($c['date'])) . '</td>
        <td width="25%" class="td" align="right"><strong>' . number_format($c['rate'], 2) . '</strong></td>
    </tr>';
}
$html .= '</tbody></table><br><br>';

// History Table
$html .= '<div class="section-title">02. HISTORICAL PRICE LOGS</div><br>
<table class="main-table" cellpadding="4" border="1">
    <thead>
        <tr class="th">
            <th width="8%">#</th>
            <th width="27%">Effective Date</th>
            <th width="45%">Fuel Description</th>
            <th width="20%" align="right">Rate (Rs.)</th>
        </tr>
    </thead>
    <tbody>';

if ($history_res->num_rows > 0) {
    $i = 1;
    while ($row = $history_res->fetch_assoc()) {
        $html .= '
        <tr>
            <td width="8%" class="td" align="center">' . sprintf('%02d', $i) . '</td>
            <td width="27%" class="td" align="center">' . date('Y-m-d', strtotime($row['date'])) . '</td>
            <td width="45%" class="td">' . htmlspecialchars($row['type']) . '</td>
            <td width="20%" class="td" align="right"><strong>' . number_format($row['rate'], 2) . '</strong></td>
        </tr>';
        $i++;
    }
} else {
    $html .= '<tr><td colspan="4" class="td" align="center">No archival records found.</td></tr>';
}
$html .= '</tbody></table><br><br><br>';

// Signature Area
$html .= '
<table width="100%" style="font-size: 8pt; color: #334155;">
    <tr>
        <td width="40%" align="center">
            <br><br><br>...................................................<br>
            <strong>Prepared By</strong><br>
            Transport Officer
        </td>
        <td width="20%"></td>
        <td width="40%" align="center">
            <br><br><br>...................................................<br>
            <strong>Authorized By</strong><br>
            Transport Manager
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

ob_end_clean();
$pdf->Output('Fuel_Official_Report.pdf', 'I');
?>