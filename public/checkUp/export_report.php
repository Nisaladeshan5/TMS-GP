<?php
// export_report.php

// 1. DATABASE CONNECTION
// Ensure this path is correct relative to where you save this file
include('../../includes/db.php'); 

date_default_timezone_set('Asia/Colombo');

if (!isset($conn)) {
    // Basic error handling if the connection fails
    die("Error: Database connection failed.");
}

// 2. SET CSV HEADERS
// This tells the browser to download the file as a CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Defective_Vehicle_Report_' . date('Ymd') . '.csv"');

// Open output stream for writing CSV data
$output = fopen('php://output', 'w'); 

// Write CSV Column Headings (These are the headers the user will see in Excel)
fputcsv($output, ['Vehicle #', 'Type', 'Supplier Code (Name)', 'Inspection Date', 'Defective Item', 'Remark/Reason', '6-Month Flag']);

// 3. GET DATA (Using the exact same logic as the display report)
$sql = "
    SELECT
        t1.*,
        v.type AS vehicle_type,
        s.supplier AS supplier_name, -- Corrected to s.supplier
        CASE
            WHEN t1.date < DATE_SUB(NOW(), INTERVAL 6 MONTH) THEN 'OLDER THAN 6 MONTHS'
            ELSE 'WITHIN 6 MONTHS'
        END AS fitness_flag
    FROM
        checkup t1
    INNER JOIN (
        SELECT
            vehicle_no,
            MAX(date) AS max_date
        FROM
            checkup
        GROUP BY
            vehicle_no
    ) t2 ON t1.vehicle_no = t2.vehicle_no AND t1.date = t2.max_date
    LEFT JOIN
        vehicle v ON t1.vehicle_no = v.vehicle_no 
    LEFT JOIN
        supplier s ON t1.supplier_code = s.supplier_code 
    ORDER BY
        t1.supplier_code, t1.vehicle_no, t1.date DESC; 
";

if ($result = $conn->query($sql)) {
    
    // Dynamic Data Processing (Extracting ONLY the Defective Items)
    while ($row = $result->fetch_assoc()) {
        $defective_items = [];
        
        $current_vehicle_type = isset($row['vehicle_type']) ? strtolower($row['vehicle_type']) : null; 
        $is_bus = ($current_vehicle_type === 'bus');
        
        $fitness_status_key = 'vehicle_fitness_certificate_status';
        $fitness_remark_key = 'vehicle_fitness_certificate_remark';

        // --- CORE LOGIC (Filtering) ---
        
        if ($is_bus) {
            // Bus logic: Only report if fitness certificate is NOT OK (0)
            if (isset($row[$fitness_status_key]) && $row[$fitness_status_key] == 0) {
                $defective_items[] = [
                    'item' => 'Vehcile Fitness Certificate',
                    'remark' => $row[$fitness_remark_key]
                ];
            } else {
                continue; 
            }
        } else {
            // Non-Bus logic: Check all other defects, skipping fitness certificate
            foreach ($row as $key => $value) {
                if (str_ends_with($key, '_status')) {
                    if ($key === $fitness_status_key) {
                        continue; 
                    }
                    
                    $base_name = str_replace('_status', '', $key);
                    $remark_key = $base_name . '_remark';
                    
                    if ($value == 0 && isset($row[$remark_key])) {
                        $defective_items[] = [
                            'item' => ucwords(str_replace('_', ' ', $base_name)),
                            'remark' => $row[$remark_key]
                        ];
                    }
                }
            }
        }
        // --- END CORE LOGIC ---

        // 4. WRITE DATA TO CSV
        // For each defect found, write a new row to the CSV output
        if (!empty($defective_items)) {
            // Construct the Supplier Display string
            $supplier_display = $row['supplier_code'] . ' (' . ($row['supplier_name'] ?? 'N/A') . ')';

            foreach ($defective_items as $defect) {
                // Write the filtered data row
                fputcsv($output, [
                    $row['vehicle_no'],
                    ucwords($current_vehicle_type),
                    $supplier_display,
                    $row['date'],
                    $defect['item'],
                    $defect['remark'],
                    $row['fitness_flag']
                ]);
            }
        }
    }
}

// 5. CLOSE AND EXIT
fclose($output);
exit();
?>