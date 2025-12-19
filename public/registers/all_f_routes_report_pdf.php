<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Include the database connection
require_once('../../includes/db.php');

// ✅ Include TCPDF
require_once('../../TCPDF/tcpdf.php');

// --- 🚀 URL පරාමිතීන් ලබා ගැනීම සහ මාසය වෙන් කිරීම (Get URL parameters and split month) ---
$route_code = isset($_GET['route_code']) ? htmlspecialchars($_GET['route_code']) : ''; // All routes for this report

// `month_year` පරාමිතිය ලබාගෙන, පෙරනිමි අගය ලෙස වත්මන් මාසය යොදන්න.
$filterDate = isset($_GET['month_year']) ? htmlspecialchars($_GET['month_year']) : date('Y-m');

// YYYY-MM ආකෘතිය YYYY සහ MM ලෙස වෙන් කිරීම
$dateParts = explode('-', $filterDate);

// වර්ෂය (Year)
$filterYear = (int) ($dateParts[0] ?? date('Y')); 

// මාසය (Month)
$filterMonth = (int) ($dateParts[1] ?? date('m')); 

// PDF Header එකේ පෙන්වීම සඳහා මාසය සැකසීම
$headerDateString = date('F Y', strtotime($filterDate . '-01'));
// ------------------------------------------------------------------------------------------


// Extend the TCPDF class
class PDF extends TCPDF
{
    // ✅ පෙරහන් කළ මාසය ශීර්ෂයේ පෙන්වීමට නව ගුණාංගයක් (New property to show filtered month in header)
    public $filterDateString;

    // --- Custom Rounded Rectangle (optional visual element) ---
    function MyRoundedRect($x, $y, $w, $h, $r, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        if ($style == 'F')
            $op = 'f';
        elseif ($style == 'FD' || $style == 'DF')
            $op = 'B';
        else
            $op = 'S';

        $MyArc = 4 / 3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_Arc($xc + $r * $MyArc, $yc - $r, $xc + $r, $yc - $r * $MyArc, $xc + $r, $yc);

        $xc = $x + $w - $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc + $r, $yc + $r * $MyArc, $xc + $r * $MyArc, $yc + $r, $xc, $yc + $r);

        $xc = $x + $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_Arc($xc - $r * $MyArc, $yc + $r, $xc - $r, $yc + $r * $MyArc, $xc - $r, $yc);

        $xc = $x + $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_Arc($xc - $r, $yc - $r * $MyArc, $xc - $r * $MyArc, $yc - $r, $xc, $yc - $r);

        $this->_out($op);
    }

    function _Arc($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $this->k, ($h - $y1) * $this->k,
            $x2 * $this->k, ($h - $y2) * $this->k,
            $x3 * $this->k, ($h - $y3) * $this->k));
    }

    // --- Custom Header ---
    function Header()
    {
        // Draw borders
        $this->MyRoundedRect(7.5, 7.5, 195, 40, 1.5);
        $this->Rect(5, 5, 200, 287);

        // Logo
        $this->Image('../assets/logo.png', 12, 8, 25);
        $this->SetY(12);
        // Company details
        $this->SetFont('helvetica', 'B', 12);
        
        $this->Cell(0, 5, 'GP Garments (Pvt) Ltd', 0, 1, 'C');
        $this->SetFont('helvetica', '', 10);
        $this->Cell(0, 5, 'Seethawaka Export Processing Zone', 0, 1, 'C');
        $this->Cell(0, 5, 'Awissawella, Sri Lanka', 0, 1, 'C');
        $this->Ln(5);

        // Report Title
        $this->SetFont('helvetica', 'B', 15);
        $this->Cell(0, 5, 'Staff Transport Attendance Report', 0, 1, 'C');
        $this->Ln(1);

        // ✅ පෙරහන් කළ මාසය පෙන්වීම (Displaying the filtered month)
        $this->SetFont('helvetica', 'I', 10);
        $this->Cell(0, 5, 'For the month of ' . $this->filterDateString, 0, 1, 'C'); 
        
        // Important: Set the starting Y position for the main content
        $this->SetY(50);
    }
}


