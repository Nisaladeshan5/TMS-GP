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

// --- AJAX HANDLER FOR "MARK" BUTTON ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_snapshot') {
    header('Content-Type: application/json');
    
    // 1. Clear old snapshot
    $conn->query("TRUNCATE TABLE route_previous_state");
    
    // 2. Insert current state as the new baseline
    $snapshot_sql = "
        INSERT INTO route_previous_state (route_code, old_total) 
        SELECT SUBSTRING(route, 1, 10), COUNT(emp_id) 
        FROM employee 
        WHERE is_active = 1 AND LENGTH(route) >= 12 AND vacated = 0
        GROUP BY SUBSTRING(route, 1, 10)
    ";
    
    if ($conn->query($snapshot_sql)) {
        echo json_encode(['status' => 'success', 'message' => 'Current state marked successfully! Arrows will now compare against this state.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to mark state: ' . $conn->error]);
    }
    exit;
}

// --- FETCH ROUTE SUMMARY DATA ---
$summary_sql = "
    SELECT 
        SUBSTRING(e.route, 1, 10) AS r_code, 
        SUBSTRING(e.route, 12, LENGTH(e.route) - 12) AS r_name,
        COUNT(e.emp_id) AS total_count,
        SUM(CASE WHEN CAST(e.to_home_distance AS DECIMAL(10,2)) <= 3 THEN 1 ELSE 0 END) AS nearby_count,
        IFNULL(p.old_total, COUNT(e.emp_id)) AS old_total
    FROM employee e
    LEFT JOIN route_previous_state p ON SUBSTRING(e.route, 1, 10) = p.route_code
    WHERE e.is_active = 1 AND LENGTH(e.route) >= 12 AND e.vacated = 0
    GROUP BY r_code, r_name, p.old_total
    ORDER BY r_code
";

$summary_result = $conn->query($summary_sql);

// --- TOTALS CALCULATION ---
$grand_total = 0;
$grand_nearby = 0;
if ($summary_result && $summary_result->num_rows > 0) {
    $route_data = [];
    while ($row = $summary_result->fetch_assoc()) {
        $route_data[] = $row;
        $grand_total += $row['total_count'];
        $grand_nearby += $row['nearby_count'];
    }
} else {
    $route_data = [];
}

include('../../includes/header.php'); 
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Overview Summary</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
    </style>
</head>

<body class="bg-gray-100 overflow-x-hidden text-sm"> 

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-12 flex justify-between items-center px-4 shadow-md z-50 border-b border-gray-700">
    <div class="flex items-center gap-2">
        <div class="text-sm font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Route Overview
        </div>
    </div>
    <div class="flex items-center gap-3 text-xs font-medium">
        <button onclick="markCurrentState()" id="markBtn" class="bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded shadow-sm transition border border-indigo-500 font-bold flex items-center gap-1.5">
          Mark State
        </button>
        <a href="employee.php" class="flex items-center gap-1.5 text-gray-100 px-2.5 py-1 hover:text-white transition">
            Back
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 px-4 pb-4 min-h-screen flex flex-col">
    
    <div class="bg-white rounded shadow-sm border border-gray-200 p-3 mb-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="bg-indigo-50 text-indigo-500 p-2 rounded-full">
                <i class="fas fa-chart-pie text-lg"></i>
            </div>
            <div>
                <h1 class="text-sm font-bold text-gray-800">Transport Requirement Summary</h1>
                <p class="text-[10px] text-gray-500 leading-tight">Overview of all active employees grouped by their assigned routes. Arrow indicators show changes since the last marked state.</p>
            </div>
        </div>
        <div class="flex gap-4">
            <div class="text-center bg-gray-50 px-3 py-1 rounded border border-gray-100">
                <div class="text-[9px] font-bold text-gray-500 uppercase">Total Employees</div>
                <div class="text-lg font-black text-blue-600 leading-none mt-0.5"><?php echo $grand_total; ?></div>
            </div>
            <div class="text-center bg-green-50 px-3 py-1 rounded border border-green-100">
                <div class="text-[9px] font-bold text-green-600 uppercase">&le; 3 KM Distance</div>
                <div class="text-lg font-black text-green-600 leading-none mt-0.5"><?php echo $grand_nearby; ?></div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-3 pb-6">
        
        <?php if (!empty($route_data)): ?>
            <?php foreach ($route_data as $card): ?>
                
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-2.5 hover:border-blue-300 hover:shadow transition-all duration-200 group">
                    
                    <div class="flex justify-between items-center mb-1.5">
                        <span class="bg-blue-50 text-blue-700 text-[10px] font-bold px-1.5 py-0.5 rounded border border-blue-100 font-mono tracking-tight">
                            <?php echo htmlspecialchars($card['r_code']); ?>
                        </span>
                        <i class="fas fa-bus text-gray-300 group-hover:text-blue-500 transition-colors text-[10px]"></i>
                    </div>
                    
                    <h3 class="text-xs font-semibold text-gray-800 mb-2 capitalize truncate" title="<?php echo htmlspecialchars($card['r_name']); ?>">
                        <?php echo strtolower(htmlspecialchars($card['r_name'])); ?>
                    </h3>
                    
                    <div class="flex justify-between items-end pt-1.5 border-t border-gray-100">
                        <div class="flex flex-col">
                            
                            <div class="flex items-center gap-1">
                                <span class="text-[8px] font-bold text-gray-400 uppercase tracking-wide">Total</span>
                                <?php 
                                    $diff = $card['total_count'] - $card['old_total'];
                                    
                                    if ($diff !== 0) {
                                        if ($diff > 0) {
                                            echo '<span class="text-green-500 text-[9px] ml-1 font-bold" title="Increased by ' . $diff . '"><i class="fas fa-arrow-up"></i> ' . $diff . '</span>';
                                        } elseif ($diff < 0) {
                                            echo '<span class="text-red-500 text-[9px] ml-1 font-bold" title="Decreased by ' . abs($diff) . '"><i class="fas fa-arrow-down"></i> ' . abs($diff) . '</span>';
                                        }
                                    }
                                ?>
                            </div>

                            <span class="text-base font-black text-gray-700 leading-none mt-0.5">
                                <?php echo $card['total_count']; ?>
                            </span>
                        </div>
                        <div class="flex flex-col text-right">
                            <span class="text-[8px] font-bold text-green-500 uppercase tracking-wide">&le; 3 KM</span>
                            <span class="text-base font-black text-green-600 leading-none mt-0.5">
                                <?php echo $card['nearby_count']; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
        <?php else: ?>
            <div class="col-span-full text-center text-gray-400 text-xs py-10 bg-white rounded border border-dashed border-gray-200">
                No route data available.
            </div>
        <?php endif; ?>

    </div>

</div>

<script>
    function markCurrentState() {
        if(confirm("Are you sure you want to mark the current state? \n\nThis will reset all comparison arrows to zero, and future changes will be compared against today's count.")) {
            
            const btn = document.getElementById('markBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking...';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'mark_snapshot');

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    alert(data.message);
                    location.reload(); // Reloads the page to clear the ↑/↓ arrows
                } else {
                    alert("Error: " + data.message);
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                alert("An error occurred while marking the state.");
                console.error(error);
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }
    }
</script>

</body>
</html>
<?php 
$conn->close(); 
?>