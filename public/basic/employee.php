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

// Get filter parameters from GET request (Required for both normal view and export)
$filter_emp_id = $_GET['emp_id'] ?? '';
$filter_route_code = $_GET['route_code'] ?? '';
$filter_department = $_GET['department'] ?? '';
$filter_staff_type = $_GET['staff_type'] ?? '';

// Base query for the main table view - near_bus_stop must be included
$sql = "SELECT 
            emp_id, 
            calling_name, 
            department, 
            gender,
            route, 
            near_bus_stop,  
            SUBSTRING(route, 1, 10) AS route_code, 
            SUBSTRING(route, 12, LENGTH(route) - 12) AS route_name
        FROM employee
        WHERE route != ''"; 

// Array for prepared statement binding parameters
$params = [];
$param_types = '';

// 1. Filter by Employee ID
if (!empty($filter_emp_id)) {
    $sql .= " AND emp_id LIKE ?";
    $params[] = "%$filter_emp_id%";
    $param_types .= 's';
}

// 2. Filter by Department
if (!empty($filter_department)) {
    $sql .= " AND department = ?";
    $params[] = $filter_department;
    $param_types .= 's';
}

// 3. Filter by Route Code (Exact match since it's a dropdown selection)
if (!empty($filter_route_code)) {
    $sql .= " AND SUBSTRING(route, 1, 10) = ?";
    $params[] = $filter_route_code;
    $param_types .= 's';
}

// 4. Filter by Staff Type (Derived from 5th character of route)
if (!empty($filter_staff_type)) {
    $char = strtoupper(substr($filter_staff_type, 0, 1)); // 'S' or 'F'
    if ($char === 'S' || $char === 'F') {
        $sql .= " AND SUBSTRING(route, 5, 1) = ?";
        $params[] = $char;
        $param_types .= 's';
    }
}

// Order by Employee ID
$sql .= " ORDER BY emp_id";

// --- 💾 EXPORT MODE (CSV Download) ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // 1. Prepare and execute the query for filtered data
    $stmt_export = $conn->prepare($sql);
    if (!empty($params)) {
        // Dynamically bind parameters
        $stmt_export->bind_param($param_types, ...$params);
    }
    $stmt_export->execute();
    $employees_to_export = $stmt_export->get_result();

    // 2. Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=employee_details_' . date('Ymd_His') . '.csv');
    ob_clean(); 
    
    // 3. Create a file pointer connected to the output stream
    $output = fopen('php://output', 'w');

    // 4. Define CSV Headers (Column Names)
    $headers = [
        'Employee ID', 
        'Calling Name', 
        'Department', 
        'Gender', 
        'Route Code', 
        'Route Name', 
        'Near Bus Stop (Raw Data)', 
        'Sub Route Info (Derived)' // The calculated field
    ];
    
    // Write the column headers to the CSV file
    fputcsv($output, $headers);

    // 5. Write data rows, including the calculated Sub Route Info
    while ($employee = $employees_to_export->fetch_assoc()) {
        $bus_stop_chars = $employee['near_bus_stop'] ?? '';
        $first_char_is_number = isset($bus_stop_chars[0]) && is_numeric($bus_stop_chars[0]);
        $sub_route_info = 'No'; // Default value

        // Replicate the Sub Route calculation logic
        if ($first_char_is_number) {
            $vehicle_type = 'Other';
            if (isset($bus_stop_chars[1])) {
                switch (strtoupper($bus_stop_chars[1])) {
                    case 'T': $vehicle_type = 'Three Wheel'; break;
                    case 'V': $vehicle_type = 'Van'; break;
                    case 'B': $vehicle_type = 'Bus'; break;
                }
            }
            $sub_route_info = ($bus_stop_chars[0] ?? '') . ' . - . ' . $vehicle_type;
        }

        $row = [
            $employee['emp_id'] ?? 'N/A',
            $employee['calling_name'],
            $employee['department'],
            $employee['gender'] ?? 'N/A',
            $employee['route_code'],
            $employee['route_name'],
            $employee['near_bus_stop'] ?? 'N/A',
            $sub_route_info 
        ];

        fputcsv($output, $row);
    }

    // 6. Close and exit
    fclose($output);
    $stmt_export->close(); 
    $conn->close(); 
    exit; // Stop script execution after sending the file
}
// --- END EXPORT MODE ---

