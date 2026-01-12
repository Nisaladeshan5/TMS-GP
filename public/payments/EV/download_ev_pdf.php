<?php
// download_ev_pdf.php - Generates PDF summary for Extra Vehicle Payments

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php'); 
require('../fpdf.php'); // Ensure fpdf.php is in the correct path relative to this file

date_default_timezone_set('Asia/Colombo');

// --- 1. Get Inputs ---
$identifier = $_GET['id'] ?? ''; // This is either Op Code or Route Code
$type = $_GET['type'] ?? '';     // 'Operation' or 'Route'
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m'); 
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

if (!$identifier || !$type) {
    die("Missing Identifier or Type.");
}

$monthName = date('F', mktime(0,0,0,$selected_month,10));
$filterMonthNum = str_pad($selected_month, 2, '0', STR_PAD_LEFT);

// --- 2. Extend FPDF Class (Same Style as Reference) ---
class PDF extends FPDF
{
    protected $identifier;
    protected $type;
    protected $period;

    function SetReportDetails($identifier, $type, $monthName, $year) {
        $this->identifier = $identifier;
        $this->type = $type;
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
        // Adjust path to logo if needed
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
        $this->Cell(0, 5, 'Extra Vehicle Payment Report', 0, 1, 'C');
    }

    function Footer() {
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

// --- 3. PRE-FETCH DATA (Same Logic as ev_payments.php) ---

// A. Fuel Rate History
$fuel_history = [];
$fuel_res = $conn->query("SELECT rate_id, rate, date FROM fuel_rate ORDER BY date DESC");
if ($fuel_res) {
    while ($row = $fuel_res->fetch_assoc()) {
        $fuel_history[$row['rate_id']][] = ['date' => $row['date'], 'rate' => (float)$row['rate']];
    }
}

function get_rate_for_date($rate_id, $trip_date, $history) {
    if (!isset($history[$rate_id])) return 0;
    foreach ($history[$rate_id] as $record) {
        if ($record['date'] <= $trip_date) return $record['rate'];
    }
    $last = end($history[$rate_id]);
    return $last ? $last['rate'] : 0;
}

// B. Op Rates
$op_rates = [];
$op_res = $conn->query("SELECT op_code, extra_rate_ac, extra_rate FROM op_services");
if ($op_res) {
    while ($row = $op_res->fetch_assoc()) {
        $op_rates[$row['op_code']] = ['ac' => (float)$row['extra_rate_ac'], 'non_ac' => (float)$row['extra_rate']];
    }
}

// C. Vehicle Specs
$vehicle_specs = [];
$veh_res = $conn->query("SELECT v.vehicle_no, v.rate_id, c.distance AS km_per_liter FROM vehicle v LEFT JOIN consumption c ON v.fuel_efficiency = c.c_id");
if ($veh_res) {
    while ($row = $veh_res->fetch_assoc()) {
        $vehicle_specs[$row['vehicle_no']] = ['rate_id' => $row['rate_id'], 'km_per_liter' => (float)$row['km_per_liter']];
    }
}

// D. Route Data
$route_data = [];
$rt_res = $conn->query("SELECT route_code, fixed_amount, vehicle_no, with_fuel FROM route");
if ($rt_res) {
    while ($row = $rt_res->fetch_assoc()) {
        $route_data[$row['route_code']] = ['fixed_amount' => (float)$row['fixed_amount'], 'assigned_vehicle' => $row['vehicle_no'], 'with_fuel' => (int)$row['with_fuel']];
    }
}

// --- 4. FETCH TRIPS ---
$sql = "
    SELECT evr.*, s.supplier, s.supplier_code, s.email, s.beneficiaress_name, s.bank, s.branch, s.acc_no
    FROM extra_vehicle_register evr
    JOIN supplier s ON evr.supplier_code = s.supplier_code
    WHERE MONTH(evr.date) = ? AND YEAR(evr.date) = ? AND evr.done = 1
";

// Filter by ID based on type
if ($type === 'Route') {
    $sql .= " AND evr.route = ?";
} else {
    $sql .= " AND evr.op_code = ?";
}
$sql .= " ORDER BY evr.date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $filterMonthNum, $selected_year, $identifier);
$stmt->execute();
$result = $stmt->get_result();

$trips = [];
$supplier_details = null;
$total_payment = 0;
$total_distance = 0;

while ($row = $result->fetch_assoc()) {
    if (!$supplier_details) {
        $supplier_details = [
            'supplier' => $row['supplier'], 'supplier_code' => $row['supplier_code'],
            'email' => $row['email'], 'beneficiary' => $row['beneficiaress_name'],
            'bank' => $row['bank'], 'branch' => $row['branch'], 'acc_no' => $row['acc_no']
        ];
    }

    $distance = (float)$row['distance'];
    $trip_date = $row['date'];
    $pay_amount = 0;
    $rate_applied = 0;

    // Calc Logic (Mirrors ev_payments.php)
    if (!empty($row['op_code'])) {
        if (isset($op_rates[$row['op_code']])) {
            $rate_applied = ($row['ac_status'] == 1) ? $op_rates[$row['op_code']]['ac'] : $op_rates[$row['op_code']]['non_ac'];
            $pay_amount = $distance * $rate_applied;
        }
    } elseif (!empty($row['route'])) {
        if (isset($route_data[$row['route']])) {
            $fixed = $route_data[$row['route']]['fixed_amount'];
            $assigned_veh = $route_data[$row['route']]['assigned_vehicle'];
            $with_fuel = $route_data[$row['route']]['with_fuel'];
            $fuel_cost = 0;

            if ($with_fuel == 1 && !empty($assigned_veh) && isset($vehicle_specs[$assigned_veh])) {
                $v_spec = $vehicle_specs[$assigned_veh];
                $km_l = $v_spec['km_per_liter'];
                $fuel_rate = get_rate_for_date($v_spec['rate_id'], $trip_date, $fuel_history);
                if ($km_l > 0) $fuel_cost = $fuel_rate / $km_l;
            }
            $rate_applied = $fixed + $fuel_cost;
            $pay_amount = $distance * $rate_applied;
        }
    }

    $row['pay_amount'] = $pay_amount;
    $row['rate_applied'] = $rate_applied;
    $trips[] = $row;
    $total_payment += $pay_amount;
    $total_distance += $distance;
}
$stmt->close();

if (empty($trips)) {
    die("No records found for ID: {$identifier} in {$monthName} {$selected_year}.");
}

// --- 5. GENERATE PDF ---
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->SetReportDetails($identifier, $type, $monthName, $selected_year);

$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// Subtitle
$title_text = ($type === 'Route') ? "Route Code: " . $identifier : "Op Code: " . $identifier;
$pdf->Cell(0,10,"Summary for {$monthName}, {$selected_year}",0,1,'C');
$pdf->Cell(0,5,$title_text,0,1,'C');
$pdf->Ln(8);

// Supplier Details
if ($supplier_details) {
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,7,'Supplier Details',0,1,'L');
    $pdf->SetFont('Arial','',10);

    $fields = [
        'Supplier' => 'supplier', 'Supplier Code' => 'supplier_code',
        'Email' => 'email', 'Beneficiary' => 'beneficiary',
        'Bank' => 'bank', 'Branch' => 'branch', 'Account No' => 'acc_no'
    ];
    
    foreach($fields as $label => $key){
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(40,6,$label.':',0,0);
        $pdf->SetFont('Arial','',10);
        $pdf->Cell(0,6,$supplier_details[$key],0,1);
    }
    $pdf->Ln(5);
}

// Table Header
$pdf->SetFont('Arial','B',9);
$pdf->SetFillColor(220,220,220);
$pdf->Cell(25,8,'Date',1,0,'C',1);
$pdf->Cell(65,8,'From / To',1,0,'L',1);
$pdf->Cell(25,8,'Vehicle',1,0,'C',1);
$pdf->Cell(20,8,'Dist(Km)',1,0,'C',1);
$pdf->Cell(25,8,'Rate',1,0,'R',1);
$pdf->Cell(30,8,'Amount',1,1,'R',1);

$pdf->SetFont('Arial','',9);
$pdf->SetFillColor(255,255,255);

foreach ($trips as $trip) {
    // Check Page Break
    if ($pdf->GetY() + 8 > $pdf->GetSafeLimit()) {
        $pdf->AddPage();
        // Reprint Header
        $pdf->SetFont('Arial','B',9);
        $pdf->SetFillColor(220,220,220);
        $pdf->Cell(25,8,'Date',1,0,'C',1);
        $pdf->Cell(65,8,'From / To',1,0,'L',1);
        $pdf->Cell(25,8,'Vehicle',1,0,'C',1);
        $pdf->Cell(20,8,'Dist(Km)',1,0,'C',1);
        $pdf->Cell(25,8,'Rate',1,0,'R',1);
        $pdf->Cell(30,8,'Amount',1,1,'R',1);
        $pdf->SetFont('Arial','',9);
    }

    $pdf->Cell(25,7,$trip['date'],1,0,'C');
    // Truncate location to fit
    $loc = substr($trip['from_location'] . ' > ' . $trip['to_location'], 0, 35);
    $pdf->Cell(65,7,$loc,1,0,'L');
    $pdf->Cell(25,7,$trip['vehicle_no'],1,0,'C');
    $pdf->Cell(20,7,number_format($trip['distance'], 2),1,0,'C');
    $pdf->Cell(25,7,number_format($trip['rate_applied'], 2),1,0,'R');
    $pdf->Cell(30,7,number_format($trip['pay_amount'], 2),1,1,'R');
}

// Totals Row
$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(230,230,230);
$pdf->Cell(115,8,'TOTALS',1,0,'R',1);
$pdf->Cell(20,8,number_format($total_distance, 2),1,0,'C',1);
$pdf->Cell(25,8,'',1,0,'C',1); // Empty Rate Col
$pdf->Cell(30,8,number_format($total_payment, 2),1,1,'R',1);

$pdf->Ln(10);

$filename = "EV_Report_" . $identifier . "_" . date('Y-m', mktime(0,0,0,$selected_month,1,$selected_year)) . ".pdf";
$pdf->Output('D', $filename);

$conn->close();
?>