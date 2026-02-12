<?php
// download_sub_route_pdf.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Session Check
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

include('../../../../includes/db.php');
require('../../fpdf.php'); 

// --- FUEL CALCULATION LOGIC ---
function get_latest_fuel_price_by_rate_id($conn, $rate_id) {
    $sql = "SELECT rate FROM fuel_rate WHERE rate_id = ? ORDER BY date DESC, id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0; 
    $stmt->bind_param("i", $rate_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['rate'] ?? 0;
}

function calculate_fuel_cost_per_km($conn, $vehicle_no) {
    if (empty($vehicle_no)) return 0;
    
    $consumption_rates = [];
    $res_c = $conn->query("SELECT c_id, distance FROM consumption");
    if ($res_c) while ($r = $res_c->fetch_assoc()) $consumption_rates[$r['c_id']] = $r['distance'];

    $stmt = $conn->prepare("SELECT fuel_efficiency, rate_id FROM vehicle WHERE vehicle_no = ?");
    $stmt->bind_param("s", $vehicle_no);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$v) return 0;
    $price = get_latest_fuel_price_by_rate_id($conn, $v['rate_id']);
    $km_per_l = $consumption_rates[$v['fuel_efficiency']] ?? 1.0;
    return ($price > 0) ? ($price / $km_per_l) : 0;
}

// ---------------------------------------------------------
// EXTENDED PDF CLASS
// ---------------------------------------------------------
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
        $this->RoundedRect(7.5, 7.5, 195, 40, 1.5);
        $this->Rect(5, 5, 200, 287); 
        
        if(file_exists('../../../assets/logo.png')) {
             $this->Image('../../../assets/logo.png', 12, 5, 25); 
        }

        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 5, 'GP Garments (Pvt) Ltd', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Seethawaka Export Processing Zone', 0, 1, 'C');
        $this->Cell(0, 5, 'Awissawella, Sri Lanka', 0, 1, 'C');
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 5, 'Sub Route Monthly Payment Voucher', 0, 1, 'C');
    }

    function Footer()
    {
        $this->SetY(-35);
        $this->SetFont('Arial', '', 10);
        
        $this->SetX(20);
        $this->Cell(50, 5, '________________________', 0, 0, 'C');
        
        $this->SetX(140);
        $this->Cell(50, 5, '________________________', 0, 1, 'C');
        
        $this->SetX(20);
        $this->Cell(50, 5, 'Prepared By', 0, 0, 'C');
        
        $this->SetX(140);
        $this->Cell(50, 5, 'Checked and Approved', 0, 1, 'C');

        $this->SetFont('Arial', '', 8);
        $this->SetY(-15);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetX(10);
        $this->Cell(0, 10, 'Print Date: ' . date('Y-m-d H:i'), 0, 0, 'R');
    }
}

// ---------------------------------------------------------
// DATA FETCHING LOGIC
// ---------------------------------------------------------

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$sub_route_code = isset($_GET['sub_route_code']) ? $_GET['sub_route_code'] : '';

if (!$sub_route_code) {
    die("Error: Sub Route Code missing.");
}

// 1. Get Sub Route Info (Added fixed_rate, with_fuel, distance)
$sql_route = "
    SELECT 
        sr.sub_route AS sub_route_name,
        sr.vehicle_no,
        sr.fixed_rate,
        sr.with_fuel,
        sr.distance,
        sr.route_code AS parent_route,
        sr.supplier_code
    FROM sub_route sr
    WHERE sr.sub_route_code = ?
";
$stmt = $conn->prepare($sql_route);
$stmt->bind_param("s", $sub_route_code);
$stmt->execute();
$res_route = $stmt->get_result();

if ($res_route->num_rows == 0) die("Sub Route not found.");
$route_data = $res_route->fetch_assoc();

$sub_route_name = $route_data['sub_route_name'];
$vehicle_no = $route_data['vehicle_no'];
$fixed_rate = (float)$route_data['fixed_rate'];
$distance = (float)$route_data['distance'];
$parent_route = $route_data['parent_route'];
$supplier_code = $route_data['supplier_code'];

// 2. Calculate Fuel Rate
$fuel_rate = 0;
if ((int)$route_data['with_fuel'] === 1) {
    $fuel_rate = calculate_fuel_cost_per_km($conn, $vehicle_no);
}

// DAILY RATE = (Fixed + Fuel) * Distance
$final_daily_rate = ($fixed_rate + $fuel_rate) * $distance;

// 3. Get Supplier Details
$sup_data = [];
if (!empty($supplier_code)) {
    $sql_sup = "SELECT supplier, email, beneficiaress_name, bank, branch, acc_no FROM supplier WHERE supplier_code = ?";
    $stmt_sup = $conn->prepare($sql_sup);
    $stmt_sup->bind_param("s", $supplier_code);
    $stmt_sup->execute();
    $res_sup = $stmt_sup->get_result();
    if ($res_sup->num_rows > 0) {
        $sup_data = $res_sup->fetch_assoc();
    }
}

