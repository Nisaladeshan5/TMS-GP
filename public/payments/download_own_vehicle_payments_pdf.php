<?php
// Note: This file MUST NOT have any whitespace/characters before the opening <?php tag.

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
require('fpdf.php'); 
date_default_timezone_set('Asia/Colombo');

// --- Input Validation ---
$emp_id = $_GET['emp_id'] ?? die("Employee ID missing.");
$vehicle_no = $_GET['vehicle_no'] ?? die("Vehicle No missing.");
$selected_month = (int)($_GET['month'] ?? die("Month missing."));
$selected_year = (int)($_GET['year'] ?? die("Year missing."));

if ($selected_month < 1 || $selected_month > 12 || $selected_year < 2020) {
    die("Invalid date parameters.");
}

// --- FPDF Class Extension ---
class PDF extends FPDF
{
    function RoundedRect($x, $y, $w, $h, $r, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
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
        $this->RoundedRect(7.5, 7.5, 195, 40, 1.5);
        $this->Rect(5, 5, 200, 287);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 5, 'GP Garments (Pvt) Ltd', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Seethawaka Export Processing Zone', 0, 1, 'C');
        $this->Cell(0, 5, 'Awissawella, Sri Lanka', 0, 1, 'C');
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 5, 'Managers Vehicle Payment Report', 0, 1, 'C');
    }
    
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page '.$this->PageNo().'/{nb}', 0, 0, 'C');
    }
}

// --- Database Functions ---
function get_applicable_fuel_price($conn, $rate_id, $datetime) {
    $sql = "SELECT rate FROM fuel_rate WHERE rate_id = ? AND date <= ? ORDER BY date DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return 0;
    $stmt->bind_param("ss", $rate_id, $datetime);
    $stmt->execute();
    $result = $stmt->get_result();
    $price = $result->fetch_assoc()['rate'] ?? 0;
    $stmt->close();
    return (float)$price;
}

function get_detailed_attendance_records($conn, $emp_id, $vehicle_no, $month, $year) {
    $sql = "SELECT date, time FROM own_vehicle_attendance 
            WHERE emp_id = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ? 
            ORDER BY date ASC, time ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $emp_id, $vehicle_no, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = [];
    while ($row = $result->fetch_assoc()) { $records[] = $row; }
    $stmt->close();
    return $records;
}

function get_detailed_extra_records($conn, $emp_id, $vehicle_no, $month, $year) {
    $sql = "SELECT date, out_time, in_time, distance FROM own_vehicle_extra 
            WHERE emp_id = ? AND vehicle_no = ? AND MONTH(date) = ? AND YEAR(date) = ? AND done = 1 AND distance IS NOT NULL 
            ORDER BY date ASC, out_time ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $emp_id, $vehicle_no, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = [];
    while ($row = $result->fetch_assoc()) { $records[] = $row; }
    $stmt->close();
    return $records;
}

$pdf = new PDF();
$pdf->AliasNbPages(); 
$pdf->AddPage();

// --- 1. Fetch Employee and Vehicle Base Details ---
$base_details_sql = "
    SELECT 
        e.calling_name,
        ov.vehicle_no,
        ov.fuel_efficiency AS consumption, 
        ov.fixed_amount,
        ov.paid,
        ov.distance, 
        ov.rate_id
    FROM 
        own_vehicle ov
    JOIN 
        employee e ON ov.emp_id = e.emp_id
    WHERE e.emp_id = ? AND ov.vehicle_no = ?
";
$stmt = $conn->prepare($base_details_sql);
$stmt->bind_param("ss", $emp_id, $vehicle_no);
$stmt->execute();
$base_details = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$base_details) {
    die("Details not found.");
}

$rate_id = $base_details['rate_id'];
$is_paid_status = (int)$base_details['paid'];
$consumption = (float)$base_details['consumption'];
$daily_distance = (float)$base_details['distance'];
$fixed_amount_orig = (float)$base_details['fixed_amount'];

// පේමන්ට් එක පෙන්වන්නේ paid = 1 නම් පමණි
$fixed_amount_display = ($is_paid_status === 1) ? $fixed_amount_orig : 0.00;

// --- 2. Fetch Detailed Records ---
$attendance_records = get_detailed_attendance_records($conn, $emp_id, $vehicle_no, $selected_month, $selected_year);
$extra_records = get_detailed_extra_records($conn, $emp_id, $vehicle_no, $selected_month, $selected_year);

// --- 3. Run Calculations ---
$total_attendance_days = 0;
$total_calculated_distance = 0.00;
$total_extra_distance = 0.00;
$total_base_payment = 0.00;
$total_extra_payment = 0.00;

$extra_breakdown = [];

// A. Attendance Component
foreach ($attendance_records as $record) {
    $total_attendance_days++;
    $total_calculated_distance += $daily_distance;

    $datetime = $record['date'] . ' ' . $record['time'];
    $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
    $day_rate = 0.00;
    
    if ($is_paid_status === 1 && $fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
        $day_rate = ($consumption / 100) * $daily_distance * $fuel_price;
    }

    $total_base_payment += $day_rate;
}

