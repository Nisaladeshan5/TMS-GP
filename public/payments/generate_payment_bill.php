<?php
// Include the database connection and FPDF library
include('../../includes/db.php');
require('fpdf.php'); // Adjust the path as needed

// Check for required GET parameters
if (!isset($_GET['route_code']) || !isset($_GET['month']) || !isset($_GET['year'])) {
    die("Missing required parameters.");
}

$route_code = $_GET['route_code'];
$selected_month = $_GET['month'];
$selected_year = $_GET['year'];

// Fetch the latest fuel rate
$sql_rate = "SELECT rate, date FROM fuel_rate ORDER BY date DESC LIMIT 1";
$result_rate = $conn->query($sql_rate);
$latest_fuel_rate = $result_rate && $result_rate->num_rows > 0 ? $result_rate->fetch_assoc() : null;

// Fetch the route's specific details
$route_sql = "SELECT route_code, route, vehicle_no, monthly_fixed_rental, working_days, distance, extra_day_rate FROM route WHERE route_code = ? LIMIT 1";
$route_stmt = $conn->prepare($route_sql);
$route_stmt->bind_param("s", $route_code);
$route_stmt->execute();
$route_result = $route_stmt->get_result();
$route_row = $route_result->fetch_assoc();
$route_stmt->close();

if (!$route_row) {
    die("Route not found.");
}

// Extract data from the row
$route_name = $route_row['route'];
$vehicle_no = $route_row['vehicle_no'];
$monthly_fixed_rental = $route_row['monthly_fixed_rental'];
$working_days = $route_row['working_days'];
$daily_distance = $route_row['distance'];
$extra_day_rate = $route_row['extra_day_rate'];

// Calculate Total Extra Distance
$extra_dist_sql = "SELECT SUM(distance) AS total_distance FROM extra_distance WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
$extra_dist_stmt = $conn->prepare($extra_dist_sql);
$extra_dist_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$extra_dist_stmt->execute();
$extra_dist_result = $extra_dist_stmt->get_result();
$total_extra_distance = 0;
if ($extra_dist_row = $extra_dist_result->fetch_assoc()) {
    $total_extra_distance = $extra_dist_row['total_distance'] ?? 0;
}
$extra_dist_stmt->close();

// Calculate Actual Days Worked and get vehicle's fuel efficiency
$register_sql = "SELECT vehicle_no, date FROM staff_transport_vehicle_register WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ?";
$register_stmt = $conn->prepare($register_sql);
$register_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$register_stmt->execute();
$register_result = $register_stmt->get_result();
$actual_days_worked = $register_result->num_rows;
$km_per_liter = 0;
if ($actual_days_worked > 0) {
    $first_entry = $register_result->fetch_assoc();
    $first_vehicle_no = $first_entry['vehicle_no'];
    $efficiency_sql = "SELECT c.distance FROM vehicle v JOIN consumption c ON v.condition_type = c.c_type WHERE v.vehicle_no = ?";
    $efficiency_stmt = $conn->prepare($efficiency_sql);
    $efficiency_stmt->bind_param("s", $first_vehicle_no);
    $efficiency_stmt->execute();
    $efficiency_result = $efficiency_stmt->get_result();
    if ($efficiency_row = $efficiency_result->fetch_assoc()) {
        $km_per_liter = $efficiency_row['distance'];
    }
    $efficiency_stmt->close();
}
$register_stmt->close();

// Fetch reduction details
$ruduce_sql = "SELECT amount, reason FROM extra_vehicle_register WHERE route_code = ? AND MONTH(date) = ? AND YEAR(date) = ?";
$ruduce_stmt = $conn->prepare($ruduce_sql);
$ruduce_stmt->bind_param("sii", $route_code, $selected_month, $selected_year);
$ruduce_stmt->execute();
$reduction_results = $ruduce_stmt->get_result();
$reductions = [];
$total_reduce = 0;
while ($reduce_row = $reduction_results->fetch_assoc()) {
    $reductions[] = $reduce_row;
    $total_reduce += $reduce_row['amount'];
}
$ruduce_stmt->close();

// Perform the calculations
$total_distance = $daily_distance * $working_days;
$fuel_amount = 0;
if ($km_per_liter > 0 && $latest_fuel_rate) {
    $fuel_amount = (($total_distance + $total_extra_distance) / $km_per_liter) * $latest_fuel_rate['rate'];
}
$extra_days = max(0, $actual_days_worked - $working_days);
$extra_days_amount = $extra_days * $extra_day_rate;
$total_payments = $monthly_fixed_rental + $fuel_amount + $extra_days_amount - $total_reduce;

// Check for future dates
$current_month = date('m');
$current_year = date('Y');
if ($selected_year > $current_year || ($selected_year == $current_year && $selected_month > $current_month)) {
    $total_payments = 0;
}

// Create PDF with A4 page size
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();

