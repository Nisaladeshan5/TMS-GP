<?php
require_once '../../includes/session_check.php';
// generate_report_checkup.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

include('../../includes/db.php'); 
include('../../includes/header.php'); 
include('../../includes/navbar.php'); 

// --- Initialize variables ---
$processed_report_data = []; 
$report_generated = false;
$error_message = '';

// ... (Your existing SQL Query and Data Processing Logic - NO CHANGE NEEDED HERE) ...
// (ඉහත කේතයේ තිබූ SQL query එක සහ while loop එක එලෙසම තිබිය යුතුය.)

// --- Data Processing Logic (Re-pasting for completeness, but assuming it works) ---
if (isset($conn)) {
    $report_generated = true;
    
    // Define the full SQL query (using s.supplier as supplier_name)
    $sql = "
        SELECT t1.*, v.type AS vehicle_type, s.supplier AS supplier_name, 
        CASE WHEN t1.date < DATE_SUB(NOW(), INTERVAL 6 MONTH) THEN '🔴 OLDER THAN 6 MONTHS' ELSE '🟢 WITHIN 6 MONTHS' END AS fitness_flag
        FROM checkup t1
        INNER JOIN (SELECT vehicle_no, MAX(date) AS max_date FROM checkup GROUP BY vehicle_no) t2 
            ON t1.vehicle_no = t2.vehicle_no AND t1.date = t2.max_date
        LEFT JOIN vehicle v ON t1.vehicle_no = v.vehicle_no 
        LEFT JOIN supplier s ON t1.supplier_code = s.supplier_code             
        ORDER BY t1.supplier_code, t1.vehicle_no, t1.date DESC; 
    ";

    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $defective_items = [];
            $current_vehicle_type = isset($row['vehicle_type']) ? strtolower($row['vehicle_type']) : null; 
            $is_bus = ($current_vehicle_type === 'bus');
            $fitness_status_key = 'vehicle_fitness_certificate_status';
            $fitness_remark_key = 'vehicle_fitness_certificate_remark';

            // Core Logic for filtering defects (Bus vs Non-Bus)
            if ($is_bus) {
                if (isset($row[$fitness_status_key]) && $row[$fitness_status_key] == 0) {
                    $defective_items[] = ['item' => 'Vehcile Fitness Certificate', 'remark' => $row[$fitness_remark_key]];
                } else { continue; }
            } else {
                foreach ($row as $key => $value) {
                    if (str_ends_with($key, '_status') && $key !== $fitness_status_key) {
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

            // Common vehicle/inspection data
            $common_data = [
                'supplier_display' => $row['supplier_code'] . ' (' . htmlspecialchars($row['supplier_name'] ?? 'N/A') . ')',
                'vehicle_no' => $row['vehicle_no'],
                'date' => $row['date'],
                'fitness_flag' => $row['fitness_flag'],
                'vehicle_type' => $current_vehicle_type 
            ];
            
            if (!empty($defective_items)) {
                $processed_report_data[] = [
                    'common' => $common_data,
                    'defects' => $defective_items
                ];
            }
        }
    } else {
        $error_message = "Query execution failed: " . $conn->error;
        $report_generated = false;
    }
} else {
    $error_message = "Database connection (\$conn) not established.";
}
// --- End Data Processing Logic ---

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defective Vehicle Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .old-flag-row { background-color: #fce7e7; font-weight: bold; }
        .report-table th, .report-table td { padding: 8px 16px; }
        /* PDF printing සඳහා table එකට පමණක් වෙනම ID එකක් දෙමු */
        #report-content-to-print { border-collapse: collapse; width: 100%; }
    </style>
</head>
<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%] mb-6">
        <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg ">
            <div class="text-lg font-semibold ml-3">Defective Vehicle Report</div>
            <div class="flex gap-4">
                <?php
                if ($user_role === 'admin' || $user_role === 'superadmin' || $user_role === 'viewer') {
                ?>
                <a href="checkUp_category.php" class="hover:text-yellow-600">Add Inspection</a>
                <a href="edit_inspection.php" class="hover:text-yellow-600">Edit Inspection</a>
                <a href="view_supplier.php" class="hover:text-yellow-600">View Supplier</a>
                 <?php
                }
                ?>
                <a href="generate_report_checkup.php" class="text-yellow-600">Report</a>
            </div>
        </div>

        <div class="container mx-auto p-6 bg-white shadow-lg rounded-lg mt-6 max-w-7xl">
            <h2 class="text-3xl font-bold text-center mb-6 text-gray-800">Defective Vehicle Report (Latest Inspections)</h2>

            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <strong class="font-bold">Error!</strong>
                    <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($report_generated && empty($error_message)): ?>
                
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-700">
                        Currently Defective Items (Based on Latest Inspection for Each Vehicle)
                    </h3>
                    <div class="flex gap-2">
                        <a href="export_report.php" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded shadow-md">
                            📥 Excel
                        </a>
                        <a href="export_pdf_report.php" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded shadow-md">
                            🖨️ PDF
                        </a>
                    </div>
                </div>

                <?php if (empty($processed_report_data)): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4" role="alert">
                        <p class="font-bold">All OK! 🎉</p>
                        <p>The latest inspection for every vehicle meets the required fitness standard.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-md">
                        <table id="report-content-to-print" class="min-w-full divide-y divide-gray-200 report-table">
                            <thead class="bg-red-100">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Vehicle #</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Type</th> 
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Supplier</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Inspection Date</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Defective Item</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Remark/Reason</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">6-Month Flag</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                foreach ($processed_report_data as $record): 
                                    $common = $record['common'];
                                    $defects = $record['defects'];
                                    $rowspan = count($defects);
                                    
                                    $row_class = ($common['fitness_flag'] == '🔴 OLDER THAN 6 MONTHS') ? 'old-flag-row' : 'hover:bg-gray-50';
                                ?>
                                    
                                    <?php for ($i = 0; $i < $rowspan; $i++): ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <?php if ($i === 0): ?>
                                                <td rowspan="<?php echo $rowspan; ?>" class="px-4 py-2 whitespace-nowrap text-sm font-bold text-gray-900 border-r border-gray-200"><?php echo htmlspecialchars($common['vehicle_no']); ?></td>
                                                <td rowspan="<?php echo $rowspan; ?>" class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 border-r border-gray-200"><?php echo htmlspecialchars(ucwords($common['vehicle_type'])); ?></td> 
                                                
                                                <td rowspan="<?php echo $rowspan; ?>" class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 border-r border-gray-200 font-medium">
                                                    <?php echo $common['supplier_display']; ?> 
                                                </td>
                                                
                                                <td rowspan="<?php echo $rowspan; ?>" class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 border-r border-gray-200"><?php echo htmlspecialchars($common['date']); ?></td>
                                            <?php endif; ?>
                                            
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-red-700 font-semibold"><?php echo htmlspecialchars($defects[$i]['item']); ?></td>
                                            <td class="px-4 py-2 text-sm text-gray-900"><?php echo htmlspecialchars($defects[$i]['remark']); ?></td>
                                            
                                            <?php if ($i === 0): ?>
                                                <td rowspan="<?php echo $rowspan; ?>" class="px-4 py-2 whitespace-nowrap text-sm font-bold text-gray-900 border-l border-gray-200"><?php echo htmlspecialchars($common['fitness_flag']); ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endfor; ?>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>
</body>
</html>