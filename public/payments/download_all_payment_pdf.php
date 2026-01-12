<?php
// download_all_payment_pdf.php - Grand Summary (Amount Only)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php'); 
require('fpdf.php'); 

date_default_timezone_set('Asia/Colombo');

// --- 1. Get Inputs ---
$supplier_code = $_GET['supplier_code'] ?? '';
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m'); 
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

if (!$supplier_code) {
    die("Missing Supplier Code.");
}

$monthName = date('F', mktime(0,0,0,$month,10));

// --- 2. Extend FPDF Class ---
class PDF extends FPDF
{
    protected $period;

    function SetReportDetails($period) {
        $this->period = $period;
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
        
        if(file_exists('../assets/logo.png')) {
            $this->Image('../assets/logo.png', 12, 5, 25); 
        }

        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 5, 'GP Garments (Pvt) Ltd', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Seethawaka Export Processing Zone', 0, 1, 'C');
        $this->Cell(0, 5, 'Awissawella, Sri Lanka', 0, 1, 'C');
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 5, 'Total Payment Summary Voucher', 0, 1, 'C');
    }

    function Footer() {
        $this->SetY(-35);
        $this->SetFont('Arial', '', 10);
        
        $this->Cell(63, 5, '____________________', 0, 0, 'C');
        $this->Cell(63, 5, '____________________', 0, 0, 'C');
        $this->Cell(63, 5, '____________________', 0, 1, 'C');
        
        $this->Cell(63, 5, 'Prepared By', 0, 0, 'C');
        $this->Cell(63, 5, 'Checked By', 0, 0, 'C');
        $this->Cell(63, 5, 'Authorized By', 0, 1, 'C');

        $this->SetFont('Arial', '', 8);
        $this->SetY(-15);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetX(10);
        $this->Cell(0, 10, 'Print Date: ' . date('Y-m-d H:i'), 0, 0, 'R');
    }
}

// --- 3. FETCH DATA (UNION ALL - NO DISTANCE) ---
$sql = "
    SELECT 'Staff Transport' as category, route_code as identifier, monthly_payment as amount 
    FROM monthly_payments_sf WHERE supplier_code = ? AND month = ? AND year = ?
    
    UNION ALL
    
    SELECT 'Factory Transport' as category, route_code as identifier, monthly_payment as amount 
    FROM monthly_payments_f WHERE supplier_code = ? AND month = ? AND year = ?
    
    UNION ALL
    
    SELECT 'Factory Sub Routes' as category, sub_route_code as identifier, monthly_payment as amount 
    FROM monthly_payments_sub WHERE supplier_code = ? AND month = ? AND year = ?
    
    UNION ALL
    
    SELECT 'Night Emergency' as category, op_code as identifier, monthly_payment as amount 
    FROM monthly_payment_ne WHERE supplier_code = ? AND month = ? AND year = ?
    
    UNION ALL
    
    SELECT 'Extra Vehicle' as category, code as identifier, monthly_payment as amount 
    FROM monthly_payments_ev WHERE supplier_code = ? AND month = ? AND year = ?
    
    UNION ALL
    
    SELECT 'Day Heldup' as category, op_code as identifier, monthly_payment as amount 
    FROM monthly_payments_dh WHERE supplier_code = ? AND month = ? AND year = ?
    
    UNION ALL
    
    SELECT 'Night Heldup' as category, op_code as identifier, monthly_payment as amount 
    FROM monthly_payments_nh WHERE supplier_code = ? AND month = ? AND year = ?
";

$stmt = $conn->prepare($sql);
// Bind params: (sii) * 7 times = 21 params
$stmt->bind_param(
    "siisiisiisiisiisiisii", 
    $supplier_code, $month, $year,
    $supplier_code, $month, $year,
    $supplier_code, $month, $year,
    $supplier_code, $month, $year,
    $supplier_code, $month, $year,
    $supplier_code, $month, $year,
    $supplier_code, $month, $year
);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
$total_payment = 0;

while ($row = $result->fetch_assoc()) {
    $amt = (float)$row['amount'];
    if ($amt > 0) {
        $items[] = $row;
        $total_payment += $amt;
    }
}
$stmt->close();

if (empty($items)) {
    die("No payment records found for Supplier: {$supplier_code} in {$monthName} {$year}.");
}

// Fetch Supplier Info
$sup_sql = "SELECT supplier, supplier_code, email, beneficiaress_name, bank, branch, acc_no FROM supplier WHERE supplier_code = ?";
$sup_stmt = $conn->prepare($sup_sql);
$sup_stmt->bind_param("s", $supplier_code);
$sup_stmt->execute();
$supplier_details = $sup_stmt->get_result()->fetch_assoc();
$sup_stmt->close();


// --- 4. GENERATE PDF ---
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->SetReportDetails("$monthName, $year");

$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// Subtitle
$pdf->Cell(0,10,"Summary for {$monthName}, {$year}",0,1,'C');
$pdf->Cell(0,5,"Supplier: " . $supplier_code,0,1,'C');
$pdf->Ln(8);

// Supplier Details Box
if ($supplier_details) {
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,7,'Supplier Details',0,1,'L');
    $pdf->SetFont('Arial','',10);

    $fields = [
        'Supplier Name' => 'supplier', 
        'Supplier Code' => 'supplier_code',
        'Beneficiary' => 'beneficiaress_name',
        'Bank' => 'bank', 
        'Branch' => 'branch', 
        'Account No' => 'acc_no'
    ];
    
    foreach($fields as $label => $key){
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(40,6,$label.':',0,0);
        $pdf->SetFont('Arial','',10);
        $pdf->Cell(0,6,$supplier_details[$key],0,1);
    }
    $pdf->Ln(5);
}

// Table Header (Widths Adjusted for No Distance)
$pdf->SetFont('Arial','B',9);
$pdf->SetFillColor(220,220,220);
$pdf->Cell(60,8,'Payment Type',1,0,'L',1);
$pdf->Cell(80,8,'Identifier (Route/Op Code)',1,0,'L',1); // Wider column for Identifier
$pdf->Cell(50,8,'Amount (LKR)',1,1,'R',1);

$pdf->SetFont('Arial','',9);
$pdf->SetFillColor(255,255,255);

foreach ($items as $item) {
    // Check Page Break
    if ($pdf->GetY() + 8 > $pdf->GetSafeLimit()) {
        $pdf->AddPage();
        $pdf->SetFont('Arial','B',9);
        $pdf->SetFillColor(220,220,220);
        $pdf->Cell(60,8,'Payment Type',1,0,'L',1);
        $pdf->Cell(80,8,'Identifier (Route/Op Code)',1,0,'L',1);
        $pdf->Cell(50,8,'Amount (LKR)',1,1,'R',1);
        $pdf->SetFont('Arial','',9);
    }

    $pdf->Cell(60,7,$item['category'],1,0,'L');
    $pdf->Cell(80,7,$item['identifier'],1,0,'L'); 
    $pdf->Cell(50,7,number_format($item['amount'], 2),1,1,'R');
}

// Totals Row
$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(230,230,230);
$pdf->Cell(140,8,'GRAND TOTAL',1,0,'R',1); // Spans across first two cols
$pdf->Cell(50,8,number_format($total_payment, 2),1,1,'R',1);

$pdf->Ln(10);

$filename = "Summary_Voucher_" . $supplier_code . "_" . date('M_Y', mktime(0,0,0,$month,1,$year)) . ".pdf";
$pdf->Output('D', $filename);

$conn->close();
?>