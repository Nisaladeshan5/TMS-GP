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
include('../../includes/header.php'); 
include('../../includes/navbar.php');

// --- FETCH DATA ---
$sql = "SELECT 
            e.emp_id, 
            e.calling_name, 
            e.department, 
            e.route, 
            e.phone_no,
            SUBSTRING(e.route, 1, 10) AS route_code,
            r.is_active
        FROM employee e
        LEFT JOIN route r ON SUBSTRING(e.route, 1, 10) = r.route_code
        WHERE 
            (r.is_active != 1 OR r.is_active IS NULL) 
            AND (SUBSTRING(e.route, 4, 3) = '-S-' OR SUBSTRING(e.route, 4, 3) = '-F-')
        ORDER BY e.department, e.emp_id";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>To Be Updated Employees</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Table එක ඇතුලේ එන Scrollbar එක ලස්සනට පෙනෙන්න */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
    </style>
</head>

<body class="bg-gray-100 text-sm overflow-hidden"> <div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-14 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center space-x-2 w-fit">
        <a href="Employee.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
            Employee 
        </a>
        <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>
        <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
            Inactive Routes
        </span>
    </div>
    <div class="flex items-center gap-3 text-xs font-medium">
        <a href="employee.php" class="text-gray-300 hover:text-white transition flex items-center gap-2">
             Back
        </a>
    </div>
</div>

<div class="fixed top-14 left-[15%] w-[85%] h-[calc(100vh-3.5rem)] bg-gray-100 p-2">
    
    <div class="bg-white shadow-lg rounded-lg border border-gray-200 flex flex-col">
        
        <div class="overflow-y-auto flex-grow rounded-lg">
            <table class="w-full table-auto border-collapse relative">
                <thead class="bg-gray-800 text-white text-xs uppercase sticky top-0 z-10 shadow-md">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold tracking-wide w-[15%]">Emp ID</th>
                        <th class="px-4 py-3 text-left font-semibold tracking-wide w-[20%]">Name</th>
                        <th class="px-4 py-3 text-left font-semibold tracking-wide w-[15%]">Department</th>
                        <th class="px-4 py-3 text-left font-semibold tracking-wide w-[30%]">Inactive Route Assigned</th>
                        <th class="px-4 py-3 text-center font-semibold tracking-wide w-[20%]">Status</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr class="hover:bg-red-50 transition duration-150">
                                <td class="px-4 py-3 font-mono text-blue-600 font-bold align-top">
                                    <?php echo htmlspecialchars($row['emp_id']); ?>
                                </td>
                                <td class="px-4 py-3 font-medium capitalize align-top">
                                    <?php echo htmlspecialchars(strtolower($row['calling_name'])); ?>
                                </td>
                                <td class="px-4 py-3 text-gray-600 text-xs font-bold uppercase align-top">
                                    <?php echo htmlspecialchars($row['department']); ?>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-col">
                                        <span class="font-mono text-red-600 font-bold text-xs">
                                            <?php echo htmlspecialchars($row['route_code']); ?>
                                        </span>
                                        <span class="text-gray-500 text-xs truncate max-w-[250px]" title="<?php echo htmlspecialchars($row['route']); ?>">
                                            <?php echo htmlspecialchars($row['route']); ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center align-top">
                                    <span class="px-2 py-1 inline-flex text-[10px] leading-5 font-semibold rounded-full bg-red-100 text-red-800 border border-red-200">
                                        Inactive
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-gray-500 flex flex-col items-center justify-center">
                                <span class="text-lg font-medium">No Issues Found</span>
                                <span class="text-xs">All employees are assigned to active routes.</span>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ?>