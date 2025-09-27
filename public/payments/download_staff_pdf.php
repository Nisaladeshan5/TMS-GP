<?php
// Suppress all PHP errors and warnings to prevent them from being
// outputted before the PDF stream. This is crucial for FPDF.
error_reporting(0);

// Include the database connection file
include('../../includes/db.php');

// Include the FPDF library
require('fpdf.php');

// Extend the FPDF class to create a custom Header
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
        $this->RoundedRect(7.5, 7.5, 195, 40, 1.5);
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
        $this->Cell(0, 5, 'Staff Transport Payment Report', 0, 1, 'C');
    }
}

// --- Get and sanitize parameters ---
$route_code = isset($_GET['route_code']) ? htmlspecialchars($_GET['route_code']) : '';
$selected_month = isset($_GET['month']) ? htmlspecialchars($_GET['month']) : date('m');
$selected_year = isset($_GET['year']) ? htmlspecialchars($_GET['year']) : date('Y');

// Create PDF
$pdf = new PDF();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// --- Fetch main route data ---
$route_sql = "SELECT route, monthly_fixed_rental, working_days, distance, supplier_code 
              FROM route WHERE route_code = ? LIMIT 1";
$route_stmt = $conn->prepare($route_sql);
$route_stmt->bind_param("s", $route_code);
$route_stmt->execute();
$route_result = $route_stmt->get_result();
$route_data = $route_result->fetch_assoc();
$route_stmt->close();

if (!$route_data) {
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Error: Route not found or invalid route code.', 0, 1);
    $pdf->Output('D', 'error_report.pdf');
    exit;
}

$monthly_fixed_rental = $route_data['monthly_fixed_rental'] ?? 0;
$working_days_quota   = $route_data['working_days'] ?? 0;
$daily_distance       = $route_data['distance'] ?? 0;

// --- Get Actual Days Worked + Vehicle Info ---
$km_per_liter = 10;
$price_per_liter = 0;
$actual_days_worked = 0;

$register_sql = "SELECT vehicle_no FROM staff_transport_vehicle_register 
                 WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ?";
$register_stmt = $conn->prepare($register_sql);
$register_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$register_stmt->execute();
$register_result = $register_stmt->get_result();
$actual_days_worked = $register_result->num_rows;

if ($actual_days_worked > 0) {
    $register_result->data_seek(0);
    $first_entry = $register_result->fetch_assoc();
    $first_vehicle_no = $first_entry['vehicle_no'];

    $vehicle_info_sql = "
        SELECT c.distance, fr.rate 
        FROM vehicle v
        JOIN consumption c ON v.condition_type = c.c_type
        JOIN fuel_rate fr ON v.rate_id = fr.rate_id
        WHERE v.vehicle_no = ?
        ORDER BY fr.date DESC
        LIMIT 1";
    $vehicle_info_stmt = $conn->prepare($vehicle_info_sql);
    $vehicle_info_stmt->bind_param("s", $first_vehicle_no);
    $vehicle_info_stmt->execute();
    $vehicle_info_result = $vehicle_info_stmt->get_result();

    if ($vehicle_info_row = $vehicle_info_result->fetch_assoc()) {
        $km_per_liter = $vehicle_info_row['distance'] ?? 0;
        $price_per_liter = $vehicle_info_row['rate'] ?? 0;
    }
    $vehicle_info_stmt->close();
}
$register_stmt->close();

// --- Supplier Data ---
$supplier_data = [];
$supplier_code = $route_data['supplier_code'];
if (!empty($supplier_code)) {
    $supplier_sql = "SELECT supplier, email, beneficiaress_name, bank, bank_code, branch, branch_code, acc_no, supplier_code 
                     FROM supplier WHERE supplier_code = ? LIMIT 1";
    $supplier_stmt = $conn->prepare($supplier_sql);
    $supplier_stmt->bind_param("s", $supplier_code);
    $supplier_stmt->execute();
    $supplier_result = $supplier_stmt->get_result();
    if ($supplier_row = $supplier_result->fetch_assoc()) {
        $supplier_data = $supplier_row;
    }
    $supplier_stmt->close();
}

