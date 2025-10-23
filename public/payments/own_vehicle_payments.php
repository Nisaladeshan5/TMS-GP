<?php
// Include the database connection
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Get selected month and year, default to current month/year
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

$payment_data = [];
$page_title = "Own Vehicle Payments Summary";

// --- SIMPLIFIED TABLE HEADERS ---
$table_headers = [
    "Employee (Vehicle No)",
    "Attendance Days",       // New column for no_of_attendance
    "Total Distance (km)",
    "Total Payment (LKR)",   // The final authoritative payment
    "PDF"
];

// 1. Fetch data from own_vehicle_payments for the selected month/year
$sql = "
    SELECT 
        ovp.emp_id,
        ovp.distance AS total_distance,
        ovp.monthly_payment AS final_payment,    -- Using monthly_payment as the final payment as per the simplified requirement
        ovp.no_of_attendance,                   -- Attendance Days
        
        e.calling_name,
        ov.vehicle_no
    FROM 
        own_vehicle_payments ovp
    JOIN 
        employee e ON ovp.emp_id = e.emp_id
    JOIN 
        own_vehicle ov ON ovp.emp_id = ov.emp_id 
    WHERE 
        ovp.month = ? AND ovp.year = ?
    ORDER BY 
        e.calling_name ASC;
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        
        // Store only the required data for display
        $payment_data[] = [
            'emp_id' => $row['emp_id'],
            'display_name' => $row['emp_id'] . ' - ' . $row['calling_name'] . " (" . $row['vehicle_no'] . ")",
            'attendance_days' => $row['no_of_attendance'] ?? 0, // Added
            'total_distance' => $row['total_distance'] ?? 0,
            'payments' => $row['final_payment'] ?? 0, // The final approved payment
        ];
    }
}

$stmt->close();
// Note: $conn->close() is moved to the end of the script, after all SQL execution.
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Custom scrollbar for better visibility */
        .overflow-x-auto::-webkit-scrollbar { height: 8px; }
        .overflow-x-auto::-webkit-scrollbar-thumb { background-color: #a0aec0; border-radius: 4px; }
        .overflow-x-auto::-webkit-scrollbar-track { background-color: #edf2f7; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="" class="hover:text-yellow-600">Factory</a>
            <a href="" class="hover:text-yellow-600">Day Heldup</a>
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
            <a href="night_emergency_payment.php" class="hover:text-yellow-600">Night Emergency</a>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Own Vehicle</p> 
        </div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-4 mt-[1%]"> 
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 pt-4">
            <h2 class="text-3xl font-extrabold text-gray-800 mb-4 sm:mb-0"><?php echo htmlspecialchars($page_title); ?></h2>
            
            <div class="w-full sm:w-auto">
                <form method="get" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="flex flex-wrap gap-2 items-center">
                    
                    <a href="download_own_vehicle.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>" 
                        class="px-3 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 text-center"
                        title="Download Monthly Report">
                        <i class="fas fa-download"></i>
                    </a>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="month" id="month" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php for ($m=1; $m<=12; $m++): ?>
                                <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($selected_month == sprintf('%02d', $m)) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <div class="relative border border-gray-300 rounded-lg shadow-sm">
                        <select name="year" id="year" class="w-full pl-3 pr-10 py-2 text-base rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200 appearance-none bg-white">
                            <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-sm"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200">
                        <i class="fas fa-filter mr-1"></i> Filter
                    </button>
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-xl shadow-2xl border border-gray-200">
    <table class="min-w-full leading-normal">
        <thead>
            <tr class="bg-blue-600 text-white text-sm font-bold tracking-wider uppercase">
                <?php 
                // Define headers and their required alignment classes
                $header_alignments = [
                    "Employee (Vehicle No)" => "text-left",
                    "Attendance Days"       => "text-right", // Changed to text-center
                    "Total Distance (km)"   => "text-right",  // Changed to text-right
                    "Total Payment (LKR)"   => "text-right",  // Changed to text-right
                    "PDF"                   => "text-center"  // Changed to text-center
                ];
                
                // Iterate through the headers and apply the correct alignment class
                foreach ($header_alignments as $header => $class): ?>
                    <th class="py-3 px-6 border-b border-blue-500 <?php echo $class; ?>">
                        <?php echo htmlspecialchars($header); ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
            <?php if (!empty($payment_data)): ?>
                <?php foreach ($payment_data as $data): ?>
                    <tr class="hover:bg-blue-50 transition-colors duration-150 ease-in-out">
                        <?php 
                        // Define the SIMPLIFIED order of keys for display (No change needed here)
                        $display_keys = ['display_name', 'attendance_days', 'total_distance', 'payments'];

                        foreach ($display_keys as $key): 
                            $value = $data[$key];
                            $cell_class = "py-3 px-6 whitespace-nowrap"; // Consistent padding
                            $formatted_value = htmlspecialchars($value);

                            // Apply specific formatting and text alignment for data cells (Matches the header adjustments)
                            if ($key === 'payments') {
                                // Total Payment (Right align)
                                $formatted_value = number_format($value, 2);
                                $cell_class .= " text-right text-blue-700 text-base font-extrabold";
                            } elseif ($key === 'total_distance') {
                                // Total Distance (Right align)
                                $formatted_value = number_format($value, 2);
                                $cell_class .= " text-right text-purple-600";
                            } elseif ($key === 'attendance_days') {
                                // Attendance Days (Center align)
                                $formatted_value = number_format($value, 0);
                                $cell_class .= " text-right font-semibold";
                            } else {
                                // Employee (Left align)
                                $cell_class .= " font-medium text-left";
                            }
                        ?>
                            <td class="<?php echo $cell_class; ?>">
                                <?php echo $formatted_value; ?>
                            </td>
                        <?php endforeach; ?>
                        
                        <td class="py-3 px-6 whitespace-nowrap text-center"> 
                            <a href="download_own_vehicle_detail_pdf.php?emp_id=<?php echo htmlspecialchars($data['emp_id']); ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>"
                                class="text-red-500 hover:text-red-700 transition duration-150"
                                title="Download Detailed PDF" target="_blank">
                                <i class="fas fa-file-pdf fa-lg"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" class="py-12 text-center text-gray-500 text-base font-medium">No Own Vehicle payment data available for <?php echo date('F', mktime(0, 0, 0, $selected_month, 10)) . ", " . $selected_year; ?>.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
    </main>
</body>
</html>
<?php
// Close the database connection
$conn->close();
?>