// Define border margin and content padding
$borderMargin = 10; // Space between page edge and border
$padding = 10;       // Space between border and content

// Set the initial position for content
$contentX = $borderMargin + $padding;
$contentY = $borderMargin + $padding;
$pdf->SetXY($contentX, $contentY);

// Bill Heading
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Vehicle Payment Bill', 0, 1, 'C');
$pdf->SetFont('Arial', '', 12);
$pdf->SetX($contentX);
$pdf->Cell(0, 10, 'For the Month of ' . date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)), 0, 1, 'C');
$pdf->Ln(10);

// Vehicle and Route Details
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetX($contentX);
$pdf->Cell(60, 10, 'Vehicle No:', 0, 0);
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 10, $vehicle_no, 0, 1);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetX($contentX);
$pdf->Cell(60, 10, 'Route:', 0, 0);
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 10, $route_name, 0, 1);
$pdf->Ln(5);

// Payment Breakdown Table
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetX($contentX);
$pdf->Cell(0, 10, 'Payment Breakdown', 0, 1, 'L');
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 10);
$tableWidth = $pdf->GetPageWidth() - 2 * ($borderMargin + $padding);
$pdf->SetX($contentX);
$pdf->Cell($tableWidth * 0.7, 8, 'Description', 1, 0, 'L', 1);
$pdf->Cell($tableWidth * 0.3, 8, 'Amount (LKR)', 1, 1, 'R', 1);

// Monthly Rental
$pdf->SetFont('Arial', '', 10);
$pdf->SetX($contentX);
$pdf->Cell($tableWidth * 0.7, 8, 'Monthly Fixed Rental', 1, 0, 'L');
$pdf->Cell($tableWidth * 0.3, 8, number_format($monthly_fixed_rental, 2), 1, 1, 'R');

// Fuel Amount
$pdf->SetX($contentX);
$pdf->Cell($tableWidth * 0.7, 8, 'Fuel Amount (' . $total_distance . ' + ' . $total_extra_distance . ') km @ ' . $km_per_liter . ' km/L)', 1, 0, 'L');
$pdf->Cell($tableWidth * 0.3, 8, number_format($fuel_amount, 2), 1, 1, 'R');

// Extra Days Amount
$pdf->SetX($contentX);
$pdf->Cell($tableWidth * 0.7, 8, 'Extra Days Amount (' . $extra_days . ' days)', 1, 0, 'L');
$pdf->Cell($tableWidth * 0.3, 8, number_format($extra_days_amount, 2), 1, 1, 'R');

// Reductions
$pdf->SetFillColor(255, 200, 200); // Light red background for reductions
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetX($contentX);
$pdf->Cell($tableWidth * 0.7, 8, 'Total Reductions', 1, 0, 'L', 1);
$pdf->SetTextColor(255, 0, 0); // Red color for amount
$pdf->Cell($tableWidth * 0.3, 8, '(' . number_format($total_reduce, 2) . ')', 1, 1, 'R', 1);
$pdf->SetTextColor(0, 0, 0); // Reset color

// Total Payments
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetX($contentX);
$pdf->SetFillColor(173, 216, 230);
$pdf->Cell($tableWidth * 0.7, 10, 'Total Payments', 1, 0, 'L', 1);
$pdf->Cell($tableWidth * 0.3, 10, number_format($total_payments, 2), 1, 1, 'R', 1);
$pdf->Ln(10);

// Add the reduction reasons at the bottom
if (!empty($reductions)) {
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetX($contentX);
    $pdf->Cell(0, 10, 'Reduction Details', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    foreach ($reductions as $reduction) {
        $pdf->SetX($contentX);
        $pdf->SetTextColor(255, 0, 0);
        $pdf->Cell(15, 8, '- ' . number_format($reduction['amount'], 2), 0, 0, 'R');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(0, 8, ' : ' . htmlspecialchars($reduction['reason']), 0, 'L');
    }
}

// Fuel rate information at the bottom
$pdf->SetFont('Arial', '', 10);
$pdf->SetX($contentX);
$pdf->Cell(0, 5, 'Fuel Rate: LKR ' . number_format($latest_fuel_rate['rate'], 2) . ' per liter', 0, 1, 'L');
$pdf->SetX($contentX);
$pdf->Cell(0, 5, 'As of ' . date('F j, Y', strtotime($latest_fuel_rate['date'])), 0, 1, 'L');

// Draw a border around the entire page
$pageWidth = $pdf->GetPageWidth();
$pageHeight = $pdf->GetPageHeight();
$pdf->Rect($borderMargin, $borderMargin, $pageWidth - 2 * $borderMargin, $pageHeight - 2 * $borderMargin);

// Output the PDF
$filename = "Payment_Bill_" . $vehicle_no . "_" . date('Y_m', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . ".pdf";
$pdf->Output('I', $filename);
?>