// --- Extra Distance ---
$total_extra_distance = 0;
$extra_dist_sql = "SELECT SUM(distance) AS total_distance 
                   FROM extra_distance WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
$extra_dist_stmt = $conn->prepare($extra_dist_sql);
$extra_dist_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$extra_dist_stmt->execute();
$extra_dist_result = $extra_dist_stmt->get_result();
if ($extra_dist_row = $extra_dist_result->fetch_assoc()) {
    $total_extra_distance = $extra_dist_row['total_distance'] ?? 0;
}
$extra_dist_stmt->close();

// --- Other Payments (increments/reductions) ---
$increments = [];
$reductions = [];
$total_extra_absent_count = 0;
$petty_cash_absent_count = 0;

$trip_sql = "SELECT date, reason AS description, amount FROM trip 
             WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
$trip_stmt = $conn->prepare($trip_sql);
$trip_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$trip_stmt->execute();
$trip_result = $trip_stmt->get_result();
while ($row = $trip_result->fetch_assoc()) { $increments[] = $row; }
$trip_stmt->close();

$extra_vehicle_sql = "SELECT date, reason AS description, amount, absent_type FROM extra_vehicle_register WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ? AND status = 0";
$extra_vehicle_stmt = $conn->prepare($extra_vehicle_sql);
$extra_vehicle_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$extra_vehicle_stmt->execute();
$extra_vehicle_result = $extra_vehicle_stmt->get_result();
while ($row = $extra_vehicle_result->fetch_assoc()) { 
    $reductions[] = $row; 
    $total_extra_absent_count += $row['absent_type'] ?? 0;
}
$extra_vehicle_stmt->close();

$petty_cash_sql = "SELECT date, reason AS description, amount, absent_type FROM petty_cash WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ? AND status = 0";
$petty_cash_stmt = $conn->prepare($petty_cash_sql);
$petty_cash_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$petty_cash_stmt->execute();
$petty_cash_result = $petty_cash_stmt->get_result();
while ($row = $petty_cash_result->fetch_assoc()) { 
    $reductions[] = $row;
    $petty_cash_absent_count += $row['absent_type'] ?? 0;
}
$petty_cash_stmt->close();

// --- Calculations ---
$working_days_limit = ($working_days_quota ?? 0) * 2;
$days_for_fuel = min($actual_days_worked + ($total_extra_absent_count ?? 0) + ($petty_cash_absent_count ?? 0), $working_days_limit);
$total_distance_for_fuel = (($daily_distance ?? 0) / 2) * $days_for_fuel;

$fuel_amount = 0;
if (($km_per_liter ?? 0) > 0 && ($price_per_liter ?? 0) > 0) {
    $fuel_amount = (($total_distance_for_fuel + ($total_extra_distance ?? 0)) / $km_per_liter) * $price_per_liter;
}

$extra_day_rate = 0;
if (($working_days_quota ?? 0) > 0 && ($km_per_liter ?? 0) > 0 && ($price_per_liter ?? 0) > 0) {
    $extra_day_rate = (($monthly_fixed_rental ?? 0) / ($working_days_quota ?? 1)) + ((($daily_distance ?? 0) / $km_per_liter) * $price_per_liter);
}

$extra_days_worked = max(0, $actual_days_worked - ($working_days_limit ?? 0));
$extra_days = ($extra_days_worked / 2);
$extra_days_amount = ($extra_days ?? 0) * ($extra_day_rate ?? 0);

$other_amount = array_sum(array_column($increments, 'amount')) - array_sum(array_column($reductions, 'amount'));
$total_payments = ($monthly_fixed_rental ?? 0) + ($fuel_amount ?? 0) + ($extra_days_amount ?? 0) + ($other_amount ?? 0);

// --- PDF Content ---
$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));
$pdf->SetFont('Arial', '', 13);
$pdf->Cell(0, 12, "Route: {$route_data['route']} for $month_name, $selected_year", 0, 1, 'C');
$pdf->SetFont('Arial', '', 12); 
$pdf->Ln(5);

