<?php
ob_start();
require_once '../../includes/session_check.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// Log wela inna user ge ID eka gannawa
$current_session_user_id = $_SESSION['user_id'] ?? null;

// Filter values
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : date('Y-m-d');
$view_filter = isset($_GET['view_filter']) ? $_GET['view_filter'] : 'routes';

// Data fetching logic (user_id ekath ekka)
if ($view_filter === 'routes') {
    $sql = "SELECT fi.id, fi.code, fi.date, fi.issued_qty, fi.user_id,
                   COALESCE(r.vehicle_no, sr.vehicle_no) AS vehicle_no,
                   COALESCE(r.route, sr.sub_route) AS display_name,
                   CASE WHEN r.route_code IS NOT NULL THEN 'Main' ELSE 'Sub' END AS category,
                   emp.calling_name AS recorded_by
            FROM fuel_issues fi
            LEFT JOIN route r ON fi.code = r.route_code
            LEFT JOIN sub_route sr ON fi.code = sr.sub_route_code
            LEFT JOIN admin adm ON fi.user_id = adm.user_id
            LEFT JOIN employee emp ON adm.emp_id = emp.emp_id
            WHERE fi.date = '$date_filter'
            ORDER BY category ASC";
} else {
    $sql = "SELECT efi.id, efi.emp_id AS code, efi.issue_date AS date, efi.issued_qty, efi.user_id,
                   efi.reason AS display_name, e.calling_name AS issuer_name, 
                   'Employee' AS category,
                   rec_emp.calling_name AS recorded_by
            FROM employee_fuel_issues efi
            LEFT JOIN employee e ON efi.emp_id = e.emp_id
            LEFT JOIN admin adm ON efi.user_id = adm.user_id
            LEFT JOIN employee rec_emp ON adm.emp_id = rec_emp.emp_id
            WHERE efi.issue_date = '$date_filter'
            ORDER BY efi.id DESC";
}

$result = $conn->query($sql);

include('../../includes/header.php');
include('../../includes/navbar.php');
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Issue History</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>

<body class="bg-gray-100 font-sans">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
            <a href="fuel.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Fuel Management
            </a>
            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Fuel Issue History
            </span>
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        <div class="flex items-center bg-gray-700 rounded-lg p-2 border border-gray-600 shadow-inner">
            <input type="date" id="date-filter" value="<?php echo $date_filter; ?>" onchange="filterData()" 
                   class="bg-transparent text-white text-xs font-medium border-none outline-none focus:ring-0 cursor-pointer px-2">
        </div>
        
        <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            <select id="view-filter" onchange="filterData()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <option value="routes" <?php echo ($view_filter === 'routes') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Route Issues</option>
                <option value="employees" <?php echo ($view_filter === 'employees') ? 'selected' : ''; ?> class="text-gray-900 bg-white">Employee Issues</option>
            </select>
        </div>

        <span class="text-gray-600">|</span>

        <a href="fuel_history_excel.php?date_filter=<?php echo $date_filter; ?>&view_filter=<?php echo $view_filter; ?>" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md shadow-md transition font-semibold text-xs tracking-wide border border-green-700">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="add_manual_fuel_issue.php" class="flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-purple-700">
            Route Issue
        </a>
        <a href="add_employee_fuel.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-blue-700">
            Employee Issue
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    <div id="table-container" class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-h-[85vh]">
        <table class="w-full table-auto border-collapse">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Code</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">
                        <?php echo ($view_filter === 'routes') ? 'Route Name' : 'Employee Name'; ?>
                    </th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">
                        <?php echo ($view_filter === 'routes') ? 'Vehicle No' : 'Reason / Purpose'; ?>
                    </th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Issued Qty (L)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm">Category</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Recorded By</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm">Action</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php
                if ($result && $result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $cat = $row['category'];
                        $cat_style = ($cat === 'Main') ? 'bg-blue-100 text-blue-700' : (($cat === 'Sub') ? 'bg-purple-100 text-purple-700' : 'bg-green-100 text-green-700');
                        
                        echo "<tr class='hover:bg-indigo-50 border-b border-gray-100 transition duration-150'>";
                        echo "<td class='px-4 py-3 font-mono text-blue-600 font-bold'>" . htmlspecialchars($row["code"]) . "</td>";
                        $displayName = ($view_filter === 'routes') ? $row["display_name"] : ($row["issuer_name"] ?? 'Unknown');
                        echo "<td class='px-4 py-3 font-medium text-gray-800'>" . htmlspecialchars($displayName) . "</td>";
                        $thirdCol = ($view_filter === 'routes') ? $row["vehicle_no"] : $row["display_name"];
                        echo "<td class='px-4 py-3 font-bold uppercase text-gray-600'>" . htmlspecialchars($thirdCol) . "</td>";
                        echo "<td class='px-4 py-3 text-right font-bold text-orange-600 font-mono'>" . number_format($row["issued_qty"], 2) . "</td>";
                        echo "<td class='px-4 py-3 text-center'><span class='text-[10px] uppercase px-2 py-1 $cat_style rounded-full border border-current font-bold'>$cat</span></td>";
                        echo "<td class='px-4 py-3 text-gray-500 font-medium italic'>" . htmlspecialchars($row["recorded_by"] ?? '-') . "</td>";

                        // Delete Permission Logic
                        echo "<td class='px-4 py-3 text-center'>";
                        if (!empty($row['user_id']) && $row['user_id'] == $current_session_user_id) {
                            echo "<button onclick='deleteRecord({$row['id']}, \"$view_filter\")' class='text-red-500 hover:text-red-700 transition transform hover:scale-110' title='Delete Record'>
                                    <i class='fas fa-trash-alt'></i>
                                  </button>";
                        } else {
                            echo "<span class='text-gray-300 cursor-not-allowed'><i class='fas fa-trash-alt'></i></span>";
                        }
                        echo "</td>";
                        
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='7' class='px-6 py-10 text-center text-gray-400 italic font-medium'>No fuel issues recorded for this date.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function filterData() {
        const date = document.getElementById('date-filter').value;
        const view = document.getElementById('view-filter').value;
        window.location.href = `?date_filter=${date}&view_filter=${view}`;
    }

    function deleteRecord(id, type) {
        if (confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
            // Delete karanna backend ekata request ekak yawamu (Loku code ekak nisa AJAX implementation eka hadanna puluwan)
            window.location.href = `delete_fuel_issue.php?id=${id}&type=${type}`;
        }
    }
</script>

</body>
</html>