<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$user_role = $is_logged_in && isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';

include('../../includes/db.php'); // DB connection
include('../../includes/header.php');
include('../../includes/navbar.php');

$vehicles_inspections_summary = []; 
$search_supplier_name = '';
$inspection_data = null; 
$message = '';

// Criteria Mapping
$criteria_mapping = [
    'Revenue License' => 'revenue_license', 'Driver License' => 'driver_license', 'Insurance' => 'insurance',
    'Driver Data sheet' => 'driver_data_sheet', 'Driver NIC' => 'driver_nic', 'Break' => 'break',
    'Tires' => 'tires', 'Spare Wheel' => 'spare_wheel', 'Lights (Head/Signal/Break)' => 'lights',
    'Revers lights/ tones' => 'revers_lights', 'Horns' => 'horns', 'Windows and shutters' => 'windows',
    'Door locks' => 'door_locks', 'No oil leaks' => 'no_oil_leaks', 'No high smoke' => 'no_high_smoke',
    'Seat condition' => 'seat_condition', 'Seat Gap' => 'seat_gap', 'Body condition' => 'body_condition',
    'Roof leek' => 'roof_leek', 'Air Conditions' => 'air_conditions', 'Noise' => 'noise'
];

// Fetch Suppliers
$suppliers = [];
if (isset($conn)) {
    $sql_suppliers = "SELECT DISTINCT supplier_code FROM checkUp ORDER BY supplier_code ASC";
    $result_suppliers = $conn->query($sql_suppliers);
    if ($result_suppliers && $result_suppliers->num_rows > 0) {
        while ($row = $result_suppliers->fetch_assoc()) { $suppliers[] = $row['supplier_code']; }
    }
}

