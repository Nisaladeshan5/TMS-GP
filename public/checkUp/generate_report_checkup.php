<?php
require_once '../../includes/session_check.php';
// generate_report_checkup.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in
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

// --- Data Processing Logic ---
if (isset($conn)) {
    $report_generated = true;
    
    $sql = "
        SELECT t1.*, v.type AS vehicle_type, s.supplier AS supplier_name, 
        CASE WHEN t1.date < DATE_SUB(NOW(), INTERVAL 6 MONTH) THEN 'OLDER' ELSE 'VALID' END AS fitness_status_code,
        CASE WHEN t1.date < DATE_SUB(NOW(), INTERVAL 6 MONTH) THEN '🔴 OLDER THAN 6 MONTHS' ELSE '🟢 WITHIN 6 MONTHS' END AS fitness_flag
        FROM checkUp t1
        INNER JOIN (SELECT vehicle_no, MAX(date) AS max_date FROM checkUp GROUP BY vehicle_no) t2 
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

            // Core Logic for filtering defects
            if ($is_bus) {
                if (isset($row[$fitness_status_key]) && $row[$fitness_status_key] == 0) {
                    $defective_items[] = ['item' => 'Vehicle Fitness Certificate', 'remark' => $row[$fitness_remark_key]];
                } 
                // Note: If bus has fitness cert issue, we capture it. 
                // Original logic seemed to 'continue' if bus fitness was OK, skipping other checks?
                // Assuming original logic meant: If Bus, ONLY check fitness cert? 
                // Or Check everything? Keeping your original flow:
                else { 
                    // If Bus and fitness OK, do we check other things? 
                    // Your original code had `else { continue; }` which implies Buses are ONLY checked for fitness cert here.
                    continue; 
                }
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

            $common_data = [
                'supplier_display' => $row['supplier_code'] . ' <br><span class="text-xs text-gray-500 font-normal">' . htmlspecialchars($row['supplier_name'] ?? 'N/A') . '</span>',
                'vehicle_no' => $row['vehicle_no'],
                'date' => $row['date'],
                'fitness_flag' => $row['fitness_flag'],
                'is_old' => ($row['fitness_status_code'] === 'OLDER'),
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defective Vehicle Report</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .old-flag-row { background-color: #fee2e2; } /* Red-100 */
    </style>
    
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 
        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>

<body class="bg-gray-100">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="checkUp_category.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Vehicle Inspection
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Report
            </span>
        </div>
    </div>
    
    <div class="flex items-center gap-6 text-sm font-medium">
        <?php 
        $allowed_roles = ['admin', 'super admin', 'developer'];
        // Note: Check roles carefully. 'superadmin' vs 'super admin'
        if (in_array($user_role, $allowed_roles) || $user_role === 'viewer') {
        ?>
            <a href="checkUp_category.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
                <i class="fas fa-plus-circle"></i> Add
            </a>
            <a href="edit_inspection.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
                <i class="fas fa-edit"></i> Edit
            </a>
            <a href="view_supplier.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
                <i class="fas fa-users"></i> Suppliers
            </a>
        <?php } ?>
        
        <span class="text-gray-600 text-lg font-thin">|</span>

        <span class="flex items-center gap-2 text-yellow-400 font-bold px-3 py-1.5 border border-yellow-500 rounded-md bg-yellow-500 bg-opacity-10">
            <i class="fas fa-chart-bar"></i> Report
        </span>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex flex-col items-center">
    
    <div class="w-full max-w-7xl">
        
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            
            <div class="px-8 py-6 border-b border-gray-100 bg-gray-50 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle text-red-500"></i> Current Defects
                    </h2>
                    <p class="text-xs text-gray-500 mt-1">Based on the latest inspection record for each vehicle.</p>
                </div>
                
                <?php if ($report_generated && empty($error_message) && !empty($processed_report_data)): ?>
                <div class="flex gap-3">
                    <a href="export_report.php" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-lg shadow transition transform hover:scale-105">
                        <i class="fas fa-file-excel mr-2"></i> Excel
                    </a>
                    <a href="export_pdf_report.php" class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-bold rounded-lg shadow transition transform hover:scale-105">
                        <i class="fas fa-file-pdf mr-2"></i> PDF
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <div class="p-0">
                <?php if (!empty($error_message)): ?>
                    <div class="m-8 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 rounded shadow-sm">
                        <div class="flex items-center">
                            <i class="fas fa-bug text-xl mr-3"></i>
                            <div>
                                <p class="font-bold">System Error</p>
                                <p class="text-sm"><?php echo htmlspecialchars($error_message); ?></p>
                            </div>
                        </div>
                    </div>
                <?php elseif (empty($processed_report_data)): ?>
                    <div class="flex flex-col items-center justify-center py-20 text-center">
                        <div class="bg-green-100 text-green-600 w-20 h-20 rounded-full flex items-center justify-center mb-4 text-4xl shadow-sm">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800">Excellent!</h3>
                        <p class="text-gray-500 mt-2 max-w-md">No defective vehicles found based on the latest inspection records.</p>
                    </div>
                <?php else: ?>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm text-left">
                            <thead class="bg-blue-600 text-white uppercase text-xs tracking-wider sticky top-0 z-10 shadow-sm">
                                <tr>
                                    <th class="px-6 py-4 font-semibold border-b border-blue-500 w-1/12">Vehicle #</th>
                                    <th class="px-6 py-4 font-semibold border-b border-blue-500 w-1/12">Type</th> 
                                    <th class="px-6 py-4 font-semibold border-b border-blue-500 w-2/12">Supplier</th>
                                    <th class="px-6 py-4 font-semibold border-b border-blue-500 w-1/12">Insp. Date</th>
                                    <th class="px-6 py-4 font-semibold border-b border-blue-500 w-2/12">Defect</th>
                                    <th class="px-6 py-4 font-semibold border-b border-blue-500 w-3/12">Remark</th>
                                    <th class="px-6 py-4 font-semibold border-b border-blue-500 w-2/12 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <?php 
                                foreach ($processed_report_data as $record): 
                                    $common = $record['common'];
                                    $defects = $record['defects'];
                                    $rowspan = count($defects);
                                    
                                    // Highlight if old flag is present
                                    $bg_class = $common['is_old'] ? 'bg-red-50' : 'hover:bg-gray-50';
                                    $border_class = $common['is_old'] ? 'border-red-200' : 'border-gray-200';
                                ?>
                                    
                                    <?php for ($i = 0; $i < $rowspan; $i++): ?>
                                        <tr class="<?php echo $bg_class; ?> transition duration-150">
                                            <?php if ($i === 0): ?>
                                                <td rowspan="<?php echo $rowspan; ?>" class="px-6 py-4 font-bold text-gray-900 border-r <?php echo $border_class; ?> align-top">
                                                    <?php echo htmlspecialchars($common['vehicle_no']); ?>
                                                </td>
                                                <td rowspan="<?php echo $rowspan; ?>" class="px-6 py-4 text-gray-600 border-r <?php echo $border_class; ?> align-top">
                                                    <?php echo htmlspecialchars(ucwords($common['vehicle_type'])); ?>
                                                </td> 
                                                <td rowspan="<?php echo $rowspan; ?>" class="px-6 py-4 text-gray-800 font-medium border-r <?php echo $border_class; ?> align-top leading-tight">
                                                    <?php echo $common['supplier_display']; ?> 
                                                </td>
                                                <td rowspan="<?php echo $rowspan; ?>" class="px-6 py-4 text-gray-600 border-r <?php echo $border_class; ?> align-top font-mono text-xs">
                                                    <?php echo htmlspecialchars($common['date']); ?>
                                                </td>
                                            <?php endif; ?>
                                            
                                            <td class="px-6 py-3 font-semibold text-red-600 align-top">
                                                <i class="fas fa-times-circle mr-1 text-xs"></i> <?php echo htmlspecialchars($defects[$i]['item']); ?>
                                            </td>
                                            <td class="px-6 py-3 text-gray-700 italic align-top">
                                                <?php echo htmlspecialchars($defects[$i]['remark']); ?>
                                            </td>
                                            
                                            <?php if ($i === 0): ?>
                                                <td rowspan="<?php echo $rowspan; ?>" class="px-6 py-4 text-center align-top border-l <?php echo $border_class; ?>">
                                                    <?php if ($common['is_old']): ?>
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                                            Older > 6 Mo
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                                                            Valid
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endfor; ?>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 text-right text-xs text-gray-500">
                        Total Vehicles with Issues: <strong><?php echo count($processed_report_data); ?></strong>
                    </div>

                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

</body>
</html>