// --- API MODE (AJAX requests for View/Edit) ---
if (isset($_GET['view_emp_id'])) {
    header('Content-Type: application/json');
    $emp_id = $_GET['view_emp_id'];

    // Select all available fields.
    $sql = "SELECT 
                emp_id, 
                calling_name, 
                route, 
                department,
                near_bus_stop, 
                direct, 
                gender, 
                SUBSTRING(route, 1, 10) AS route_code_derived,
                SUBSTRING(route, 12, LENGTH(route) - 12) AS route_name_derived
            FROM employee 
            WHERE emp_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $emp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();

    if ($employee) {
        $route_chars = $employee['route'];
        $bus_stop_chars = $employee['near_bus_stop'];
        
        // 1. Vehicle Type (Second character of near_bus_stop: T=Three wheel, V=Van, B=Bus)
        $vehicle_type = 'Other';
        if (isset($bus_stop_chars[1])) {
            switch (strtoupper($bus_stop_chars[1])) {
                case 'T': $vehicle_type = 'Three Wheel'; break;
                case 'V': $vehicle_type = 'Van'; break;
                case 'B': $vehicle_type = 'Bus'; break;
            }
        }
        $employee['vehicle_type'] = $vehicle_type;

        // 2. Staff Type (Fifth character of route: S=Staff, F=Factory)
        $staff_type = 'Other';
        if (isset($route_chars[4])) { // Index 4 is the 5th character
            switch (strtoupper($route_chars[4])) {
                case 'S': $staff_type = 'Staff'; break;
                case 'F': $staff_type = 'Factory'; break;
            }
        }
        $employee['staff_type'] = $staff_type;

        // 3. Bus Stop Check (Is first char of near_bus_stop a number?)
        $is_number = isset($bus_stop_chars[0]) && is_numeric($bus_stop_chars[0]);
        $employee['bus_stop_starts_with_number'] = $is_number ? 'Yes' : 'No';
    }

    echo json_encode($employee ?: null);
    exit;
}

// --- NORMAL PAGE LOAD (HTML) with FILTERS ---
include('../../includes/header.php'); 
include('../../includes/navbar.php');


// Prepare and execute the final query for the HTML table view
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    // Dynamically bind parameters
    $stmt->bind_param($param_types, ...$params);
}

$stmt->execute();
$employees_result = $stmt->get_result();


// Fetch distinct departments for filter dropdowns
$department_options = $conn->query("SELECT DISTINCT department FROM employee ORDER BY department")->fetch_all(MYSQLI_ASSOC);

