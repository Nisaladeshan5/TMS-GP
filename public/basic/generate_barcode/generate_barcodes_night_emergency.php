<?php
require_once('../../../tcpdf/tcpdf.php');

// Check for the 'suppliers' POST variable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['suppliers'])) {
    // Change variable name to 'suppliers'
    $suppliers = $_POST['suppliers'];

    // Hide warnings to avoid breaking PDF headers
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Disable default header and footer (removes top line)
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('TMS System');
    // Updated PDF title
    $pdf->SetTitle('Supplier Barcodes');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();

    $pdf->SetFont('helvetica', '', 10);

    // Layout settings
    $x = 15;      // left margin
    $y = 15;      // top margin
    $width = 55;  // box width
    $height = 35; // box height
    $gapX = 10;   // horizontal gap
    $gapY = 12;   // vertical gap
    $perRow = 3;  // only 3 per row
    $count = 0;

    // Change foreach loop to iterate over 'suppliers'
    foreach ($suppliers as $supplierCode) {
        // Border box with light gray fill
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.4);
        $pdf->RoundedRect($x, $y, $width, $height, 2, 'DF');

        // Centered barcode inside box using the supplier code
        $pdf->write1DBarcode(
            $supplierCode,
            'C128',
            $x + 5,      // left padding inside box
            $y + 5,      // top padding
            $width - 10, // barcode width inside box
            15,          // barcode height
            0.4,
            [
                'position' => '',
                'align' => 'C',
                'stretch' => false,
                'fitwidth' => true,
                'cellfitalign' => '',
                'border' => false,
                'hpadding' => 'auto',
                'vpadding' => 'auto',
                'fgcolor' => [0,0,0],
                'bgcolor' => false,
                'text' => false
            ],
            'N'
        );

        // Supplier code centered under barcode
        $pdf->SetXY($x, $y + 22);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell($width, 8, $supplierCode, 0, 0, 'C');

        // Move cursor
        $x += $width + $gapX;
        $count++;

        // New row after 3 barcodes
        if ($count % $perRow == 0) {
            $x = 15;
            $y += $height + $gapY;

            // New page if no space left
            if ($y + $height > $pdf->getPageHeight() - 15) {
                $pdf->AddPage();
                $y = 15;
            }
        }
    }
    // Update the output filename
    $pdf->Output('supplier_barcodes.pdf', 'I');
} else {
    // Update the error message
    echo "No suppliers selected.";
}