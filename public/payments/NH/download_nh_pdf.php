<?php
// download_nh_pdf.php - Generates a simplified PDF summary for one Op Code's Night Heldup payments

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php'); 
require('../fpdf.php'); 

date_default_timezone_set('Asia/Colombo');

// --- 2. Get and Validate Inputs ---
$op_code = $_GET['op_code'] ?? '';
$selected_month = isset($_GET['month_num']) ? (int)$_GET['month_num'] : date('m'); 
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

if (!$op_code) {
    die("Op Code missing.");
}

$monthName = date('F', mktime(0,0,0,$selected_month,10));
$filter_month_year = "{$selected_year}-" . str_pad($selected_month, 2, '0', STR_PAD_LEFT);

// --- 1. Extend FPDF Class ---
class PDF extends FPDF
{
    protected $op_code;
    protected $vehicle_no;
    protected $period;

    function SetReportDetails($op_code, $vehicle_no, $monthName, $year) {
        $this->op_code = $op_code;
        $this->vehicle_no = $vehicle_no;
        $this->period = "$monthName, $year";
    }
    
    public function GetSafeLimit()
    {
        return $this->h - $this->bMargin;
    }

    function RoundedRect($x, $y, $w, $h, $r, $style = '')
    {
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
        // Assuming logo path is same as Day Heldup
        $this->Image('../../assets/logo.png', 12, 5, 25); 

        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 5, 'GP Garments (Pvt) Ltd', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Seethawaka Export Processing Zone', 0, 1, 'C');
        $this->Cell(0, 5, 'Awissawella, Sri Lanka', 0, 1, 'C');
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 5, 'Night Heldup Transport Payment Report', 0, 1, 'C');
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

// --- 3. Data Retrieval & Calculation ---
$total_payment = 0.00;
$total_worked_days = 0;
$total_actual_distance = 0.00; 
$vehicle_no = '';
$supplier_details = null; 

// A. Fetch Service Rates & Supplier Code
// Since NH doesn't rely on 'dh_attendance', we fetch basic rates from op_services
$service_sql = "
    SELECT 
        slab_limit_distance,
        extra_rate AS rate_per_km,
        supplier_code,
        vehicle_no -- Default vehicle
    FROM 
        op_services
    WHERE 
        op_code = ? 
    LIMIT 1
";

$stmt = $conn->prepare($service_sql);
if (!$stmt) die('Service Prepare Failed: ' . $conn->error);

$stmt->bind_param("s", $op_code);
$stmt->execute();
$service_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$service_data) {
    die("Op Code Details not found.");
}

$vehicle_no = $service_data['vehicle_no']; // Initial fallback
$supplier_code = $service_data['supplier_code'];
$slab_limit = (float)$service_data['slab_limit_distance'];
$rate_per_km = (float)$service_data['rate_per_km'];

// B. Fetch Supplier Details
if (!empty($supplier_code)) {
    $supplier_sql = "
        SELECT 
            supplier, supplier_code, email, beneficiaress_name, bank, branch, acc_no
        FROM supplier
        WHERE supplier_code = ?
        LIMIT 1
    ";
    $supp_stmt = $conn->prepare($supplier_sql);
    if ($supp_stmt) {
        $supp_stmt->bind_param("s", $supplier_code);
        $supp_stmt->execute();
        $supplier_details = $supp_stmt->get_result()->fetch_assoc();
        $supp_stmt->close();
    }
}

// C. Perform Payment Calculation (Using Night Shift Logic & Grouping)
$sql = "
    SELECT 
        -- Effective Date Calculation (Shift based)
        IF(nh.time < '07:00:00', DATE_SUB(nh.date, INTERVAL 1 DAY), nh.date) as effective_date,
        MAX(nh.vehicle_no) as daily_vehicle, 
        SUM(nh.distance) AS total_daily_distance
    FROM 
        nh_register nh
    WHERE 
        nh.op_code = ? 
        AND nh.done = 1 
    GROUP BY 
        effective_date
    HAVING 
        DATE_FORMAT(effective_date, '%Y-%m') = ?
    ORDER BY 
        effective_date ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $op_code, $filter_month_year);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $daily_actual_distance = (float)$row['total_daily_distance'];
    $vehicle_no = $row['daily_vehicle']; // Update to actual used vehicle
    
    $payable_distance = 0;

    // Logic: NH vs EV
    if (strpos($op_code, 'NH') === 0) {
        // NH: Apply Slab Limit
        $payable_distance = max($daily_actual_distance, $slab_limit);
    } elseif (strpos($op_code, 'EV') === 0) {
        // EV: Always Actual
        $payable_distance = $daily_actual_distance;
    } else {
        $payable_distance = $daily_actual_distance;
    }

    $daily_payment = $payable_distance * $rate_per_km;

    // Aggregate Totals
    $total_payment += $daily_payment;
    $total_worked_days++;
    $total_actual_distance += $daily_actual_distance;
}
$stmt->close();

// --- PDF GENERATION ---
$pdf = new PDF();
$pdf->AliasNbPages();

// Set dynamic data
$pdf->SetReportDetails($op_code, $vehicle_no, $monthName, $selected_year);

$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// --- Subtitle ---
$pdf->Cell(0,10,"Summary for {$monthName}, {$selected_year}",0,1,'C');
$pdf->Cell(0,5,"Op Code: ".$op_code. " (Vehicle: " . $vehicle_no . ")",0,1,'C');
$pdf->Ln(8);

// --- Supplier Details ---
if ($supplier_details) {
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,7,'Supplier Details',0,1,'L');
    $pdf->SetFont('Arial','',10);

    $fields = [
        'Supplier'=>'supplier',
        'Supplier Code'=>'supplier_code',
        'Email'=>'email',
        'Beneficiary'=>'beneficiaress_name',
        'Bank'=>'bank',
        'Branch'=>'branch',
        'Account No'=>'acc_no'
    ];
    
    // Check page break
    $details_height = count($fields) * 7 + 10;
    if ($pdf->GetY() + $details_height > $pdf->GetSafeLimit()) {
        $pdf->AddPage();
    }

    foreach($fields as $label=>$key){
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(40,7,$label.':',0,0);
        $pdf->SetFont('Arial','',10);
        $pdf->Cell(0,7,$supplier_details[$key],0,1);
    }

    $pdf->Ln(5);
}

// Payment Summary Table
$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(220,220,220);
$pdf->Cell(100,7,'Description',1,0,'L',1);
$pdf->Cell(80,7,'Amount',1,1,'R',1);

$pdf->SetFont('Arial','',10);
$pdf->SetFillColor(255,255,255);

// 1. Total Days Paid
$pdf->Cell(100,7,'Total Days Paid ',1,0,'L',1);
$pdf->Cell(80,7,number_format($total_worked_days),1,1,'R',1);

// 2. Total Actual Distance
$pdf->Cell(100,7,'Total Distance Traveled (km)',1,0,'L',1);
$pdf->Cell(80,7,number_format($total_actual_distance, 2),1,1,'R',1);

// Final Total Payment
$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(220,220,220);
$pdf->Cell(100,7,'TOTAL PAYMENT (LKR)',1,0,'L',1);
$pdf->Cell(80,7,number_format($total_payment,2),1,1,'R',1);

$pdf->Ln(10);

// Output PDF
$filename = "nh_report_".$op_code."_".date('Y-m', mktime(0,0,0,$selected_month,1,$selected_year)).".pdf";
$pdf->Output('D',$filename);

$conn->close();
?>