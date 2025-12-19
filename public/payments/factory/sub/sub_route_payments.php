<?php
// sub_route_payments.php (Full Updated Code with View History & Filters)
require_once '../../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../../includes/login.php");
    exit();
}

include('../../../../includes/db.php');

// =======================================================================
// 1. DYNAMIC DATE FILTER LOGIC
// =======================================================================

$max_payments_sql = "SELECT MAX(month) AS max_month, MAX(year) AS max_year FROM monthly_payments_sub"; 
$max_payments_result = $conn->query($max_payments_sql);

$db_max_month = 0; $db_max_year = 0;
if ($max_payments_result && $max_payments_result->num_rows > 0) {
    $max_data = $max_payments_result->fetch_assoc();
    $db_max_month = (int)($max_data['max_month'] ?? 0);
    $db_max_year = (int)($max_data['max_year'] ?? 0);
}

// Set Start Date
$start_month = 0; $start_year = 0;
if ($db_max_month === 0 && $db_max_year === 0) {
    $start_month = (int)date('n'); $start_year = (int)date('Y');
} elseif ($db_max_month == 12) {
    $start_month = 1; $start_year = $db_max_year + 1; 
} else {
    $start_month = $db_max_month + 1; $start_year = $db_max_year;
}

// Set Current Date
$current_month = (int)date('n'); $current_year = (int)date('Y');

// Loop Variables
$year_loop_start = $current_year;
$year_loop_end = $start_year; 
$month_loop_end = $current_month;

// =======================================================================
// 2. HELPER FUNCTIONS & CALCULATION LOGIC
// =======================================================================

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$payment_data = [];

// Function to get attendance days of the PARENT route
function get_parent_route_attendance($conn, $parent_route_code, $month, $year) {
    $sql = "SELECT COUNT(DISTINCT date) as days_run FROM factory_transport_vehicle_register WHERE route = ? AND MONTH(date) = ? AND YEAR(date) = ? AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $parent_route_code, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)($row['days_run'] ?? 0);
}

// Function to get manual adjustments
function get_adjustments($conn, $sub_route_code, $month, $year) {
    $sql = "SELECT SUM(adjustment_days) as total_adj FROM sub_route_adjustments WHERE sub_route_code = ? AND month = ? AND year = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $sub_route_code, $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)($row['total_adj'] ?? 0);
}

// --- MAIN DATA FETCH ---
$sub_route_sql = "SELECT sub_route_code, route_code, sub_route AS sub_route_name, vehicle_no, per_day_rate FROM sub_route WHERE is_active = 1 ORDER BY sub_route_code ASC";
$sub_route_result = $conn->query($sub_route_sql);

if ($sub_route_result && $sub_route_result->num_rows > 0) {
    while ($row = $sub_route_result->fetch_assoc()) {
        $sub_route_code = $row['sub_route_code'];
        $parent_route_code = $row['route_code'];
        $per_day_rate = (float)$row['per_day_rate'];

        // 1. Get Base Attendance
        $base_attendance = get_parent_route_attendance($conn, $parent_route_code, $selected_month, $selected_year);

        // 2. Get Adjustments
        $adjustments = get_adjustments($conn, $sub_route_code, $selected_month, $selected_year);
        
        // 3. Final Calculation
        $final_days = $base_attendance + $adjustments;
        if($final_days < 0) $final_days = 0; // Prevent negative

        $total_payment = $final_days * $per_day_rate;

        // Store Data
        $payment_data[] = [
            'sub_route_code' => $sub_route_code,
            'sub_route_name' => $row['sub_route_name'],
            'parent_route'   => $parent_route_code,
            'vehicle_no'     => $row['vehicle_no'],
            'per_day_rate'   => $per_day_rate,
            'base_days'      => $base_attendance,
            'adjustments'    => $adjustments,
            'final_days'     => $final_days,
            'total_payment'  => $total_payment
        ];
    }
}

