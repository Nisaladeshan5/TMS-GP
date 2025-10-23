<?php

// download_own_vehicle_detail_pdf.php

require('fpdf.php'); // Ensure this path is correct
include('../../includes/db.php');

// Get the parameters
$emp_id = isset($_GET['emp_id']) ? $_GET['emp_id'] : die('Employee ID not specified.');
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Function to format currency
function formatLKR($amount) {
    return number_format($amount, 2, '.', ',');
}

// Extend FPDF class with rounded rectangles and custom headers/footers
class PDF extends FPDF
{
    private $employee_info = [];
    private $month_year = '';

    // Set data for the header
    public function setHeaderData($info, $month, $year) {
        $this->employee_info = $info;
        $this->month_year = date('F Y', mktime(0, 0, 0, $month, 1, $year));
    }

    // Custom function for Rounded Rectangles (Copied from your code)
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

    // MODIFIED Header function: Employee details are now external
    function Header()
    {
        // Draw the custom border and rounded rectangle
        $this->RoundedRect(7.5, 7.5, 195, 35, 1.5); 
        $this->Rect(5, 5, 200, 287);
        $this->Image('../assets/logo.png', 12, 5, 25); // logo

        // Company Details
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 5, 'GP Garments (Pvt) Ltd', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Seethawaka Export Processing Zone', 0, 1, 'C');
        $this->Cell(0, 5, 'Awissawella, Sri Lanka', 0, 1, 'C');
        $this->Ln(5);

        // Report Title
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 5, 'Own Vehicle Payment Detail Report', 0, 1, 'C');
        $this->Ln(1);
        
        // Month and Year Subtitle
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 5, 'For ' . $this->month_year, 0, 1, 'C');
        $this->Ln(2);
        
        // Employee/Vehicle Details logic REMOVED from here

        // Draw a line to separate header info from report content (adjusted position)
        // Adjust the line position based on the header height (which is now shorter)
        $this->Ln(5);
    }

    // Footer function (Copied from your code)
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
    
    // NEW public function to print employee details
    public function printEmployeeDetails()
    {
        // Check if the current Y position is too high (i.e., this is the first item after the header)
        // FPDF's Header() moves the Y position, so we just continue from where it left off.
        
        if (!empty($this->employee_info)) {
            $this->SetX(10); // Move a bit right for alignment
            
            // Employee Name and Vehicle No
            $this->SetFont('Arial','B',10);
            $this->Cell(30, 5, 'Employee:', 0, 0, 'L');
            $this->SetFont('Arial','',10);
            $this->Cell(60, 5, $this->employee_info['calling_name'], 0, 1, 'L');
            
            // Employee ID
            $this->SetX(10);
            $this->SetFont('Arial','B',10);
            $this->Cell(30, 5, 'Emp ID:', 0, 0, 'L');
            $this->SetFont('Arial','',10);
            $this->Cell(0, 5, $this->employee_info['emp_id'], 0, 1, 'L');

            $this->SetX(10);
            $this->SetFont('Arial','B',10);
            $this->Cell(30, 5, 'Vehicle No:', 0, 0, 'L');
            $this->SetFont('Arial','',10);
            $this->Cell(60, 5, $this->employee_info['vehicle_no'], 0, 1, 'L');
            
            $this->Ln(5);
        }
    }
}

// ----------------------------------------------------------------------
// 1. FETCH SUMMARY DATA
// ----------------------------------------------------------------------
// (SQL remains the same)
$summary_sql = "
    SELECT  
        e.calling_name,
        ov.vehicle_no,
        ovp.monthly_payment,
        ovp.no_of_attendance,
        ovp.distance AS total_distance,
        ovp.emp_id
    FROM  
        own_vehicle_payments ovp
    JOIN  
        employee e ON ovp.emp_id = e.emp_id
    JOIN  
        own_vehicle ov ON ovp.emp_id = ov.emp_id 
    WHERE  
        ovp.emp_id = ? AND ovp.month = ? AND ovp.year = ?;
";
$stmt_summary = $conn->prepare($summary_sql);
$stmt_summary->bind_param("sii", $emp_id, $selected_month, $selected_year);
$stmt_summary->execute();
$summary_result = $stmt_summary->get_result();
$summary_data = $summary_result->fetch_assoc();
$stmt_summary->close();

if (!$summary_data) {
    die("No payment data found for Employee ID: " . htmlspecialchars($emp_id));
}

// ----------------------------------------------------------------------
// 2. DAILY LOG DATA (Placeholder)
// ----------------------------------------------------------------------
$daily_logs = []; // Initialize empty array since the table is missing

// ----------------------------------------------------------------------
// 3. PDF GENERATION
// ----------------------------------------------------------------------
$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->setHeaderData($summary_data, $selected_month, $selected_year);
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);

// --- A. Print Employee/Vehicle Details HERE (Once on the first page) ---
// This is the key change: calling the function after AddPage()
$pdf->printEmployeeDetails();
// -----------------------------------------------------------------------


// --- B. Monthly Summary Table ---
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0, 8, 'Monthly Payment Summary', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFillColor(200, 220, 255);
$pdf->SetFont('Arial','B',10);
$summary_widths = [80, 110]; 
$pdf->Cell($summary_widths[0], 7, 'Description', 1, 0, 'L', true);
$pdf->Cell($summary_widths[1], 7, 'Value', 1, 1, 'R', true);

$pdf->SetFont('Arial','',10);
// Row 1: Attendance
$pdf->Cell($summary_widths[0], 6, 'Total Attendance Days', 'LR', 0, 'L');
$pdf->Cell($summary_widths[1], 6, $summary_data['no_of_attendance'] . "", 'R', 1, 'R');
// Row 2: Total Distance
$pdf->Cell($summary_widths[0], 6, 'Total Calculated Distance(km)', 'LR', 0, 'L');
$pdf->Cell($summary_widths[1], 6, $summary_data['total_distance'] . "", 'R', 1, 'R');

$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(255, 255, 200); // Light Yellow
// Final Payment Row
$pdf->Cell($summary_widths[0], 7, 'FINAL MONTHLY PAYMENT (LKR)', 1, 0, 'L', true);
$pdf->Cell($summary_widths[1], 7, 'LKR ' . formatLKR($summary_data['monthly_payment']), 1, 1, 'R', true);

$pdf->Ln(15);

// 4. Output the PDF
$pdf->Output('I', 'OwnVehiclePayment_Detail_' . $emp_id . '_' . $selected_year . $selected_month . '.pdf');

$conn->close();
?>