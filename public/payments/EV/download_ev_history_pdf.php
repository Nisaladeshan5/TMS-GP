<?php
// download_ev_history_single_pdf.php - Payment Voucher with Rate (Exact Style)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php'); 
require('../fpdf.php'); // Ensure path is correct

date_default_timezone_set('Asia/Colombo');

// --- 1. Get Inputs ---
$code = $_GET['code'] ?? ''; 
$month = isset($_GET['month']) ? (int)$_GET['month'] : 0; 
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;

if (!$code || !$month || !$year) {
    die("Invalid Request parameters.");
}

$monthName = date('F', mktime(0,0,0,$month,10));

// --- 2. Extend FPDF Class (EXACT COPY from your download_ev_pdf.php) ---
class PDF extends FPDF
{
    protected $period;

    function SetReportDetails($monthName, $year) {
        $this->period = "$monthName, $year";
    }
    
    public function GetSafeLimit() {
        return $this->h - $this->bMargin;
    }

    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k; $hp = $this->h;
        if($style=='F') $op='f'; elseif($style=='FD' || $style=='DF') $op='B'; else $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x+$r)*$k, ($hp-$y)*$k ));
        $xc = $x+$w-$r ; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k, ($hp-$y)*$k ));
        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        $xc = $x+$w-$r ; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l', ($x+$w)*$k, ($hp-$yc)*$k));
        $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x+$r ; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k, ($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        $xc = $x+$r ; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $x*$k, ($hp-$yc)*$k ));
        $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1*$this->k, ($h-$y1)*$this->k,
            $x2*$this->k, ($h-$y2)*$this->k,
            $x3*$this->k, ($h-$y3)*$this->k));
    }

    function Header() {
        $this->RoundedRect(7.5, 7.5, 195, 47, 1.5);
        $this->Rect(5, 5, 200, 287);
        
        if(file_exists('../../assets/logo.png')) {
            $this->Image('../../assets/logo.png', 12, 5, 25); 
        }

        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 5, 'GP Garments (Pvt) Ltd', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Seethawaka Export Processing Zone', 0, 1, 'C');
        $this->Cell(0, 5, 'Awissawella, Sri Lanka', 0, 1, 'C');
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 5, 'Extra Vehicle Payment Voucher', 0, 1, 'C');
    }

    function Footer() {
        $this->SetY(-30);
        $this->SetFont('Arial', '', 10);
        
        $this->SetX(20);
        $this->Cell(50, 5, '____________________', 0, 0, 'C');
        $this->Cell(60, 5, '____________________', 0, 0, 'C');
        $this->Cell(50, 5, '____________________', 0, 1, 'C');
        
        $this->SetX(20);
        $this->Cell(50, 5, 'Prepared By', 0, 0, 'C');
        $this->Cell(60, 5, 'Checked By', 0, 0, 'C');
        $this->Cell(50, 5, 'Authorized By', 0, 1, 'C');

        $this->SetFont('Arial', '', 8);
        $this->SetY(-15);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetX(10);
        $this->Cell(0, 10, 'Print Date: ' . date('Y-m-d H:i'), 0, 0, 'R');
    }
}

// --- 3. FETCH SINGLE RECORD ---
$sql = "
    SELECT 
        mph.*,
        s.supplier,
        s.supplier_code,
        s.email,
        s.beneficiaress_name,
        s.bank,
        s.branch,
        s.acc_no
    FROM 
        monthly_payments_ev mph
    LEFT JOIN 
        supplier s ON mph.supplier_code = s.supplier_code
    WHERE 
        mph.code = ? AND mph.month = ? AND mph.year = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sii", $code, $month, $year);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
$stmt->close();

if (!$data) {
    die("Record not found.");
}

// --- 4. GENERATE PDF ---
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->SetReportDetails($monthName, $year);

$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// Subtitle
$pdf->Cell(0,10,"Summary for {$monthName}, {$year}",0,1,'C');
$pdf->Cell(0,5,"Identifier: " . $data['code'],0,1,'C');
$pdf->Ln(8);

// Supplier Details
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0,7,'Supplier Details',0,1,'L');
$pdf->SetFont('Arial','',10);

$fields = [
    'Supplier' => $data['supplier'],
    'Supplier Code' => $data['supplier_code'],
    'Email' => $data['email'],
    'Beneficiary' => $data['beneficiaress_name'],
    'Bank' => $data['bank'],
    'Branch' => $data['branch'],
    'Account No' => $data['acc_no']
];

foreach($fields as $label => $value){
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(40,6,$label.':',0,0);
    $pdf->SetFont('Arial','',10);
    $pdf->Cell(0,6,$value,0,1);
}
$pdf->Ln(5);

// Table Header
$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(220,220,220); // Gray Header
$pdf->Cell(130,8,'Description',1,0,'L',1);
$pdf->Cell(60,8,'Amount / Value',1,1,'R',1);

$pdf->SetFont('Arial','',10);
$pdf->SetFillColor(255,255,255); // White Rows

// Data Row 1: Distance
$pdf->Cell(130,8,'Total Distance (Km)',1,0,'L',1);
$pdf->Cell(60,8,number_format($data['total_distance'], 2),1,1,'R',1);

// Data Row 2: Rate (NEW ADDITION)
$pdf->Cell(130,8,'Average Rate (LKR/Km)',1,0,'L',1);
$pdf->Cell(60,8,number_format($data['rate'], 2),1,1,'R',1);

// Data Row 3: Payment (Highlighted)
$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(230,230,230); // Footer Gray
$pdf->Cell(130,8,'TOTAL PAYMENT (LKR)',1,0,'L',1);
$pdf->Cell(60,8,number_format($data['monthly_payment'], 2),1,1,'R',1);

$pdf->Ln(10);

// Output
$filename = "EV_Voucher_" . $code . "_" . date('Y-m', mktime(0,0,0,$month,1,$year)) . ".pdf";
$pdf->Output('D', $filename);

$conn->close();
?>