// 4. Get Attendance (From Parent Route)
$sql_att = "
    SELECT COUNT(DISTINCT date) as days_run 
    FROM factory_transport_vehicle_register 
    WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1
";
$stmt_att = $conn->prepare($sql_att);
$stmt_att->bind_param("sii", $parent_route, $selected_month, $selected_year);
$stmt_att->execute();
$res_att = $stmt_att->get_result();
$att_data = $res_att->fetch_assoc();
$base_days = (int)($att_data['days_run'] ?? 0);

// 5. Get Adjustments
$sql_adj = "
    SELECT SUM(adjustment_days) as total_adj 
    FROM sub_route_adjustments 
    WHERE sub_route_code = ? AND month = ? AND year = ?
";
$stmt_adj = $conn->prepare($sql_adj);
$stmt_adj->bind_param("sii", $sub_route_code, $selected_month, $selected_year);
$stmt_adj->execute();
$res_adj = $stmt_adj->get_result();
$adj_data = $res_adj->fetch_assoc();
$adjustments = (int)($adj_data['total_adj'] ?? 0);

// 6. Calculations
$final_days = $base_days + $adjustments;
if ($final_days < 0) $final_days = 0;
$total_payment = $final_days * $final_daily_rate;

// ---------------------------------------------------------
// PDF GENERATION
// ---------------------------------------------------------

$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// Subtitle
$month_name = date('F', mktime(0,0,0,$selected_month,10));
$pdf->Cell(0,10,"For $month_name, $selected_year",0,1,'C');
$pdf->Ln(7);

// SUPPLIER DETAILS SECTION
if (!empty($sup_data)) {
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,7,'Supplier Details',0,1,'L');
    $pdf->SetFont('Arial','',10);

    $sup_fields = [
        'Supplier Name' => 'supplier',
        'Supplier Code' => $supplier_code,
        'Email' => 'email',
        'Beneficiary Name' => 'beneficiaress_name',
        'Bank' => 'bank',
        'Branch' => 'branch',
        'Account No' => 'acc_no'
    ];

    foreach($sup_fields as $label => $key){
        $value = ($label === 'Supplier Code') ? $key : ($sup_data[$key] ?? '-');
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(40, 6, $label.':', 0, 0);
        $pdf->SetFont('Arial','',10);
        $pdf->Cell(0, 6, $value, 0, 1);
    }
    $pdf->Ln(5);
}

// ROUTE DETAILS SECTION
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0,7,'Route Details',0,1,'L');
$pdf->SetFont('Arial','',10);

$route_details = [
    'Sub Route Name' => $sub_route_name,
    'Sub Route Code' => $sub_route_code,
    'Parent Route' => $parent_route,
    'Vehicle No' => $vehicle_no,
    'Daily Rate (LKR)' => number_format($final_daily_rate, 2)
];

foreach($route_details as $label => $value){
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(40, 6, $label.':', 0, 0);
    $pdf->SetFont('Arial','',10);
    $pdf->Cell(0, 6, $value, 0, 1);
}

$pdf->Ln(8);

// CALCULATION TABLE
$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(220,220,220); // Header Background

$pdf->Cell(110, 8, 'Description', 1, 0, 'L', 1);
$pdf->Cell(30, 8, 'Count', 1, 0, 'C', 1);
$pdf->Cell(50, 8, 'Amount (LKR)', 1, 1, 'R', 1);

$pdf->SetFont('Arial','',10);
$pdf->SetFillColor(255,255,255);

// Row 1: Base Attendance
$pdf->Cell(110, 7, 'Base Working Days (Attendance)', 1, 0, 'L');
$pdf->Cell(30, 7, $base_days, 1, 0, 'C');
$pdf->Cell(50, 7, number_format($base_days * $final_daily_rate, 2), 1, 1, 'R');

// Row 2: Adjustments
if($adjustments != 0) {
    $adj_sign = ($adjustments > 0) ? '+' : '';
    $pdf->Cell(110, 7, 'Manual Adjustments', 1, 0, 'L');
    $pdf->Cell(30, 7, $adj_sign . $adjustments, 1, 0, 'C');
    $pdf->Cell(50, 7, number_format($adjustments * $final_daily_rate, 2), 1, 1, 'R');
}

// Row 3: Total
$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(240,240,240); // Total Row Background

$pdf->Cell(110, 8, 'NET TOTAL PAYABLE', 1, 0, 'R', 1);
$pdf->Cell(30, 8, $final_days . ' Days', 1, 0, 'C', 1);
$pdf->Cell(50, 8, number_format($total_payment, 2), 1, 1, 'R', 1);

$pdf->Ln(10);

// Output
$filename = "SubRoute_Payment_".$sub_route_code."_".$month_name.$selected_year.".pdf";
$pdf->Output('D', $filename);

$conn->close();
?>