// Fetch distinct Route Codes (First 10 characters) for the dropdown
$route_code_options = $conn->query("
    SELECT DISTINCT SUBSTRING(route, 1, 10) AS route_code_distinct 
    FROM employee 
    WHERE LENGTH(route) >= 10 
    ORDER BY route_code_distinct
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
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
<body class="bg-gray-100">
    <div class="h-screen flex flex-col">
        
        <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-12">
            <div class="text-lg font-semibold ml-3">Employee</div>
            <div class="flex gap-4">
                <p class="hover:text-yellow-600 text-yellow-500 font-bold">Employee Details</p>
                <a href="overview.php" class="hover:text-yellow-600">Overview</a>
            </div>
        </div>

        <div class="flex items-start shadow-lg w-[85%] ml-[15%] flex-col overflow-hidden flex-grow" style="height: calc(100vh - 3rem);">
            
            <div class="container p-4 w-full h-full flex flex-col">
                <p class="text-center text-4xl font-bold text-gray-800 mt-1 mb-4 flex-shrink-0">Employee Details</p>

                <div class="w-full bg-white p-4 rounded-md shadow-md mb-4 flex-shrink-0">
                    <h4 class="text-xl font-semibold mb-3 text-blue-600">Filter Employees</h4>
                    
                    <form id="filterForm" method="GET" action="" class="grid grid-cols-1 gap-4 items-end md:grid-cols-7">
                        
                        <div class="md:col-span-1">
                            <label for="filter_emp_id" class="block text-sm font-medium text-gray-700">Emp ID</label>
                            <input type="text" id="filter_emp_id" name="emp_id" value="<?php echo htmlspecialchars($filter_emp_id); ?>" class="mt-1 p-2 block w-full border rounded-md shadow-sm">
                        </div>

                        <div class="md:col-span-1">
                            <label for="filter_staff_type" class="block text-sm font-medium text-gray-700">Staff/Factory</label>
                            <select id="filter_staff_type" name="staff_type" class="mt-1 p-2 block w-full border rounded-md shadow-sm">
                                <option value="">-- All --</option>
                                <option value="S" <?php echo (strtoupper($filter_staff_type) === 'S') ? 'selected' : ''; ?>>Staff</option>
                                <option value="F" <?php echo (strtoupper($filter_staff_type) === 'F') ? 'selected' : ''; ?>>Factory</option>
                            </select>
                        </div>

                        <div class="md:col-span-1">
                            <label for="filter_route_code" class="block text-sm font-medium text-gray-700">Route Code</label>
                            <select id="filter_route_code" name="route_code" class="mt-1 p-2 block w-full border rounded-md shadow-sm">
                                <option value="">-- All --</option>
                                <?php foreach ($route_code_options as $route): ?>
                                    <?php $code = htmlspecialchars($route['route_code_distinct']); ?>
                                    <option value="<?php echo $code; ?>" <?php echo ($filter_route_code === $code) ? 'selected' : ''; ?>>
                                        <?php echo $code; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="md:col-span-1">
                            <label for="filter_department" class="block text-sm font-medium text-gray-700">Department</label>
                            <select id="filter_department" name="department" class="mt-1 p-2 block w-full border rounded-md shadow-sm">
                                <option value="">-- All --</option>
                                <?php foreach ($department_options as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept['department']); ?>" <?php echo ($filter_department === $dept['department']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['department']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="md:col-span-1"> 
                            <label class="block text-sm font-medium text-gray-700 opacity-0">Apply</label> <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 w-full whitespace-nowrap">
                                Apply Filter
                            </button>
                        </div>
                        
                        <div class="md:col-span-1">
                            <label class="block text-sm font-medium text-gray-700 opacity-0">Clear</label> <button type="button" id="clearFiltersBtn" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded-md shadow-md transition duration-300 w-full whitespace-nowrap">
                                Clear
                            </button>
                        </div>

                        <div class="md:col-span-1">
                            <label class="block text-sm font-medium text-gray-700 opacity-0">CSV</label> <button type="button" id="exportCsvBtn" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 w-full whitespace-nowrap">
                                Download CSV
                            </button>
                        </div>
                        </form>
                </div>
                
                <div class="bg-white shadow-md rounded-md w-full overflow-y-auto flex-grow">
                    <table class="min-w-full table-auto border-collapse">
                        <thead class="bg-blue-600 text-white sticky top-0 z-10">
                            <tr>
                                <th class="px-4 py-2 w-24">Emp ID</th>
                                <th class="px-4 py-2 w-32">Name</th>
                                <th class="px-4 py-2 w-32">Route Code</th>
                                <th class="px-4 py-2 w-48">Route Name</th>
                                <th class="px-4 py-2 w-48">Near Bus Stop</th> 
                                <th class="px-4 py-2 w-32">Sub Route</th> 
                                <th class="px-4 py-2 w-64">Dept.</th>
                                <th class="px-4 py-2 w-24">Gender</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($employees_result && $employees_result->num_rows > 0): ?>
                                <?php while ($employee = $employees_result->fetch_assoc()): ?>
                                    <tr class="hover:bg-gray-100">
                                        <td class="border px-4 py-2 w-24"><?php echo htmlspecialchars($employee['emp_id'] ?? 'N/A'); ?></td>
                                        <td class="border px-4 py-2 w-32"><?php echo htmlspecialchars($employee['calling_name']); ?></td>
                                        <td class="border px-4 py-2 w-32"><?php echo htmlspecialchars($employee['route_code']); ?></td>
                                        <td class="border px-4 py-2 w-48"><?php echo htmlspecialchars($employee['route_name']); ?></td>
                                        
                                        <td class="border px-4 py-2 w-48"><?php echo htmlspecialchars($employee['near_bus_stop'] ?? 'N/A'); ?></td>

                                        <td class="border px-4 py-2 w-32">
                                            <?php 
                                                $bus_stop_chars = $employee['near_bus_stop'] ?? '';
                                                // Check if the first character is a number
                                                $first_char_is_number = isset($bus_stop_chars[0]) && is_numeric($bus_stop_chars[0]);

                                                if ($first_char_is_number) {
                                                    // Logic for: first character IS a number -> Show [First Char] . - . [Vehicle Type]
                                                    $vehicle_type = 'Other';
                                                    if (isset($bus_stop_chars[1])) {
                                                        switch (strtoupper($bus_stop_chars[1])) {
                                                            case 'T': $vehicle_type = 'Three Wheel'; break;
                                                            case 'V': $vehicle_type = 'Van'; break;
                                                            case 'B': $vehicle_type = 'Bus'; break;
                                                        }
                                                    }
                                                    echo htmlspecialchars($bus_stop_chars[0]) . ' . - . ' . htmlspecialchars($vehicle_type);
                                                } else {
                                                    // Logic for: first character IS NOT a number -> Show "No" (or a different indicator)
                                                    echo "No";
                                                }
                                            ?>
                                        </td>
                                        <td class="border px-4 py-2 w-64"><?php echo htmlspecialchars($employee['department']); ?></td>
                                        <td class="border px-4 py-2 w-24"><?php echo htmlspecialchars($employee['gender'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="border px-4 py-2 text-center">No employees found matching the criteria</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<div id="toast-container"></div>

<script src="employee.js"></script>
<script>
    document.getElementById('clearFiltersBtn').addEventListener('click', function() {
        // Clear all filter fields and redirect to the base page
        window.location.href = window.location.pathname;
    });

    document.getElementById('exportCsvBtn').addEventListener('click', function() {
        // Get the current URL parameters from the filter form
        const form = document.getElementById('filterForm');
        // We use URLSearchParams to correctly handle the current filter values
        const params = new URLSearchParams(window.location.search);
        
        // Remove existing 'export' parameter if present
        params.delete('export');
        
        // Update URL parameters with current form values
        // Note: We iterate through all form elements to ensure the current values (even for text inputs) are captured, 
        // though in this specific form, only the select boxes and the text input are relevant filters.
        form.querySelectorAll('input, select').forEach(element => {
            if (element.name && element.value) {
                params.set(element.name, element.value);
            } else if (element.name && !element.value) {
                params.delete(element.name); // Clear empty fields from URL
            }
        });

        // Add the 'export=csv' flag to the parameters
        params.append('export', 'csv');
        
        // Construct the new URL and navigate (triggering the download)
        window.location.href = window.location.pathname + '?' + params.toString();
    });
</script>

</body>
</html>

<?php 
// Close the statement and connection at the very end
if (isset($stmt)) {
    $stmt->close(); 
}
$conn->close(); 
?>