<?php
error_reporting(0);
ini_set('display_errors', 0);

require '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['eml_file'])) {
    if (ob_get_length()) ob_end_clean();

    $fileTmpPath = $_FILES['eml_file']['tmp_name'];
    $rawContent = file_get_contents($fileTmpPath);
    $content = quoted_printable_decode($rawContent);

    preg_match("/<table.*?>.*?<\/table>/si", $content, $matches);
    $tableHtml = $matches[0] ?? '';

    if (!empty($tableHtml)) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $tableHtml);
        $rows = $dom->getElementsByTagName('tr');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $excelRow = 1;
        $startProcessing = false;

        foreach ($rows as $row) {
            $cols = $row->getElementsByTagName('td');
            $tempRowData = [];
            $hasAnyDataInRow = false; // පේළියේ දත්ත තිබේදැයි බැලීමට

            foreach ($cols as $col) {
                $val = trim($col->nodeValue);
                $val = str_replace("\xc2\xa0", '', $val); 
                
                if ($val !== '' && $val !== '-') {
                    $hasAnyDataInRow = true;
                }
                $tempRowData[] = $val;

                if (stripos($val, 'User name') !== false) {
                    $startProcessing = true;
                }
            }

            // පේළිය හිස් නම් එය සම්පූර්ණයෙන්ම මඟ හරින්න
            if ($startProcessing && $hasAnyDataInRow) {
                $excelCol = 1;
                $skipFirst = true; 
                $numCols = count($tempRowData);

                foreach ($tempRowData as $index => $value) {
                    if ($skipFirst) {
                        $skipFirst = false;
                        continue;
                    }

                    // අවසාන Column එකේ හිස් සෛල 0 කිරීම
                    if ($index == ($numCols - 1)) {
                        $value = trim($value);
                        if ($value === '' || $value === '-') {
                            $value = '0';
                        }
                    }

                    $numericVal = str_replace([' ', ','], '', $value);
                    
                    if (is_numeric($numericVal) && !empty($numericVal)) {
                        $sheet->setCellValueByColumnAndRow($excelCol, $excelRow, (float)$numericVal);
                        
                        // Decimal formatting
                        if (strpos($value, '.') !== false || $index >= ($numCols - 2)) {
                            $sheet->getStyleByColumnAndRow($excelCol, $excelRow)
                                  ->getNumberFormat()->setFormatCode('#,##0.00');
                        }
                    } else {
                        $sheet->setCellValueByColumnAndRow($excelCol, $excelRow, $value);
                    }
                    $excelCol++;
                }
                $excelRow++;
            }
        }

        foreach (range('A', $sheet->getHighestColumn()) as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        $tempFile = 'Clean_Fuel_Report_' . time() . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        if (file_exists($tempFile)) {
            if (ob_get_length()) ob_end_clean();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="Fuel_Report_No_Blanks.xlsx"');
            readfile($tempFile);
            unlink($tempFile);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <title>Fuel Table Converter</title>
    <style>
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background: #eceff1; }
        .box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center; }
        button { background: #1a73e8; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Cleaned EML to Excel</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="eml_file" accept=".eml" required><br><br>
            <button type="submit">Download Cleaned Excel</button>
        </form>
    </div>
</body>
</html>