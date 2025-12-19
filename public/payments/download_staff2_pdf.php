<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Include the database connection file
include('../../includes/db.php');

// Include the FPDF library. You must have this file in your project.
require('fpdf.php');

// Extend the FPDF class to create custom Header and Footer methods
class PDF extends FPDF
{
    // ... (Custom Drawing Methods like RoundedRect and _Arc remain the same) ...
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
        // ... (Header logic is unchanged)
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
$supplier_code = isset($_GET['supplier_code']) ? htmlspecialchars($_GET['supplier_code']) : ''; 
$selected_month = isset($_GET['month']) ? htmlspecialchars($_GET['month']) : date('m');
$selected_year = isset($_GET['year']) ? htmlspecialchars($_GET['year']) : date('Y');

// --- NEW PARAMETERS FROM SUMMARY PAGE (assuming they are passed) ---
// We assume 'calculated_payment' holds the Base Trip Payment (Base Rate * Total Trips)
$calculated_base_payment = isset($_GET['base_payment']) ? (float)$_GET['base_payment'] : 0; 
// OR, if you passed the final total payment, you need to adjust this block:
$total_distance = isset($_GET['total_distance_calc']) ? (float)$_GET['total_distance_calc'] : 0;

// Since the `payments_category.php` logic relies on calculating the Base Payment 
// from trip counts, we will recalculate Base Payment here for robustness 
// and only use the supplier_code for fetching trip counts, or assume 
// we only need the total working days. 
// --- We'll stick to the original plan: fetch base data, trip count, and reduction.

// Exit if required parameters are missing
if (empty($route_code) || empty($supplier_code)) {
    echo "Error: Missing Route Code or Supplier Code.";
    exit;
}

// Create a new PDF instance using your custom class
$pdf = new PDF();
$pdf->AliasNbPages(); // Required for {nb} alias
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);

// --- 1. Fetch main route data (Base Rates) ---
$route_sql = "SELECT route, fixed_amount, fuel_amount, distance FROM route WHERE route_code = ? LIMIT 1";
$route_stmt = $conn->prepare($route_sql);
$route_stmt->bind_param("s", $route_code);
$route_stmt->execute();
$route_result = $route_stmt->get_result();
$route_data = $route_result->fetch_assoc();
$route_stmt->close();

$route_suffix = substr($route_code, 6, 3);

if (!$route_data) {
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Error: Route not found or invalid route code.', 0, 1);
    $pdf->Output('D', 'error_report.pdf');
    exit;
}

$rate_per_km = $route_data['fixed_amount'] + $route_data['fuel_amount'];
$route_distance = $route_data['distance'];

// --- 2. Fetch Trip Count (to calculate Base Payment and Distance) ---
$working_days_sql = "
    SELECT COUNT(id) AS total_working_days 
    FROM staff_transport_vehicle_register 
    WHERE route = ? 
    AND supplier_code = ?  
    AND MONTH(date) = ? 
    AND YEAR(date) = ?
    AND is_active = 1";
$working_days_stmt = $conn->prepare($working_days_sql);
$working_days_stmt->bind_param("ssii", $route_code, $supplier_code, $selected_month, $selected_year);
$working_days_stmt->execute();
$working_days_result = $working_days_stmt->get_result();
$working_days_row = $working_days_result->fetch_assoc();
$total_working_days = $working_days_row['total_working_days'] ?? 0;
$working_days_stmt->close();

// Calculate Day Rate, Base Payment, and Total Distance
// Day Rate (Trip Rate) = (Rate per KM) * (Distance / 2)
$day_rate = ($rate_per_km * $route_distance) / 2;

// Calculated Base Payment = Trip Rate * Total Trip Count
$calculated_base_payment = $day_rate * $total_working_days;

// Calculated Total Distance (for display)
$total_distance = ($route_distance / 2) * $total_working_days;


// --- 3. Fetch Total Reduction Amount and Breakdown (from 'reduction' table) ---
$adjustments = [];
$total_reduction_amount = 0.00;

$reduction_sql_breakdown = "
    SELECT date, reason, amount 
    FROM reduction 
    WHERE route_code = ?
    AND supplier_code = ?
    AND MONTH(date) = ? 
    AND YEAR(date) = ?";
$reduction_stmt = $conn->prepare($reduction_sql_breakdown);
$reduction_stmt->bind_param("ssii", $route_code, $supplier_code, $selected_month, $selected_year);
$reduction_stmt->execute();
$reduction_result = $reduction_stmt->get_result();
while ($row = $reduction_result->fetch_assoc()) {
    $row['description'] = $row['reason'];
    $adjustments[] = $row; 
    // Sum the amounts
    $total_reduction_amount += (float)$row['amount'];
}
$reduction_stmt->close();

// --- 4. Final Calculation ---
// Reductions are typically stored as positive values but represent a negative adjustment.
$other_amount = $total_reduction_amount * -1;