// Supplier Details
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 7, 'Supplier Details', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
if (!empty($supplier_data)) {
    $fields = [
        'Supplier Code' => 'supplier_code',
        'Supplier'      => 'supplier',
        'Email'         => 'email',
        'Beneficiary'   => 'beneficiaress_name',
        'Bank'          => 'bank',
        'Branch'        => 'branch',
        'Account No'    => 'acc_no',
    ];
    foreach ($fields as $label => $key) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 7, $label . ':', 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, $supplier_data[$key] ?? '', 0, 1);
    }
} else {
    $pdf->Cell(0, 7, 'No supplier details found for this route.', 0, 1);
}
$pdf->Ln(5);

// Payments Summary
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(220,220,220);
$pdf->Cell(100, 7, 'Description', 1, 0, 'L', 1);
$pdf->Cell(80, 7, 'Amount', 1, 1, 'R', 1);

$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(255,255,255);
$pdf->Cell(100, 7, 'Monthly Fixed Rental (LKR)', 1, 0, 'L');
$pdf->Cell(80, 7, number_format($monthly_fixed_rental ?? 0, 2), 1, 1, 'R');

$pdf->Cell(100, 7, 'Payment for Fuel (LKR)', 1, 0, 'L');
$pdf->Cell(80, 7, number_format($fuel_amount ?? 0, 2), 1, 1, 'R');

$pdf->Cell(100, 7, 'Payment for Extra Days (LKR)' . (($extra_days ?? 0) > 0 ? " (" . number_format($extra_days ?? 0, 2) . " days)" : ''), 1, 0, 'L');
$pdf->Cell(80, 7, number_format($extra_days_amount ?? 0, 2), 1, 1, 'R');

$pdf->Cell(100, 7, 'Other Payments (LKR)', 1, 0, 'L');
if (($other_amount ?? 0) < 0) {
    $pdf->SetFillColor(255,220,220);
} else {
    $pdf->SetFillColor(220,255,220);
}
$pdf->Cell(80, 7, number_format($other_amount ?? 0, 2), 1, 1, 'R', 1);

$pdf->SetFillColor(220,220,220);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(100, 7, 'Total Payments (LKR)', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($total_payments ?? 0, 2), 1, 1, 'R', 1);
$pdf->Ln(10);

// Breakdown - Increments
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Other Payments Breakdown', 0, 1, 'L');

$pdf->Cell(0, 8, 'Additions (from Trips)', 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240,240,240);
$pdf->Cell(30, 7, 'Date', 1, 0, 'L', 1);
$pdf->Cell(100, 7, 'Description', 1, 0, 'L', 1);
$pdf->Cell(50, 7, 'Amount (LKR)', 1, 1, 'R', 1);

$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(220,255,220);
if (empty($increments)) {
    $pdf->Cell(180, 7, 'No additions recorded.', 1, 1, 'C');
} else {
    foreach ($increments as $row) {
        $pdf->Cell(30, 7, $row['date'] ?? '', 1, 0);
        $pdf->Cell(100, 7, $row['description'] ?? '', 1, 0);
        $pdf->Cell(50, 7, number_format($row['amount'] ?? 0, 2), 1, 1, 'R');
    }
}
$pdf->Ln(5);

// Breakdown - Reductions
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'Reductions (from Extra Vehicle & Petty Cash)', 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240,240,240);
$pdf->Cell(30, 7, 'Date', 1, 0, 'L', 1);
$pdf->Cell(100, 7, 'Description', 1, 0, 'L', 1);
$pdf->Cell(50, 7, 'Amount (LKR)', 1, 1, 'R', 1);

$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(255,220,220);
if (empty($reductions)) {
    $pdf->Cell(180, 7, 'No reductions recorded.', 1, 1, 'C');
} else {
    foreach ($reductions as $row) {
        $pdf->Cell(30, 7, $row['date'] ?? '', 1, 0);
        $pdf->Cell(100, 7, $row['description'] ?? '', 1, 0);
        $pdf->Cell(50, 7, number_format($row['amount'] ?? 0, 2), 1, 1, 'R');
    }
}

// Output PDF
$filename = "staff_report_{$route_code}_" . date('Y-m', mktime(0,0,0,$selected_month,1,$selected_year)) . ".pdf";
$pdf->Output('D', $filename);

// Close DB
$conn->close();
?>