// Handle Search
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_supplier'])) {
    if (isset($_POST['search_supplier_name']) && !empty($_POST['search_supplier_name'])) {
        $search_supplier_name = $conn->real_escape_string($_POST['search_supplier_name']);
        
        $sql_vehicle_nos = "SELECT DISTINCT vehicle_no FROM checkUp WHERE supplier_code = ?";
        $stmt_vehicles = $conn->prepare($sql_vehicle_nos);
        
        if ($stmt_vehicles) {
            $stmt_vehicles->bind_param("s", $search_supplier_name);
            $stmt_vehicles->execute();
            $result_vehicle_nos = $stmt_vehicles->get_result();
            $stmt_vehicles->close();

            if ($result_vehicle_nos->num_rows > 0) {
                while ($row_vehicle = $result_vehicle_nos->fetch_assoc()) {
                    $vehicle_no = $row_vehicle['vehicle_no'];
                    
                    $sql_latest = "SELECT c.*, v.type FROM checkUp c INNER JOIN vehicle v ON c.vehicle_no = v.vehicle_no WHERE c.vehicle_no = ? AND c.supplier_code = ? ORDER BY c.date DESC, c.id DESC LIMIT 1";
                    $stmt_inspection = $conn->prepare($sql_latest);
                    
                    if ($stmt_inspection) {
                        $stmt_inspection->bind_param("ss", $vehicle_no, $search_supplier_name);
                        $stmt_inspection->execute();
                        $result_inspection = $stmt_inspection->get_result();
                        
                        if ($result_inspection->num_rows > 0) {
                            $latest_inspection = $result_inspection->fetch_assoc();
                            
                            $has_problems = false;
                            foreach ($criteria_mapping as $item => $col) {
                                if (isset($latest_inspection[$col . '_status']) && $latest_inspection[$col . '_status'] == 0) {
                                    $has_problems = true; break;
                                }
                            }
                            $v_type = $latest_inspection['type'] ?? '';
                            if (strcasecmp($v_type, 'bus') == 0 && isset($latest_inspection['vehicle_fitness_certificate_status']) && $latest_inspection['vehicle_fitness_certificate_status'] == 0) {
                                $has_problems = true;
                            }
                            $latest_inspection['has_problems'] = $has_problems;
                            $vehicles_inspections_summary[] = $latest_inspection;
                        }
                        $stmt_inspection->close();
                    }
                }
            } else {
                $message = "No vehicles found for Supplier: " . htmlspecialchars($search_supplier_name);
            }
        }
    } else {
        $message = "Please enter a Supplier Name to search.";
    }
}
elseif (isset($_GET['view_vehicle_no']) && !empty($_GET['view_vehicle_no'])) {
    $search_vehicle_no_direct = $conn->real_escape_string($_GET['view_vehicle_no']);
    $search_supplier_name = isset($_GET['search_supplier_name']) ? htmlspecialchars($_GET['search_supplier_name']) : '';

    $sql = "SELECT c.*, s.supplier, v.type FROM checkUp AS c INNER JOIN supplier AS s ON c.supplier_code = s.supplier_code INNER JOIN vehicle AS v ON c.vehicle_no = v.vehicle_no WHERE c.vehicle_no = ? ORDER BY c.date DESC, c.id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $search_vehicle_no_direct);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $inspection_data = $result->fetch_assoc();
            
            $has_problems_for_detail = false;
            foreach ($criteria_mapping as $item => $col) {
                if (isset($inspection_data[$col . '_status']) && $inspection_data[$col . '_status'] == 0) {
                    $has_problems_for_detail = true; break;
                }
            }
            $v_type = $inspection_data['type'] ?? '';
            if (strcasecmp($v_type, 'bus') == 0 && isset($inspection_data['vehicle_fitness_certificate_status']) && $inspection_data['vehicle_fitness_certificate_status'] == 0) {
                $has_problems_for_detail = true;
            }
            $inspection_data['has_problems'] = $has_problems_for_detail; 
        } else {
            $message = "No detailed inspection found for Vehicle No.: " . htmlspecialchars($search_vehicle_no_direct);
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Inspections</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
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
                View
            </span>
        </div>
    </div>
    
    <div class="flex items-center gap-6 text-sm font-medium">
        <?php if ($user_role === 'admin' || $user_role === 'super admin' || $user_role === 'developer'): ?>
            <a href="checkUp_category.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
                <i class="fas fa-plus-circle"></i> Add New
            </a>
            <a href="edit_inspection.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
                <i class="fas fa-edit"></i> Edit
            </a>
        <?php endif; ?>
        
        <span class="text-gray-600 text-lg font-thin">|</span>

        <a href="generate_report_checkup.php" class="flex items-center gap-2 bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            <i class="fas fa-file-alt"></i> Report
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-20 p-6 min-h-screen flex flex-col items-center">
    
    <div class="w-full max-w-6xl">
        
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-search text-blue-600"></i> Search by Supplier
            </h2>
            
            <form action="" method="post" class="flex flex-col md:flex-row items-center gap-4">
                <div class="relative flex-grow w-full">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-building text-gray-400"></i>
                    </div>
                    <select id="search_supplier_name" name="search_supplier_name" class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition appearance-none bg-white cursor-pointer" required>
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo ($supplier == $search_supplier_name) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                        <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                    </div>
                </div>
                <button type="submit" name="search_supplier" class="w-full md:w-auto px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg shadow-md transition transform hover:scale-[1.02] flex items-center justify-center gap-2">
                    Search
                </button>
            </form>

            <?php if (!empty($message)): ?>
                <div class="mt-4 p-3 bg-red-50 text-red-600 border border-red-200 rounded-lg text-sm text-center font-medium">
                    <i class="fas fa-exclamation-circle mr-1"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($vehicles_inspections_summary)): ?>
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-2xl font-bold text-gray-800">Results for <span class="text-blue-600"><?php echo htmlspecialchars($search_supplier_name); ?></span></h3>
                <a href="export_inspections.php?supplier_name=<?php echo urlencode($search_supplier_name); ?>" 
                   class="inline-flex items-center px-4 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 transition transform hover:scale-[1.02]">
                    <i class="fas fa-file-excel mr-2"></i> Excel
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
                <?php foreach ($vehicles_inspections_summary as $vehicle_summary): 
                    $has_issues = $vehicle_summary['has_problems'];
                    $border_color = $has_issues ? 'border-red-500' : 'border-green-500';
                    $status_color = $has_issues ? 'text-red-600' : 'text-green-600';
                    $status_icon = $has_issues ? '<i class="fas fa-exclamation-triangle mr-1"></i> Issues Found' : '<i class="fas fa-check-circle mr-1"></i> All Good';
                ?>
                    <div class="bg-white rounded-xl shadow-md p-6 flex flex-col justify-between border-l-4 <?php echo $border_color; ?> hover:shadow-lg transition">
                        <div>
                            <div class="flex justify-between items-start mb-3">
                                <h4 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($vehicle_summary['vehicle_no']); ?></h4>
                                <span class="text-xs font-bold px-2 py-1 rounded bg-gray-100 text-gray-600"><?php echo htmlspecialchars($vehicle_summary['type']); ?></span>
                            </div>
                            <div class="text-sm text-gray-600 space-y-1 mb-4">
                                <p><i class="fas fa-route mr-2 text-gray-400"></i> <?php echo htmlspecialchars($vehicle_summary['route']); ?></p>
                                <p><i class="far fa-calendar-alt mr-2 text-gray-400"></i> <?php echo htmlspecialchars($vehicle_summary['date']); ?></p>
                            </div>
                            <div class="font-bold text-sm <?php echo $status_color; ?> bg-opacity-10 p-2 rounded <?php echo $has_issues ? 'bg-red-50' : 'bg-green-50'; ?>">
                                <?php echo $status_icon; ?>
                            </div>
                        </div>
                        <div class="mt-5 pt-4 border-t border-gray-100">
                            <a href="?view_vehicle_no=<?php echo htmlspecialchars($vehicle_summary['vehicle_no']); ?>&search_supplier_name=<?php echo htmlspecialchars($search_supplier_name); ?>" 
                               class="block w-full text-center py-2 bg-blue-50 text-blue-600 font-semibold rounded-lg hover:bg-blue-100 transition">
                                View Details
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($inspection_data) && $inspection_data): ?>
            
            <div id="detailView" class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden mb-10">
                <div class="px-8 py-6 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="bg-indigo-100 text-indigo-600 p-2 rounded-full shadow-sm">
                            <i class="fas fa-clipboard-list text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-gray-800">Detailed Inspection</h2>
                            <p class="text-xs text-gray-500 font-mono">Vehicle: <?php echo htmlspecialchars($inspection_data['vehicle_no']); ?></p>
                        </div>
                    </div>
                    <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded border border-blue-200">ID: <?php echo htmlspecialchars($inspection_data['id']); ?></span>
                </div>

                <div class="p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Supplier</label>
                            <div class="p-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-800 font-medium">
                                <?php echo htmlspecialchars($inspection_data['supplier'] ?? ''); ?>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Route Name</label>
                            <div class="p-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-800 font-medium">
                                <?php echo htmlspecialchars($inspection_data['route']); ?>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Transport Type</label>
                            <div class="p-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-800 font-medium">
                                <?php echo htmlspecialchars($inspection_data['transport_type']); ?>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Inspector</label>
                            <div class="p-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-800 font-medium">
                                <?php echo htmlspecialchars($inspection_data['inspector']); ?>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Inspection Date</label>
                            <div class="p-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-800 font-medium">
                                <?php echo htmlspecialchars($inspection_data['date']); ?>
                            </div>
                        </div>
                    </div>

                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b border-gray-200 pb-2">Inspection Results</h3>
                    
                    <?php if ($inspection_data['has_problems']): ?>
                        <div class="overflow-hidden border border-gray-200 rounded-lg mb-6">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Criteria</th>
                                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider w-24">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Remark</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-100 text-sm">
                                    <?php
                                    foreach ($criteria_mapping as $item => $col) {
                                        $status = $inspection_data[$col . '_status'];
                                        $remark = $inspection_data[$col . '_remark'];
                                        
                                        if ($status == 0 || !empty($remark)) {
                                            $status_badge = $status == 1 
                                                ? '<span class="px-2 py-1 rounded-full bg-green-100 text-green-800 text-xs font-bold">Pass</span>'
                                                : '<span class="px-2 py-1 rounded-full bg-red-100 text-red-800 text-xs font-bold">Fail</span>';
                                            
                                            echo "<tr class='hover:bg-gray-50'>";
                                            echo "<td class='px-6 py-3 font-medium text-gray-700'>{$item}</td>";
                                            echo "<td class='px-6 py-3 text-center'>{$status_badge}</td>";
                                            echo "<td class='px-6 py-3 text-gray-600'>" . (!empty($remark) ? htmlspecialchars($remark) : '-') . "</td>";
                                            echo "</tr>";
                                        }
                                    }
                                    
                                    // Fitness Cert
                                    $v_type = $inspection_data['type'] ?? '';
                                    if (strcasecmp($v_type, 'bus') == 0) {
                                        $fit_status = $inspection_data['vehicle_fitness_certificate_status'];
                                        $fit_remark = $inspection_data['vehicle_fitness_certificate_remark'];
                                        $fit_badge = $fit_status == 1 
                                            ? '<span class="px-2 py-1 rounded-full bg-green-100 text-green-800 text-xs font-bold">Pass</span>'
                                            : '<span class="px-2 py-1 rounded-full bg-red-100 text-red-800 text-xs font-bold">Fail</span>';
                                        
                                        echo "<tr class='bg-indigo-50/30 border-l-4 border-indigo-500'>";
                                        echo "<td class='px-6 py-3 font-bold text-indigo-800'>Vehicle Fitness Certificate</td>";
                                        echo "<td class='px-6 py-3 text-center'>{$fit_badge}</td>";
                                        echo "<td class='px-6 py-3 text-gray-600'>" . (!empty($fit_remark) ? htmlspecialchars($fit_remark) : '-') . "</td>";
                                        echo "</tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <h4 class="text-sm font-bold text-gray-700 mb-2">Other Observations</h4>
                            <p class="text-sm text-gray-600 italic">
                                <?php echo !empty($inspection_data['other_observations']) ? htmlspecialchars($inspection_data['other_observations']) : 'None recorded.'; ?>
                            </p>
                        </div>

                    <?php else: ?>
                        <div class="p-8 text-center bg-green-50 border border-green-200 rounded-xl">
                            <div class="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">
                                <i class="fas fa-check"></i>
                            </div>
                            <h3 class="text-lg font-bold text-green-800">Excellent Condition</h3>
                            <p class="text-green-700 mt-1">This vehicle passed all inspection criteria with no issues.</p>
                            <?php if (!empty($inspection_data['other_observations'])): ?>
                                <div class="mt-4 pt-4 border-t border-green-200">
                                    <p class="text-sm font-bold text-green-800">Note:</p>
                                    <p class="text-sm text-green-700 italic"><?php echo htmlspecialchars($inspection_data['other_observations']); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="text-center mt-8 pt-6 border-t border-gray-100">
                        <a href="#top" class="text-indigo-600 hover:text-indigo-800 font-medium text-sm transition">
                            <i class="fas fa-arrow-up mr-1"></i> Back to Top
                        </a>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>
</div>

</body>
</html>