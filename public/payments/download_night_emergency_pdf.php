<?php
include('../../includes/db.php');
require('fpdf.php');

// Extend FPDF class with rounded rectangles
class PDF extends FPDF
{
    function RoundedRect($x, $y, $w, $h, $r, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        if($style=='F') $op='f';
        elseif($style=='FD' || $style=='DF') $op='B';
        else $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x+$r)*$k, ($hp-$y)*$k ));

        $xc = $x+$w-$r ;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k, ($hp-$y)*$k ));
        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);

        $xc = $x+$w-$r ;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l', ($x+$w)*$k, ($hp-$yc)*$k));
        $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);

        $xc = $x+$r ;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k, ($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);

        $xc = $x+$r ;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $x*$k, ($hp-$yc)*$k ));
        $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);

        $this->_out($op);
    }

    function _Arc($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1*$this->k, ($h-$y1)*$this->k,
            $x2*$this->k, ($h-$y2)*$this->k,
            $x3*$this->k, ($h-$y3)*$this->k));
    }

    function Header()
    {
        $this->RoundedRect(7.5, 7.5, 195, 47, 1.5);
        $this->Rect(5, 5, 200, 287);
        $this->Image('../assets/logo.png', 12, 5, 25); // logo

        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 5, 'GP Garments (Pvt) Ltd', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Seethawaka Export Processing Zone', 0, 1, 'C');
        $this->Cell(0, 5, 'Awissawella, Sri Lanka', 0, 1, 'C');
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 5, 'Night Emergency Transport Payment Report', 0, 1, 'C');
    }

    function Footer()
    {
        $this->SetY(-30);
        $this->SetFont('Arial', '', 10);
        $this->SetX(150);
        $this->Cell(60, 5, '________________________', 0, 1, 'L');
        $this->SetX(155);
        $this->Cell(60, 5, 'Checked and Approved', 0, 1, 'L');

        $this->SetFont('Arial', '', 8);
        $this->SetY(-15);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetX(10);
        $this->Cell(0, 10, 'Print Date: ' . date('Y-m-d H:i'), 0, 0, 'R');
    }
}

// Get GET parameters
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$supplier_code = isset($_GET['supplier_code']) ? htmlspecialchars($_GET['supplier_code']) : '';

if (!$supplier_code) {
    die("Supplier code missing.");
}

// Fetch supplier + total_payment + total_worked_days
$sql = "
    SELECT 
        s.supplier,
        s.supplier_code,
        s.email,
        s.beneficiaress_name,
        s.bank,
        s.branch,
        s.acc_no,
        mpn.monthly_payment AS total_payment,
        (SELECT COUNT(*) 
         FROM night_emergency_attendance AS nea
         WHERE nea.supplier_code = s.supplier_code
           AND MONTH(nea.date)=?
           AND YEAR(nea.date)=?) AS total_worked_days
    FROM supplier AS s
    JOIN monthly_payment_ne AS mpn ON mpn.supplier_code = s.supplier_code
    WHERE s.supplier_code=? AND mpn.month=? AND mpn.year=?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iisii", $selected_month, $selected_year, $supplier_code, $selected_month, $selected_year);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) die("No data found.");
$data = $result->fetch_assoc();
$stmt->close();

// Prepare PDF
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// Subtitle
$month_name = date('F', mktime(0,0,0,$selected_month,10));
$pdf->Cell(0,10,"For $month_name, $selected_year",0,1,'C');
$pdf->Cell(0,5,"Supplier Code: ".$supplier_code,0,1,'C');
$pdf->Ln(8);

// Supplier Details
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0,7,'Supplier Details',0,1,'L');
$pdf->SetFont('Arial','',10);

$fields = [
    'Supplier'=>'supplier',
    'Email'=>'email',
    'Beneficiary'=>'beneficiaress_name',
    'Bank'=>'bank',
    'Branch'=>'branch',
    'Account No'=>'acc_no'
];

foreach($fields as $label=>$key){
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(30,7,$label.':',0,0);
    $pdf->SetFont('Arial','',10);
    $pdf->Cell(0,7,$data[$key],0,1);
}

$pdf->Ln(5);

// Payment Summary Table
$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(220,220,220);
$pdf->Cell(100,7,'Description',1,0,'L',1);
$pdf->Cell(80,7,'Amount',1,1,'R',1);

$pdf->SetFont('Arial','',10);
$pdf->SetFillColor(255,255,255);

$pdf->Cell(100,7,'Worked Days',1,0,'L',1);
$pdf->Cell(80,7,$data['total_worked_days'],1,1,'R',1);

$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(220,220,220);
$pdf->Cell(100,7,'Total Payment (LKR)',1,0,'L',1);
$pdf->Cell(80,7,number_format($data['total_payment'],2),1,1,'R',1);

$pdf->Ln(10);

// Output PDF
$filename = "night_emergency_report_".$supplier_code."_".date('Y-m', mktime(0,0,0,$selected_month,1,$selected_year)).".pdf";
$pdf->Output('D',$filename);

$conn->close();
?>
