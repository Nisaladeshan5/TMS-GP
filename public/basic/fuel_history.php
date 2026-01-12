<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- Filter Logic ---
$filter_rate_id = isset($_GET['rate_id']) ? $_GET['rate_id'] : 'all'; // Default to ALL for reports
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$history_data = [];

// 1. Fetch Unique Fuel Types for the Filter Dropdown
$types_sql = "SELECT DISTINCT rate_id, type FROM fuel_rate ORDER BY type ASC";
$types_result = $conn->query($types_sql);
$fuel_types = [];
if ($types_result->num_rows > 0) {
    while ($row = $types_result->fetch_assoc()) {
        $fuel_types[] = $row;
    }
}

// 2. Build Query with Filters
$sql = "SELECT rate_id, type, rate, date FROM fuel_rate WHERE 1=1";

if ($filter_rate_id != 'all') {
    $sql .= " AND rate_id = " . intval($filter_rate_id);
}

if (!empty($from_date)) {
    $sql .= " AND date >= '" . $conn->real_escape_string($from_date) . "'";
}

if (!empty($to_date)) {
    $sql .= " AND date <= '" . $conn->real_escape_string($to_date) . "'";
}

// Order by Date DESC (Newest first), then by Type
$sql .= " ORDER BY date DESC, type ASC";

$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $history_data[] = $row;
    }
}

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Rate History Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom Print Settings */
        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            /* Reset sidebar margins for full width print */
            .print-full-width { margin: 0 !important; width: 100% !important; }
            /* Ensure table borders print clearly */
            table, th, td { border: 1px solid #ddd !important; }
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">
    
    <div class="flex justify-center items-start w-[85%] ml-[15%] min-h-screen pt-10 print-full-width">
        <div class="w-full max-w-6xl mx-auto p-6 bg-white rounded-lg shadow-md print:shadow-none print:p-0">
            
            <div class="flex justify-between items-center mb-6 border-b pb-4 print:hidden">
                <div>
                    <h2 class="text-3xl font-bold text-gray-800">Fuel Rate History Log</h2>
                    <p class="text-gray-500 text-sm mt-1">View and print historical price changes.</p>
                </div>
                <div class="flex gap-2">
                    <a href="generate_fuel_pdf.php?rate_id=<?php echo $filter_rate_id; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" target="_blank" class="bg-red-600 text-white font-bold py-2 px-6 rounded-lg shadow hover:bg-red-700 transition-colors flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                        Download Report
                    </a>
                    <a href="fuel.php?view=list" class="bg-gray-600 text-white font-bold py-2 px-6 rounded-lg shadow hover:bg-gray-700 transition-colors flex items-center">
                        Back
                    </a>
                </div>
            </div>

            <div class="hidden print:block mb-6 text-center border-b pb-4">
                <h1 class="text-2xl font-bold uppercase">Fuel Rate History Report</h1>
                <p class="text-sm text-gray-600 mt-1">Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
                <div class="mt-2 text-sm">
                    <strong>Filter:</strong> 
                    <?php 
                        echo ($filter_rate_id == 'all') ? 'All Fuel Types' : 'Specific Type (ID: '.$filter_rate_id.')'; 
                        if($from_date || $to_date) echo " | Date: " . ($from_date ? $from_date : 'Start') . " to " . ($to_date ? $to_date : 'Now');
                    ?>
                </div>
            </div>

            <div class="bg-blue-50 p-4 rounded-lg mb-6 border border-blue-200 print:hidden">
                <form method="GET" action="fuel_history.php" class="flex flex-wrap items-end gap-4">
                    
                    <div class="flex flex-col">
                        <label for="rate_id" class="font-semibold text-blue-800 text-sm mb-1">Fuel Type:</label>
                        <select name="rate_id" id="rate_id" class="border border-gray-300 rounded-md px-3 py-2 w-48 focus:ring-2 focus:ring-blue-500 outline-none">
                            <option value="all">All Types</option>
                            <?php foreach ($fuel_types as $type): ?>
                                <option value="<?php echo $type['rate_id']; ?>" <?php echo ($filter_rate_id == $type['rate_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex flex-col">
                        <label for="from_date" class="font-semibold text-blue-800 text-sm mb-1">From:</label>
                        <input type="date" name="from_date" value="<?php echo $from_date; ?>" class="border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>

                    <div class="flex flex-col">
                        <label for="to_date" class="font-semibold text-blue-800 text-sm mb-1">To:</label>
                        <input type="date" name="to_date" value="<?php echo $to_date; ?>" class="border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>

                    <div class="flex gap-2 pb-[1px]">
                        <button type="submit" class="bg-blue-600 text-white font-semibold py-2 px-4 rounded hover:bg-blue-700 transition">
                            Filter
                        </button>
                        <a href="fuel_history.php" class="bg-gray-400 text-white font-semibold py-2 px-4 rounded hover:bg-gray-500 transition">
                            Reset
                        </a>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-lg border border-gray-200 shadow-sm print:border-black print:shadow-none">
                <table class="min-w-full bg-white print:text-sm">
                    <thead class="bg-gray-800 text-white print:bg-gray-200 print:text-black">
                        <tr>
                            <th class="py-3 px-6 text-left text-xs font-medium uppercase tracking-wider border-b">Effective Date</th>
                            <th class="py-3 px-6 text-left text-xs font-medium uppercase tracking-wider border-b">Fuel Type</th>
                            <th class="py-3 px-6 text-left text-xs font-medium uppercase tracking-wider border-b">Rate (Rs.)</th>
                            <th class="py-3 px-6 text-right text-xs font-medium uppercase tracking-wider border-b">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (count($history_data) > 0): ?>
                            <?php foreach ($history_data as $row): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="py-4 px-6 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo date('Y-m-d', strtotime($row['date'])); ?>
                                    </td>
                                    <td class="py-4 px-6 whitespace-nowrap">
                                        <span class="text-sm font-bold text-blue-700 print:text-black">
                                            <?php echo htmlspecialchars($row['type']); ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 whitespace-nowrap text-sm font-semibold text-gray-900">
                                        Rs. <?php echo number_format($row['rate'], 2); ?>
                                    </td>
                                    <td class="py-4 px-6 whitespace-nowrap text-right">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 print:bg-transparent print:text-black print:border print:border-gray-400">
                                            Active
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="py-8 text-center text-gray-500">
                                    No records found for the selected dates/type.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 text-sm text-gray-500 text-right font-semibold">
                Total Records: <?php echo count($history_data); ?>
            </div>

        </div>
    </div>
</body>
</html>