// Page Settings
$page_title = "Sub Route Monthly Payments";
$table_headers = ["Sub Route Code", "Sub Route Name", "Parent Route", "Vehicle No", "Daily Rate", "Days Worked", "Total Payment", "PDF"];

include('../../../../includes/header.php');
include('../../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sub Route Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Custom Scrollbar */
        .overflow-x-auto::-webkit-scrollbar { height: 8px; }
        .overflow-x-auto::-webkit-scrollbar-thumb { background-color: #cbd5e0; border-radius: 4px; }
        .overflow-x-auto::-webkit-scrollbar-track { background-color: #f7fafc; }

        /* Modal Animation Logic */
        @keyframes modalPop {
            0% { opacity: 0; transform: scale(0.95) translateY(10px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-animate {
            animation: modalPop 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
    </style>
</head>
<script>
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 
    setTimeout(function() {
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="../../payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="../factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Sub Route</p>
            <a href="../../DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a>
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
            <a href="../../night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="../../own_vehicle_payments.php" class="hover:text-yellow-600">Fuel Allowance</a>
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-4 mt-[1%]">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-2 mt-4">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0"><?php echo htmlspecialchars($page_title); ?></h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="sub_route_payments.php" class="flex flex-wrap gap-2 items-center">
                    
                    <button type="button" onclick="viewAdjustmentsLog()" 
                            class="px-3 py-2 bg-purple-600 text-white font-semibold rounded-lg shadow-md hover:bg-purple-700 transition duration-200"
                            title="View All Adjustments">
                        <i class="fas fa-list-alt"></i> 
                    </button>

                    <a href="download_sub_route_excel.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
                       class="px-3 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 transition duration-200">
                        <i class="fas fa-download"></i>
                    </a>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="month" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white appearance-none">
                            <?php 
                            $min_month = ($selected_year < $start_year) ? 13 : ($start_year == $selected_year ? $start_month : 1);
                            $max_month = ($selected_year == $current_year) ? $month_loop_end : 12;
                            for ($m = $min_month; $m <= $max_month; $m++): ?>
                                <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($selected_month == $m) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500"><i class="fas fa-chevron-down text-sm"></i></div>
                    </div>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="year" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white appearance-none">
                            <?php for ($y=$year_loop_start; $y>=$year_loop_end; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500"><i class="fas fa-chevron-down text-sm"></i></div>
                    </div>
                    
                    <button type="submit" class="px-3 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 transition duration-200"><i class="fas fa-filter mr-1"></i></button>
                    
                    <a href="sub_payments_done.php" class="px-3 py-2 bg-teal-600 text-white font-semibold rounded-lg shadow-md hover:bg-teal-700 transition duration-200"><i class="fas fa-check-circle mr-1"></i></a>
                    <a href="sub_payments_history.php" class="px-3 py-2 bg-yellow-600 text-white font-semibold rounded-lg shadow-md hover:bg-yellow-700 transition duration-200"><i class="fas fa-history mr-1"></i></a> 
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                        <?php foreach ($table_headers as $header): ?>
                            <th class="py-3 px-6 text-left border-b border-blue-500"><?php echo htmlspecialchars($header); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($payment_data)): ?>
                        <?php foreach ($payment_data as $data): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                                <td class="py-3 px-6 whitespace-nowrap font-medium"><?php echo htmlspecialchars($data['sub_route_code']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($data['sub_route_name']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap text-gray-500"><?php echo htmlspecialchars($data['parent_route']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($data['vehicle_no']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap font-semibold text-purple-600"><?php echo number_format($data['per_day_rate'], 2); ?></td>

                                <td class="py-3 px-6 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <span class="text-xl font-bold w-6 text-center text-gray-800"><?php echo $data['final_days']; ?></span>
                                        
                                        <button onclick="openAdjustModal('<?php echo $data['sub_route_code']; ?>', '<?php echo htmlspecialchars($data['sub_route_name']); ?>')" 
                                                class="bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-blue-600 px-3 py-1.5 rounded-lg text-sm shadow-sm transition border border-gray-200 flex items-center gap-1 group"
                                                title="Adjust Days">
                                            <i class="fas fa-edit group-hover:scale-110 transition-transform"></i>
                                        </button>

                                        <?php if ($data['adjustments'] != 0): ?>
                                            <span class="text-xs font-bold px-2 py-1 rounded-full <?php echo $data['adjustments'] > 0 ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?>">
                                                <?php echo ($data['adjustments'] > 0 ? '+' : '') . $data['adjustments']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="py-3 px-6 whitespace-nowrap font-extrabold text-blue-700 text-base"><?php echo number_format($data['total_payment'], 2); ?></td>

                                <td class="py-3 px-6 whitespace-nowrap text-center">
                                    <a href="download_sub_route_pdf.php?sub_route_code=<?php echo htmlspecialchars($data['sub_route_code']); ?>&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>&amount=<?php echo urlencode($data['total_payment']); ?>" 
                                       class="text-red-500 hover:text-red-700 transition duration-150 transform hover:scale-110 block">
                                        <i class="fas fa-file-pdf fa-lg"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="py-12 text-center text-gray-500 font-medium">No active sub-routes found for this criteria.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div id="adjustModal" class="fixed inset-0 z-50 hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-70 backdrop-blur-sm transition-opacity" onclick="closeAdjustModal()"></div>

        <div class="flex items-center justify-center min-h-screen px-4 py-4 pointer-events-none">
            <div class="pointer-events-auto relative bg-white rounded-2xl shadow-2xl w-full max-w-md transform transition-all modal-animate overflow-hidden">
                
                <div class="h-1.5 bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500"></div>

                <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-sliders-h text-blue-600"></i>
                            Adjust Payment
                        </h3>
                        <p class="text-xs text-gray-500 mt-1">Managing: <span id="modalRouteName" class="font-bold text-blue-600">...</span></p>
                    </div>
                    <button onclick="closeAdjustModal()" class="text-gray-400 hover:text-red-500 transition-colors duration-200 p-1">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>

                <div class="px-6 py-6 space-y-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1.5">Action Type</label>
                        <div class="relative group">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 group-focus-within:text-blue-500 transition-colors">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <select id="adjType" class="block w-full pl-10 pr-10 py-2.5 text-sm font-medium border-gray-300 bg-white border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all outline-none">
                                <option value="add">Add Extra Days (+)</option>
                                <option value="deduct">Reduce Days (-)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1.5">Number of Days</label>
                        <div class="relative group">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 group-focus-within:text-blue-500 transition-colors">
                                <i class="fas fa-hashtag"></i>
                            </div>
                            <input type="number" id="adjQuantity" min="1" value="1" 
                                   class="block w-full pl-10 pr-3 py-2.5 text-sm font-medium border-gray-300 bg-white border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all outline-none" 
                                   placeholder="1">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1.5">Reason</label>
                        <div class="relative group">
                             <div class="absolute top-3 left-3 flex items-center pointer-events-none text-gray-400 group-focus-within:text-blue-500 transition-colors">
                                <i class="fas fa-pen"></i>
                            </div>
                            <textarea id="adjReason" rows="2" 
                                      class="block w-full pl-10 pr-3 py-2.5 text-sm border-gray-300 bg-white border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all outline-none resize-none" 
                                      placeholder="Why are you adjusting this?"></textarea>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-gray-50 flex gap-3 flex-row-reverse border-t border-gray-100">
                    <button type="button" onclick="submitAdjustment()" 
                            class="flex-1 inline-flex justify-center items-center px-4 py-2.5 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all transform hover:translate-y-px">
                        Save Changes
                    </button>
                    <button type="button" onclick="closeAdjustModal()" 
                            class="flex-1 inline-flex justify-center items-center px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm text-sm font-semibold text-gray-700 bg-white hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-300 transition-all">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="viewLogModal" class="fixed inset-0 z-50 hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-70 backdrop-blur-sm transition-opacity" onclick="closeViewLogModal()"></div>

        <div class="flex items-center justify-center min-h-screen px-4 py-4 pointer-events-none">
            <div class="pointer-events-auto relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl transform transition-all modal-animate overflow-hidden">
                
                <div class="h-1.5 bg-gradient-to-r from-purple-500 via-pink-500 to-red-500"></div>

                <div class="px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-center bg-gray-50/50 gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-history text-purple-600"></i>
                            Adjustment History
                        </h3>
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="text-xs font-bold text-gray-500 uppercase">View:</label>
                        <select id="historyFilter" onchange="loadLogData()" class="block w-40 pl-3 pr-8 py-1.5 text-sm border-gray-300 focus:outline-none focus:ring-purple-500 focus:border-purple-500 sm:text-sm rounded-md shadow-sm">
                            <option value="current">Current Month Only</option>
                            <option value="all">All History (Everything)</option>
                        </select>
                        
                        <!-- <button onclick="closeViewLogModal()" class="text-gray-400 hover:text-red-500 transition-colors duration-200 p-1 ml-2">
                            <i class="fas fa-times text-lg"></i>
                        </button> -->
                    </div>
                </div>

                <div class="px-0 py-0 max-h-[65vh] overflow-y-auto">
                    <table class="min-w-full leading-normal">
                        <thead class="bg-gray-100 sticky top-0 shadow-sm z-10">
                            <tr>
                                <th class="px-5 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Sub Route</th>
                                <th class="px-5 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Payment Month</th>
                                <th class="px-5 py-3 border-b-2 border-gray-200 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Adjustment</th>
                                <th class="px-5 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reason</th>
                                <th class="px-5 py-3 border-b-2 border-gray-200 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Added On</th>
                            </tr>
                        </thead>
                        <tbody id="adjustmentListBody">
                            </tbody>
                    </table>
                    
                    <div id="noAdjustmentsMsg" class="hidden py-10 text-center text-gray-500">
                        <i class="fas fa-clipboard-check text-4xl text-gray-300 mb-2"></i>
                        <p>No records found.</p>
                    </div>
                </div>

                <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 text-right">
                    <button type="button" onclick="closeViewLogModal()" 
                            class="inline-flex justify-center items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-100 transition-all">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // --- 1. EDIT ADJUSTMENT LOGIC ---
        let currentSubRouteCode = '';

        function openAdjustModal(code, name) {
            currentSubRouteCode = code;
            document.getElementById('modalRouteName').innerText = name;
            
            // Reset fields
            document.getElementById('adjType').value = 'add';
            document.getElementById('adjQuantity').value = 1;
            document.getElementById('adjReason').value = '';
            
            // Show modal
            document.getElementById('adjustModal').classList.remove('hidden');
        }

        function closeAdjustModal() {
            document.getElementById('adjustModal').classList.add('hidden');
        }

        function submitAdjustment() {
            const type = document.getElementById('adjType').value;
            const quantity = document.getElementById('adjQuantity').value;
            const reason = document.getElementById('adjReason').value;

            if (quantity <= 0) {
                alert("Please enter a valid number of days.");
                return;
            }
            if (!reason.trim()) {
                alert("Please enter a reason for the adjustment.");
                return;
            }

            // Prepare Data
            const payload = {
                sub_route_code: currentSubRouteCode,
                month: <?php echo $selected_month; ?>,
                year: <?php echo $selected_year; ?>,
                type: type,
                quantity: quantity,
                reason: reason
            };

            // Send to Backend
            fetch('save_sub_route_adjustment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    closeAdjustModal();
                    // Refresh page to show new calculations
                    location.reload(); 
                } else {
                    alert("Error: " + (data.message || "Unknown error occurred."));
                }
            })
            .catch(err => {
                console.error(err);
                alert("Connection error. Please try again.");
            });
        }

        // --- 2. VIEW LOG LOGIC ---
        function getMonthName(monthNumber) {
            const date = new Date();
            date.setMonth(monthNumber - 1);
            return date.toLocaleString('default', { month: 'short' });
        }

        function viewAdjustmentsLog() {
            document.getElementById('viewLogModal').classList.remove('hidden');
            document.getElementById('historyFilter').value = 'current'; 
            loadLogData();
        }

        function loadLogData() {
            const filterMode = document.getElementById('historyFilter').value;
            const tbody = document.getElementById('adjustmentListBody');
            const noDataMsg = document.getElementById('noAdjustmentsMsg');

            // Show Loading Spinner
            tbody.innerHTML = '<tr><td colspan="5" class="px-5 py-8 text-center"><i class="fas fa-spinner fa-spin text-purple-600 text-2xl"></i><p class="text-xs text-gray-400 mt-2">Loading...</p></td></tr>';
            noDataMsg.classList.add('hidden');

            // Fetch Data
            fetch(`get_adjustment_list.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>&mode=${filterMode}`)
            .then(response => response.json())
            .then(data => {
                tbody.innerHTML = ''; // Clear spinner
                
                if (data.length === 0) {
                    noDataMsg.classList.remove('hidden');
                } else {
                    data.forEach(item => {
                        const isPositive = item.adjustment_days > 0;
                        const badgeColor = isPositive 
                            ? 'bg-green-100 text-green-800 ring-1 ring-green-300' 
                            : 'bg-red-100 text-red-800 ring-1 ring-red-300';
                        const sign = isPositive ? '+' : '';
                        
                        const paymentMonthStr = `${getMonthName(item.month)} ${item.year}`;
                        
                        const isCurrentMonth = (item.month == <?php echo $selected_month; ?> && item.year == <?php echo $selected_year; ?>);
                        const rowClass = isCurrentMonth ? "bg-purple-50 hover:bg-purple-100" : "hover:bg-gray-50";
                        const monthBadge = isCurrentMonth 
                            ? '<span class="text-purple-700 font-bold text-xs bg-purple-100 px-2 py-0.5 rounded">Current</span>' 
                            : `<span class="text-gray-500 text-xs">${paymentMonthStr}</span>`;

                        const row = `
                            <tr class="${rowClass} border-b border-gray-200 transition-colors">
                                <td class="px-5 py-3 text-sm">
                                    <div class="font-bold text-gray-800">${item.sub_route_code}</div>
                                    <div class="text-xs text-gray-500 truncate max-w-[150px]">${item.sub_route || 'Unknown'}</div>
                                </td>
                                <td class="px-5 py-3 text-sm">
                                    ${monthBadge}
                                    ${!isCurrentMonth ? `<div class="font-semibold text-gray-700">${paymentMonthStr}</div>` : ''}
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <span class="px-2.5 py-1 font-bold text-xs leading-tight rounded-full ${badgeColor}">
                                        ${sign}${item.adjustment_days}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 italic max-w-xs break-words">
                                    "${item.reason}"
                                </td>
                                <td class="px-5 py-3 text-xs text-right text-gray-400 whitespace-nowrap">
                                    ${new Date(item.created_at).toLocaleDateString()} <br>
                                    ${new Date(item.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                                </td>
                            </tr>
                        `;
                        tbody.innerHTML += row;
                    });
                }
            })
            .catch(err => {
                console.error(err);
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-red-500 py-4">Error loading data.</td></tr>';
            });
        }

        function closeViewLogModal() {
            document.getElementById('viewLogModal').classList.add('hidden');
        }
    </script>
</body>
</html>
<?php
$conn->close();
?>