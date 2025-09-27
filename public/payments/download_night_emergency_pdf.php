<?php
// Include the database connection file
include('../../includes/db.php');

// Include the FPDF library. You must have this file in your project.
require('fpdf.php');

// Extend the FPDF class to create custom Header and Footer methods
class PDF extends FPDF
{
    function RoundedRect($x, $y, $w, $h, $r, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        if($style=='F')
            $op='f';
        elseif($style=='FD' || $style=='DF')
            $op='B';
        else
            $op='S';
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
        // Logo (adjust path if needed)
        $this->Image('../assets/logo.png', 12, 5, 25); // left logo

        // Company details
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 5, 'GP Garments (Pvt) Ltd', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Seethawaka Export Processing Zone', 0, 1, 'C');
        $this->Cell(0, 5, 'Awissawella, Sri Lanka', 0, 1, 'C');
        $this->Ln(5);

        // Title
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 5, 'Night Emergency Transport Payment Report', 0, 1, 'C');
    }
    
    // Add the Footer method
    function Footer()
    {
        // Set position at 15 mm from bottom
        $this->SetY(-30);
        
        // Add signature line and text
        $this->SetFont('Arial', '', 10);
        $this->SetX(150);
        $this->Cell(60, 5, '________________________', 0, 1, 'L');
        
        $this->SetX(155);
        $this->Cell(60, 5, 'Checked and Approved', 0, 1, 'L');

        // Add page number and date
        $this->SetFont('Arial', '', 8);
        $this->SetY(-15);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetX(10);
        $this->Cell(0, 10, 'Print Date: ' . date('Y-m-d H:i'), 0, 0, 'R');
    }
}

// Get and sanitize the parameters from the URL
$selected_month = isset($_GET['month']) ? htmlspecialchars($_GET['month']) : date('m');
$selected_year = isset($_GET['year']) ? htmlspecialchars($_GET['year']) : date('Y');
$supplier_code = isset($_GET['supplier_code']) ? htmlspecialchars($_GET['supplier_code']) : '';
$day_rate = isset($_GET['day_rate']) ? htmlspecialchars($_GET['day_rate']) : 0;
$worked_days = isset($_GET['worked_days']) ? (int) $_GET['worked_days'] : 0;

// Create a new PDF instance using your custom class
$pdf = new PDF();
$pdf->AliasNbPages(); // Required for {nb} alias
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);

// --- Fetch main data for Night Emergency payments for the specific supplier ---
$data_sql = "
    SELECT 
        s.supplier_code, 
        s.supplier, 
        s.email, 
        s.beneficiaress_name, 
        s.bank, 
        s.bank_code, 
        s.branch, 
        s.branch_code, 
        s.acc_no
    FROM supplier AS s
    WHERE s.supplier_code = ? 
    LIMIT 1
";
$data_stmt = $conn->prepare($data_sql);
$data_stmt->bind_param("s", $supplier_code);
$data_stmt->execute();
$data_result = $data_stmt->get_result();

$supplier_data = [];

if ($data_result && $data_result->num_rows > 0) {
    $supplier_data = $data_result->fetch_assoc();
}
$data_stmt->close();

// The calculated amount should use the passed values
$calculated_amount = $day_rate * $worked_days;

// --- PDF Content Generation ---
$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));

$pdf->SetFont('Arial', '', 12);

// Subtitle for the report body
$pdf->Cell(0, 10, "For $month_name, $selected_year", 0, 1, 'C');
$pdf->Cell(0, 5, "Supplier Code: " . $supplier_code, 0, 1, 'C');
$pdf->Ln(8);

// Supplier Details Section - Corrected to match your desired format
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 7, 'Supplier Details', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
if (!empty($supplier_data)) {
    // Supplier Code
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 7, 'Supplier Code:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, $supplier_data['supplier_code'], 0, 1);

    // Supplier
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 7, 'Supplier:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, $supplier_data['supplier'], 0, 1);

    // Email
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 7, 'Email:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, $supplier_data['email'], 0, 1);

    // Beneficiary
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 7, 'Beneficiary:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, $supplier_data['beneficiaress_name'], 0, 1);

    // Bank
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 7, 'Bank:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, $supplier_data['bank'], 0, 1);

    // Branch
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 7, 'Branch:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, $supplier_data['branch'], 0, 1);

    // Account No
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 7, 'Account No:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, $supplier_data['acc_no'], 0, 1);
} else {
    $pdf->Cell(0, 7, 'No supplier details found for this supplier code.', 0, 1);
}
$pdf->Ln(5);

// Main Payments Summary Table
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(100, 7, 'Description', 1, 0, 'L', 1);
$pdf->Cell(80, 7, 'Amount', 1, 1, 'R', 1);

$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(255, 255, 255);
$pdf->Cell(100, 7, 'Payment For a Day (LKR)', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($day_rate, 2), 1, 1, 'R', 1);

$pdf->Cell(100, 7, 'Worked Days', 1, 0, 'L', 1);
$pdf->Cell(80, 7, $worked_days, 1, 1, 'R', 1);

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(100, 7, 'Total Payment (LKR)', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($calculated_amount, 2), 1, 1, 'R', 1);
$pdf->Ln(10);

// Output the PDF for download
$filename = "night_emergency_report_" . $supplier_code . "_" . date('Y-m', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . ".pdf";
$pdf->Output('D', $filename);

// Close the database connection
$conn->close();
?>