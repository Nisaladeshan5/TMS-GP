<?php
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Set timezone to Sri Lanka
date_default_timezone_set('Asia/Colombo');

// Initialize filter variables
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Base SQL query
$sql = "SELECT id, vehicle_no, date, driver, out_time, in_time, description FROM night_emergency_vehicle_register";
$conditions = [];
$params = [];
$types = "";

// Add month and year filters if they are set
if (!empty($filter_month) && !empty($filter_year)) {
    $conditions[] = "MONTH(date) = ? AND YEAR(date) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= "ii";
}

// Append conditions to the query
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY date DESC, out_time DESC";

// Prepare and execute the statement
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
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
    <title>Night Emergency Vehicle Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* CSS for toast */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 4000; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; opacity: 0; transition: opacity 0.3s; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        
        /* Table Scrollbar */
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
            Night Emergency Register
        </div>
    </div>

    <div class="flex items-center gap-4 text-sm font-medium">
        
        <form method="GET" class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
            
            <select name="month" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-2 pr-1 appearance-none hover:text-yellow-200 transition">
                <?php foreach ($months as $num => $name): 
                    $selected = ($num == $filter_month) ? 'selected' : '';
                ?>
                    <option value="<?php echo $num; ?>" <?php echo $selected; ?> class="text-gray-900 bg-white">
                        <?php echo $name; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <span class="text-gray-400 mx-1">|</span>

            <select name="year" onchange="this.form.submit()" class="bg-transparent text-white text-sm font-medium border-none outline-none focus:ring-0 cursor-pointer py-1 pl-1 pr-2 appearance-none hover:text-yellow-200 transition">
                <?php for ($y = $current_year_sys; $y >= 2020; $y--): 
                    $selected = ($y == $filter_year) ? 'selected' : '';
                ?>
                    <option value="<?php echo $y; ?>" <?php echo $selected; ?> class="text-gray-900 bg-white">
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>

        </form>

        <span class="text-gray-600">|</span>

        <a href="add_records/night_emergency_attendance.php" class="text-gray-300 hover:text-white transition">Attendance</a>
        
        <a href="add_records/add_night_emergency_vehicle.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide">
            Add Trip
        </a>

    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg border border-gray-200">
        <table class="w-full table-auto">
            <thead class="bg-blue-600 text-white text-sm">
                <tr>
                    <th class="px-4 py-3 text-left">Vehicle No</th>
                    <th class="px-4 py-3 text-left">Date</th>
                    <th class="px-4 py-3 text-left">Driver License ID</th>
                    <th class="px-4 py-3 text-left">Out Time</th>
                    <th class="px-4 py-3 text-left">In Time</th>
                    <th class="px-4 py-3 text-left">Description</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center" style="min-width: 120px;">Action</th>
                </tr>
            </thead>
            <tbody class="text-sm">
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $is_unavailable = empty($row['in_time']);
                        
                        // Status styling
                        $status_badge = $is_unavailable 
                            ? '<span class="px-2 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700 border border-red-200">Unavailable</span>' 
                            : '<span class="px-2 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700 border border-green-200">Available</span>';

                        $formatted_out = date('H:i', strtotime($row['out_time']));
                        $formatted_in = !empty($row['in_time']) ? date('H:i', strtotime($row['in_time'])) : '<span class="text-gray-400 italic">--:--</span>';

                        echo "<tr class='hover:bg-indigo-50 transition duration-150 border-b border-gray-100'>";
                        echo "<td class='px-4 py-3 font-bold text-gray-700'>{$row['vehicle_no']}</td>";
                        echo "<td class='px-4 py-3'>{$row['date']}</td>";
                        echo "<td class='px-4 py-3'>{$row['driver']}</td>";
                        echo "<td class='px-4 py-3 font-mono text-gray-600'>{$formatted_out}</td>";
                        echo "<td class='px-4 py-3 font-mono text-gray-600'>{$formatted_in}</td>";
                        echo "<td class='px-4 py-3 text-gray-600'>{$row['description']}</td>";
                        echo "<td class='px-4 py-3 text-center'>{$status_badge}</td>";
                        
                        echo "<td class='px-4 py-3 text-center'>";
                        if ($is_unavailable) {
                            echo "<button data-id='{$row['id']}' class='log-in-btn bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded shadow-sm transition transform hover:scale-105 text-xs font-bold'>
                                    <i class='fas fa-check-circle mr-1'></i> Mark In
                                  </button>";
                        } else {
                            echo "<span class='text-gray-400 text-xs italic'><i class='fas fa-lock'></i> Completed</span>";
                        }
                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='8' class='px-6 py-4 text-center text-gray-500'>
                            No records found for the selected period.
                          </td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

<script>
    // Utility Function for Toast (replacing alert)
    function showToast(message, type = 'success') {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.classList.add('toast', type, 'show');
        const iconHtml = type === 'success' ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-circle mr-2"></i>';
        toast.innerHTML = iconHtml + `<span>${message}</span>`;
        toastContainer.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 3000);
    }

    $(document).ready(function() {
        $('.log-in-btn').on('click', function() {
            var recordId = $(this).data('id');
            var button = $(this);

            // Change button state to loading
            var originalText = button.html();
            button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

            $.ajax({
                url: 'update_in_time.php', 
                type: 'POST',
                data: { id: recordId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('Vehicle logged in successfully!', 'success');
                        
                        // Optional: Smoothly update row without reload (or keep reload for simplicity)
                        setTimeout(() => location.reload(), 1000); 
                    } else {
                        showToast('Error: ' + response.error, 'error');
                        button.prop('disabled', false).html(originalText);
                    }
                },
                error: function() {
                    showToast('An error occurred. Please try again.', 'error');
                    button.prop('disabled', false).html(originalText);
                }
            });
        });
    });
</script>

</body>
</html>