// Create PDF
$pdf = new PDF();
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('GP Garments');
$pdf->SetTitle('All Route Attendance Report');

// ✅ filterDateString සැකසීම (Setting filterDateString)
$pdf->filterDateString = $headerDateString;

$pdf->SetMargins(10, 55, 10);
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

// Fetch all routes
$routes_data = [];
$sql_routes = "SELECT route_code, route FROM route WHERE route_code LIKE '____F%' ORDER BY route_code ASC";
$result_routes = $conn->query($sql_routes);
while ($row = $result_routes->fetch_assoc()) {
    $routes_data[] = $row;
}
$result_routes->close();

$current_index = 0;

foreach ($routes_data as $route) {
    $current_index++;
    if ($current_index > 1) {
        $pdf->AddPage();
    }

    $current_route_code = $route['route_code'];
    $current_route_name = $route['route'];

    // Query trip details - දැන් මෙය පෙරහන් කළ $filterYear සහ $filterMonth භාවිත කරයි.
    $sql_details = "
        SELECT 
            DATE(date) AS trip_date,
            MAX(CASE WHEN shift = 'morning' THEN vehicle_no ELSE '' END) AS morning_vehicle,
            MAX(CASE WHEN shift = 'evening' THEN vehicle_no ELSE '' END) AS evening_vehicle
        FROM factory_transport_vehicle_register
        WHERE route = ? AND YEAR(date) = ? AND MONTH(date) = ? AND is_active = 1
        GROUP BY trip_date
        ORDER BY trip_date ASC
    ";

    $stmt_details = $conn->prepare($sql_details);
    // ✅ $filterYear සහ $filterMonth භාවිත කිරීම (Using $filterYear and $filterMonth)
    $stmt_details->bind_param('sii', $current_route_code, $filterYear, $filterMonth);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    $trip_details = $result_details->fetch_all(MYSQLI_ASSOC);
    $stmt_details->close();

    // Section Header
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(75, 0, 130);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(0, 8, $current_route_name . ' (' . $current_route_code . ') - Days Logged: ' . count($trip_details), 0, 1, 'L', 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);

    // Build HTML table
    $html = '<table border="1" cellspacing="0" cellpadding="4">
        <thead>
            <tr style="background-color:#f3f4f6;">
                <th width="15%">Date</th>
                <th width="10%">Day</th>
                <th width="37.5%">Morning Shift (Vehicle No.)</th>
                <th width="37.5%">Evening Shift (Vehicle No.)</th>
            </tr>
        </thead><tbody>';

    if (empty($trip_details)) {
        $html .= '<tr><td colspan="4" align="center">No trips recorded for this route in ' . $pdf->filterDateString . '</td></tr>';
    } else {
        foreach ($trip_details as $trip) {
            $day_name = date('D', strtotime($trip['trip_date']));
            $morning_vehicle = htmlspecialchars($trip['morning_vehicle']);
            $evening_vehicle = htmlspecialchars($trip['evening_vehicle']);

            $morning_style = $morning_vehicle ? 'color:#15803d;font-weight:bold;' : 'color:#dc2626;';
            $evening_style = $evening_vehicle ? 'color:#15803d;font-weight:bold;' : 'color:#dc2626;';
            $date_style = (strtoupper($day_name) === 'SUN' || strtoupper($day_name) === 'SAT') ? 'background-color:#ffe4e6;' : ''; // Highlight weekends

            $html .= '<tr style="' . $date_style . '">
                        <td width="15%">' . htmlspecialchars($trip['trip_date']) . '</td>
                        <td width="10%">' . htmlspecialchars($day_name) . '</td>
                        <td width="37.5%" style="' . $morning_style . '">' . ($morning_vehicle ?: 'N/A') . '</td>
                        <td width="37.5%" style="' . $evening_style . '">' . ($evening_vehicle ?: 'N/A') . '</td>
                    </tr>';
        }
    }

    $html .= '</tbody></table>';
    $pdf->writeHTML($html, true, false, true, false, '');
}

$conn->close();

// Output the PDF
$filename = 'Master_Trip_Report_' . $filterYear . $filterMonth . '.pdf';
$pdf->Output($filename, 'I'); // "I" = Inline view; use "D" to force download
?>