// B. Extra Component
foreach ($extra_records as $record) {
    $extra_dist = (float)$record['distance'];
    $total_calculated_distance += $extra_dist;
    $total_extra_distance += $extra_dist;

    $datetime = $record['date'] . ' ' . $record['out_time'];
    $fuel_price = get_applicable_fuel_price($conn, $rate_id, $datetime);
    $extra_pay = 0.00;
    
    if ($is_paid_status === 1 && $fuel_price > 0 && $consumption > 0 && $daily_distance > 0) {
        $day_rate_base = ($consumption / 100) * $daily_distance * $fuel_price;
        $rate_per_km = $day_rate_base / $daily_distance; 
        $extra_pay = $rate_per_km * $extra_dist;
    }

    $total_extra_payment += $extra_pay;

    $extra_breakdown[] = [
        'date' => $record['date'],
        'out_time' => $record['out_time'],
        'in_time' => $record['in_time'],
        'distance' => $extra_dist,
        'fuel_price' => $fuel_price,
        'payment' => $extra_pay,
    ];
}

$total_monthly_payment = $total_base_payment + $total_extra_payment + $fixed_amount_display;

// --- 4. PDF Content Generation ---
$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));

$pdf->SetFont('Arial', '', 13);
$pdf->Cell(0, 12, "Payment Statement for $month_name, $selected_year", 0, 1, 'C'); 
$pdf->Ln(5);

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 7, 'Employee Details', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);

$pdf->SetFont('Arial', 'B', 10); $pdf->Cell(40, 7, 'Employee ID:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, htmlspecialchars($emp_id), 0, 1);
$pdf->SetFont('Arial', 'B', 10); $pdf->Cell(40, 7, 'Employee Name:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, htmlspecialchars($base_details['calling_name']), 0, 1);
$pdf->SetFont('Arial', 'B', 10); $pdf->Cell(40, 7, 'Vehicle No:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, htmlspecialchars($base_details['vehicle_no']), 0, 1);
$pdf->SetFont('Arial', 'B', 10); $pdf->Cell(40, 7, 'Fixed Allowance:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, 'LKR ' . number_format($fixed_amount_display, 2), 0, 1);

if ($is_paid_status === 0) {
    $pdf->SetTextColor(200, 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, 'STATUS: UNPAID (Payment Disabled)', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

$pdf->Ln(5);

// --- Main Payments Summary Table ---
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(100, 7, 'Description', 1, 0, 'L', 1);
$pdf->Cell(80, 7, 'Amount (LKR)', 1, 1, 'R', 1);

$pdf->SetFont('Arial', '', 10);
$pdf->Cell(100, 7, 'Total Attendance Payment (' . $total_attendance_days . ' days)', 1, 0, 'L');
$pdf->Cell(80, 7, number_format($total_base_payment, 2), 1, 1, 'R');

$pdf->Cell(100, 7, 'Extra Distance Payment (' . number_format($total_extra_distance, 2) . ' km)', 1, 0, 'L');
$pdf->Cell(80, 7, number_format($total_extra_payment, 2), 1, 1, 'R'); 

$pdf->Cell(100, 7, 'Fixed Allowance', 1, 0, 'L');
$pdf->Cell(80, 7, number_format($fixed_amount_display, 2), 1, 1, 'R');

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(180, 180, 255);
$pdf->Cell(100, 7, 'NET TOTAL PAYMENTS', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($total_monthly_payment, 2), 1, 1, 'R', 1);
$pdf->Ln(10);

if (!empty($extra_breakdown)) {
    $pdf->SetFont('Arial', 'BU', 10);
    $pdf->Cell(0, 6, 'Extra Trip Breakdown Detail', 0, 1, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(25, 5, 'Date', 1, 0, 'L', 1);
    $pdf->Cell(20, 5, 'Out Time', 1, 0, 'L', 1);
    $pdf->Cell(20, 5, 'In Time', 1, 0, 'L', 1);
    $pdf->Cell(30, 5, 'Fuel Price', 1, 0, 'R', 1);
    $pdf->Cell(30, 5, 'Distance (km)', 1, 0, 'R', 1);
    $pdf->Cell(35, 5, 'Payment (LKR)', 1, 1, 'R', 1);

    $pdf->SetFont('Arial', '', 8);
    foreach ($extra_breakdown as $item) {
        $pdf->Cell(25, 5, $item['date'], 1, 0, 'L');
        $pdf->Cell(20, 5, $item['out_time'], 1, 0, 'L');
        $pdf->Cell(20, 5, $item['in_time'], 1, 0, 'L');
        $pdf->Cell(30, 5, number_format($item['fuel_price'], 2), 1, 0, 'R');
        $pdf->Cell(30, 5, number_format($item['distance'], 2), 1, 0, 'R');
        $pdf->Cell(35, 5, number_format($item['payment'], 2), 1, 1, 'R');
    }
}

$filename = "OwnVehicle_Payment_{$emp_id}_{$vehicle_no}_" . date('Y_m', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . ".pdf";
$pdf->Output('D', $filename);

$conn->close();
?>