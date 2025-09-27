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

// Get and sanitize the parameters from the URL
$route_code = isset($_GET['route_code']) ? htmlspecialchars($_GET['route_code']) : '';
$selected_month = isset($_GET['month']) ? htmlspecialchars($_GET['month']) : date('m');
$selected_year = isset($_GET['year']) ? htmlspecialchars($_GET['year']) : date('Y');

// Create a new PDF instance using your custom class
$pdf = new PDF();
$pdf->AliasNbPages(); // Required for {nb} alias
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);

// --- Fetch main route data and other values for calculations ---
// This section fetches the necessary data for the calculations
// that match the logic in the payment_staff2.php file.
// FIX: Added 'supplier_code' to the SELECT statement to prevent 'Undefined array key' warning.
$route_sql = "SELECT route, fixed_amount, distance, supplier_code FROM route WHERE route_code = ? LIMIT 1";
$route_stmt = $conn->prepare($route_sql);
$route_stmt->bind_param("s", $route_code);
$route_stmt->execute();
$route_result = $route_stmt->get_result();
$route_data = $route_result->fetch_assoc();
$route_stmt->close();

if (!$route_data) {
    // Handle the case where the route is not found
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Error: Route not found or invalid route code.', 0, 1);
    $pdf->Output('D', 'error_report.pdf');
    exit;
}

$route_name = $route_data['route'];
$price_per_1km = $route_data['fixed_amount'];
$route_distance = $route_data['distance'];

// --- **UPDATED LOGIC**: Fetch supplier details directly from the route table ---
$supplier_data = [];
$supplier_code = $route_data['supplier_code'];
if (!empty($supplier_code)) {
    $supplier_sql = "
        SELECT supplier, email, beneficiaress_name, bank, bank_code, branch, branch_code, acc_no, supplier_code FROM supplier
        WHERE supplier_code = ? LIMIT 1";
    $supplier_stmt = $conn->prepare($supplier_sql);
    $supplier_stmt->bind_param("s", $supplier_code);
    $supplier_stmt->execute();
    $supplier_result = $supplier_stmt->get_result();

    if ($supplier_row = $supplier_result->fetch_assoc()) {
        $supplier_data = $supplier_row;
    }
    $supplier_stmt->close();
}

// FIX: Get the total number of trips (working days) for the selected month and year
$working_days_sql = "SELECT COUNT(*) AS total_working_days FROM staff_transport_vehicle_register WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ?";
$working_days_stmt = $conn->prepare($working_days_sql);
$working_days_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$working_days_stmt->execute();
$working_days_result = $working_days_stmt->get_result();
$working_days_row = $working_days_result->fetch_assoc();
$total_working_days = $working_days_row['total_working_days'] ?? 0;
$working_days_stmt->close();

// Calculate Total Distance based on working days * fixed route distance
$total_distance = $total_working_days * $route_distance/2;

// 2. Calculate Total Extra Distance from extra_distance
$extra_dist_sql = "SELECT SUM(distance) AS total_extra_distance FROM extra_distance WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
$extra_dist_stmt = $conn->prepare($extra_dist_sql);
$extra_dist_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$extra_dist_stmt->execute();
$extra_dist_result = $extra_dist_stmt->get_result();
$extra_dist_row = $extra_dist_result->fetch_assoc();
$total_extra_distance = $extra_dist_row['total_extra_distance'] ?? 0;
$extra_dist_stmt->close();

// --- Fetch breakdown data for the "Other Amount" section ---
$increments = [];

// Increments (from trip table)
$trip_sql = "SELECT date, reason AS description, amount FROM trip WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
$trip_stmt = $conn->prepare($trip_sql);
$trip_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$trip_stmt->execute();
$trip_result = $trip_stmt->get_result();
while ($row = $trip_result->fetch_assoc()) {
    $increments[] = $row;
}
$trip_stmt->close();

// --- Final Calculations based on `payment_staff2.php` logic ---
$other_amount = array_sum(array_column($increments, 'amount'));

// Calculate the total payments
$total_payments = ($total_distance + $total_extra_distance) * $price_per_1km + $other_amount;

// --- PDF Content Generation ---
$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));

$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));
$pdf->SetFont('Arial', '', 13); // Bigger + Bold
$pdf->Cell(0, 12, "Route: {$route_data['route']} for $month_name, $selected_year", 0, 1, 'C');
$pdf->SetFont('Arial', '', 12); 
$pdf->Ln(5);

// Supplier Details Section
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
    $pdf->Cell(0, 7, 'No supplier details found for this route.', 0, 1);
}
$pdf->Ln(5);

// Main Payments Summary Table
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(100, 7, 'Description', 1, 0, 'L', 1);
$pdf->Cell(80, 7, '', 1, 1, 'R', 1);

$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(255, 255, 255);
$pdf->Cell(100, 7, 'Total Trip Count', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($total_working_days), 1, 1, 'R', 1);

$pdf->SetFillColor(255, 255, 255);
$pdf->Cell(100, 7, 'Total Distance (km)', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($total_distance), 1, 1, 'R', 1);

$pdf->SetFillColor(255, 255, 255);
$pdf->Cell(100, 7, 'Extra Distance (km)', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($total_extra_distance), 1, 1, 'R', 1);

$pdf->SetFillColor(255, 255, 255);
$pdf->Cell(100, 7, 'Price per 1km (LKR)', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($price_per_1km, 2), 1, 1, 'R', 1);

$pdf->Cell(100, 7, 'Other Payments (LKR)', 1, 0, 'L');
// Set the fill color for the other amount based on its value
if ($other_amount < 0) {
    $pdf->SetFillColor(255, 220, 220); // Light Red
} else {
    $pdf->SetFillColor(220, 255, 220); // Light Green
}
$pdf->Cell(80, 7, number_format($other_amount, 2), 1, 1, 'R', 1);
$pdf->SetFillColor(255, 255, 255); // Reset fill color to white

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(100, 7, 'Total Payments (LKR)', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($total_payments, 2), 1, 1, 'R', 1);
$pdf->Ln(10);

// Other Amount Breakdown Section
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Other Payments Breakdown', 0, 1, 'L');
$pdf->Ln(2);

// Increments Table
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Additions (from Trips)', 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(30, 7, 'Date', 1, 0, 'L', 1);
$pdf->Cell(100, 7, 'Description', 1, 0, 'L', 1);
$pdf->Cell(50, 7, 'Amount (LKR)', 1, 1, 'R', 1);

$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(220, 255, 220); // Light Green fill for increments
if (empty($increments)) {
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(180, 7, 'No additions recorded.', 1, 1, 'C', 1);
} else {
    foreach ($increments as $row) {
        $pdf->Cell(30, 7, $row['date'], 1, 0, 'L', 1);
        $pdf->Cell(100, 7, $row['description'], 1, 0, 'L', 1);
        $pdf->Cell(50, 7, number_format($row['amount'], 2), 1, 1, 'R', 1);
    }
}
$pdf->SetFillColor(255, 255, 255); // Reset fill color
$pdf->Ln(5);

// Output the PDF for download
$filename = "staff_report_{$route_code}_" . date('Y-m', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . ".pdf";
$pdf->Output('D', $filename);

// Close the database connection
$conn->close();
?>
