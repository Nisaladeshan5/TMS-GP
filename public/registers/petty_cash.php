<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Set the filter month and year to the current values by default
$filterYear = date('Y');
$filterMonth = date('m');

// If month and year are submitted via the GET method, use those values
if (isset($_GET['month']) && isset($_GET['year'])) {
    $filterYear = $_GET['year'];
    $filterMonth = $_GET['month'];
}

// Fetch Petty Cash details based on the determined filter month and year
$sql = "SELECT pc.id, pc.empNo, pc.date, pc.amount, pc.reason, r.route AS route_name
        FROM petty_cash pc
        LEFT JOIN route r ON pc.route_code = r.route_code
        WHERE MONTH(pc.date) = ? AND YEAR(pc.date) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $filterMonth, $filterYear);
$stmt->execute();
$result = $stmt->get_result();

// Data for filters
$months = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', 
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August', 
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];
$current_year_sys = date('Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Petty Cash Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
    <style>
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Petty Cash Register
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <form method="GET" class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            
            <select name="month" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <?php foreach ($months as $num => $name): 
                    $selected = ($num == $filterMonth) ? 'selected' : '';
                ?>
                    <option value="<?php echo $num; ?>" <?php echo $selected; ?> class="text-gray-900 bg-white">
                        <?php echo $name; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <span class="text-gray-400 mx-1">|</span>

            <select name="year" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-1 pr-2 appearance-none hover:text-yellow-200 transition">
                <?php 
                for ($i = $current_year_sys; $i >= $current_year_sys - 5; $i--):
                    $selected = ($i == $filterYear) ? 'selected' : '';
                ?>
                    <option value="<?php echo $i; ?>" <?php echo $selected; ?> class="text-gray-900 bg-white">
                        <?php echo $i; ?>
                    </option>
                <?php endfor; ?>
            </select>

        </form>

        <span class="text-gray-600">|</span>

        <a href="add_records/add_petty_cash.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Record
        </a>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200">
        <table class="w-full table-auto">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-3 text-left">Date</th>
                    <th class="px-4 py-3 text-left">Employee No</th>
                    <th class="px-4 py-3 text-left">Amount</th>
                    <th class="px-4 py-3 text-left">Reason</th>
                    <th class="px-4 py-3 text-left">Route</th>
                    <th class="px-4 py-3 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="text-sm text-gray-700">
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $routeName = $row['route_name'] ?? '<span class="text-gray-400 italic">No Route</span>';
                        
                        echo "<tr id='row-{$row['id']}' class='hover:bg-indigo-50 border-b border-gray-100 transition duration-150'>";
                        echo "<td class='px-4 py-3'>{$row['date']}</td>";
                        echo "<td class='px-4 py-3 font-semibold'>{$row['empNo']}</td>";
                        echo "<td class='px-4 py-3 font-mono text-green-700 font-bold'>Rs. " . number_format($row['amount'], 2) . "</td>";
                        echo "<td class='px-4 py-3 text-gray-600'>{$row['reason']}</td>";
                        echo "<td class='px-4 py-3 text-sm'>{$routeName}</td>";
                        
                        echo "<td class='px-4 py-3 text-center'>
                                <a href='edit_petty_cash.php?id={$row['id']}' class='bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded text-xs shadow-sm transition inline-flex items-center gap-1'>
                                    <i class='fas fa-edit'></i> Edit
                                </a>
                            </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' class='px-6 py-4 text-center text-gray-500'>
                            No records found for the selected period.
                          </td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>