// Total Payments = Base Payment + Other Amount (which is the reduction multiplied by -1)
$total_payments = $calculated_base_payment + $other_amount;


// --- 5. Fetch supplier details ---
$supplier_data = [];
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


// Sort adjustments by date (Optional but useful for presentation)
usort($adjustments, function($a, $b) {
    return strcmp($a['date'], $b['date']);
});

// --- PDF Content Generation ---
$month_name = date('F', mktime(0, 0, 0, $selected_month, 10));

$pdf->SetFont('Arial', '', 13);
// Display route name and supplier code
$pdf->Cell(0, 12, "Route: {$route_data['route']} ($route_suffix) for $month_name, $selected_year", 0, 1, 'C'); 
$pdf->SetFont('Arial', '', 12); 
$pdf->Ln(5);

// Supplier Details Section
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 7, 'Supplier Details', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
if (!empty($supplier_data)) {
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(30, 7, 'Supplier Code:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, $supplier_data['supplier_code'], 0, 1);
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(30, 7, 'Supplier:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, $supplier_data['supplier'], 0, 1);
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(30, 7, 'Email:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, $supplier_data['email'], 0, 1);
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(30, 7, 'Beneficiary:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, $supplier_data['beneficiaress_name'], 0, 1);
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(30, 7, 'Bank:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, $supplier_data['bank'], 0, 1);
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(30, 7, 'Branch:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, $supplier_data['branch'], 0, 1);
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(30, 7, 'Account No:', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 7, $supplier_data['acc_no'], 0, 1);
} else {
    $pdf->Cell(0, 7, 'No supplier details found for this supplier code.', 0, 1);
}
$pdf->Ln(5);

// Main Payments Summary Table - UPDATED VALUES
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(100, 7, 'Description', 1, 0, 'L', 1);
$pdf->Cell(80, 7, 'Amount', 1, 1, 'R', 1);

$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(255, 255, 255);

// Total Trip Count
$pdf->Cell(100, 7, 'Total Trip Count', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($total_working_days), 1, 1, 'R', 1);

// Calculated Total Distance
$pdf->Cell(100, 7, 'Total Distance (km)', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($total_distance, 2), 1, 1, 'R', 1); 

// Calculated Base Payment (Trip Rate * Count)
$pdf->Cell(100, 7, 'Base Payment (LKR)', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($calculated_base_payment, 2), 1, 1, 'R', 1);

// Other Payments (Calculated as Reduction * -1)
$pdf->Cell(100, 7, 'Adjustment (LKR)', 1, 0, 'L');
if ($other_amount < 0) {
    $pdf->SetFillColor(255, 220, 220); // Light Red for negative adjustments (deductions)
} else {
    $pdf->SetFillColor(220, 255, 220); // Light Green for positive adjustments (additions/credits)
}
$pdf->Cell(80, 7, number_format($other_amount, 2), 1, 1, 'R', 1);
$pdf->SetFillColor(255, 255, 255); // Reset fill color to white

// Total Payments (Calculated from Base Payment + Adjustment)
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(100, 7, 'TOTAL PAYMENTS (LKR)', 1, 0, 'L', 1);
$pdf->Cell(80, 7, number_format($total_payments, 2), 1, 1, 'R', 1);
$pdf->Ln(10);

// Reduction Breakdown Section
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Reduction Breakdown', 0, 1, 'L');
$pdf->Ln(2);

// Adjustments Table
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(30, 7, 'Date', 1, 0, 'L', 1);
$pdf->Cell(100, 7, 'Description', 1, 0, 'L', 1);
$pdf->Cell(50, 7, 'Amount (LKR)', 1, 1, 'R', 1); // This is the Gross Reduction Amount

$pdf->SetFont('Arial', '', 10);
if (empty($adjustments)) {
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(180, 7, 'No specific reduction records found for this route.', 1, 1, 'C', 1);
} else {
    foreach ($adjustments as $row) {
        // Assume reduction amounts are stored as positive values representing a charge/deduction
        $pdf->SetFillColor(255, 220, 220); // Light Red for reductions
        
        $pdf->Cell(30, 7, $row['date'], 1, 0, 'L', 1);
        
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->MultiCell(100, 7, $row['description'], 1, 'L', 1);
        $pdf->SetXY($x + 100, $y); 

        // Cell for Amount (Displaying the positive amount recorded in the reduction table)
        $pdf->Cell(50, 7, number_format($row['amount'], 2), 1, 1, 'R', 1);
    }
}
$pdf->SetFillColor(255, 255, 255); // Reset fill color
$pdf->Ln(5);

// Output the PDF for download
$filename = "staff_report_{$route_code}_{$supplier_code}_" . date('Y-m', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . ".pdf";
$pdf->Output('D', $filename);

// Close the database connection
$conn->close();
?>